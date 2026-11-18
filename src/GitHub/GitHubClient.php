<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\GitHub;

use ProCoders\ThemeRelease\Release\StateKey;
use ProCoders\ThemeRelease\Release\StateStore;
use ProCoders\ThemeRelease\Support\Clock;

/**
 * The three GitHub REST calls the plugin needs. The token goes only into the Authorization header
 * of requests to https://api.github.com/repos/{owner}/{repo}/…; redirects are followed by hand
 * to codeload.github.com without it.
 */
final class GitHubClient
{
    public const string API_BASE = 'https://api.github.com';
    public const string DOWNLOAD_HOST = 'codeload.github.com';
    public const int PER_PAGE = 100;
    public const int MAX_TAG_PAGES = 10;
    /** Largest accepted API answer body, in bytes (at most 2 MiB, audit S11). */
    public const int MAX_API_BYTES = 2_097_152;
    /** Largest accepted theme zip, in bytes (at most 50 MiB, audit S11). */
    public const int MAX_ZIP_BYTES = 52_428_800;
    private const int API_TIMEOUT = 15;
    private const int DOWNLOAD_TIMEOUT = 300;

    public function __construct(
        private readonly HttpClient $http,
        private readonly TokenProvider $tokens,
        private readonly StateStore $state,
        private readonly Clock $clock,
    ) {
    }

    /** @throws GitHubError */
    public function tags(RepoName $repo): TagList
    {
        $tags = [];
        $truncated = false;
        for ($page = 1; $page <= self::MAX_TAG_PAGES; $page++) {
            $response = $this->api($repo->apiPath() . '/tags?per_page=' . self::PER_PAGE . '&page=' . $page);
            $rows = json_decode($response->body, true);
            if (! is_array($rows)) {
                throw new GitHubError(GitHubErrorKind::BadResponse, $response->status);
            }
            foreach ($rows as $row) {
                $name = is_array($row) ? ($row['name'] ?? null) : null;
                $sha = is_array($row) && is_array($row['commit'] ?? null) ? ($row['commit']['sha'] ?? null) : null;
                if (is_string($name) && is_string($sha) && 1 === preg_match('/^[0-9a-f]{40}$/', $sha)) {
                    $tags[] = new Tag($name, $sha);
                }
            }
            $hasNext = str_contains((string) $response->header('link'), 'rel="next"');
            if (! $hasNext || count($rows) < self::PER_PAGE) {
                break;
            }
            if (self::MAX_TAG_PAGES === $page) {
                $truncated = true;
            }
        }

        return new TagList($tags, $truncated);
    }

    /**
     * Whether $sha is contained in $branch: compare status "behind" or "identical".
     *
     * @throws GitHubError For any answer other than 200 or 404 (404 = branch or commit missing).
     */
    public function compare(RepoName $repo, string $branch, string $sha): CompareResult
    {
        $path = $repo->apiPath() . '/compare/' . rawurlencode($branch) . '...' . rawurlencode($sha);
        try {
            $response = $this->api($path . '?per_page=1');
        } catch (GitHubError $error) {
            if (GitHubErrorKind::NotFound === $error->kind) {
                return new CompareResult(Containment::BranchMissing, null);
            }
            throw $error;
        }
        $data = json_decode($response->body, true);
        $status = is_array($data) ? ($data['status'] ?? null) : null;
        if (! is_string($status)) {
            throw new GitHubError(GitHubErrorKind::BadResponse, $response->status);
        }
        $contained = in_array($status, ['behind', 'identical'], true);
        $date = null;
        $raw = $data['merge_base_commit']['commit']['committer']['date'] ?? null;
        if ($contained && is_string($raw)) {
            $parsed = strtotime($raw);
            $date = false === $parsed ? null : $parsed;
        }

        return new CompareResult($contained ? Containment::Contained : Containment::NotContained, $date);
    }

    /**
     * Downloads the zipball of a commit into $target.
     *
     * @throws GitHubError
     */
    public function downloadZip(RepoName $repo, string $sha, string $target): void
    {
        if (1 !== preg_match('/^[0-9a-f]{40}$/', $sha)) {
            throw new GitHubError(GitHubErrorKind::BadResponse, 0);
        }
        // Streamed into $target: a direct 200 is complete; a redirect leaves an empty file we overwrite.
        $first = $this->request(self::API_BASE . $repo->apiPath() . '/zipball/' . $sha, true, $target, self::DOWNLOAD_TIMEOUT);
        if (200 === $first->status) {
            return;
        }
        if (! in_array($first->status, [301, 302, 303, 307, 308], true)) {
            $this->raiseFor($first);
        }
        $location = (string) $first->header('location');
        if (! self::isAllowedDownloadUrl($location)) {
            throw new GitHubError(GitHubErrorKind::ForeignRedirect, $first->status);
        }
        $second = $this->request($location, false, $target, self::DOWNLOAD_TIMEOUT);
        if (200 !== $second->status) {
            throw new GitHubError(GitHubErrorKind::Server, $second->status);
        }
    }

    public static function isAllowedDownloadUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && 'https' === ($parts['scheme'] ?? '')
            && self::DOWNLOAD_HOST === strtolower($parts['host'] ?? '')
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['port']);
    }

    /** @throws GitHubError */
    private function api(string $path): HttpResponse
    {
        $response = $this->request(self::API_BASE . $path, true, null, self::API_TIMEOUT);
        if (200 !== $response->status) {
            $this->raiseFor($response);
        }

        return $response;
    }

    /** @throws GitHubError */
    private function request(string $url, bool $authenticated, ?string $saveTo, int $timeout): HttpResponse
    {
        $headers = ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'];
        if ($authenticated) {
            $token = $this->tokens->token();
            if (null === $token || '' === $token) {
                throw new GitHubError(GitHubErrorKind::NoToken, 0);
            }
            if ($this->state->int(StateKey::RATE_LIMITED_UNTIL) > $this->clock->now()) {
                throw new GitHubError(GitHubErrorKind::RateLimited, 0);
            }
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $max = null === $saveTo ? self::MAX_API_BYTES : self::MAX_ZIP_BYTES;
        $response = $this->http->get($url, $headers, $timeout, $saveTo, $max);
        if ($authenticated) {
            $this->remember($response);
        }
        if (self::received($response, $saveTo) > $max) {
            throw new GitHubError(GitHubErrorKind::TooLarge, $response->status);
        }

        return $response;
    }

    /**
     * The size of the answer: the announced length or what arrived, whichever is larger. The HTTP layer
     * stops reading one byte past the limit, so "more than the limit" is seen without reading it all.
     */
    private static function received(HttpResponse $response, ?string $saveTo): int
    {
        $announced = (string) $response->header('content-length');
        $size = ctype_digit($announced) ? (int) $announced : 0;
        if (null === $saveTo) {
            return max($size, strlen($response->body));
        }
        clearstatcache(true, $saveTo);
        $written = is_file($saveTo) ? (int) filesize($saveTo) : 0;

        return max($size, $written);
    }

    private function remember(HttpResponse $response): void
    {
        $expiry = TokenExpiry::parse($response->header('github-authentication-token-expiration'));
        if (null !== $expiry && $expiry !== $this->state->int(StateKey::TOKEN_EXPIRES_AT)) {
            $this->state->setInt(StateKey::TOKEN_EXPIRES_AT, $expiry);
        }
        $until = $this->rateLimitedUntil($response);
        if (null !== $until) {
            $this->state->setInt(StateKey::RATE_LIMITED_UNTIL, $until);
        }
    }

    private function rateLimitedUntil(HttpResponse $response): ?int
    {
        if (! in_array($response->status, [403, 429], true)) {
            return null;
        }
        $retryAfter = $response->header('retry-after');
        if (null !== $retryAfter && ctype_digit($retryAfter)) {
            return $this->clock->now() + (int) $retryAfter;
        }
        $reset = $response->header('x-ratelimit-reset');
        if ('0' === $response->header('x-ratelimit-remaining') && null !== $reset && ctype_digit($reset)) {
            return (int) $reset;
        }
        if (429 === $response->status) {
            return $this->clock->now() + 60;
        }

        return null;
    }

    /** @throws GitHubError */
    private function raiseFor(HttpResponse $response): never
    {
        $limited = $this->state->int(StateKey::RATE_LIMITED_UNTIL) > $this->clock->now();
        $kind = match (true) {
            401 === $response->status => GitHubErrorKind::Unauthorized,
            404 === $response->status => GitHubErrorKind::NotFound,
            in_array($response->status, [403, 429], true) && $limited => GitHubErrorKind::RateLimited,
            403 === $response->status => GitHubErrorKind::Forbidden,
            $response->status >= 500 => GitHubErrorKind::Server,
            default => GitHubErrorKind::BadResponse,
        };
        throw new GitHubError($kind, $response->status);
    }
}
