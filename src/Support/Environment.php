<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Support;

/**
 * Read-only view of wp-config constants and the site host, so policies can be tested without globals.
 */
interface Environment
{
    /** Value of a defined constant as string, or null when it is not defined. */
    public function constant(string $name): ?string;

    public function isTrue(string $name): bool;

    /** Host of home_url(), as WordPress reports it. */
    public function siteHost(): string;
}
