<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Update;

use ProCoders\ThemeRelease\Config\Channel;
use ProCoders\ThemeRelease\Config\SiteContext;
use ProCoders\ThemeRelease\GitHub\GitHubClient;
use ProCoders\ThemeRelease\GitHub\RepoName;
use ProCoders\ThemeRelease\Release\RepoState;
use ProCoders\ThemeRelease\Release\SemVer;
use ProCoders\ThemeRelease\Release\StateKey;
use ProCoders\ThemeRelease\Release\StateStore;
use ProCoders\ThemeRelease\Theme\InstallRecord;
use ProCoders\ThemeRelease\Theme\Mapping;
use ProCoders\ThemeRelease\Theme\MappingRepository;
use ProCoders\ThemeRelease\Theme\ThemeDirectory;

/**
 * Decides, from stored state only (no HTTP), what each mapped theme is offered.
 */
final class UpdateOffer
{
    public function __construct(
        private readonly MappingRepository $mappings,
        private readonly StateStore $state,
        private readonly ThemeDirectory $themes,
        private readonly SiteContext $site,
    ) {
    }

    /**
     * The stored state of the mapping's repository, or an empty state when it was computed for
     * another channel or branch than the one in force now (channel switched, branch renamed, DB cloned).
     */
    public function currentState(Mapping $mapping): RepoState
    {
        $state = $this->state->repo($mapping->repo);
        $channel = $this->site->channel();
        if (null === $channel || $state->context !== RepoState::contextFor($channel, $mapping->branch($this->site->settings(), $channel))) {
            return RepoState::empty();
        }

        return $state;
    }

    public static function packageUrl(RepoName $repo, string $sha): string
    {
        return GitHubClient::API_BASE . $repo->apiPath() . '/zipball/' . $sha;
    }

    public function evaluate(Mapping $mapping): ThemeStatus
    {
        $state = $this->currentState($mapping);
        $missing = ! $this->themes->exists($mapping->stylesheet);
        $conflict = ! $missing && $this->themes->managedByGitUpdater($mapping->stylesheet);
        $record = $mapping->record;
        $label = null !== $record ? $record->tag : ($missing ? '' : $this->themes->version($mapping->stylesheet));
        $installed = SemVer::parse($label);

        $channel = $this->site->channel();
        $branch = null === $channel ? '' : $mapping->branch($this->site->settings(), $channel);
        $candidate = $state->offerable;
        $deleted = null !== $record
            && $state->checkedAt > 0
            && null === $state->error
            && ! in_array('tags_truncated', $state->warnings, true)
            && ! isset($state->tagShas[$record->tag]);
        $outOfChannel = self::outOfChannel($record, $installed, $channel, $state);

        $offer = null;
        $switchTarget = null;
        if (! $missing && ! $conflict && ! $mapping->frozen && null !== $candidate) {
            if (null !== $outOfChannel && (null === $record || $candidate->tag !== $record->tag)) {
                // The installed tag is not of this channel: the newest channel tag is offered even when lower.
                $switchTarget = $candidate;
                $offer = $candidate;
            } elseif (null === $installed || $candidate->version()->compare($installed) > 0) {
                $offer = $candidate;
            }
        }

        $moved = null !== $record && isset($state->tagShas[$record->tag]) && $state->tagShas[$record->tag] !== $record->sha;
        // tagShas holds every tag GitHub listed, so a tag of the other channel is not "deleted".
        $recordVersion = null === $record ? null : SemVer::parse($record->tag);
        $otherChannel = null !== $record
            && null !== $recordVersion
            && null !== $channel
            && isset($state->tagShas[$record->tag])
            && $recordVersion->isPrerelease() !== (Channel::Staging === $channel);
        $targets = array_values(array_filter(
            $state->releases,
            static fn ($release): bool => null === $record || $release->tag !== $record->tag,
        ));

        return new ThemeStatus(
            $mapping,
            $state,
            $label,
            $offer,
            $missing,
            $conflict,
            $moved,
            $deleted,
            $targets,
            $otherChannel,
            $this->state->int(StateKey::RATE_LIMITED_UNTIL),
            $outOfChannel,
            $switchTarget,
            $channel,
            $branch,
        );
    }

    /**
     * Whether the installed tag is not a tag of the site's channel. A wrong suffix is known without GitHub,
     * and is judged on the style.css version when nothing is recorded (mapping re-added, cloned site).
     * A right-suffix recorded tag counts only when it is listed and higher than the newest confirmed channel
     * tag (a deleted one is not offered a downgrade, UpdateTest::test_lower_only_in_rollback_list; its
     * status says it is gone instead): the resolver walks tags newest first, so a listed one was compared
     * and answered "not in the branch" (a cached, uncompared, unconfirmed or capped tag cannot sit above
     * the offerable one). Tags the resolver dropped below the offerable one are never out of channel.
     */
    private static function outOfChannel(?InstallRecord $record, ?SemVer $installed, ?Channel $channel, RepoState $state): ?OutOfChannel
    {
        if (null === $installed || null === $channel) {
            return null;
        }
        if ($installed->isPrerelease() !== (Channel::Staging === $channel)) {
            return OutOfChannel::Suffix;
        }
        $best = $state->offerable;
        if (null !== $record && null !== $best && isset($state->tagShas[$record->tag]) && $installed->compare($best->version()) > 0) {
            return OutOfChannel::Branch;
        }

        return null;
    }

    /** @return array<string, ThemeStatus> */
    public function all(): array
    {
        $result = [];
        foreach ($this->mappings->all() as $stylesheet => $mapping) {
            $result[$stylesheet] = $this->evaluate($mapping);
        }

        return $result;
    }
}
