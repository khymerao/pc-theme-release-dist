<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Release;

use ProCoders\ThemeRelease\GitHub\RepoName;
use ProCoders\ThemeRelease\Support\Keys;
use ProCoders\ThemeRelease\Support\OptionStore;

/**
 * Runtime state the plugin learns from GitHub and its own triggers. One non-autoloaded option.
 * Holds repo names, tags, SHAs, timestamps and error codes only — never secrets or payloads.
 */
final class StateStore
{
    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function __construct(private readonly OptionStore $options)
    {
    }

    public function repo(RepoName $repo): RepoState
    {
        $repos = $this->all()['repos'] ?? [];
        $raw = is_array($repos) ? ($repos[$repo->full()] ?? null) : null;

        return is_array($raw) ? RepoState::fromArray($raw) : RepoState::empty();
    }

    public function saveRepo(RepoName $repo, RepoState $state): void
    {
        $data = $this->all();
        $repos = is_array($data['repos'] ?? null) ? $data['repos'] : [];
        $repos[$repo->full()] = $state->toArray();
        $data['repos'] = $repos;
        $this->write($data);
    }

    public function forgetRepo(RepoName $repo): void
    {
        $data = $this->all();
        if (is_array($data['repos'] ?? null)) {
            unset($data['repos'][$repo->full()]);
            $this->write($data);
        }
    }

    public function int(string $key): int
    {
        $value = $this->all()[$key] ?? 0;

        return is_int($value) ? $value : 0;
    }

    public function setInt(string $key, int $value): void
    {
        $data = $this->all();
        $data[$key] = $value;
        $this->write($data);
    }

    /** @return array<string, mixed> */
    private function all(): array
    {
        if (null === $this->cache) {
            $raw = $this->options->get(Keys::STATE, []);
            $this->cache = is_array($raw) ? $raw : [];
        }

        return $this->cache;
    }

    /** @param array<string, mixed> $data */
    private function write(array $data): void
    {
        $this->cache = $data;
        $this->options->set(Keys::STATE, $data, false);
    }
}
