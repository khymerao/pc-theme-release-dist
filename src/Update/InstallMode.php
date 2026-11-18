<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Update;

enum InstallMode: string
{
    case Update = 'update';
    case Rollback = 'rollback';
    /** Plugin-screen install of the newest channel tag over an out-of-channel tag: never freezes. */
    case Switch = 'switch';
}
