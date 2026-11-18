<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Config;

use ProCoders\ThemeRelease\Admin\View;
use ProCoders\ThemeRelease\Update\UpdateOffer;

/**
 * On a multisite network the plugin works on the main site only (audit S1): on any other site it offers
 * nothing, handles no package and only says so. The update transient is network-wide.
 */
final class SiteScope
{
    public static function inert(): bool
    {
        return is_multisite() && ! is_main_site();
    }

    /** `admin_notices` on an inert site. */
    public static function renderNotice(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        View::notice('warning', __('ProCoders Theme Release runs on the main site of this network only. It does nothing on this site.', 'pc-theme-release'), false);
    }

    /**
     * Filter `upgrader_pre_download` on an inert site: a package of this plugin is never downloaded here,
     * not even by core without the token (fail closed, audit S3).
     */
    /** @param array<string, mixed> $hookExtra */
    public static function refuseOwnPackage(mixed $reply, mixed $package, mixed $upgrader = null, array $hookExtra = []): mixed
    {
        if (false !== $reply || ! is_string($package) || 1 !== preg_match(UpdateOffer::PACKAGE_PATTERN, $package)) {
            return $reply;
        }
        // The plugin offers themes only: another updater's plugin zipball is not ours (plan O3).
        if (! isset($hookExtra['theme']) && isset($hookExtra['plugin'])) {
            return $reply;
        }

        return new \WP_Error('pc_theme_release_main_site_only', __('ProCoders Theme Release installs themes on the main site of the network only. Nothing was installed.', 'pc-theme-release'));
    }
}
