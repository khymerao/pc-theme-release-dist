<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Lifecycle;

use ProCoders\ThemeRelease\Support\Keys;
use ProCoders\ThemeRelease\Support\OptionStore;
use ProCoders\ThemeRelease\Support\WpOptionStore;

/**
 * Activation, deactivation and uninstall (spec call 12).
 */
final class Lifecycle
{
    public function __construct(
        private readonly OptionStore $options,
        private readonly Scheduler $scheduler,
        private readonly TransientPurger $purger,
    ) {
    }

    public static function wordpress(): self
    {
        return new self(new WpOptionStore(), new WpScheduler(), new WpTransientPurger());
    }

    public static function onActivate(): void
    {
        self::wordpress()->activate();
    }

    public static function onDeactivate(): void
    {
        self::wordpress()->deactivate();
    }

    public function activate(): void
    {
        $this->scheduler->schedule();
    }

    public function deactivate(): void
    {
        $this->scheduler->unschedule();
    }

    /** Removes every option, transient and cron event the plugin created. Theme files are untouched. */
    public function uninstall(): void
    {
        $this->scheduler->unschedule();
        foreach (Keys::options() as $option) {
            $this->options->delete($option);
        }
        $this->purger->purge(Keys::PREFIX);
    }
}
