<?php

/**
 * Plugin Name:       ProCoders Theme Release
 * Description:       Offers channel tags of private GitHub theme repositories as standard WordPress theme updates (staging or production), with webhook, rollback and freeze.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.3
 * Author:            ProCoders
 * License:           GPL-2.0-or-later
 * Text Domain:       pc-theme-release
 * Update URI:        false
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

if (! is_readable(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/src/Lifecycle/MissingAutoloadNotice.php';
    add_action('admin_notices', [\ProCoders\ThemeRelease\Lifecycle\MissingAutoloadNotice::class, 'render']);
    return;
}

require_once __DIR__ . '/vendor/autoload.php';

register_activation_hook(__FILE__, [\ProCoders\ThemeRelease\Lifecycle\Lifecycle::class, 'onActivate']);
register_deactivation_hook(__FILE__, [\ProCoders\ThemeRelease\Lifecycle\Lifecycle::class, 'onDeactivate']);
add_action('plugins_loaded', [\ProCoders\ThemeRelease\Plugin::class, 'boot']);
