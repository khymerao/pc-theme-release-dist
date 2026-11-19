<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Domain\Release;

use ProCoders\ThemeRelease\Core\Support\Environment;
use ProCoders\ThemeRelease\Core\Support\Keys;
use ProCoders\ThemeRelease\Core\Support\Storage;

/**
 * The last events, for the admin screen. Only event code, repo, tag, HTTP status and time.
 */
final class StatusLog
{
    public const int CAP = 50;

    public function __construct(private readonly Storage $storage, private readonly Environment $env)
    {
    }

    public function add(string $event, string $repo = '', string $tag = '', int $httpStatus = 0): void
    {
        $entries = $this->entries();
        array_unshift($entries, [
            'time' => $this->env->now(),
            'event' => self::clean($event, 64),
            'repo' => self::clean($repo, 201),
            'tag' => self::clean($tag, 128),
            'http' => $httpStatus,
        ]);
        $this->storage->set(Keys::LOG, array_slice($entries, 0, self::CAP), false);
    }

    /** @return list<array{time: int, event: string, repo: string, tag: string, http: int}> Newest first. */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->storage->getArray(Keys::LOG) as $row) {
            if (is_array($row) && is_int($row['time'] ?? null)) {
                $entries[] = [
                    'time' => $row['time'],
                    'event' => is_string($row['event'] ?? null) ? $row['event'] : '',
                    'repo' => is_string($row['repo'] ?? null) ? $row['repo'] : '',
                    'tag' => is_string($row['tag'] ?? null) ? $row['tag'] : '',
                    'http' => is_int($row['http'] ?? null) ? $row['http'] : 0,
                ];
            }
        }

        return $entries;
    }

    private static function clean(string $value, int $max): string
    {
        return substr((string) preg_replace('/[^A-Za-z0-9_.\/+-]/', '', $value), 0, $max);
    }
}
