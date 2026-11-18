<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Update;

use ProCoders\ThemeRelease\Support\Keys;

/**
 * Core's option-based upgrader lock; theme upgrades take none themselves (WP 6.4 class-wp-upgrader.php:1007).
 */
final class WpUpgraderLock implements Lock
{
    private const int TIMEOUT = 600;

    public function acquire(): bool
    {
        $this->load();

        return \WP_Upgrader::create_lock(Keys::LOCK, self::TIMEOUT);
    }

    public function release(): void
    {
        $this->load();
        \WP_Upgrader::release_lock(Keys::LOCK);
    }

    private function load(): void
    {
        if (! class_exists('WP_Upgrader', false)) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }
    }
}
