<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Domain\Theme;

use ProCoders\ThemeRelease\Core\Config\Channel;
use ProCoders\ThemeRelease\Core\Config\Settings;
use ProCoders\ThemeRelease\Core\GitHub\RepoName;

/**
 * One installed theme ↔ one private repository, with the plugin's record of what is installed and the
 * repository's GitHub id (audit N1: the repository is the one with this id, not whatever has the name now).
 */
final readonly class Mapping
{
    public function __construct(
        public string $stylesheet,
        public RepoName $repo,
        public string $branchOverride,
        public ?InstallRecord $record,
        public bool $frozen,
        public ?int $repoId = null,
    ) {
    }

    public function branch(Settings $settings, Channel $channel): string
    {
        return '' !== $this->branchOverride ? $this->branchOverride : $settings->branchFor($channel);
    }

    public function withRecord(InstallRecord $record, bool $frozen): self
    {
        return new self($this->stylesheet, $this->repo, $this->branchOverride, $record, $frozen, $this->repoId);
    }

    public function withFrozen(bool $frozen): self
    {
        return new self($this->stylesheet, $this->repo, $this->branchOverride, $this->record, $frozen, $this->repoId);
    }

    public function withRepoId(int $repoId): self
    {
        return new self($this->stylesheet, $this->repo, $this->branchOverride, $this->record, $this->frozen, $repoId);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'repo' => $this->repo->full(),
            'branch' => $this->branchOverride,
            'record' => $this->record?->toArray(),
            'frozen' => $this->frozen,
        ] + (null === $this->repoId ? [] : ['repo_id' => $this->repoId]);
    }
}
