<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Release;

use ProCoders\ThemeRelease\Config\Channel;
use ProCoders\ThemeRelease\Config\SiteContext;
use ProCoders\ThemeRelease\GitHub\GitHubClient;
use ProCoders\ThemeRelease\GitHub\GitHubError;
use ProCoders\ThemeRelease\GitHub\RepoName;
use ProCoders\ThemeRelease\Support\Environment;
use ProCoders\ThemeRelease\Support\Keys;
use ProCoders\ThemeRelease\Support\Storage;
use ProCoders\ThemeRelease\Theme\Mapping;
use ProCoders\ThemeRelease\Theme\MappingRepository;

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

    private function checkMapping(Mapping $mapping, string $trigger): void
    {
        $channel = $this->site->channel();
        if (null === $channel) {
            return;
        }
        $previous = $this->state->repo($mapping->repo);
        $branch = $mapping->branch($this->site->settings(), $channel);
        try {
            $state = $this->resolve($mapping->repo, $channel, $branch, $this->github->tags($mapping->repo));
        } catch (GitHubError $error) {
            $failed = $this->failed($previous, $channel, $branch, $error, $mapping->repo);
            $this->log->add($trigger . '_failed', $mapping->repo->full(), '', $error->httpStatus);
            $this->state->saveRepo($mapping->repo, $failed);
            return;
        }
        $this->state->saveRepo($mapping->repo, $state);
        // A round whose newest tag could not be confirmed is reported as failed by Check now; log it apart.
        $event = $state->hasWarning(RepoState::CONTAINMENT_UNKNOWN) ? '_unconfirmed' : '_checked';
        $this->log->add($trigger . $event, $mapping->repo->full(), null === $state->offerable ? '' : $state->offerable->tag);
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
     * The channel releases of a repository: newest first, at most LIST_SIZE, each confirmed in the branch.
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

        $releases = [];
        $unknownAboveBest = false;
        $budget = self::COMPARE_BUDGET;
        foreach (self::channelTags($tags['tags'], $channel) as $tag) {
            if (count($releases) >= self::LIST_SIZE) {
                break;
            }
            $cached = $this->cachedDate($repo, $branch, $tag['sha']);
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
            $result = $this->confirm($repo, $branch, $tag);
            if ('branch_missing' === $result) {
                return new RepoState([], null, $tagShas, $this->env->now(), RepoState::BRANCH_MISSING, $branch, $warnings, $context);
            }
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
     * One uncached compare: the release when the tag's commit is in the branch (and caches that answer),
     * "not_contained", "branch_missing", or "unknown" when GitHub could not answer (never cached).
     *
     * @param array{name: string, sha: string} $tag
     * @return ChannelRelease|'not_contained'|'branch_missing'|'unknown'
     */
    private function confirm(RepoName $repo, string $branch, array $tag): ChannelRelease|string
    {
        try {
            $result = $this->github->compare($repo, $branch, $tag['sha']);
        } catch (GitHubError) {
            return 'unknown';
        }
        if ('contained' !== $result['status']) {
            return $result['status'];
        }
        $this->storage->setTransient(self::cacheKey($repo, $branch, $tag['sha']), $result['date'] ?? 0, self::CONTAINMENT_TTL);

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
    private function cachedDate(RepoName $repo, string $branch, string $sha): ?int
    {
        $value = $this->storage->transient(self::cacheKey($repo, $branch, $sha));

        return is_int($value) ? $value : null;
    }

    private static function cacheKey(RepoName $repo, string $branch, string $sha): string
    {
        return Keys::COMPARE_PREFIX . md5($repo->full() . '|' . $branch . '|' . $sha);
    }
}
