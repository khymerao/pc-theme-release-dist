<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Lifecycle;

interface Scheduler
{
    /** Schedules the 12-hour check unless it is already scheduled. */
    public function schedule(): void;

    public function unschedule(): void;
}
