<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Support;

/**
 * Persistent key/value storage (WordPress options). Values are never autoloaded unless asked.
 */
interface OptionStore
{
    public function get(string $key, mixed $default = null): mixed;

    /** Like get(), but read from the database, not from this request's cache (another request may have written it). */
    public function fresh(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value, bool $autoload = false): void;

    public function delete(string $key): void;
}
