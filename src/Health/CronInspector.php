<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Health;

interface CronInspector
{
    public function nextScheduled(string $hook): ?int;

    /** Events of any plugin whose time passed more than $graceSeconds ago. */
    public function overdueCount(int $now, int $graceSeconds): int;
}
