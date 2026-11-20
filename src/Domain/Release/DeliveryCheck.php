<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Domain\Release;

use ProCoders\ThemeRelease\Core\GitHub\GitHubClient;
use ProCoders\ThemeRelease\Core\GitHub\GitHubError;
use ProCoders\ThemeRelease\Core\GitHub\GitHubErrorKind;
use ProCoders\ThemeRelease\Core\GitHub\RepoName;
use ProCoders\ThemeRelease\Core\Support\Environment;
use ProCoders\ThemeRelease\Core\Support\Keys;

/**
 * Reads GitHub delivery log for the repository webhook that points at this site and stores what it says
 * (spec calls 2, 3, 5, 7). Two API calls per repository; any GitHub error other than "may not read the
 * webhooks" keeps the previous result, so it ages into "unknown" instead of turning into an alert.
 */
final class DeliveryCheck
{
    /** Deliveries read per check (spec call 1). */
    public const int DELIVERIES = 10;
    /** Events that carry tags to the webhook endpoint; one of them must be subscribed. */
    private const array TAG_EVENTS = ['create', 'push', '*'];

    public function __construct(
        private readonly GitHubClient $github,
        private readonly StateStore $state,
        private readonly Environment $env,
    ) {
    }

    /** The plugin webhook route as REST resolves it, without host or prefix. */
    public static function route(): string
    {
        return '/' . Keys::REST_NAMESPACE . '/webhook';
    }

    /**
     * Checks one repository against this site endpoint ($endpoint as rest_url() gives it, $restPrefix as
     * rest_get_url_prefix() gives it). Never throws.
     */
    public function check(RepoName $repo, string $endpoint, string $restPrefix): void
    {
        $now = $this->env->now();
        try {
            $hooks = $this->github->hooks($repo);
        } catch (GitHubError $error) {
            // Only the hook list tells "the token may not read webhooks"; any other failure keeps the previous result.
            if (in_array($error->kind, [GitHubErrorKind::NotFound, GitHubErrorKind::Forbidden], true)) {
                $this->state->saveDelivery($repo, new DeliveryStatus(DeliveryStatus::NO_PERMISSION, 0, '', 0, [], 0, 0, 0, $now));
            }
            return;
        }
        $match = self::matchHook($hooks, $endpoint, $restPrefix);
        if (null === $match) {
            $this->state->saveDelivery($repo, new DeliveryStatus(DeliveryStatus::NO_HOOK, 0, '', 0, [], 0, 0, 0, $now));
            return;
        }
        [$hook, $via] = $match;
        try {
            $deliveries = $this->github->hookDeliveries($repo, $hook['id'], self::DELIVERIES);
        } catch (GitHubError) {
            // A hook deleted between the two calls (404) or any other failure: the previous result is kept.
            return;
        }
        $this->state->saveDelivery($repo, $this->judge($repo, $hook, $deliveries, $now)->via($via));
    }

    /**
     * Create webhook (spec calls 2-4): reads the hooks first; when one already points at exactly this site endpoint
     * nothing is created ("exists"). Else POSTs the hook; on 201 the new hook id is stored and the delivery check runs
     * at once with the same client. A check that cannot see the new hook (no permission, not listed yet, an error)
     * keeps the stored id. The secret goes to GitHub only; nothing here stores it.
     *
     * @return array{result: 'created'|'exists'|'duplicate'|'rejected', id: int, message: string}
     *
     * @throws GitHubError From the hooks read or the create request.
     */
    public function create(RepoName $repo, string $endpoint, string $restPrefix, string $secret): array
    {
        $existing = self::thisSiteHook($this->github->hooks($repo), $endpoint, $restPrefix);
        if (null !== $existing) {
            $this->check($repo, $endpoint, $restPrefix);

            return ['result' => 'exists', 'id' => $existing['id'], 'message' => ''];
        }
        $created = $this->github->createHook($repo, $endpoint, $secret);
        if ('created' !== $created['result']) {
            return $created;
        }
        $id = $created['id'];
        $stored = new DeliveryStatus(DeliveryStatus::NO_DELIVERIES, $id, '', 0, [], 0, 0, 0, $this->env->now());
        $this->state->saveDelivery($repo, $stored);
        $this->check($repo, $endpoint, $restPrefix);
        $after = $this->state->delivery($repo);
        if (null === $after || ! $after->knowsHook() || $id !== $after->hookId) {
            $this->state->saveDelivery($repo, $stored);
        }

        return $created;
    }

    /**
     * The hook whose URL is exactly this site endpoint, or null. A hook on another host (a proxy fallback of
     * matchHook) is another site and never counts here (spec edge: staging and production share a repository).
     *
     * @param list<array{id: int, active: bool, events: list<string>, url: string, content_type: string}> $hooks
     * @return array{id: int, active: bool, events: list<string>, url: string, content_type: string}|null
     */
    public static function thisSiteHook(array $hooks, string $endpoint, string $restPrefix): ?array
    {
        $match = self::matchHook($hooks, $endpoint, $restPrefix);

        return null !== $match && '' === $match[1] ? $match[0] : null;
    }

    /**
     * @param array{id: int, active: bool, events: list<string>, url: string, content_type: string} $hook
     * @param list<array{delivered_at: int, status: string, status_code: int}> $deliveries
     */
    private function judge(RepoName $repo, array $hook, array $deliveries, int $now): DeliveryStatus
    {
        $problems = self::problems($hook);
        $newest = null;
        $newestOk = 0;
        foreach ($deliveries as $delivery) {
            // GitHub does not document the order: the newest is the latest delivered_at (the first one on ties).
            if (null === $newest || $delivery['delivered_at'] > $newest['delivered_at']) {
                $newest = $delivery;
            }
            if (self::succeeded($delivery['status_code'])) {
                $newestOk = max($newestOk, $delivery['delivered_at']);
            }
        }
        $previous = $this->state->delivery($repo);
        $keptOk = null !== $previous && $previous->hookId === $hook['id'] ? $previous->lastOkAt : 0;
        $lastOk = max($keptOk, $newestOk);
        $lastAt = null === $newest ? 0 : $newest['delivered_at'];

        if (in_array(DeliveryStatus::INACTIVE, $problems, true)) {
            return new DeliveryStatus(DeliveryStatus::FAILING, $hook['id'], '', 0, $problems, $lastAt, $lastOk, $now, $now);
        }
        if (null !== $newest && ! self::succeeded($newest['status_code'])) {
            return new DeliveryStatus(DeliveryStatus::FAILING, $hook['id'], DeliveryStatus::cleanReason($newest['status']), $newest['status_code'], $problems, $lastAt, $lastOk, $newest['delivered_at'], $now);
        }
        $outcome = match (true) {
            [] !== $problems => DeliveryStatus::MISCONFIGURED,
            null === $newest => DeliveryStatus::NO_DELIVERIES,
            default => DeliveryStatus::OK,
        };

        return new DeliveryStatus($outcome, $hook['id'], '', 0, $problems, $lastAt, $lastOk, 0, $now);
    }

    /** A 2xx answer; 0 (no HTTP response, a timeout or a refused connection) is a failure. */
    public static function succeeded(int $statusCode): bool
    {
        return $statusCode >= 200 && $statusCode <= 299;
    }

    /**
     * @param array{id: int, active: bool, events: list<string>, url: string, content_type: string} $hook
     * @return list<string>
     */
    private static function problems(array $hook): array
    {
        $problems = [];
        if (! $hook['active']) {
            $problems[] = DeliveryStatus::INACTIVE;
        }
        if ([] === array_intersect($hook['events'], self::TAG_EVENTS)) {
            $problems[] = DeliveryStatus::NO_TAG_EVENTS;
        }
        if ('json' !== strtolower($hook['content_type'])) {
            $problems[] = DeliveryStatus::NOT_JSON;
        }

        return $problems;
    }

    /**
     * This site hook and, when it was not matched exactly, the host it points at ("" for an exact match).
     * Exact: the hook URL is this site endpoint (host with port, base path and route; any scheme, trailing slash
     * ignored). Else the only hook on another host name whose base path and route equal this site ones (a tunnel or
     * proxy in front of this site). Never a hook on this host name with another port or base path: that is
     * another site. Else null.
     *
     * @param list<array{id: int, active: bool, events: list<string>, url: string, content_type: string}> $hooks
     * @return array{0: array{id: int, active: bool, events: list<string>, url: string, content_type: string}, 1: string}|null
     */
    public static function matchHook(array $hooks, string $endpoint, string $restPrefix): ?array
    {
        $site = self::endpointKey($endpoint, $restPrefix);
        if (null === $site) {
            return null;
        }
        $proxied = [];
        foreach ($hooks as $hook) {
            $key = self::endpointKey($hook['url'], $restPrefix);
            if (null === $key || $key['route'] !== $site['route'] || $key['base'] !== $site['base']) {
                continue;
            }
            if ($key === $site) {
                return [$hook, ''];
            }
            if ($key['hostname'] !== $site['hostname']) {
                $proxied[] = [$hook, $key['host']];
            }
        }

        return 1 === count($proxied) ? $proxied[0] : null;
    }

    /**
     * A REST URL as host (with port), base path and route: "https://a.test/wp/wp-json/x/y/", "http://a.test/wp/index.php/wp-json/x/y"
     * and "https://a.test/wp/?rest_route=/x/y" all give a.test, /wp, /x/y. Null when it is not a REST URL.
     *
     * @return array{host: string, hostname: string, base: string, route: string}|null
     */
    public static function endpointKey(string $url, string $restPrefix): ?array
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || '' === ($parts['host'] ?? '')) {
            return null;
        }
        $hostname = strtolower($parts['host']);
        $host = $hostname . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);
        $restRoute = $query['rest_route'] ?? null;
        if (is_string($restRoute) && '' !== trim($restRoute, '/')) {
            $base = $path;
            $route = $restRoute;
        } else {
            $prefix = '/' . trim($restPrefix, '/') . '/';
            $at = strpos($path, $prefix);
            if (false === $at) {
                return null;
            }
            $base = substr($path, 0, $at);
            $route = substr($path, $at + strlen($prefix) - 1);
        }
        $base = rtrim($base, '/');
        if (str_ends_with($base, '/index.php')) {
            $base = substr($base, 0, -strlen('/index.php'));
        }

        return ['host' => $host, 'hostname' => $hostname, 'base' => rtrim($base, '/'), 'route' => '/' . strtolower(trim($route, '/'))];
    }
}
