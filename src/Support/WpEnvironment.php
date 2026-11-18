<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Support;

final class WpEnvironment implements Environment
{
    public function constant(string $name): ?string
    {
        if (! defined($name)) {
            return null;
        }
        $value = constant($name);

        return is_scalar($value) ? (string) $value : null;
    }

    public function isTrue(string $name): bool
    {
        return defined($name) && true === (bool) constant($name);
    }

    public function siteHost(): string
    {
        $host = wp_parse_url(home_url(), PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }
}
