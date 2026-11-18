<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\GitHub;

use ProCoders\ThemeRelease\Support\ValidationError;

/**
 * A validated "owner/repo" pair, canonicalised to lower case (GitHub names are case-insensitive).
 */
final readonly class RepoName
{
    private const string PART = '[A-Za-z0-9_.-]{1,100}';

    private function __construct(public string $owner, public string $name)
    {
    }

    /** @throws ValidationError */
    public static function fromString(string $value): self
    {
        $value = trim($value);
        // No part may be "." or ".." or start with a dot (".git"), and no ".." anywhere (audit S12).
        if (1 !== preg_match('#^(' . self::PART . ')/(' . self::PART . ')$#', $value, $m) || '.' === $m[1][0] || '.' === $m[2][0] || str_contains($value, '..')) {
            throw new ValidationError('invalid_repo', 'repo');
        }

        return new self(strtolower($m[1]), strtolower($m[2]));
    }

    public function full(): string
    {
        return $this->owner . '/' . $this->name;
    }

    public function apiPath(): string
    {
        return '/repos/' . rawurlencode($this->owner) . '/' . rawurlencode($this->name);
    }

    public function equals(self $other): bool
    {
        return $this->full() === $other->full();
    }
}
