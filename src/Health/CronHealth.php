<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Health;

use ProCoders\ThemeRelease\Release\StateKey;
use ProCoders\ThemeRelease\Release\StateStore;
use ProCoders\ThemeRelease\Support\Clock;
use ProCoders\ThemeRelease\Support\Environment;
use ProCoders\ThemeRelease\Support\Keys;

/**
 * Is WP-Cron there and running? (spec Goal 7). Loopback reachability is core's own Site Health test.
 */
final class CronHealth
{
    public const int STALE_AFTER = 86400;
    public const int OVERDUE_GRACE = 600;

    public function __construct(
        private readonly Environment $environment,
        private readonly StateStore $state,
        private readonly CronInspector $cron,
        private readonly Clock $clock,
    ) {
    }

    /** @return list<HealthItem> */
    public function items(): array
    {
        $now = $this->clock->now();
        $items = [];
        if ($this->environment->isTrue('DISABLE_WP_CRON')) {
            $items[] = new HealthItem(HealthItem::RECOMMENDED, 'cron_disabled', __('DISABLE_WP_CRON is set: WP-Cron runs only if a server cron calls wp-cron.php.', 'pc-theme-release'));
        }
        if ($this->environment->isTrue('ALTERNATE_WP_CRON')) {
            $items[] = new HealthItem(HealthItem::RECOMMENDED, 'cron_alternate', __('ALTERNATE_WP_CRON is set: cron runs through redirects and may be unreliable.', 'pc-theme-release'));
        }
        if (null === $this->cron->nextScheduled(Keys::CRON_HOOK)) {
            $items[] = new HealthItem(HealthItem::CRITICAL, 'cron_missing', __('The 12-hour check is not scheduled. The plugin schedules it again on the next page load; if this message stays, something on this site removes WP-Cron events.', 'pc-theme-release'));
        }
        $last = $this->state->int(StateKey::LAST_CRON_AT);
        if ($last > 0 && $now - $last > self::STALE_AFTER) {
            $items[] = new HealthItem(HealthItem::RECOMMENDED, 'cron_stale', __('The scheduled check has not run for more than 24 hours. WP-Cron may be broken; webhook and Check now still work.', 'pc-theme-release'));
        }
        $overdue = $this->cron->overdueCount($now, self::OVERDUE_GRACE);
        if ($overdue > 0) {
            /* translators: %d: number of overdue cron events. */
            $items[] = new HealthItem(HealthItem::RECOMMENDED, 'cron_overdue', sprintf(__('%d cron events are overdue by more than 10 minutes.', 'pc-theme-release'), $overdue));
        }
        if ([] === $items) {
            $items[] = new HealthItem(HealthItem::GOOD, 'cron_ok', __('WP-Cron is scheduled and running.', 'pc-theme-release'));
        }

        return $items;
    }

    public static function serverCronHint(): string
    {
        /* translators: %s: the site's wp-cron.php URL. */
        return sprintf(__('Server cron (every 15 minutes): wget -q -O - "%s" >/dev/null 2>&1 — or with WP-CLI: wp cron event run --due-now', 'pc-theme-release'), esc_url_raw(site_url('wp-cron.php')) . '?doing_wp_cron');
    }
}
