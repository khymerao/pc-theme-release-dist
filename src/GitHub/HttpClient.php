<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\GitHub;

/**
 * One HTTP GET that never follows redirects. Implementations must keep TLS verification on.
 * The answer's header names are lower case.
 *
 * @phpstan-type Response array{status: int, headers: array<string, string>, body: string}
 */
interface HttpClient
{
    /**
     * @param array<string, string> $headers
     * @param string|null           $saveTo   Stream the body into this file instead of memory.
     * @param int                   $maxBytes Read at most one byte more than this, so the caller can see an oversized answer.
     * @return array{status: int, headers: array<string, string>, body: string}
     *
     * @throws GitHubError With kind Transport when no HTTP response was received.
     */
    public function get(string $url, array $headers, int $timeout, ?string $saveTo, int $maxBytes): array;
}
