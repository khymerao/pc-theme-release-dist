<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Status;

use ProCoders\ThemeRelease\Config\Channel;
use ProCoders\ThemeRelease\GitHub\GitHubErrorKind;
use ProCoders\ThemeRelease\Release\ChannelRelease;
use ProCoders\ThemeRelease\Release\RepoState;
use ProCoders\ThemeRelease\Support\Environment;
use ProCoders\ThemeRelease\Theme\Mapping;

/**
 * Everything the screen, Site Health and the update injection know about one mapped theme, and its
 * plain-language status (the Status column, the Check now notice and Site Health share these texts).
 * Built from stored state only, never from the URL.
 */
final readonly class ThemeStatus
{
    /** The installed tag's suffix belongs to the other channel (a prerelease on production, a plain tag on staging). */
    public const string OUT_OF_CHANNEL_SUFFIX = 'suffix';
    /** Right suffix, but the check found the installed tag's commit outside the channel branch. */
    public const string OUT_OF_CHANNEL_BRANCH = 'branch';
    /** The newest tag's commit does not come after the installed commit (audit N2). */
    public const string ANCESTRY_NOT_NEWER = 'not_newer';
    /** GitHub did not confirm that it does (compare failed, or not asked since the installed tag changed). */
    public const string ANCESTRY_UNCONFIRMED = 'unconfirmed';

    /**
     * @param list<ChannelRelease>  $rollbackTargets
     * @param 'suffix'|'branch'|null $outOfChannel    Why the installed tag is not a tag of the site's channel.
     * @param 'not_newer'|'unconfirmed'|null $ancestry Why the newest tag is not offered over the installed commit.
     */
    public function __construct(
        public Mapping $mapping,
        public RepoState $state,
        public string $installedLabel,
        public ?ChannelRelease $offer,
        public bool $themeMissing,
        public bool $gitUpdaterConflict,
        public bool $tagMoved,
        public bool $tagDeleted,
        public array $rollbackTargets,
        public bool $tagOtherChannel,
        public int $rateLimitedUntil,
        public ?string $outOfChannel,
        public ?ChannelRelease $switchTarget,
        public ?Channel $channel,
        public string $branch,
        public ?string $ancestry = null,
    ) {
    }

    /** No rollback, switch or update can run: the theme is missing on disk or managed by Git Updater. */
    public function unavailable(): bool
    {
        return $this->themeMissing || $this->gitUpdaterConflict;
    }

    /** @return list<string> */
    public function lines(): array
    {
        $lines = $this->headline();
        $outOfChannel = $this->outOfChannelText();
        if (null !== $outOfChannel) {
            $lines[] = $outOfChannel;
        }
        if ($this->nothingConfirmed()) {
            $lines[] = self::nothingConfirmedText();
        }

        return array_merge($lines, $this->problemLines(), $this->notes());
    }

    /**
     * The installed tag is not of the site's channel: says from where, and what moves the theme to this channel.
     * Null when the installed tag (recorded, else the style.css version) belongs to the channel.
     */
    public function outOfChannelText(): ?string
    {
        $channel = $this->channel;
        if (null === $this->outOfChannel || '' === $this->installedLabel || null === $channel) {
            return null;
        }
        $first = self::OUT_OF_CHANNEL_SUFFIX === $this->outOfChannel
            /* translators: 1: installed tag, 2: channel name (staging or production). */
            ? sprintf(__('Installed %1$s is from the %2$s channel.', 'pc-theme-release'), $this->installedLabel, $channel->other()->lowerLabel())
            /* translators: 1: installed tag, 2: branch. */
            : sprintf(__('Installed %1$s is not in the %2$s branch.', 'pc-theme-release'), $this->installedLabel, $this->branch);
        if (null !== $this->switchTarget) {
            /* translators: 1: tag to install, 2: channel name. */
            return $first . ' ' . sprintf(__('Install %1$s to move this theme to %2$s.', 'pc-theme-release'), $this->switchTarget->tag, $channel->lowerLabel());
        }
        if ($this->mapping->frozen) {
            /* translators: %s: channel name. */
            return $first . ' ' . sprintf(__('Unfreeze the theme to move it to %s.', 'pc-theme-release'), $channel->lowerLabel());
        }
        if ($this->state->checkedAt > 0 && null === $this->state->offerable && null === $this->problem()) {
            /* translators: %s: channel name. */
            return $first . ' ' . sprintf(__('The %s channel has no tag to install yet.', 'pc-theme-release'), $channel->lowerLabel());
        }

        return $first;
    }

    /**
     * A state that is neither an error nor "Up to date.": the installed tag is gone from GitHub, or the
     * compare limit was reached before any channel tag was confirmed. Also listed in Site Health.
     */
    public function unresolved(): ?string
    {
        if ($this->tagDeleted) {
            return self::tagDeletedText();
        }

        if ($this->nothingConfirmed()) {
            return self::nothingConfirmedText();
        }

        return self::ANCESTRY_NOT_NEWER === $this->ancestry ? self::notNewerText() : null;
    }

    /** Why the last check of this mapping failed, or null when it did not. */
    public function error(): ?string
    {
        $state = $this->state;
        if (null === $state->error) {
            return null;
        }

        return self::errorText($state->error, $state->errorDetail, $this->mapping->repo->full(), $this->rateLimitedUntil);
    }

    /** The newest tag's branch could not be confirmed (a compare call failed): the check did not complete. */
    public function unconfirmed(): bool
    {
        return $this->state->hasWarning(RepoState::CONTAINMENT_UNKNOWN);
    }

    /** Why the last check of this mapping did not complete: its error, or the unconfirmed-branch warning. */
    public function problem(): ?string
    {
        $error = $this->error();
        if (null !== $error) {
            return $error;
        }

        if ($this->unconfirmed()) {
            return self::warningText(RepoState::CONTAINMENT_UNKNOWN);
        }

        return self::ANCESTRY_UNCONFIRMED === $this->ancestry ? self::ancestryUnconfirmedText() : null;
    }

    /**
     * One line per mapping whose last check did not complete: "theme: reason".
     *
     * @param array<string, ThemeStatus> $statuses
     * @return list<string>
     */
    public static function failures(array $statuses): array
    {
        $lines = [];
        foreach ($statuses as $status) {
            $problem = $status->problem();
            if (null !== $problem) {
                $lines[] = $status->mapping->stylesheet . ': ' . $problem;
            }
        }

        return $lines;
    }

    /**
     * Plain-language text for a stored per-repository error code (spec call 7).
     *
     * @param int $rateLimitedUntil When GitHub's rate limit resets (0: unknown).
     */
    public static function errorText(string $code, string $detail, string $repo, int $rateLimitedUntil = 0): string
    {
        if (RepoState::REPO_PUBLIC === $code) {
            /* translators: %s: owner/repo. */
            return sprintf(__('Error: repository %s is public. Only private repositories are accepted, so nothing is offered or installed.', 'pc-theme-release'), $repo);
        }
        if (RepoState::REPO_REPLACED === $code) {
            /* translators: %s: owner/repo. */
            return sprintf(__('Error: repository %s on GitHub is not the one that was added (its GitHub id changed: deleted and created again?). Nothing is offered or installed. If this is intended, remove the theme here and add it again.', 'pc-theme-release'), $repo);
        }
        if (RepoState::BRANCH_MISSING === $code) {
            /* translators: %s: branch name. */
            return sprintf(__('Error: branch %s not found on GitHub. Nothing is offered.', 'pc-theme-release'), $detail);
        }
        if (GitHubErrorKind::RateLimited->value === $code && $rateLimitedUntil > 0) {
            /* translators: %s: date and time the rate limit resets. */
            return sprintf(__('Error: GitHub rate limit reached; rate-limited until %s. The plugin waits until then.', 'pc-theme-release'), Environment::date('Y-m-d H:i', $rateLimitedUntil));
        }

        return match (GitHubErrorKind::tryFrom($code)) {
            GitHubErrorKind::Unauthorized => __('Error: the GitHub token expired or was revoked.', 'pc-theme-release'),
            /* translators: %s: owner/repo. */
            GitHubErrorKind::NotFound => sprintf(__('Error: the token cannot see repository %s (check the token\'s repository access).', 'pc-theme-release'), $repo),
            GitHubErrorKind::RateLimited => __('Error: GitHub rate limit reached. The plugin waits until it resets.', 'pc-theme-release'),
            GitHubErrorKind::Forbidden => __('Error: GitHub refused the request (403). Check the token permissions.', 'pc-theme-release'),
            GitHubErrorKind::Server => __('Error: GitHub answered with a server error. It is retried at the next check.', 'pc-theme-release'),
            GitHubErrorKind::Transport => __('Error: GitHub could not be reached from this server.', 'pc-theme-release'),
            GitHubErrorKind::NoToken => __('Error: no usable GitHub token.', 'pc-theme-release'),
            GitHubErrorKind::TooLarge => __('Error: GitHub\'s answer was larger than the plugin accepts. It is retried at the next check.', 'pc-theme-release'),
            default => __('Error: GitHub returned an unexpected answer.', 'pc-theme-release'),
        };
    }

    /** Plain-language text for a stored warning code (RepoState::*). */
    public static function warningText(string $code): string
    {
        return match ($code) {
            RepoState::TAGS_TRUNCATED => __('Warning: more than 1000 tags; only the first 1000 GitHub returns are read. Delete old tags.', 'pc-theme-release'),
            RepoState::CONTAINMENT_UNKNOWN => __('Warning: GitHub could not confirm the branch of the newest tag. Nothing is offered until the next check.', 'pc-theme-release'),
            RepoState::OLDER_UNCONFIRMED => __('Warning: some older tags could not be confirmed; they are not listed until the next check.', 'pc-theme-release'),
            RepoState::COMPARE_BUDGET_EXHAUSTED => __('Warning: more than 30 of the newest channel tags are not in the channel branch, so older tags were not checked. Delete or move the stray tags.', 'pc-theme-release'),
            default => __('Warning.', 'pc-theme-release'),
        };
    }

    /** @return list<string> Missing, Git Updater, frozen, then the offer or the check summary. */
    private function headline(): array
    {
        $lines = [];
        if ($this->themeMissing) {
            $lines[] = __('Theme missing on disk.', 'pc-theme-release');
        }
        if ($this->gitUpdaterConflict) {
            $lines[] = __('Managed by Git Updater (GitHub Theme URI). Deactivate Git Updater to manage it here.', 'pc-theme-release');
        }
        if ($this->mapping->frozen) {
            $lines[] = __('Frozen after a rollback: no update notices.', 'pc-theme-release');
        }
        if (null !== $this->offer && null === $this->switchTarget) {
            /* translators: %s: tag. */
            $lines[] = sprintf(__('Update available: %s', 'pc-theme-release'), $this->offer->tag);
        } elseif (0 === $this->state->checkedAt) {
            $lines[] = __('Not checked yet.', 'pc-theme-release');
        } elseif ($this->upToDate()) {
            $lines[] = __('Up to date.', 'pc-theme-release');
        }

        return $lines;
    }

    /** Checked, nothing to offer, and nothing else to say about it. */
    private function upToDate(): bool
    {
        return null === $this->state->error
            && ! $this->unconfirmed()
            && ! $this->mapping->frozen
            && null === $this->outOfChannelText()
            && null === $this->unresolved()
            && null === $this->ancestry;
    }

    /** @return list<string> The check's error and warnings. */
    private function problemLines(): array
    {
        $lines = [];
        $error = $this->error();
        if (null !== $error) {
            $lines[] = $error;
        }
        foreach ($this->state->warnings as $warning) {
            $lines[] = self::warningText($warning);
        }
        if (self::ANCESTRY_NOT_NEWER === $this->ancestry) {
            $lines[] = self::notNewerText();
        } elseif (self::ANCESTRY_UNCONFIRMED === $this->ancestry) {
            $lines[] = self::ancestryUnconfirmedText();
        }

        return $lines;
    }

    /** @return list<string> Facts about the installed tag, then when it was checked. */
    private function notes(): array
    {
        $lines = [];
        if ($this->tagMoved) {
            $lines[] = __('Warning: the installed tag now points to a different commit (tag moved).', 'pc-theme-release');
        }
        if ($this->tagDeleted) {
            $lines[] = self::tagDeletedText();
        } elseif ($this->tagOtherChannel) {
            $lines[] = __('Note: the installed tag belongs to the other channel.', 'pc-theme-release');
        }
        if (null !== $this->mapping->record && $this->mapping->record->versionMismatch) {
            $lines[] = __('Note: style.css Version differs from the tag.', 'pc-theme-release');
        }
        if ($this->state->checkedAt > 0) {
            /* translators: %s: date and time. */
            $lines[] = sprintf(__('Checked %s', 'pc-theme-release'), Environment::date('Y-m-d H:i', $this->state->checkedAt));
        }

        return $lines;
    }

    private function nothingConfirmed(): bool
    {
        return null === $this->state->offerable
            && null === $this->state->error
            && $this->state->hasWarning(RepoState::COMPARE_BUDGET_EXHAUSTED);
    }

    private static function nothingConfirmedText(): string
    {
        return __('No tag of this channel could be confirmed in the branch (compare limit reached), so nothing is offered.', 'pc-theme-release');
    }

    private static function notNewerText(): string
    {
        return __('The newest tag is not newer than the installed commit.', 'pc-theme-release');
    }

    private static function ancestryUnconfirmedText(): string
    {
        return __('Warning: GitHub could not confirm that the newest tag comes after the installed commit. Nothing is offered until the next check.', 'pc-theme-release');
    }

    private static function tagDeletedText(): string
    {
        return __('The installed tag is no longer on GitHub.', 'pc-theme-release');
    }
}
