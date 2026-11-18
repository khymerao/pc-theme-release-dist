<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Health;

final class WpCronInspector implements CronInspector
{
    public function nextScheduled(string $hook): ?int
    {
        $next = wp_next_scheduled($hook);

        return is_int($next) ? $next : null;
    }

    public function overdueCount(int $now, int $graceSeconds): int
    {
        $crons = _get_cron_array();
        $count = 0;
        foreach ($crons as $timestamp => $hooks) {
            if ((int) $timestamp < $now - $graceSeconds) {
                $count += count($hooks);
            }
        }

        return $count;
    }
}
