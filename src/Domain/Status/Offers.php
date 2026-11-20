<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Domain\Status;

use ProCoders\ThemeRelease\Core\Config\Channel;
use ProCoders\ThemeRelease\Core\Config\SiteContext;
use ProCoders\ThemeRelease\Core\GitHub\RepoName;
use ProCoders\ThemeRelease\Core\Support\Keys;
use ProCoders\ThemeRelease\Domain\Release\ChannelRelease;
use ProCoders\ThemeRelease\Domain\Release\RepoState;
use ProCoders\ThemeRelease\Domain\Release\SemVer;
use ProCoders\ThemeRelease\Domain\Release\StateStore;
use ProCoders\ThemeRelease\Domain\Theme\InstallRecord;
use ProCoders\ThemeRelease\Domain\Theme\Mapping;
use ProCoders\ThemeRelease\Domain\Theme\MappingRepository;
use ProCoders\ThemeRelease\Domain\Theme\Themes;

/**
 * Decides, from stored state only (no HTTP), what each mapped theme is offered.
 */
final class Offers
{
    public function __construct(
        private readonly MappingRepository $mappings,
        private readonly StateStore $state,
        private readonly Themes $themes,
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

    /** @return list<RepoName> The mapped repositories, without reading any theme (cheap enough for every admin screen). */
    public function repos(): array
    {
        return array_values(array_map(static fn (Mapping $mapping): RepoName => $mapping->repo, $this->mappings->all()));
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

    public function evaluate(Mapping $mapping): ThemeStatus
    {
        $state = $this->currentState($mapping);
        $missing = ! $this->themes->exists($mapping->stylesheet);
        $conflict = ! $missing && $this->themes->managedByGitUpdater($mapping->stylesheet);
        $record = $mapping->record;
        $label = $this->installedLabel($mapping, $missing);
        $installed = SemVer::parse($label);
        $channel = $this->site->channel();
        $outOfChannel = self::outOfChannel($record, $installed, $channel, $state);
        $available = ! $missing && ! $conflict && ! $mapping->frozen;
        [$offer, $switchTarget] = $available ? self::offerFor($state->offerable, $record, $installed, $outOfChannel) : [null, null];
        $ancestry = null === $offer || null !== $switchTarget ? null : self::ancestryRefusal($state, $record, $offer);
        if (null !== $ancestry) {
            $offer = null;
        }

        return new ThemeStatus(
            $mapping,
            $state,
            $label,
            $offer,
            $missing,
            $conflict,
            null !== $record && isset($state->tagShas[$record->tag]) && $state->tagShas[$record->tag] !== $record->sha,
            self::tagDeleted($record, $state),
            self::rollbackTargets($state, $record),
            self::tagOtherChannel($record, $channel, $state),
            $this->state->int(Keys::RATE_LIMITED_UNTIL),
            $outOfChannel,
            $switchTarget,
            $channel,
            null === $channel ? '' : $mapping->branch($this->site->settings(), $channel),
            $ancestry,
        );
    }

    /**
     * Why a newer tag is not offered over the recorded commit (audit N2): GitHub said its commit does not
     * come after the installed one, or that was not confirmed for exactly this pair (fail closed). Null: offer
     * it. No record (version from style.css), the same commit, and the switch to the site's channel are not
     * judged; explicit rollbacks never pass here.
     *
     * @return 'not_newer'|'unconfirmed'|null
     */
    private static function ancestryRefusal(RepoState $state, ?InstallRecord $record, ChannelRelease $offer): ?string
    {
        if (null === $record || $record->sha === $offer->sha) {
            return null;
        }
        $status = $state->ancestryFor($record->sha, $offer->sha);
        if (null === $status || 'unknown' === $status) {
            return ThemeStatus::ANCESTRY_UNCONFIRMED;
        }

        return in_array($status, RepoState::DESCENDANT, true) ? null : ThemeStatus::ANCESTRY_NOT_NEWER;
    }

    /** The recorded tag, else the style.css version; empty when the theme is missing and nothing is recorded. */
    private function installedLabel(Mapping $mapping, bool $missing): string
    {
        if (null !== $mapping->record) {
            return $mapping->record->tag;
        }
        if ($missing) {
            return '';
        }

        return $this->themes->version($mapping->stylesheet);
    }

    /**
     * What is offered: the newest channel tag when it is higher than the installed one; when the installed
     * tag is not of this channel, the newest channel tag even when lower (it is then also the switch target).
     *
     * @return array{0: ChannelRelease|null, 1: ChannelRelease|null} offer, switch target
     */
    private static function offerFor(?ChannelRelease $candidate, ?InstallRecord $record, ?SemVer $installed, ?string $outOfChannel): array
    {
        if (null === $candidate) {
            return [null, null];
        }
        if (null !== $outOfChannel && (null === $record || $candidate->tag !== $record->tag)) {
            return [$candidate, $candidate];
        }
        if (null === $installed || $candidate->version()->compare($installed) > 0) {
            return [$candidate, null];
        }

        return [null, null];
    }

    /** The recorded tag is gone from GitHub (only judged after a complete, error-free, untruncated check). */
    private static function tagDeleted(?InstallRecord $record, RepoState $state): bool
    {
        return null !== $record
            && $state->checkedAt > 0
            && null === $state->error
            && ! $state->hasWarning(RepoState::TAGS_TRUNCATED)
            && ! isset($state->tagShas[$record->tag]);
    }

    /** The recorded tag is listed on GitHub (tagShas has both channels) and has the other channel's suffix. */
    private static function tagOtherChannel(?InstallRecord $record, ?Channel $channel, RepoState $state): bool
    {
        $version = null === $record ? null : SemVer::parse($record->tag);

        return null !== $record
            && null !== $version
            && null !== $channel
            && isset($state->tagShas[$record->tag])
            && $version->isPrerelease() !== $channel->takesPrerelease();
    }

    /** @return list<ChannelRelease> Every channel release except the recorded one. */
    private static function rollbackTargets(RepoState $state, ?InstallRecord $record): array
    {
        return array_values(array_filter(
            $state->releases,
            static fn (ChannelRelease $release): bool => null === $record || $release->tag !== $record->tag,
        ));
    }

    /**
     * Whether the installed tag is not a tag of the site's channel. A wrong suffix is known without GitHub,
     * and is judged on the style.css version when nothing is recorded (mapping re-added, cloned site).
     * A right-suffix recorded tag counts only when it is listed and higher than the newest confirmed channel
     * tag (a deleted one is not offered a downgrade, UpdateTest::test_lower_only_in_rollback_list; its
     * status says it is gone instead): the check walks tags newest first, so a listed one was compared
     * and answered "not in the branch" (a cached, uncompared, unconfirmed or capped tag cannot sit above
     * the offerable one). Tags the check dropped below the offerable one are never out of channel.
     *
     * @return 'suffix'|'branch'|null
     */
    private static function outOfChannel(?InstallRecord $record, ?SemVer $installed, ?Channel $channel, RepoState $state): ?string
    {
        if (null === $installed || null === $channel) {
            return null;
        }
        if ($installed->isPrerelease() !== $channel->takesPrerelease()) {
            return ThemeStatus::OUT_OF_CHANNEL_SUFFIX;
        }
        $best = $state->offerable;
        if (null !== $record && null !== $best && isset($state->tagShas[$record->tag]) && $installed->compare($best->version()) > 0) {
            return ThemeStatus::OUT_OF_CHANNEL_BRANCH;
        }

        return null;
    }
}
