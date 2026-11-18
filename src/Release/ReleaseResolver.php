<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Release;

use ProCoders\ThemeRelease\Config\Channel;
use ProCoders\ThemeRelease\GitHub\Containment;
use ProCoders\ThemeRelease\GitHub\GitHubClient;
use ProCoders\ThemeRelease\GitHub\GitHubError;
use ProCoders\ThemeRelease\GitHub\RepoName;
use ProCoders\ThemeRelease\GitHub\TagList;
use ProCoders\ThemeRelease\Support\Clock;
use ProCoders\ThemeRelease\Support\Keys;
use ProCoders\ThemeRelease\Support\TransientStore;

/**
 * Turns a repository's tags into the site's channel releases (spec call 1):
 * the suffix must match the channel AND the commit must be contained in the channel branch.
 */
final class ReleaseResolver
{
    public const int LIST_SIZE = 20;
    public const int CONTAINMENT_TTL = 86400;
    /** Uncached compare calls per repository and check, to bound API use (builder's call). */
    public const int COMPARE_BUDGET = 30;

    public function __construct(
        private readonly GitHubClient $github,
        private readonly TransientStore $cache,
        private readonly Clock $clock,
    ) {
    }

    /** @throws GitHubError Only for failures that make the whole result unusable. */
    public function resolve(RepoName $repo, Channel $channel, string $branch, TagList $tags): RepoState
    {
        $context = RepoState::contextFor($channel, $branch);
        $candidates = $this->channelTags($tags, $channel);
        // Every tag GitHub listed (both channels): "is the installed tag still on GitHub?" is judged against all of them.
        $tagShas = [];
        foreach ($tags->tags as $tag) {
            $tagShas[$tag->name] ??= $tag->sha;
        }
        $warnings = $tags->truncated ? ['tags_truncated'] : [];

        $releases = [];
        $unknownAboveBest = false;
        $budget = self::COMPARE_BUDGET;
        foreach ($candidates as [$tag]) {
            if (count($releases) >= self::LIST_SIZE) {
                break;
            }
            $cached = $this->cachedDate($repo, $branch, $tag->sha);
            if (null !== $cached) {
                $releases[] = new ChannelRelease($tag->name, $tag->sha, $cached > 0 ? $cached : null);
                continue;
            }
            if ($budget <= 0) {
                // Every compare so far answered: the stray tags are known not to be in the branch. Nothing
                // is offered (no release found), but the check itself completed; the stray-tag warning says why.
                $warnings[] = 'compare_budget_exhausted';
                break;
            }
            $budget--;
            try {
                $result = $this->github->compare($repo, $branch, $tag->sha);
            } catch (GitHubError) {
                // Unknown: never cached, and nothing below it may be offered as "the newest" (A6).
                if ([] === $releases) {
                    $unknownAboveBest = true;
                } else {
                    // An older tag that could not be confirmed drops out of the list until the next check.
                    $warnings[] = 'older_unconfirmed';
                }
                continue;
            }
            if (Containment::BranchMissing === $result->containment) {
                return new RepoState([], null, $tagShas, $this->clock->now(), 'branch_missing', $branch, $warnings, $context);
            }
            if (Containment::Contained === $result->containment) {
                $this->cache->set($this->cacheKey($repo, $branch, $tag->sha), $result->commitDate ?? 0, self::CONTAINMENT_TTL);
                $releases[] = new ChannelRelease($tag->name, $tag->sha, $result->commitDate);
            }
        }

        $offerable = $unknownAboveBest || [] === $releases ? null : $releases[0];
        if ($unknownAboveBest) {
            $warnings[] = 'containment_unknown';
        }

        return new RepoState($releases, $offerable, $tagShas, $this->clock->now(), null, '', array_values(array_unique($warnings)), $context);
    }

    /** @return list<array{0: \ProCoders\ThemeRelease\GitHub\Tag, 1: SemVer}> Newest first. */
    private function channelTags(TagList $tags, Channel $channel): array
    {
        $wantPrerelease = Channel::Staging === $channel;
        $result = [];
        $seen = [];
        foreach ($tags->tags as $tag) {
            $version = SemVer::parse($tag->name);
            if (null === $version || $version->isPrerelease() !== $wantPrerelease || isset($seen[$tag->name])) {
                continue;
            }
            $seen[$tag->name] = true;
            $result[] = [$tag, $version];
        }
        usort($result, static fn (array $a, array $b): int => $b[1]->compare($a[1]));

        return $result;
    }

    /** Commit date (0 when unknown) of a cached positive result, or null when not cached. */
    private function cachedDate(RepoName $repo, string $branch, string $sha): ?int
    {
        $value = $this->cache->get($this->cacheKey($repo, $branch, $sha));

        return is_int($value) ? $value : null;
    }

    private function cacheKey(RepoName $repo, string $branch, string $sha): string
    {
        return Keys::COMPARE_PREFIX . md5($repo->full() . '|' . $branch . '|' . $sha);
    }
}
