<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Modules\Update;

use ProCoders\ThemeRelease\Core\Support\Keys;

/**
 * Core's Theme_Upgrader with its standard skin (so output, maintenance mode and the temp-backup restore
 * are exactly what Dashboard → Updates does), core's option-based upgrader lock (theme upgrades take
 * none themselves, WP 6.4 class-wp-upgrader.php:1007) and WP_Filesystem.
 */
final class WpCoreUpgrader implements CoreUpgrader
{
    private const int LOCK_TIMEOUT = 600;

    public function upgrade(string $stylesheet): bool|\WP_Error
    {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/theme.php';

        $skin = new \Theme_Upgrader_Skin([
            'theme' => $stylesheet,
            'title' => __('Rolling back theme', 'pc-theme-release'),
        ]);
        $result = (new \Theme_Upgrader($skin))->upgrade($stylesheet);

        return $result instanceof \WP_Error ? $result : true === $result;
    }

    public function acquireLock(): bool
    {
        self::loadUpgrader();

        return \WP_Upgrader::create_lock(Keys::LOCK, self::LOCK_TIMEOUT);
    }

    public function releaseLock(): void
    {
        self::loadUpgrader();
        \WP_Upgrader::release_lock(Keys::LOCK);
    }

    public function children(string $dir): array
    {
        $list = self::fs()?->dirlist($dir);

        return is_array($list) ? array_map('strval', array_keys($list)) : [];
    }

    public function isDir(string $path): bool
    {
        return true === self::fs()?->is_dir($path);
    }

    public function isFile(string $path): bool
    {
        return true === self::fs()?->is_file($path);
    }

    public function themeHeaders(string $styleCss): array
    {
        $headers = get_file_data($styleCss, ['name' => 'Theme Name', 'text_domain' => 'Text Domain', 'version' => 'Version']);

        return [
            'name' => (string) ($headers['name'] ?? ''),
            'text_domain' => (string) ($headers['text_domain'] ?? ''),
            'version' => (string) ($headers['version'] ?? ''),
        ];
    }

    public function move(string $from, string $to): bool
    {
        return true === self::fs()?->move($from, $to, true);
    }

    private static function loadUpgrader(): void
    {
        if (! class_exists('WP_Upgrader', false)) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }
    }

    private static function fs(): ?\WP_Filesystem_Base
    {
        global $wp_filesystem;

        return $wp_filesystem instanceof \WP_Filesystem_Base ? $wp_filesystem : null;
    }
}
