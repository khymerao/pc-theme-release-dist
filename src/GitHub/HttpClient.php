<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\GitHub;

/**
 * One HTTP GET that never follows redirects. Implementations must keep TLS verification on.
 */
interface HttpClient
{
    /**
     * @param array<string, string> $headers
     * @param string|null           $saveTo  Stream the body into this file instead of memory.
     *
     * @throws GitHubError With kind Transport when no HTTP response was received.
     */
    public function get(string $url, array $headers, int $timeout, ?string $saveTo = null): HttpResponse;
}
