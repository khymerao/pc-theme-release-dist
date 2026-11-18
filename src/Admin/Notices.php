<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Admin;

use ProCoders\ThemeRelease\Health\HealthItem;
use ProCoders\ThemeRelease\Health\HealthReport;

/**
 * Site-wide admin notices for the problems that need the admin's action. Administrators only;
 * not on the plugin screen itself (it shows them inline).
 */
final class Notices
{
    private const array SHOWN = ['host_changed', 'token_undecryptable', 'token_expiring', 'channel_constant_invalid', 'token_constant_invalid', 'webhook_secret_constant_invalid'];

    public function __construct(private readonly HealthReport $report)
    {
    }

    /** The tab where the admin fixes the item. */
    public static function tabFor(string $code): Tab
    {
        return match ($code) {
            'token_undecryptable', 'token_expiring', 'token_constant_invalid', 'webhook_secret_constant_invalid' => Tab::GitHub,
            'host_changed', 'channel_constant_invalid' => Tab::Channel,
            default => Tab::Themes,
        };
    }

    public function render(): void
    {
        if (! Capability::canManage()) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (null !== $screen && str_ends_with((string) $screen->id, AdminPage::SLUG)) {
            return;
        }
        foreach ($this->report->items() as $item) {
            if (! in_array($item->code, self::SHOWN, true)) {
                continue;
            }
            $url = self::tabFor($item->code)->url();
            printf(
                '<div class="notice notice-%1$s"><p><strong>%2$s</strong> %3$s <a href="%4$s">%5$s</a></p></div>',
                esc_attr(HealthItem::CRITICAL === $item->status ? 'error' : 'warning'),
                esc_html__('Theme Release:', 'pc-theme-release'),
                esc_html($item->label),
                esc_url($url),
                esc_html__('Open settings', 'pc-theme-release')
            );
        }
    }
}
