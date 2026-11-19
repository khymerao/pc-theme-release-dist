<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Core\Support;

/**
 * The plugin's WordPress options and its own transients. Options are never autoloaded unless asked.
 */
final class Storage
{
    public function get(string $key, mixed $default = null): mixed
    {
        return get_option($key, $default);
    }

    /** @return array<string, mixed> The option as an array; anything else reads as empty. */
    public function getArray(string $key): array
    {
        $value = get_option($key, []);

        return is_array($value) ? $value : [];
    }

    /**
     * Like getArray(), but read from the database, not from this request's cache (another request may
     * have written it meanwhile).
     *
     * @return array<string, mixed>
     */
    public function freshArray(string $key): array
    {
        // Non-autoloaded options are cached per request under their own key; a missing one in "notoptions".
        wp_cache_delete($key, 'options');
        wp_cache_delete('notoptions', 'options');
        $value = get_option($key, []);

        return is_array($value) ? $value : [];
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

    /** Null when the entry is missing or expired. */
    public function transient(string $key): mixed
    {
        $value = get_transient($key);

        return false === $value ? null : $value;
    }

    public function setTransient(string $key, mixed $value, int $ttlSeconds): void
    {
        set_transient($key, $value, $ttlSeconds);
    }

    /**
     * Deletes the transients whose name starts with $prefix. With a persistent object cache they are not
     * enumerable; they expire by themselves (≤ 24 h).
     */
    public function purgeTransients(string $prefix): void
    {
        global $wpdb;
        if (! $wpdb instanceof \wpdb) {
            return;
        }
        foreach (['_transient_', '_transient_timeout_'] as $kind) {
            $names = $wpdb->get_col(
                $wpdb->prepare(
                    'SELECT option_name FROM %i WHERE option_name LIKE %s',
                    $wpdb->options,
                    $wpdb->esc_like($kind . $prefix) . '%'
                )
            );
            foreach ($names as $name) {
                if ('_transient_' === $kind) {
                    delete_transient(substr((string) $name, strlen('_transient_')));
                    continue;
                }
                delete_option((string) $name);
            }
        }
    }
}
