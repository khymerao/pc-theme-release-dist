<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Modules\Update;

/**
 * How a package of the plugin gets installed.
 */
enum InstallMode
{
    /** Core's own update (Dashboard → Updates, Themes screen): only the offered tag. */
    case Update;
    /** Plugin-screen install of an older channel tag: freezes the theme. */
    case Rollback;
    /** Plugin-screen install of the newest channel tag over an out-of-channel tag: never freezes. */
    case Switch;
}
