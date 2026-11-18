<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Release;

/**
 * A tag that belongs to the site's channel: right suffix and its commit is in the channel branch.
 */
final readonly class ChannelRelease
{
    public function __construct(public string $tag, public string $sha, public ?int $date)
    {
    }

    public function version(): SemVer
    {
        $version = SemVer::parse($this->tag);
        if (null === $version) {
            throw new \LogicException('A channel release always has a SemVer tag.');
        }

        return $version;
    }

    /** @return array{tag: string, sha: string, date: int|null} */
    public function toArray(): array
    {
        return ['tag' => $this->tag, 'sha' => $this->sha, 'date' => $this->date];
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): ?self
    {
        $tag = $data['tag'] ?? null;
        $sha = $data['sha'] ?? null;
        if (! is_string($tag) || ! is_string($sha) || null === SemVer::parse($tag) || 1 !== preg_match('/^[0-9a-f]{40}$/', $sha)) {
            return null;
        }

        return new self($tag, $sha, is_int($data['date'] ?? null) ? $data['date'] : null);
    }
}
