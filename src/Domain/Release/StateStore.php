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
    /** Per-repository maps of the STATE option next to "repos": webhook delivery results and receive times. */
    public const string DELIVERIES = 'webhooks';
    public const string RECEIVED = 'webhook_received';

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

    /** The repository state, its webhook delivery result and its receive time (mapping removed). */
    public function forgetRepo(RepoName $repo): void
    {
        $this->forgetIn(['repos', self::DELIVERIES, self::RECEIVED], $repo);
    }

    /** The webhook delivery result and receive time of one repository. Writes only when one exists. */
    public function forgetDelivery(RepoName $repo): void
    {
        $this->forgetIn([self::DELIVERIES, self::RECEIVED], $repo);
    }

    /** @param list<string> $maps */
    private function forgetIn(array $maps, RepoName $repo): void
    {
        $data = $this->storage->freshArray(Keys::STATE);
        $changed = false;
        foreach ($maps as $map) {
            if (! is_array($data[$map] ?? null) || ! array_key_exists($repo->full(), $data[$map])) {
                continue;
            }
            unset($data[$map][$repo->full()]);
            $changed = true;
            // The delivery maps are left out when empty, like before they existed.
            if ('repos' !== $map && [] === $data[$map]) {
                unset($data[$map]);
            }
        }
        if ($changed) {
            $this->storage->set(Keys::STATE, $data, false);
        }
    }

    /** What the last check read about the deliveries of this site webhook for $repo, or null. */
    public function delivery(RepoName $repo): ?DeliveryStatus
    {
        $map = $this->storage->getArray(Keys::STATE)[self::DELIVERIES] ?? [];
        $raw = is_array($map) ? ($map[$repo->full()] ?? null) : null;

        return is_array($raw) ? DeliveryStatus::fromArray($raw) : null;
    }

    public function saveDelivery(RepoName $repo, DeliveryStatus $status): void
    {
        $this->setIn(self::DELIVERIES, $repo, $status->toArray());
    }

    /** When the webhook endpoint last accepted a valid signed delivery for $repo; 0 when never. */
    public function received(RepoName $repo): int
    {
        $map = $this->storage->getArray(Keys::STATE)[self::RECEIVED] ?? [];
        $value = is_array($map) ? ($map[$repo->full()] ?? 0) : 0;

        return is_int($value) ? $value : 0;
    }

    public function setReceived(RepoName $repo, int $time): void
    {
        $this->setIn(self::RECEIVED, $repo, $time);
    }

    /** Every delivery result and receive time (the webhook secret was removed). Writes only when some exist. */
    public function forgetDeliveries(): void
    {
        $data = $this->storage->freshArray(Keys::STATE);
        if (! array_key_exists(self::DELIVERIES, $data) && ! array_key_exists(self::RECEIVED, $data)) {
            return;
        }
        unset($data[self::DELIVERIES], $data[self::RECEIVED]);
        $this->storage->set(Keys::STATE, $data, false);
    }

    /** One entry of a per-repository map, on a fresh read (audit S10). */
    private function setIn(string $map, RepoName $repo, mixed $value): void
    {
        $data = $this->storage->freshArray(Keys::STATE);
        $entries = is_array($data[$map] ?? null) ? $data[$map] : [];
        $entries[$repo->full()] = $value;
        $data[$map] = $entries;
        $this->storage->set(Keys::STATE, $data, false);
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
