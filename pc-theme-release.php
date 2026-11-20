<?php

/**
 * Plugin Name:       ProCoders Theme Release
 * Description:       Offers channel tags of private GitHub theme repositories as standard WordPress theme updates (staging or production), with webhook, rollback and freeze.
 * Version:           1.5.1
 * Requires at least: 6.4
 * Requires PHP:      8.3
 * Author:            ProCoders
 * License:           GPL-2.0-or-later
 * Text Domain:       pc-theme-release
 * Update URI:        false
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * `admin_notices` when vendor/autoload.php is missing (broken upload): explains, never fatals.
 */
function pc_theme_release_missing_autoload_notice(): void
{
    if (! current_user_can('manage_options')) {
        return;
    }
    echo '<div class="notice notice-error"><p>'
        . esc_html__('ProCoders Theme Release is incomplete: vendor/autoload.php is missing. Upload the release ZIP again.', 'pc-theme-release')
        . '</p></div>';
}

if (! is_readable(__DIR__ . '/vendor/autoload.php')) {
    add_action('admin_notices', 'pc_theme_release_missing_autoload_notice');
    return;
}

require_once __DIR__ . '/vendor/autoload.php';

register_activation_hook(__FILE__, [\ProCoders\ThemeRelease\Modules\Lifecycle\Module::class, 'onActivate']);
register_deactivation_hook(__FILE__, [\ProCoders\ThemeRelease\Modules\Lifecycle\Module::class, 'onDeactivate']);
add_action('plugins_loaded', [\ProCoders\ThemeRelease\Plugin::class, 'boot']);
