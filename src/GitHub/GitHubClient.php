<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\GitHub;

use ProCoders\ThemeRelease\Security\Secrets;
use ProCoders\ThemeRelease\Support\Environment;
use ProCoders\ThemeRelease\Support\Keys;
use ProCoders\ThemeRelease\Support\Storage;

/**
 * The three GitHub REST calls the plugin needs. The token goes only into the Authorization header
 * of requests to https://api.github.com/repos/{owner}/{repo}/…; redirects are followed by hand
 * to codeload.github.com without it. Also keeps GitHub's rate-limit and token-expiry answers.
 */
final class GitHubClient
{
    public const string API_BASE = 'https://api.github.com';
    public const string DOWNLOAD_HOST = 'codeload.github.com';
    /** The packages this plugin offers: a commit zipball of the GitHub API, nothing else. */
    public const string PACKAGE_PATTERN = '#^https://api\.github\.com/repos/([^/]+)/([^/]+)/zipball/([0-9a-f]{40})$#';
    /** A full commit SHA as GitHub returns it. */
    public const string SHA_PATTERN = '/^[0-9a-f]{40}$/';
    public const int PER_PAGE = 100;
    public const int MAX_TAG_PAGES = 10;
    /** Largest accepted API answer body, in bytes (at most 2 MiB, audit S11). */
    public const int MAX_API_BYTES = 2_097_152;
    /** Largest accepted theme zip, in bytes (at most 50 MiB, audit S11). */
    public const int MAX_ZIP_BYTES = 52_428_800;
    private const int API_TIMEOUT = 15;
    private const int DOWNLOAD_TIMEOUT = 300;
    private const array REDIRECTS = [301, 302, 303, 307, 308];

    public function __construct(
        private readonly HttpClient $http,
        private readonly Secrets $secrets,
        private readonly Storage $storage,
        private readonly Environment $env,
    ) {
    }

    /** The package URL core is given for one commit of a repository. */
    public static function packageUrl(RepoName $repo, string $sha): string
    {
        return self::API_BASE . $repo->apiPath() . '/zipball/' . $sha;
    }

    /**
     * Every tag (name and commit SHA), in GitHub's order; truncated when more than MAX_TAG_PAGES pages exist.
     *
     * @return array{tags: list<array{name: string, sha: string}>, truncated: bool}
     *
     * @throws GitHubError
     */
    public function tags(RepoName $repo): array
    {
        $tags = [];
        $truncated = false;
        for ($page = 1; $page <= self::MAX_TAG_PAGES; $page++) {
            $response = $this->apiGet($repo->apiPath() . '/tags?per_page=' . self::PER_PAGE . '&page=' . $page);
            $rows = json_decode($response['body'], true);
            if (! is_array($rows)) {
                throw new GitHubError(GitHubErrorKind::BadResponse, $response['status']);
            }
            foreach ($rows as $row) {
                $name = is_array($row) ? ($row['name'] ?? null) : null;
                $sha = is_array($row) && is_array($row['commit'] ?? null) ? ($row['commit']['sha'] ?? null) : null;
                if (is_string($name) && is_string($sha) && 1 === preg_match(self::SHA_PATTERN, $sha)) {
                    $tags[] = ['name' => $name, 'sha' => $sha];
                }
            }
            $hasNext = str_contains((string) ($response['headers']['link'] ?? ''), 'rel="next"');
            if (! $hasNext || count($rows) < self::PER_PAGE) {
                break;
            }
            if (self::MAX_TAG_PAGES === $page) {
                $truncated = true;
            }
        }

        return ['tags' => $tags, 'truncated' => $truncated];
    }

    /**
     * Whether $sha is contained in $branch (compare status "behind" or "identical"), with the commit date
     * when it is; "branch_missing" when GitHub answers 404 (branch or commit missing).
     *
     * @return array{status: 'contained'|'not_contained'|'branch_missing', date: int|null}
     *
     * @throws GitHubError For any answer other than 200 or 404.
     */
    public function compare(RepoName $repo, string $branch, string $sha): array
    {
        $path = $repo->apiPath() . '/compare/' . rawurlencode($branch) . '...' . rawurlencode($sha);
        try {
            $response = $this->apiGet($path . '?per_page=1');
        } catch (GitHubError $error) {
            if (GitHubErrorKind::NotFound === $error->kind) {
                return ['status' => 'branch_missing', 'date' => null];
            }
            throw $error;
        }
        $data = json_decode($response['body'], true);
        $status = is_array($data) ? ($data['status'] ?? null) : null;
        if (! is_string($status)) {
            throw new GitHubError(GitHubErrorKind::BadResponse, $response['status']);
        }
        if (! in_array($status, ['behind', 'identical'], true)) {
            return ['status' => 'not_contained', 'date' => null];
        }
        $raw = $data['merge_base_commit']['commit']['committer']['date'] ?? null;
        $parsed = is_string($raw) ? strtotime($raw) : false;

        return ['status' => 'contained', 'date' => false === $parsed ? null : $parsed];
    }

    /**
     * Downloads the zipball of a commit into $target.
     *
     * @throws GitHubError
     */
    public function downloadZip(RepoName $repo, string $sha, string $target): void
    {
        if (1 !== preg_match(self::SHA_PATTERN, $sha)) {
            throw new GitHubError(GitHubErrorKind::BadResponse, 0);
        }
        // Streamed into $target: a direct 200 is complete; a redirect leaves an empty file we overwrite.
        $first = $this->authenticatedGet(self::packageUrl($repo, $sha), $target, self::DOWNLOAD_TIMEOUT);
        if (200 === $first['status']) {
            return;
        }
        if (! in_array($first['status'], self::REDIRECTS, true)) {
            $this->raiseFor($first);
        }
        $location = (string) ($first['headers']['location'] ?? '');
        if (! self::isAllowedDownloadUrl($location)) {
            throw new GitHubError(GitHubErrorKind::ForeignRedirect, $first['status']);
        }
        $second = $this->plainGet($location, $target, self::DOWNLOAD_TIMEOUT);
        if (200 !== $second['status']) {
            throw new GitHubError(GitHubErrorKind::Server, $second['status']);
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

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     *
     * @throws GitHubError
     */
    private function apiGet(string $path): array
    {
        $response = $this->authenticatedGet(self::API_BASE . $path, null, self::API_TIMEOUT);
        if (200 !== $response['status']) {
            $this->raiseFor($response);
        }

        return $response;
    }

    /**
     * A request that carries the token: refused without one, and while GitHub's rate limit lasts.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     *
     * @throws GitHubError
     */
    private function authenticatedGet(string $url, ?string $saveTo, int $timeout): array
    {
        $token = $this->secrets->token();
        if (null === $token || '' === $token) {
            throw new GitHubError(GitHubErrorKind::NoToken, 0);
        }
        if ($this->rateLimited()) {
            throw new GitHubError(GitHubErrorKind::RateLimited, 0);
        }
        $response = $this->http->get($url, self::headers() + ['Authorization' => 'Bearer ' . $token], $timeout, $saveTo, self::limit($saveTo));
        $this->remember($response);

        return self::sized($response, $saveTo);
    }

    /**
     * A request without the token (the codeload.github.com redirect target).
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     *
     * @throws GitHubError
     */
    private function plainGet(string $url, ?string $saveTo, int $timeout): array
    {
        return self::sized($this->http->get($url, self::headers(), $timeout, $saveTo, self::limit($saveTo)), $saveTo);
    }

    /** @return array<string, string> */
    private static function headers(): array
    {
        return ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'];
    }

    private static function limit(?string $saveTo): int
    {
        return null === $saveTo ? self::MAX_API_BYTES : self::MAX_ZIP_BYTES;
    }

    /**
     * @param array{status: int, headers: array<string, string>, body: string} $response
     * @return array{status: int, headers: array<string, string>, body: string}
     *
     * @throws GitHubError When the answer is larger than its limit.
     */
    private static function sized(array $response, ?string $saveTo): array
    {
        if (self::received($response, $saveTo) > self::limit($saveTo)) {
            throw new GitHubError(GitHubErrorKind::TooLarge, $response['status']);
        }

        return $response;
    }

    /**
     * The size of the answer: the announced length or what arrived, whichever is larger. The HTTP layer
     * stops reading one byte past the limit, so "more than the limit" is seen without reading it all.
     *
     * @param array{status: int, headers: array<string, string>, body: string} $response
     */
    private static function received(array $response, ?string $saveTo): int
    {
        $announced = (string) ($response['headers']['content-length'] ?? '');
        $size = ctype_digit($announced) ? (int) $announced : 0;
        if (null === $saveTo) {
            return max($size, strlen($response['body']));
        }
        clearstatcache(true, $saveTo);
        $written = is_file($saveTo) ? (int) filesize($saveTo) : 0;

        return max($size, $written);
    }

    private function rateLimited(): bool
    {
        return $this->stateInt(Keys::RATE_LIMITED_UNTIL) > $this->env->now();
    }

    /** @param array{status: int, headers: array<string, string>, body: string} $response */
    private function remember(array $response): void
    {
        $expiry = self::tokenExpiry($response['headers']['github-authentication-token-expiration'] ?? null);
        if (null !== $expiry && $expiry !== $this->stateInt(Keys::TOKEN_EXPIRES_AT)) {
            $this->setStateInt(Keys::TOKEN_EXPIRES_AT, $expiry);
        }
        $until = $this->rateLimitedUntil($response);
        if (null !== $until) {
            $this->setStateInt(Keys::RATE_LIMITED_UNTIL, $until);
        }
    }

    /** Unix time of the token's expiry from GitHub's header, or null when absent or unreadable (plan O1). */
    private static function tokenExpiry(?string $header): ?int
    {
        if (null === $header || '' === trim($header)) {
            return null;
        }
        try {
            return (new \DateTimeImmutable(trim($header), new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }

    /** @param array{status: int, headers: array<string, string>, body: string} $response */
    private function rateLimitedUntil(array $response): ?int
    {
        if (! self::isLimitStatus($response['status'])) {
            return null;
        }
        $headers = $response['headers'];
        $retryAfter = $headers['retry-after'] ?? null;
        if (null !== $retryAfter && ctype_digit($retryAfter)) {
            return $this->env->now() + (int) $retryAfter;
        }
        $reset = $headers['x-ratelimit-reset'] ?? null;
        if ('0' === ($headers['x-ratelimit-remaining'] ?? null) && null !== $reset && ctype_digit($reset)) {
            return (int) $reset;
        }
        if (429 === $response['status']) {
            return $this->env->now() + 60;
        }

        return null;
    }

    private static function isLimitStatus(int $status): bool
    {
        return in_array($status, [403, 429], true);
    }

    /**
     * @param array{status: int, headers: array<string, string>, body: string} $response
     *
     * @throws GitHubError
     */
    private function raiseFor(array $response): never
    {
        $status = $response['status'];
        $kind = match (true) {
            401 === $status => GitHubErrorKind::Unauthorized,
            404 === $status => GitHubErrorKind::NotFound,
            self::isLimitStatus($status) && $this->rateLimited() => GitHubErrorKind::RateLimited,
            403 === $status => GitHubErrorKind::Forbidden,
            $status >= 500 => GitHubErrorKind::Server,
            default => GitHubErrorKind::BadResponse,
        };
        throw new GitHubError($kind, $status);
    }

    private function stateInt(string $key): int
    {
        $value = $this->storage->getArray(Keys::STATE)[$key] ?? 0;

        return is_int($value) ? $value : 0;
    }

    /** One key changed on a fresh read of the state option, as Release\StateStore writes it (audit S10). */
    private function setStateInt(string $key, int $value): void
    {
        $data = $this->storage->freshArray(Keys::STATE);
        $data[$key] = $value;
        $this->storage->set(Keys::STATE, $data, false);
    }
}
