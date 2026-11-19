<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\GitHub;

use ProCoders\ThemeRelease\Support\ValidationError;

/**
 * What GitHub says a repository is right now: its numeric id (stable across renames and transfers),
 * whether it is private, and its current name.
 */
final readonly class Repository
{
    public function __construct(public int $id, public bool $private, public ?RepoName $name)
    {
    }

    /** @param array<mixed> $data A repository object of the REST API; null when id or private is missing. */
    public static function fromApi(array $data): ?self
    {
        $id = $data['id'] ?? null;
        $private = $data['private'] ?? null;
        if (! is_int($id) || $id <= 0 || ! is_bool($private)) {
            return null;
        }
        try {
            $name = RepoName::fromString(is_string($data['full_name'] ?? null) ? $data['full_name'] : '');
        } catch (ValidationError) {
            $name = null;
        }

        return new self($id, $private, $name);
    }
}
