<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Lifecycle;

/**
 * Loaded without Composer when vendor/autoload.php is missing (broken upload): explains, never fatals.
 */
final class MissingAutoloadNotice
{
    public static function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        echo '<div class="notice notice-error"><p>'
            . esc_html__('ProCoders Theme Release is incomplete: vendor/autoload.php is missing. Upload the release ZIP again.', 'pc-theme-release')
            . '</p></div>';
    }
}
