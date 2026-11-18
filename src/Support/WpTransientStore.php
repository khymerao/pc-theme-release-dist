<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Support;

final class WpTransientStore implements TransientStore
{
    public function get(string $key): mixed
    {
        $value = get_transient($key);

        return false === $value ? null : $value;
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        set_transient($key, $value, $ttlSeconds);
    }

    public function delete(string $key): void
    {
        delete_transient($key);
    }
}
