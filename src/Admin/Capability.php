<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Admin;

/**
 * The one capability rule for the plugin's screen and every plugin action: the configuration feeds core's
 * theme-update flow, so it needs `manage_options` and `update_themes` (audit S1). Where file modifications
 * are disallowed (DISALLOW_FILE_MODS) core grants `update_themes` to nobody and nothing can be installed,
 * so `manage_options` alone keeps the settings usable there (parent spec); installs stay disabled.
 */
final class Capability
{
    public static function canManage(): bool
    {
        return current_user_can('manage_options')
            && (current_user_can('update_themes') || ! wp_is_file_mod_allowed('pc_theme_release_install'));
    }
}
