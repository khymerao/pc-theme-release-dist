<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Theme;

use ProCoders\ThemeRelease\Config\Channel;
use ProCoders\ThemeRelease\Config\Settings;
use ProCoders\ThemeRelease\GitHub\RepoName;

/**
 * One installed theme ↔ one private repository, with the plugin's record of what is installed.
 */
final readonly class Mapping
{
    public function __construct(
        public string $stylesheet,
        public RepoName $repo,
        public string $branchOverride,
        public ?InstallRecord $record,
        public bool $frozen,
    ) {
    }

    public function branch(Settings $settings, Channel $channel): string
    {
        return '' !== $this->branchOverride ? $this->branchOverride : $settings->branchFor($channel);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'repo' => $this->repo->full(),
            'branch' => $this->branchOverride,
            'record' => $this->record?->toArray(),
            'frozen' => $this->frozen,
        ];
    }
}
