<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Health;

use ProCoders\ThemeRelease\Config\SiteContext;
use ProCoders\ThemeRelease\GitHub\TokenExpiry;
use ProCoders\ThemeRelease\Release\StateKey;
use ProCoders\ThemeRelease\Release\StateStore;
use ProCoders\ThemeRelease\Security\SaltPolicy;
use ProCoders\ThemeRelease\Security\SecretKind;
use ProCoders\ThemeRelease\Security\Secrets;
use ProCoders\ThemeRelease\Security\SecretSource;
use ProCoders\ThemeRelease\Support\Clock;

/**
 * Configuration health, shared by the plugin screen, admin notices and Site Health.
 */
final class HealthReport
{
    public function __construct(
        private readonly SiteContext $site,
        private readonly Secrets $secrets,
        private readonly SaltPolicy $salts,
        private readonly StateStore $state,
        private readonly Clock $clock,
    ) {
    }

    /** @return list<HealthItem> */
    public function items(): array
    {
        $items = [];
        $settings = $this->site->settings();
        if ($settings->invalidChannelConstant) {
            $items[] = new HealthItem(HealthItem::CRITICAL, 'channel_constant_invalid', __('PC_THEME_RELEASE_CHANNEL must be "staging" or "production".', 'pc-theme-release'));
        }
        if ($this->site->hostChanged()) {
            $items[] = new HealthItem(HealthItem::CRITICAL, 'host_changed', __('The site address changed since the channel was saved. Confirm the channel for this site.', 'pc-theme-release'));
        } elseif (null === $this->site->channel()) {
            $items[] = new HealthItem(HealthItem::CRITICAL, 'channel_missing', __('Choose the channel of this site. Nothing is checked until then.', 'pc-theme-release'));
        }

        $items[] = match ($this->secrets->source(SecretKind::Token)) {
            SecretSource::None => new HealthItem(HealthItem::CRITICAL, 'token_missing', __('No GitHub token is set.', 'pc-theme-release')),
            SecretSource::InvalidConstant => new HealthItem(HealthItem::CRITICAL, 'token_constant_invalid', __('PC_THEME_RELEASE_GITHUB_TOKEN in wp-config.php is not a valid token (20–255 letters, digits, "_"). Nothing is checked until it is fixed.', 'pc-theme-release')),
            SecretSource::Undecryptable => new HealthItem(HealthItem::CRITICAL, 'token_undecryptable', __('The stored GitHub token cannot be decrypted (the salts in wp-config.php changed). Enter it again.', 'pc-theme-release')),
            default => new HealthItem(HealthItem::GOOD, 'token_ok', __('A GitHub token is set.', 'pc-theme-release')),
        };
        $expires = $this->state->int(StateKey::TOKEN_EXPIRES_AT);
        if (TokenExpiry::shouldWarn($expires, $this->clock->now())) {
            /* translators: %s: expiry date. */
            $items[] = new HealthItem(HealthItem::RECOMMENDED, 'token_expiring', sprintf(__('The GitHub token expires on %s. Create a new one and replace it.', 'pc-theme-release'), wp_date('Y-m-d H:i', $expires)));
        }
        $secret = $this->secrets->source(SecretKind::WebhookSecret);
        if (SecretSource::InvalidConstant === $secret) {
            $items[] = new HealthItem(HealthItem::CRITICAL, 'webhook_secret_constant_invalid', __('PC_THEME_RELEASE_WEBHOOK_SECRET in wp-config.php is too short (minimum 16 characters), contains control characters or is the documentation example. Webhook deliveries are refused; generate one with: openssl rand -hex 32', 'pc-theme-release'));
        } elseif (! in_array($secret, [SecretSource::Constant, SecretSource::Database], true)) {
            $items[] = new HealthItem(HealthItem::RECOMMENDED, 'webhook_secret_missing', __('No webhook secret is set: updates are found only by the 12-hour check and Check now.', 'pc-theme-release'));
        }
        if (! $this->salts->isStrong()) {
            $items[] = new HealthItem(HealthItem::RECOMMENDED, 'weak_salts', __('AUTH_KEY / SECURE_AUTH_KEY in wp-config.php are missing or weak, so secrets cannot be stored encrypted. Use the wp-config constants.', 'pc-theme-release'));
        }
        $limited = $this->state->int(StateKey::RATE_LIMITED_UNTIL);
        if ($limited > $this->clock->now()) {
            /* translators: %s: time. */
            $items[] = new HealthItem(HealthItem::RECOMMENDED, 'rate_limited', sprintf(__('GitHub rate limit reached; next request after %s.', 'pc-theme-release'), wp_date('H:i', $limited)));
        }

        return $items;
    }
}
