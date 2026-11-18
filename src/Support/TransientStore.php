<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Support;

/**
 * Expiring storage for the plugin's own cache entries (WordPress transients).
 */
interface TransientStore
{
    /** Returns null when the entry is missing or expired. */
    public function get(string $key): mixed;

    public function set(string $key, mixed $value, int $ttlSeconds): void;

    public function delete(string $key): void;
}
