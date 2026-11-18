<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Lifecycle;

interface TransientPurger
{
    /** Deletes every transient whose name starts with $prefix. */
    public function purge(string $prefix): void;
}
