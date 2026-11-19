<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Core\Config;

/**
 * Site-level configuration as it applies to the current request.
 */
final readonly class Settings
{
    public const string DEFAULT_STAGING_BRANCH = 'develop';
    public const string DEFAULT_PRODUCTION_BRANCH = 'main';

    public function __construct(
        public ?Channel $channel,
        public bool $channelFromConstant,
        public bool $invalidChannelConstant,
        public string $boundHost,
        public string $stagingBranch,
        public string $productionBranch,
    ) {
    }

    public function branchFor(Channel $channel): string
    {
        return Channel::Staging === $channel ? $this->stagingBranch : $this->productionBranch;
    }
}
