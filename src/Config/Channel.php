<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Config;

/**
 * The release channel of a site. Staging takes prerelease tags, production takes plain tags.
 */
enum Channel: string
{
    case Staging = 'staging';
    case Production = 'production';

    public static function fromValue(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }

    public function label(): string
    {
        return match ($this) {
            self::Staging => __('Staging', 'pc-theme-release'),
            self::Production => __('Production', 'pc-theme-release'),
        };
    }

    /** Lower-case name for use inside a sentence ("the staging channel"). */
    public function name(): string
    {
        return match ($this) {
            self::Staging => __('staging', 'pc-theme-release'),
            self::Production => __('production', 'pc-theme-release'),
        };
    }

    public function other(): self
    {
        return match ($this) {
            self::Staging => self::Production,
            self::Production => self::Staging,
        };
    }
}
