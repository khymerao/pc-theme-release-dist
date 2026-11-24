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

    /**
     * @param \Closure(string): void|null $onRewritten Told the key (never the value) of an option that could not be
     *                                                  stored as meant, even after the repair write.
     */
    public function __construct(private readonly ?\Closure $onRewritten = null)
    {
    }

    /**
     * Writes the option, then reads it back from the database. A `pre_update_option` filter may have rewritten or
     * wiped it (Local's Live Link helper replaces the tunnel host and can break the serialization): then the row's
     * value is set to the intended bytes with one SQL UPDATE, which no option filter runs on. The row is never
     * deleted: a write that failed at the database (or a failed read) leaves the existing row as it is.
     */
    public function set(string $key, mixed $value, bool $autoload = false): void
    {
        // add_option() is the only way to set autoload=no on first write in WordPress 6.4.
        if (false === get_option($key, false)) {
            add_option($key, $value, '', $autoload);
        } else {
            update_option($key, $value, $autoload);
        }
        global $wpdb;
        if (! $wpdb instanceof \wpdb) {
            return;
        }
        $intended = self::bytes($value);
        if (! self::rewritten($wpdb, $key, $intended)) {
            return;
        }
        // Only the value column: the row, its autoload flag and its id stay.
        $wpdb->update($wpdb->options, ['option_value' => $intended], ['option_name' => $key]);
        // The caches hold the filtered value WordPress wrote.
        wp_cache_delete($key, 'options');
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete('alloptions', 'options');
        // The log is itself an option: a rewritten log entry must not log again.
        if (self::rewritten($wpdb, $key, $intended) && null !== $this->onRewritten && Keys::LOG !== $key) {
            ($this->onRewritten)($key);
        }
    }

    /**
     * True only when the row exists and holds other bytes than $intended (a filter changed the value). Read with SQL,
     * so neither the object cache nor an option read filter can hide it. A missing row or a read error (no row
     * returned) is not a rewrite: nothing is repaired. A row whose value reads as NULL (how some database drivers
     * return the empty string WordPress writes for a wiped value) counts as ''.
     *
     * @phpstan-impure Reads the database, which a write in between changes.
     */
    private static function rewritten(\wpdb $wpdb, string $key, string $intended): bool
    {
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, $key),
            ARRAY_N
        );
        if (! is_array($row)) {
            return false;
        }
        $stored = is_string($row[0] ?? null) ? $row[0] : '';

        return $intended !== $stored;
    }

    /** The bytes WordPress writes for $value: maybe_serialize(), and a scalar as the database stores it. */
    private static function bytes(mixed $value): string
    {
        $serialized = maybe_serialize($value);

        return is_scalar($serialized) ? (string) $serialized : '';
    }

    public function delete(string $key): void
    {
        delete_option($key);
    }

    /**
     * A network-wide option (the options table on a single site, sitemeta on a multisite network); never autoloaded.
     *
     * @return array<string, mixed> The option as an array; anything else reads as empty.
     */
    public function getSiteArray(string $key): array
    {
        $value = get_site_option($key, []);

        return is_array($value) ? $value : [];
    }

    /**
     * On a single site update_site_option() is update_option() with the default autoload, so the row would be loaded on
     * every request: written there with autoload off instead (an autoloaded row of an older version turns off at the
     * next write). On a network, sitemeta has no autoload.
     *
     * @param array<string, mixed> $value
     */
    public function setSite(string $key, array $value): void
    {
        if (is_multisite()) {
            update_site_option($key, $value);

            return;
        }
        update_option($key, $value, false);
    }

    public function deleteSite(string $key): void
    {
        delete_site_option($key);
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

    public function deleteTransient(string $key): void
    {
        delete_transient($key);
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
