<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Support;

final class WpOptionStore implements OptionStore
{
    public function get(string $key, mixed $default = null): mixed
    {
        return get_option($key, $default);
    }

    public function fresh(string $key, mixed $default = null): mixed
    {
        // Non-autoloaded options are cached per request under their own key; a missing one in "notoptions".
        wp_cache_delete($key, 'options');
        wp_cache_delete('notoptions', 'options');

        return get_option($key, $default);
    }

    public function set(string $key, mixed $value, bool $autoload = false): void
    {
        // add_option() is the only way to set autoload=no on first write in WordPress 6.4.
        if (false === get_option($key, false)) {
            add_option($key, $value, '', $autoload);
            return;
        }
        update_option($key, $value, $autoload);
    }

    public function delete(string $key): void
    {
        delete_option($key);
    }
}
