<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Support;

interface Clock
{
    /** Current time as a UTC Unix timestamp. */
    public function now(): int;
}
