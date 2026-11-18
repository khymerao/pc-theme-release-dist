<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Admin;

use ProCoders\ThemeRelease\Update\OutOfChannel;
use ProCoders\ThemeRelease\Update\ThemeStatus;

/**
 * Plain-language status of one mapped theme, shared by the Status column, the Check now notice and Site Health.
 * Built from stored state only, never from the URL.
 */
final class StatusText
{
    /** @return list<string> */
    public static function lines(ThemeStatus $status): array
    {
        $lines = [];
        $state = $status->state;
        if ($status->themeMissing) {
            $lines[] = __('Theme missing on disk.', 'pc-theme-release');
        }
        if ($status->gitUpdaterConflict) {
            $lines[] = __('Managed by Git Updater (GitHub Theme URI). Deactivate Git Updater to manage it here.', 'pc-theme-release');
        }
        if ($status->mapping->frozen) {
            $lines[] = __('Frozen after a rollback: no update notices.', 'pc-theme-release');
        }
        $outOfChannel = self::outOfChannel($status);
        if (null !== $status->offer && null === $status->switchTarget) {
            /* translators: %s: tag. */
            $lines[] = sprintf(__('Update available: %s', 'pc-theme-release'), $status->offer->tag);
        } elseif (0 === $state->checkedAt) {
            $lines[] = __('Not checked yet.', 'pc-theme-release');
        } elseif (null === $state->error && ! self::unconfirmed($status) && ! $status->mapping->frozen && null === $outOfChannel && null === self::unresolved($status)) {
            $lines[] = __('Up to date.', 'pc-theme-release');
        }
        if (null !== $outOfChannel) {
            $lines[] = $outOfChannel;
        }
        if (self::nothingConfirmed($status)) {
            $lines[] = self::nothingConfirmedText();
        }
        $error = self::error($status);
        if (null !== $error) {
            $lines[] = $error;
        }
        foreach ($state->warnings as $warning) {
            $lines[] = ErrorText::warning($warning);
        }
        if ($status->tagMoved) {
            $lines[] = __('Warning: the installed tag now points to a different commit (tag moved).', 'pc-theme-release');
        }
        if ($status->tagDeleted) {
            $lines[] = __('The installed tag is no longer on GitHub.', 'pc-theme-release');
        } elseif ($status->tagOtherChannel) {
            $lines[] = __('Note: the installed tag belongs to the other channel.', 'pc-theme-release');
        }
        if (null !== $status->mapping->record && $status->mapping->record->versionMismatch) {
            $lines[] = __('Note: style.css Version differs from the tag.', 'pc-theme-release');
        }
        if ($state->checkedAt > 0) {
            /* translators: %s: date and time. */
            $lines[] = sprintf(__('Checked %s', 'pc-theme-release'), View::date('Y-m-d H:i', $state->checkedAt));
        }

        return $lines;
    }

    /**
     * The installed tag is not of the site's channel: says from where, and what moves the theme to this channel.
     * Null when the installed tag (recorded, else the style.css version) belongs to the channel.
     */
    public static function outOfChannel(ThemeStatus $status): ?string
    {
        $installed = $status->installedLabel;
        $channel = $status->channel;
        if (null === $status->outOfChannel || '' === $installed || null === $channel) {
            return null;
        }
        $first = OutOfChannel::Suffix === $status->outOfChannel
            /* translators: 1: installed tag, 2: channel name (staging or production). */
            ? sprintf(__('Installed %1$s is from the %2$s channel.', 'pc-theme-release'), $installed, $channel->other()->name())
            /* translators: 1: installed tag, 2: branch. */
            : sprintf(__('Installed %1$s is not in the %2$s branch.', 'pc-theme-release'), $installed, $status->branch);
        if (null !== $status->switchTarget) {
            /* translators: 1: tag to install, 2: channel name. */
            return $first . ' ' . sprintf(__('Install %1$s to move this theme to %2$s.', 'pc-theme-release'), $status->switchTarget->tag, $channel->name());
        }
        if ($status->mapping->frozen) {
            /* translators: %s: channel name. */
            return $first . ' ' . sprintf(__('Unfreeze the theme to move it to %s.', 'pc-theme-release'), $channel->name());
        }
        if ($status->state->checkedAt > 0 && null === $status->state->offerable && null === self::problem($status)) {
            /* translators: %s: channel name. */
            return $first . ' ' . sprintf(__('The %s channel has no tag to install yet.', 'pc-theme-release'), $channel->name());
        }

        return $first;
    }

    /**
     * A state that is neither an error nor "Up to date.": the installed tag is gone from GitHub, or the
     * compare limit was reached before any channel tag was confirmed. Also listed in Site Health.
     */
    public static function unresolved(ThemeStatus $status): ?string
    {
        if ($status->tagDeleted) {
            return __('The installed tag is no longer on GitHub.', 'pc-theme-release');
        }

        return self::nothingConfirmed($status) ? self::nothingConfirmedText() : null;
    }

    private static function nothingConfirmed(ThemeStatus $status): bool
    {
        return null === $status->state->offerable
            && null === $status->state->error
            && in_array('compare_budget_exhausted', $status->state->warnings, true);
    }

    private static function nothingConfirmedText(): string
    {
        return __('No tag of this channel could be confirmed in the branch (compare limit reached), so nothing is offered.', 'pc-theme-release');
    }

    /** Why the last check of this mapping failed, or null when it did not. */
    public static function error(ThemeStatus $status): ?string
    {
        $state = $status->state;
        if (null === $state->error) {
            return null;
        }

        return ErrorText::forRepo($state->error, $state->errorDetail, $status->mapping->repo->full(), $status->rateLimitedUntil);
    }

    /** The newest tag's branch could not be confirmed (a compare call failed): the check did not complete. */
    public static function unconfirmed(ThemeStatus $status): bool
    {
        return in_array('containment_unknown', $status->state->warnings, true);
    }

    /** Why the last check of this mapping did not complete: its error, or the unconfirmed-branch warning. */
    public static function problem(ThemeStatus $status): ?string
    {
        $error = self::error($status);
        if (null !== $error) {
            return $error;
        }

        return self::unconfirmed($status) ? ErrorText::warning('containment_unknown') : null;
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
            $problem = self::problem($status);
            if (null !== $problem) {
                $lines[] = $status->mapping->stylesheet . ': ' . $problem;
            }
        }

        return $lines;
    }
}
