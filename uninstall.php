<?php

/**
 * Removes the plugin's options, transients and cron event. Theme files are never touched.
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

if (is_readable(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    \ProCoders\ThemeRelease\Lifecycle\Lifecycle::wordpress()->uninstall();
}
