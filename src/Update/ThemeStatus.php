<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Update;

use ProCoders\ThemeRelease\Config\Channel;
use ProCoders\ThemeRelease\Release\ChannelRelease;
use ProCoders\ThemeRelease\Release\RepoState;
use ProCoders\ThemeRelease\Theme\Mapping;

/**
 * Everything the screen and the update injection need to know about one mapped theme.
 */
final readonly class ThemeStatus
{
    /** @param list<ChannelRelease> $rollbackTargets */
    public function __construct(
        public Mapping $mapping,
        public RepoState $state,
        public string $installedLabel,
        public ?ChannelRelease $offer,
        public bool $themeMissing,
        public bool $gitUpdaterConflict,
        public bool $tagMoved,
        public bool $tagDeleted,
        public array $rollbackTargets,
        public bool $tagOtherChannel = false,
        public int $rateLimitedUntil = 0,
        public ?OutOfChannel $outOfChannel = null,
        public ?ChannelRelease $switchTarget = null,
        public ?Channel $channel = null,
        public string $branch = '',
    ) {
    }
}
