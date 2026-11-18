<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Update;

/**
 * Why the recorded installed tag is not a tag of the site's current channel.
 */
enum OutOfChannel: string
{
    /** Its suffix belongs to the other channel (a prerelease on production, a plain tag on staging). */
    case Suffix = 'suffix';
    /** Right suffix, but the check found its commit outside the channel branch. */
    case Branch = 'branch';
}
