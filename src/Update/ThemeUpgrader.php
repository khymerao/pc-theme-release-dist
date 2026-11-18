<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Update;

interface ThemeUpgrader
{
    /** Runs core's Theme_Upgrader::upgrade() for one theme; true on success. */
    public function upgrade(string $stylesheet): bool|\WP_Error;
}
