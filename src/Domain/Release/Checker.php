<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Domain\Release;

use ProCoders\ThemeRelease\Core\Config\Channel;
use ProCoders\ThemeRelease\Core\Config\SiteContext;
use ProCoders\ThemeRelease\Core\GitHub\GitHubClient;
use ProCoders\ThemeRelease\Core\GitHub\GitHubError;
use ProCoders\ThemeRelease\Core\GitHub\GitHubErrorKind;
use ProCoders\ThemeRelease\Core\GitHub\RepoName;
use ProCoders\ThemeRelease\Core\GitHub\Repository;
use ProCoders\ThemeRelease\Core\Support\Environment;
use ProCoders\ThemeRelease\Core\Support\Keys;
use ProCoders\ThemeRelease\Core\Support\Storage;
use ProCoders\ThemeRelease\Core\Support\ValidationError;
use ProCoders\ThemeRelease\Domain\Theme\Mapping;
use ProCoders\ThemeRelease\Domain\Theme\MappingRepository;

/**
 * Re-checks GitHub for one or all mapped repositories (cron, Check now, webhook) and re-injects offers.
 * Never installs anything. A repository's channel releases are its tags with the channel's suffix whose
 * commit is contained in the channel branch (spec call 1).
 */
final class Checker
{
    public const int LIST_SIZE = 20;
    public const int CONTAINMENT_TTL = 3600;
    /** Uncached compare calls per repository and check, to bound API use (builder's call). */
    public const int COMPARE_BUDGET = 30;

    public function __construct(
        private readonly SiteContext $site,
        private readonly MappingRepository $mappings,
        private readonly GitHubClient $github,
        private readonly StateStore $state,
        private readonly StatusLog $log,
        private readonly Storage $storage,
        private readonly Environment $env,
    ) {
    }

    /** @return int Number of repositories checked (0 when the site is not operational). */
    public function checkAll(string $trigger): int
    {
        // A stored pair the plugin no longer accepts (e.g. theme "../uploads") is removed and logged once.
        foreach ($this->mappings->dropInvalid() as $stylesheet) {
            $this->log->add('mapping_dropped_invalid', $stylesheet);
        }
        if (! $this->site->isOperational()) {
            return 0;
        }
        $count = 0;
        foreach ($this->mappings->all() as $mapping) {
            $this->checkMapping($mapping, $trigger);
            $count++;
        }
        $this->checkDeliveries();
        $this->state->setInt(Keys::LAST_CHECK_AT, $this->env->now());
        UpdateTransient::refresh();

        return $count;
    }

    /** Checks only the mapping of $repo; false when it is not managed or the site is not operational. */
    public function checkRepo(RepoName $repo, string $trigger): bool
    {
        $mapping = $this->mappings->findByRepo($repo);
        if (null === $mapping || ! $this->site->isOperational()) {
            return false;
        }
        $this->checkMapping($mapping, $trigger);
        UpdateTransient::refresh();

        return true;
    }

    /** Cron entry point. */
    public function runScheduled(): void
    {
        $this->state->setInt(Keys::LAST_CRON_AT, $this->env->now());
        $this->checkAll('cron');
    }

    /**
     * The id of a repository about to be mapped (audit N1), or null when GitHub cannot tell now (the next
     * check stores it, trust on first use).
     *
     * @throws ValidationError "repo_public" for a public repository.
     */
    public function idForNewMapping(RepoName $repo): ?int
    {
        try {
            $repository = $this->github->repository($repo);
        } catch (GitHubError) {
            return null;
        }
        if (! $repository->private) {
            throw new ValidationError(RepoState::REPO_PUBLIC);
        }

        return $repository->id;
    }

    /**
     * Whether GitHub still has the mapped repository under its stored id, private (audit N1). Stores the id
     * when none is stored yet (trust on first use, same public refusal). Null refusal: it is the mapped one.
     *
     * @return array{0: string|null, 1: Repository|null} refusal code (RepoState::REPO_*, or NotFound's code), what GitHub said
     *
     * @throws GitHubError For failures other than "not found".
     */
    public function identity(Mapping $mapping): array
    {
        try {
            $repository = $this->github->repository($mapping->repo);
        } catch (GitHubError $error) {
            if (GitHubErrorKind::NotFound === $error->kind) {
                return [GitHubErrorKind::NotFound->value, null];
            }
            throw $error;
        }
        if (null !== $mapping->repoId && $repository->id !== $mapping->repoId) {
            return [RepoState::REPO_REPLACED, $repository];
        }
        if (! $repository->private) {
            return [RepoState::REPO_PUBLIC, $repository];
        }
        if (null === $mapping->repoId) {
            $this->mappings->setRepoId($mapping->stylesheet, $repository->id);
        }

        return [null, $repository];
    }

    /**
     * Right before a download (audit N3): the repository is still the mapped one, and the commit is in the
     * channel branch as GitHub resolves it now. Nothing cached is used.
     *
     * @return array{refusal: string|null, names: list<RepoName>} refusal: RepoState::REPO_* / NotFound's code,
     *         RepoState::BRANCH_MISSING or "not_in_branch"; names: the repository's current name too (after a rename).
     *
     * @throws GitHubError When GitHub could not answer (rate limit included).
     */
    public function confirmForInstall(Mapping $mapping, string $sha): array
    {
        [$refusal, $repository] = $this->identity($mapping);
        $names = null === $repository?->name || $repository->name->equals($mapping->repo) ? [] : [$repository->name];
        $channel = $this->site->channel();
        if (null !== $refusal || null === $channel) {
            return ['refusal' => $refusal ?? 'not_in_branch', 'names' => $names];
        }
        $head = $this->github->branchHead($mapping->repo, $mapping->branch($this->site->settings(), $channel));
        if (null === $head) {
            return ['refusal' => RepoState::BRANCH_MISSING, 'names' => $names];
        }
        $contained = 'contained' === $this->github->compare($mapping->repo, $head, $sha)['status'];

        return ['refusal' => $contained ? null : 'not_in_branch', 'names' => $names];
    }

    /**
     * Refreshes the ancestry verdict (audit N2) after the installed record changed without a check (Record
     * installed tag, Unfreeze), so the stored offer stays true. One compare at most; nothing when not operational.
     */
    public function confirmAncestry(string $stylesheet): void
    {
        $mapping = $this->mappings->get($stylesheet);
        $channel = $this->site->channel();
        if (null === $mapping || null === $channel || ! $this->site->isOperational()) {
            return;
        }
        $state = $this->state->repo($mapping->repo);
        if ($state->context !== RepoState::contextFor($channel, $mapping->branch($this->site->settings(), $channel))) {
            return;
        }
        $verdict = $this->withAncestry($mapping, $state);
        if ($verdict !== $state) {
            $this->state->saveRepo($mapping->repo, $verdict);
        }
    }

    /**
     * After every tag check of a cron or Check now round (never for a webhook or a new mapping): the GitHub
     * delivery log of this site webhook, per repository (spec call 1). Without any webhook secret nothing is read
     * and every stored result is removed (spec call 15); a theme without a secret of its own, global or constant is
     * skipped and its result removed (per-theme secret spec call 7). A repository that is not the mapped one is skipped.
     */
    private function checkDeliveries(): void
    {
        if (! $this->site->webhookInUse()) {
            $this->state->forgetDeliveries();
            return;
        }
        $check = new DeliveryCheck($this->github, $this->state, $this->env);
        $endpoint = rest_url(Keys::REST_NAMESPACE . '/webhook');
        $prefix = rest_get_url_prefix();
        $notMapped = [RepoState::REPO_PUBLIC, RepoState::REPO_REPLACED, GitHubErrorKind::NotFound->value];
        foreach ($this->mappings->all() as $mapping) {
            $inUse = $this->site->webhookInUseFor($mapping->stylesheet, $mapping->repo->full());
            if (! $inUse || in_array($this->state->repo($mapping->repo)->error, $notMapped, true)) {
                // No secret for this theme (it means to receive nothing), or not the mapped repository now: what was
                // read about it before no longer applies.
                $this->state->forgetDelivery($mapping->repo);
                continue;
            }
            $check->check($mapping->repo, $endpoint, $prefix);
        }
    }

    private function checkMapping(Mapping $mapping, string $trigger): void
    {
        $channel = $this->site->channel();
        if (null === $channel) {
            return;
        }
        $previous = $this->state->repo($mapping->repo);
        $branch = $mapping->branch($this->site->settings(), $channel);
        $context = RepoState::contextFor($channel, $branch);
        try {
            [$refusal] = $this->identity($mapping);
            if (null !== $refusal) {
                // Not the mapped repository: nothing it listed may be offered, rolled back to or installed.
                $this->log->add($trigger . '_failed', $mapping->repo->full(), '', GitHubErrorKind::NotFound->value === $refusal ? 404 : 0);
                $this->state->saveRepo($mapping->repo, new RepoState([], null, [], $this->env->now(), $refusal, $mapping->repo->full(), [], $context));
                return;
            }
            $state = $this->resolve($mapping->repo, $channel, $branch, $this->github->tags($mapping->repo));
        } catch (GitHubError $error) {
            $failed = $this->failed($previous, $channel, $branch, $error, $mapping->repo);
            $this->log->add($trigger . '_failed', $mapping->repo->full(), '', $error->httpStatus);
            $this->state->saveRepo($mapping->repo, $failed);
            return;
        }
        $state = $this->withAncestry($this->mappings->get($mapping->stylesheet) ?? $mapping, $state);
        $this->state->saveRepo($mapping->repo, $state);
        // A round whose newest tag could not be confirmed is reported as failed by Check now; log it apart.
        $event = $state->hasWarning(RepoState::CONTAINMENT_UNKNOWN) ? '_unconfirmed' : '_checked';
        $this->log->add($trigger . $event, $mapping->repo->full(), null === $state->offerable ? '' : $state->offerable->tag);
    }

    /**
     * The state with the verdict whether the offerable tag's commit comes after the installed one (audit N2).
     * Asked only when it decides an offer: a record exists, the commits differ, and the tag is newer than
     * the recorded one. "unknown" when GitHub could not answer (never offered until the next check).
     */
    private function withAncestry(Mapping $mapping, RepoState $state): RepoState
    {
        $record = $mapping->record;
        $candidate = $state->offerable;
        if (null === $record || null === $candidate || $record->sha === $candidate->sha || null !== $state->ancestryFor($record->sha, $candidate->sha)) {
            return $state;
        }
        $installed = SemVer::parse($record->tag);
        if (null !== $installed && $candidate->version()->compare($installed) <= 0) {
            return $state;
        }
        try {
            $status = $this->github->compareCommits($mapping->repo, $record->sha, $candidate->sha);
        } catch (GitHubError) {
            $status = 'unknown';
        }

        return $state->withAncestry($record->sha, $candidate->sha, $status);
    }

    /**
     * Why this round failed, for the channel and branch in force now, so the screen shows it. What was
     * known is kept only when it was learnt for this same channel and branch.
     */
    private function failed(RepoState $previous, Channel $channel, string $branch, GitHubError $error, RepoName $repo): RepoState
    {
        $context = RepoState::contextFor($channel, $branch);
        $same = $previous->context === $context;

        return new RepoState(
            $same ? $previous->releases : [],
            null,
            $same ? $previous->tagShas : [],
            $this->env->now(),
            $error->kind->value,
            $repo->full(),
            $same ? $previous->warnings : [],
            $context,
        );
    }

    /**
     * The channel releases of a repository: newest first, at most LIST_SIZE, each confirmed in the branch as
     * its head resolves now (audit N5: the head is resolved once, every compare is against that SHA).
     *
     * @param array{tags: list<array{name: string, sha: string}>, truncated: bool} $tags
     *
     * @throws GitHubError Only for failures that make the whole result unusable.
     */
    private function resolve(RepoName $repo, Channel $channel, string $branch, array $tags): RepoState
    {
        $context = RepoState::contextFor($channel, $branch);
        // Every tag GitHub listed (both channels): "is the installed tag still on GitHub?" is judged against all of them.
        $tagShas = [];
        foreach ($tags['tags'] as $tag) {
            $tagShas[$tag['name']] ??= $tag['sha'];
        }
        $warnings = $tags['truncated'] ? [RepoState::TAGS_TRUNCATED] : [];
        $candidates = self::channelTags($tags['tags'], $channel);
        $head = [] === $candidates ? '' : $this->github->branchHead($repo, $branch);
        if (null === $head) {
            return new RepoState([], null, $tagShas, $this->env->now(), RepoState::BRANCH_MISSING, $branch, $warnings, $context);
        }

        $releases = [];
        $unknownAboveBest = false;
        $budget = self::COMPARE_BUDGET;
        foreach ($candidates as $tag) {
            if (count($releases) >= self::LIST_SIZE) {
                break;
            }
            $cached = $this->cachedDate($repo, $head, $tag['sha']);
            if (null !== $cached) {
                $releases[] = new ChannelRelease($tag['name'], $tag['sha'], $cached > 0 ? $cached : null);
                continue;
            }
            if ($budget <= 0) {
                // Every compare so far answered: the stray tags are known not to be in the branch. Nothing
                // is offered (no release found), but the check itself completed; the stray-tag warning says why.
                $warnings[] = RepoState::COMPARE_BUDGET_EXHAUSTED;
                break;
            }
            $budget--;
            $result = $this->confirm($repo, $head, $tag);
            if ($result instanceof ChannelRelease) {
                $releases[] = $result;
            } elseif ('unknown' === $result && [] === $releases) {
                // Unknown: nothing below it may be offered as "the newest" (A6).
                $unknownAboveBest = true;
            } elseif ('unknown' === $result) {
                // An older tag that could not be confirmed drops out of the list until the next check.
                $warnings[] = RepoState::OLDER_UNCONFIRMED;
            }
        }

        $offerable = $unknownAboveBest || [] === $releases ? null : $releases[0];
        if ($unknownAboveBest) {
            $warnings[] = RepoState::CONTAINMENT_UNKNOWN;
        }

        return new RepoState($releases, $offerable, $tagShas, $this->env->now(), null, '', array_values(array_unique($warnings)), $context);
    }

    /**
     * One uncached compare against the branch head: the release when the tag's commit is in it (and caches
     * that answer, keyed by the head SHA), "not_contained", or "unknown" when GitHub could not answer.
     *
     * @param array{name: string, sha: string} $tag
     * @return ChannelRelease|'not_contained'|'unknown'
     */
    private function confirm(RepoName $repo, string $head, array $tag): ChannelRelease|string
    {
        try {
            $result = $this->github->compare($repo, $head, $tag['sha']);
        } catch (GitHubError) {
            return 'unknown';
        }
        if ('contained' !== $result['status']) {
            // The head exists (just resolved): a 404 here means GitHub could not compare this commit.
            return 'branch_missing' === $result['status'] ? 'unknown' : 'not_contained';
        }
        $this->storage->setTransient(self::cacheKey($repo, $head, $tag['sha']), $result['date'] ?? 0, self::CONTAINMENT_TTL);

        return new ChannelRelease($tag['name'], $tag['sha'], $result['date']);
    }

    /**
     * Tags of the channel (SemVer with the channel's suffix), each name once, newest first.
     *
     * @param list<array{name: string, sha: string}> $tags
     * @return list<array{name: string, sha: string}>
     */
    private static function channelTags(array $tags, Channel $channel): array
    {
        $result = [];
        foreach ($tags as $tag) {
            $version = SemVer::parse($tag['name']);
            if (null === $version || $version->isPrerelease() !== $channel->takesPrerelease() || isset($result[$tag['name']])) {
                continue;
            }
            $result[$tag['name']] = [$tag, $version];
        }
        usort($result, static fn (array $a, array $b): int => $b[1]->compare($a[1]));

        return array_map(static fn (array $pair): array => $pair[0], $result);
    }

    /** Commit date (0 when unknown) of a cached positive result, or null when not cached. */
    private function cachedDate(RepoName $repo, string $head, string $sha): ?int
    {
        $value = $this->storage->transient(self::cacheKey($repo, $head, $sha));

        return is_int($value) ? $value : null;
    }

    /** Keyed by the branch head's SHA: a cached "contained" is a fact about that commit, never about a ref name. */
    private static function cacheKey(RepoName $repo, string $head, string $sha): string
    {
        return Keys::COMPARE_PREFIX . md5($repo->full() . '|' . $head . '|' . $sha);
    }
}
