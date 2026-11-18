<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Admin;

use ProCoders\ThemeRelease\Support\Keys;

/**
 * The tabs of the plugin screen. The active tab lives only in the `tab` query arg; anything else opens Themes.
 */
enum Tab: string
{
    case Themes = 'themes';
    case Channel = 'channel';
    case GitHub = 'github';
    case Health = 'health';

    public function label(): string
    {
        return match ($this) {
            self::Themes => __('Themes', 'pc-theme-release'),
            self::Channel => __('Channel', 'pc-theme-release'),
            self::GitHub => __('GitHub', 'pc-theme-release'),
            default => __('Health & log', 'pc-theme-release'),
        };
    }

    /** Missing, empty or unknown value: the default tab. */
    public static function fromQuery(string $value): self
    {
        return self::tryFrom($value) ?? self::Themes;
    }

    /**
     * The one place that builds the screen URL: page and tab first, then the given extra args.
     *
     * @param array<string, string> $extra
     */
    public function url(array $extra = []): string
    {
        return add_query_arg(['page' => Keys::PAGE_SLUG, 'tab' => $this->value] + $extra, admin_url('themes.php'));
    }
}
