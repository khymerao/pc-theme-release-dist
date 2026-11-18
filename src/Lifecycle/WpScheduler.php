<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Lifecycle;

use ProCoders\ThemeRelease\Support\Keys;

final class WpScheduler implements Scheduler
{
    public function schedule(): void
    {
        if (false === wp_next_scheduled(Keys::CRON_HOOK)) {
            wp_schedule_event(time() + 300, 'twicedaily', Keys::CRON_HOOK);
        }
    }

    public function unschedule(): void
    {
        wp_clear_scheduled_hook(Keys::CRON_HOOK);
    }
}
