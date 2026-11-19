<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Domain\Status;

use ProCoders\ThemeRelease\Core\Config\SiteContext;
use ProCoders\ThemeRelease\Core\GitHub\GitHubErrorKind;
use ProCoders\ThemeRelease\Core\Security\SecretKind;
use ProCoders\ThemeRelease\Core\Security\Secrets;
use ProCoders\ThemeRelease\Core\Security\SecretSource;
use ProCoders\ThemeRelease\Core\Security\SecretVault;
use ProCoders\ThemeRelease\Core\Support\Environment;
use ProCoders\ThemeRelease\Core\Support\Keys;
use ProCoders\ThemeRelease\Domain\Release\StateStore;

/**
 * The plugin's health in three groups — configuration, the scheduled check, the repositories — shared by
 * the plugin screen, the admin notices and Site Health.
 */
final class Health
{
    /** Warn about the token's expiry when this many seconds or fewer are left (≤ 14 days, spec call 7). */
    public const int TOKEN_WARN_SECONDS = 14 * 86400;
    public const int CRON_STALE_AFTER = 86400;
    public const int CRON_OVERDUE_GRACE = 600;
    /** Repository errors that clear by themselves (builder's call): only recommended, not critical. */
    private const array TRANSIENT_ERRORS = [GitHubErrorKind::RateLimited, GitHubErrorKind::Server, GitHubErrorKind::Transport];
    /** Cron findings after which the plugin screen shows the server-cron hint. */
    private const array CRON_HINT_CODES = ['cron_disabled', 'cron_stale', 'cron_missing'];

    public function __construct(
        private readonly SiteContext $site,
        private readonly Secrets $secrets,
        private readonly SecretVault $vault,
        private readonly StateStore $state,
        private readonly Environment $env,
        private readonly Offers $offers,
    ) {
    }

    /** @return list<HealthItem> Channel, token, webhook secret, salts and GitHub rate limit. */
    public function config(): array
    {
        $items = $this->channelItems();
        $items[] = $this->tokenItem();
        if ($this->secrets->tokenIsLegacyFormat()) {
            $items[] = new HealthItem(HealthItem::RECOMMENDED, 'token_not_fine_grained', __('The GitHub token is not a fine-grained personal access token (github_pat_…). It keeps working, but replace it with a fine-grained token that can read only the theme repositories.', 'pc-theme-release'));
        }
        $expires = $this->state->int(Keys::TOKEN_EXPIRES_AT);
        if ($expires > 0 && $expires - $this->env->now() <= self::TOKEN_WARN_SECONDS) {
            /* translators: %s: expiry date. */
            $items[] = new HealthItem(HealthItem::RECOMMENDED, 'token_expiring', sprintf(__('The GitHub token expires on %s. Create a new one and replace it.', 'pc-theme-release'), Environment::date('Y-m-d H:i', $expires)));
        }
        $secret = $this->secrets->source(SecretKind::WebhookSecret);
        if (SecretSource::InvalidConstant === $secret) {
            $items[] = new HealthItem(HealthItem::CRITICAL, 'webhook_secret_constant_invalid', __('PC_THEME_RELEASE_WEBHOOK_SECRET in wp-config.php is too short (minimum 16 characters), contains control characters or is the documentation example. Webhook deliveries are refused; generate one with: openssl rand -hex 32', 'pc-theme-release'));
        } elseif (! $secret->isUsable()) {
            $items[] = new HealthItem(HealthItem::RECOMMENDED, 'webhook_secret_missing', __('No webhook secret is set: updates are found only by the 12-hour check and Check now.', 'pc-theme-release'));
        }
        if (! $this->vault->saltsAreStrong()) {
            $items[] = new HealthItem(HealthItem::RECOMMENDED, 'weak_salts', __('AUTH_KEY / SECURE_AUTH_KEY in wp-config.php are missing or weak, so secrets cannot be stored encrypted. Use the wp-config constants.', 'pc-theme-release'));
        }
        $limited = $this->state->int(Keys::RATE_LIMITED_UNTIL);
        if ($limited > $this->env->now()) {
            /* translators: %s: time. */
            $items[] = new HealthItem(HealthItem::RECOMMENDED, 'rate_limited', sprintf(__('GitHub rate limit reached; next request after %s.', 'pc-theme-release'), Environment::date('H:i', $limited)));
        }

        return $items;
    }

    /** @return list<HealthItem> Is WP-Cron there and running? (spec Goal 7). Loopback reachability is core's own test. */
    public function cron(): array
    {
        $now = $this->env->now();
        $items = [];
        if ($this->env->isTrue('DISABLE_WP_CRON')) {
            $items[] = new HealthItem(HealthItem::RECOMMENDED, 'cron_disabled', __('DISABLE_WP_CRON is set: WP-Cron runs only if a server cron calls wp-cron.php.', 'pc-theme-release'));
        }
        if ($this->env->isTrue('ALTERNATE_WP_CRON')) {
            $items[] = new HealthItem(HealthItem::RECOMMENDED, 'cron_alternate', __('ALTERNATE_WP_CRON is set: cron runs through redirects and may be unreliable.', 'pc-theme-release'));
        }
        if (false === wp_next_scheduled(Keys::CRON_HOOK)) {
            $items[] = new HealthItem(HealthItem::CRITICAL, 'cron_missing', __('The 12-hour check is not scheduled. The plugin schedules it again the next time an administrator opens the dashboard; if this message stays, something on this site removes WP-Cron events.', 'pc-theme-release'));
        }
        $last = $this->state->int(Keys::LAST_CRON_AT);
        if ($last > 0 && $now - $last > self::CRON_STALE_AFTER) {
            $items[] = new HealthItem(HealthItem::RECOMMENDED, 'cron_stale', __('The scheduled check has not run for more than 24 hours. WP-Cron may be broken; webhook and Check now still work.', 'pc-theme-release'));
        }
        $overdue = self::overdueCronEvents($now);
        if ($overdue > 0) {
            /* translators: %d: number of overdue cron events. */
            $items[] = new HealthItem(HealthItem::RECOMMENDED, 'cron_overdue', sprintf(__('%d cron events are overdue by more than 10 minutes.', 'pc-theme-release'), $overdue));
        }
        if ([] === $items) {
            $items[] = new HealthItem(HealthItem::GOOD, 'cron_ok', __('WP-Cron is scheduled and running.', 'pc-theme-release'));
        }

        return $items;
    }

    /** @return list<HealthItem> The last check of every mapped repository (same text as the Status column). */
    public function repos(): array
    {
        $items = [];
        foreach ($this->offers->all() as $status) {
            $outOfChannel = $status->outOfChannelText();
            if (null !== $outOfChannel) {
                // Not an error: the admin has an action to take (install the channel tag, or unfreeze first).
                $items[] = new HealthItem(HealthItem::RECOMMENDED, 'repo_other_channel', $status->mapping->stylesheet . ': ' . $outOfChannel);
            }
            $unresolved = $status->unresolved();
            if (null !== $unresolved) {
                $items[] = new HealthItem(HealthItem::RECOMMENDED, 'repo_unresolved', $status->mapping->stylesheet . ': ' . $unresolved);
            }
            $problem = $status->problem();
            if (null !== $problem) {
                $items[] = new HealthItem(self::repoErrorStatus($status->state->error), 'repo_error', $status->mapping->stylesheet . ': ' . $problem);
            }
        }
        if ([] === $items) {
            $items[] = new HealthItem(HealthItem::GOOD, 'repos_ok', __('The last GitHub check of every managed theme succeeded (or none was checked yet).', 'pc-theme-release'));
        }

        return $items;
    }

    /** @param list<HealthItem> $items The plugin screen shows the server-cron hint after these findings. */
    public static function needsServerCronHint(array $items): bool
    {
        foreach ($items as $item) {
            if ($item->isProblem() && in_array($item->code, self::CRON_HINT_CODES, true)) {
                return true;
            }
        }

        return false;
    }

    public static function serverCronHint(): string
    {
        /* translators: %s: the site's wp-cron.php URL. */
        return sprintf(__('Server cron (every 15 minutes): wget -q -O - "%s" >/dev/null 2>&1 — or with WP-CLI: wp cron event run --due-now', 'pc-theme-release'), esc_url_raw(site_url('wp-cron.php')) . '?doing_wp_cron');
    }

    /** @return list<HealthItem> */
    private function channelItems(): array
    {
        $items = [];
        if ($this->site->settings()->invalidChannelConstant) {
            $items[] = new HealthItem(HealthItem::CRITICAL, 'channel_constant_invalid', __('PC_THEME_RELEASE_CHANNEL must be "staging" or "production".', 'pc-theme-release'));
        }
        if ($this->site->hostChanged()) {
            $items[] = new HealthItem(HealthItem::CRITICAL, 'host_changed', __('The site address changed since the channel was saved. Confirm the channel for this site.', 'pc-theme-release'));
        } elseif (null === $this->site->channel()) {
            $items[] = new HealthItem(HealthItem::CRITICAL, 'channel_missing', __('Choose the channel of this site. Nothing is checked until then.', 'pc-theme-release'));
        }

        return $items;
    }

    private function tokenItem(): HealthItem
    {
        return match ($this->secrets->source(SecretKind::Token)) {
            SecretSource::None => new HealthItem(HealthItem::CRITICAL, 'token_missing', __('No GitHub token is set.', 'pc-theme-release')),
            SecretSource::InvalidConstant => new HealthItem(HealthItem::CRITICAL, 'token_constant_invalid', __('PC_THEME_RELEASE_GITHUB_TOKEN in wp-config.php is not a valid token (20–255 letters, digits, "_"). Nothing is checked until it is fixed.', 'pc-theme-release')),
            SecretSource::Undecryptable => new HealthItem(HealthItem::CRITICAL, 'token_undecryptable', __('The stored GitHub token cannot be decrypted (the salts in wp-config.php changed). Enter it again.', 'pc-theme-release')),
            default => new HealthItem(HealthItem::GOOD, 'token_ok', __('A GitHub token is set.', 'pc-theme-release')),
        };
    }

    /** An unconfirmed branch (no error code) clears at the next check, like the transient errors. */
    private static function repoErrorStatus(?string $code): string
    {
        $transient = null === $code || in_array(GitHubErrorKind::tryFrom($code), self::TRANSIENT_ERRORS, true);

        return $transient ? HealthItem::RECOMMENDED : HealthItem::CRITICAL;
    }

    /** Events of any plugin whose time passed more than CRON_OVERDUE_GRACE seconds ago. */
    private static function overdueCronEvents(int $now): int
    {
        $count = 0;
        foreach (_get_cron_array() as $timestamp => $hooks) {
            if ((int) $timestamp < $now - self::CRON_OVERDUE_GRACE) {
                $count += count($hooks);
            }
        }

        return $count;
    }
}
