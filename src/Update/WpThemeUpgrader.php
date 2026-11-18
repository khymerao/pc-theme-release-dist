<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Update;

/**
 * Core's Theme_Upgrader with its standard skin, so output, maintenance mode and the temp-backup
 * restore are exactly what Dashboard → Updates does.
 */
final class WpThemeUpgrader implements ThemeUpgrader
{
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
}
