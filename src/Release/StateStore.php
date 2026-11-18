<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Release;

use ProCoders\ThemeRelease\GitHub\RepoName;
use ProCoders\ThemeRelease\Support\Keys;
use ProCoders\ThemeRelease\Support\OptionStore;

/**
 * Runtime state the plugin learns from GitHub and its own triggers. One non-autoloaded option.
 * Holds repo names, tags, SHAs, timestamps and error codes only — never secrets or payloads.
 * Reads use this request's copy; every write changes one key on a fresh read of the option, so a long
 * request does not overwrite what another request stored meanwhile (audit S10; narrows, not atomic).
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
        $data = $this->fresh();
        $repos = is_array($data['repos'] ?? null) ? $data['repos'] : [];
        $repos[$repo->full()] = $state->toArray();
        $data['repos'] = $repos;
        $this->write($data);
    }

    public function forgetRepo(RepoName $repo): void
    {
        $data = $this->fresh();
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
        $data = $this->fresh();
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

    /** @return array<string, mixed> */
    private function fresh(): array
    {
        $raw = $this->options->fresh(Keys::STATE, []);

        return is_array($raw) ? $raw : [];
    }

    /** @param array<string, mixed> $data */
    private function write(array $data): void
    {
        $this->cache = $data;
        $this->options->set(Keys::STATE, $data, false);
    }
}
