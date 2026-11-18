<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Lifecycle;

/**
 * Core's `update_themes` site transient, as far as the plugin's own entries are concerned.
 */
interface UpdateCache
{
    /** Removes every entry this plugin wrote; other updaters' entries are left as they are. */
    public function stripOwnEntries(): void;
}
