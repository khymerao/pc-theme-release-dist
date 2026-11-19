<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Domain\Release;

use ProCoders\ThemeRelease\Core\GitHub\RepoName;
use ProCoders\ThemeRelease\Core\Support\Keys;
use ProCoders\ThemeRelease\Core\Support\Storage;

/**
 * Runtime state the plugin learns from GitHub and its own triggers. One non-autoloaded option.
 * Holds repo names, tags, SHAs, timestamps and error codes only — never secrets or payloads.
 * Reads use WordPress's copy of the option for this request; every write changes one key on a fresh read
 * of the option, so a long request does not overwrite what another request stored meanwhile (audit S10;
 * narrows, not atomic).
 */
final class StateStore
{
    public function __construct(private readonly Storage $storage)
    {
    }

    public function repo(RepoName $repo): RepoState
    {
        $repos = $this->storage->getArray(Keys::STATE)['repos'] ?? [];
        $raw = is_array($repos) ? ($repos[$repo->full()] ?? null) : null;

        return is_array($raw) ? RepoState::fromArray($raw) : RepoState::empty();
    }

    public function saveRepo(RepoName $repo, RepoState $state): void
    {
        $data = $this->storage->freshArray(Keys::STATE);
        $repos = is_array($data['repos'] ?? null) ? $data['repos'] : [];
        $repos[$repo->full()] = $state->toArray();
        $data['repos'] = $repos;
        $this->storage->set(Keys::STATE, $data, false);
    }

    public function forgetRepo(RepoName $repo): void
    {
        $data = $this->storage->freshArray(Keys::STATE);
        if (is_array($data['repos'] ?? null)) {
            unset($data['repos'][$repo->full()]);
            $this->storage->set(Keys::STATE, $data, false);
        }
    }

    /** A scalar entry (Keys::RATE_LIMITED_UNTIL, Keys::LAST_CRON_AT, …); 0 when unset. */
    public function int(string $key): int
    {
        $value = $this->storage->getArray(Keys::STATE)[$key] ?? 0;

        return is_int($value) ? $value : 0;
    }

    public function setInt(string $key, int $value): void
    {
        $data = $this->storage->freshArray(Keys::STATE);
        $data[$key] = $value;
        $this->storage->set(Keys::STATE, $data, false);
    }
}
