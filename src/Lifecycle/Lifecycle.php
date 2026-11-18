<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Lifecycle;

use ProCoders\ThemeRelease\Config\SiteScope;
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
        private readonly UpdateCache $updates = new WpUpdateCache(),
    ) {
    }

    public static function wordpress(): self
    {
        return new self(new WpOptionStore(), new WpScheduler(), new WpTransientPurger());
    }

    public static function onActivate(): void
    {
        // On a multisite site other than the main site the plugin does nothing (audit S1).
        if (SiteScope::inert()) {
            return;
        }
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

    /** Unschedules the check and withdraws the plugin's entries from core's update list (audit S3). */
    public function deactivate(): void
    {
        $this->scheduler->unschedule();
        $this->updates->stripOwnEntries();
    }

    /**
     * Removes every option, transient and cron event the plugin created, and its update entries.
     * Theme files are untouched.
     */
    public function uninstall(): void
    {
        $this->scheduler->unschedule();
        // First, while the mappings still tell which unmarked (v1.0.0) entries are the plugin's.
        $this->updates->stripOwnEntries();
        foreach (Keys::options() as $option) {
            $this->options->delete($option);
        }
        $this->purger->purge(Keys::PREFIX);
    }
}
