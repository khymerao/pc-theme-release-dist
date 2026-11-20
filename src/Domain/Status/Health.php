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
use ProCoders\ThemeRelease\Core\GitHub\RepoName;
use ProCoders\ThemeRelease\Domain\Release\DeliveryStatus;
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

    /**
     * @return list<HealthItem> What the last check read about the webhook deliveries to this site, one item per
     *         repository that has a result, as shown now (spec calls 2, 12, 13). Nothing without a webhook secret.
     */
    public function webhook(): array
    {
        $items = [];
        foreach ($this->deliveries() as [$repo, $status]) {
            $items[] = new HealthItem(self::deliveryLevel($status), 'webhook_' . $status->outcome, $this->deliveryLabel($repo, $status));
        }

        return $items;
    }

    /**
     * @return list<array{label: string, link: string}> Every repository whose deliveries fail now (the red notice):
     *         its text and the settings page of its webhook on GitHub, built from the repository and the hook id.
     */
    public function deliveryAlerts(): array
    {
        $alerts = [];
        foreach ($this->deliveries() as [$repo, $status]) {
            if ($status->isAlert()) {
                $alerts[] = ['label' => $this->deliveryLabel($repo, $status), 'link' => self::hooksPage($repo, $status->hookId)];
            }
        }

        return $alerts;
    }

    /**
     * The repository webhook settings page on GitHub, or one hook settings page when $hookId > 0; built from the
     * mapped name and an integer id only, never from an API URL.
     */
    public static function hooksPage(RepoName $repo, int $hookId = 0): string
    {
        return 'https://github.com/' . rawurlencode($repo->owner) . '/' . rawurlencode($repo->name) . '/settings/hooks' . ($hookId > 0 ? '/' . $hookId : '');
    }

    /** The settings page of this site stored hook of $repo (exact match only), else the repository webhooks page. */
    public function hookLink(RepoName $repo): string
    {
        $stored = $this->state->delivery($repo);

        return self::hooksPage($repo, null !== $stored && $stored->knowsHook() ? $stored->hookId : 0);
    }

    /** @return list<array{0: RepoName, 1: DeliveryStatus}> Effective results of the mapped repositories. */
    private function deliveries(): array
    {
        if (! $this->site->webhookInUse()) {
            return [];
        }
        $now = $this->env->now();
        $results = [];
        foreach ($this->offers->repos() as $repo) {
            $stored = $this->state->delivery($repo);
            if (null !== $stored) {
                $results[] = [$repo, $stored->effective($this->state->received($repo), $now)];
            }
        }

        return $results;
    }

    private static function deliveryLevel(DeliveryStatus $status): string
    {
        return match ($status->outcome) {
            DeliveryStatus::FAILING, DeliveryStatus::MISCONFIGURED => HealthItem::CRITICAL,
            DeliveryStatus::NO_HOOK, DeliveryStatus::NO_PERMISSION, DeliveryStatus::UNKNOWN => HealthItem::RECOMMENDED,
            default => HealthItem::GOOD,
        };
    }

    private function deliveryLabel(RepoName $repo, DeliveryStatus $status): string
    {
        $name = $repo->full();
        if ('' !== $status->viaHost) {
            /* translators: 1: owner/repo, 2: host name of the webhook URL. */
            $name = sprintf(__('%1$s (webhook for %2$s)', 'pc-theme-release'), $name, $status->viaHost);
        }
        $text = match ($status->outcome) {
            /* translators: 1: owner/repo, 2: what GitHub reported. */
            DeliveryStatus::FAILING => sprintf(__('%1$s: GitHub could not deliver the webhook to this site: %2$s.', 'pc-theme-release'), $name, self::failure($status)),
            /* translators: 1: owner/repo, 2: what is wrong. */
            DeliveryStatus::MISCONFIGURED => sprintf(__('%1$s: the webhook on GitHub is misconfigured: %2$s.', 'pc-theme-release'), $name, self::misconfiguration($status)),
            /* translators: 1: owner/repo, 2: the webhook URL of this site. */
            DeliveryStatus::NO_HOOK => sprintf(__('%1$s: no webhook on GitHub points at this site (%2$s). Updates arrive only with the 12-hour check and Check now.', 'pc-theme-release'), $name, rest_url(Keys::REST_NAMESPACE . '/webhook')),
            /* translators: %s: owner/repo. */
            DeliveryStatus::NO_PERMISSION => sprintf(__('%s: the token cannot read the webhooks of this repository, so failed deliveries are not shown. Give the token Webhooks: Read-only; the token owner needs admin access to the repository.', 'pc-theme-release'), $name),
            /* translators: 1: owner/repo, 2: date and time. */
            DeliveryStatus::UNKNOWN => sprintf(__('%1$s: webhook delivery status unknown: last read from GitHub on %2$s, more than 48 hours ago.', 'pc-theme-release'), $name, Environment::date('Y-m-d H:i', $status->checkedAt)),
            /* translators: %s: owner/repo. */
            DeliveryStatus::NO_DELIVERIES => sprintf(__('%s: GitHub lists no webhook delivery to this site in the last 3 days.', 'pc-theme-release'), $name),
            /* translators: 1: owner/repo, 2: date and time. */
            default => sprintf(__('%1$s: the last webhook delivery to this site succeeded (%2$s).', 'pc-theme-release'), $name, Environment::date('Y-m-d H:i', $status->lastDeliveryAt)),
        };
        if (DeliveryStatus::UNKNOWN === $status->outcome) {
            return $text;
        }
        if (! $status->isAlert()) {
            /* translators: 1: the finding, 2: date and time. */
            return sprintf(__('%1$s Checked: %2$s.', 'pc-theme-release'), $text, Environment::date('Y-m-d H:i', $status->checkedAt));
        }
        $lastOk = $status->lastOkAt > 0 ? Environment::date('Y-m-d H:i', $status->lastOkAt) : __('none among the last 10 deliveries', 'pc-theme-release');

        /* translators: 1: the finding, 2: date and time or "none", 3: date and time. */
        return sprintf(__('%1$s Last successful delivery: %2$s. Checked: %3$s.', 'pc-theme-release'), $text, $lastOk, Environment::date('Y-m-d H:i', $status->checkedAt));
    }

    /** GitHub own words for the failed delivery, with its HTTP status code. */
    private static function failure(DeliveryStatus $status): string
    {
        if (in_array(DeliveryStatus::INACTIVE, $status->problems, true)) {
            return __('the webhook is disabled (inactive) on GitHub', 'pc-theme-release');
        }
        /* translators: %d: HTTP status code. */
        $code = $status->statusCode > 0 ? sprintf(__('HTTP %d', 'pc-theme-release'), $status->statusCode) : __('no HTTP response', 'pc-theme-release');

        return '' === $status->reason ? $code : $status->reason . ' (' . $code . ')';
    }

    private static function misconfiguration(DeliveryStatus $status): string
    {
        $parts = [];
        if (in_array(DeliveryStatus::NO_TAG_EVENTS, $status->problems, true)) {
            $parts[] = __('it sends neither "Branch or tag creation" nor "Pushes" events', 'pc-theme-release');
        }
        if (in_array(DeliveryStatus::NOT_JSON, $status->problems, true)) {
            $parts[] = __('its content type is not application/json', 'pc-theme-release');
        }

        return implode('; ', $parts);
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
