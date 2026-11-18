<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Lifecycle;

/**
 * Deletes the plugin's own transients only. With a persistent object cache the entries are not
 * enumerable; they expire by themselves (≤ 24 h).
 */
final class WpTransientPurger implements TransientPurger
{
    public function purge(string $prefix): void
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
