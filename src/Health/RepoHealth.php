<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Health;

use ProCoders\ThemeRelease\Admin\StatusText;
use ProCoders\ThemeRelease\GitHub\GitHubErrorKind;
use ProCoders\ThemeRelease\Update\UpdateOffer;

/**
 * The last check's error of every mapped repository, for Site Health (same text as the Status column).
 */
final class RepoHealth
{
    /** Errors that clear by themselves (builder's call): only recommended, not critical. */
    private const array TRANSIENT = [GitHubErrorKind::RateLimited, GitHubErrorKind::Server, GitHubErrorKind::Transport];

    public function __construct(private readonly UpdateOffer $offers)
    {
    }

    /** @return list<HealthItem> */
    public function items(): array
    {
        $items = [];
        foreach ($this->offers->all() as $status) {
            $outOfChannel = StatusText::outOfChannel($status);
            if (null !== $outOfChannel) {
                // Not an error: the admin has an action to take (install the channel tag, or unfreeze first).
                $items[] = new HealthItem(HealthItem::RECOMMENDED, 'repo_other_channel', $status->mapping->stylesheet . ': ' . $outOfChannel);
            }
            $unresolved = StatusText::unresolved($status);
            if (null !== $unresolved) {
                $items[] = new HealthItem(HealthItem::RECOMMENDED, 'repo_unresolved', $status->mapping->stylesheet . ': ' . $unresolved);
            }
            $problem = StatusText::problem($status);
            if (null === $problem) {
                continue;
            }
            // An unconfirmed branch (no error code) clears at the next check, like the transient errors.
            $code = $status->state->error;
            $transient = null === $code || in_array(GitHubErrorKind::tryFrom($code), self::TRANSIENT, true);
            $items[] = new HealthItem(
                $transient ? HealthItem::RECOMMENDED : HealthItem::CRITICAL,
                'repo_error',
                $status->mapping->stylesheet . ': ' . $problem
            );
        }
        if ([] === $items) {
            $items[] = new HealthItem(HealthItem::GOOD, 'repos_ok', __('The last GitHub check of every managed theme succeeded (or none was checked yet).', 'pc-theme-release'));
        }

        return $items;
    }
}
