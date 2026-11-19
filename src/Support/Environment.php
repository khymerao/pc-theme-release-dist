<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Support;

/**
 * The small WordPress facade: wp-config constants, the site host, the current time and site-timezone
 * dates. The calls to time(), defined() and constant() stay unqualified: the tests resolve them to
 * namespaced stand-ins (tests/Support/overrides.php).
 */
final class Environment
{
    /** Value of a defined constant as string, or null when it is not defined. */
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

    /** Host of home_url(), as WordPress reports it. */
    public function siteHost(): string
    {
        $host = wp_parse_url(home_url(), PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    /** Current time as a UTC Unix timestamp. */
    public function now(): int
    {
        return time();
    }

    /** A site-timezone date for display; empty when WordPress cannot format it. */
    public static function date(string $format, int $timestamp): string
    {
        $formatted = wp_date($format, $timestamp);

        return is_string($formatted) ? $formatted : '';
    }
}
