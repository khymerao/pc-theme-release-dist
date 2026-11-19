<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Domain\Theme;

/**
 * What the plugin installed last for a theme (spec call 2): the source of truth for comparisons.
 */
final readonly class InstallRecord
{
    public function __construct(
        public string $tag,
        public string $sha,
        public int $installedAt,
        public bool $versionMismatch = false,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tag' => $this->tag,
            'sha' => $this->sha,
            'installed_at' => $this->installedAt,
            'version_mismatch' => $this->versionMismatch,
        ];
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): ?self
    {
        $tag = $data['tag'] ?? null;
        $sha = $data['sha'] ?? null;
        if (! is_string($tag) || ! is_string($sha) || '' === $tag) {
            return null;
        }

        return new self(
            $tag,
            $sha,
            is_int($data['installed_at'] ?? null) ? $data['installed_at'] : 0,
            true === ($data['version_mismatch'] ?? false),
        );
    }
}
