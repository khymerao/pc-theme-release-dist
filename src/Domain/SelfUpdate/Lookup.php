<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Domain\SelfUpdate;

use ProCoders\ThemeRelease\Core\GitHub\GitHubError;
use ProCoders\ThemeRelease\Core\GitHub\HttpClient;
use ProCoders\ThemeRelease\Core\Support\Environment;
use ProCoders\ThemeRelease\Core\Support\Keys;
use ProCoders\ThemeRelease\Core\Support\Storage;

/**
 * The latest published version of the plugin, read from the main file on the branch of the dist repository and kept in
 * one non-expiring site option (spec C2, C3). Core runs its update check every 1 min to 12 h and after every upgrade,
 * so the plugin keeps its own cache: fresh for an hour, then a conditional GET; after failures the last good value is
 * served and the next try backs off (15 min, 1 h, 6 h); a forced check skips both with a 60 s floor.
 *
 * @phpstan-type State array{source: string, version: string, requires_php: string, requires: string, etag: string, checked_at: int, failed_at: int, failures: int, error: string, status: int}
 * @phpstan-type Release array{version: string, requires_php: string, requires: string}
 */
final class Lookup
{
    public const int FRESH_SECONDS = 3600;
    public const int FORCED_FLOOR_SECONDS = 60;
    /** Wait after the 1st, 2nd and 3rd-or-later failure in a row. */
    public const array BACKOFF_SECONDS = [900, 3600, 21600];
    public const int MAX_BYTES = 65536;
    private const int TIMEOUT = 10;
    private const string VERSION = '/^\d{1,5}\.\d{1,5}\.\d{1,5}$/D';
    private const string REQUIREMENT = '/^\d{1,3}(?:\.\d{1,3}){0,2}$/D';

    public function __construct(
        private readonly HttpClient $http,
        private readonly Storage $storage,
        private readonly Environment $env,
        private readonly Source $source,
    ) {
    }

    public function source(): Source
    {
        return $this->source;
    }

    /**
     * The latest release to offer, checking GitHub when the cache allows; null while no check has ever succeeded
     * for this source.
     *
     * @return Release|null
     */
    public function latest(bool $forced = false): ?array
    {
        $state = $this->state();
        if ($this->due($state, $forced)) {
            $state = $this->fetch($state);
            $this->storage->setSite(Keys::SELF_UPDATE, $state);
        }

        return self::release($state);
    }

    /**
     * The last known release without any request (View details, Health).
     *
     * @return Release|null
     */
    public function known(): ?array
    {
        return self::release($this->state());
    }

    /**
     * The stored state for this source; a state stored for another repository or branch reads as empty.
     *
     * @return State
     */
    public function state(): array
    {
        $stored = $this->storage->getSiteArray(Keys::SELF_UPDATE);
        $state = self::empty($this->source->key());
        if (($stored['source'] ?? null) !== $state['source']) {
            return $state;
        }
        foreach (['version', 'requires_php', 'requires', 'etag', 'error'] as $field) {
            $state[$field] = is_string($stored[$field] ?? null) ? $stored[$field] : '';
        }
        foreach (['checked_at', 'failed_at', 'failures', 'status'] as $field) {
            $state[$field] = is_int($stored[$field] ?? null) ? $stored[$field] : 0;
        }
        // A stored version that may not be offered (a pre-release left in the option) is no known version: its etag
        // would turn the next check into a 304 for it, and Health would name it. The next check is due at once and is
        // a full GET; the failure fields stay, so a back-off still holds.
        if ('' !== $state['version'] && ! self::offerable($state['version'])) {
            $state['version'] = '';
            $state['etag'] = '';
            $state['checked_at'] = 0;
        }

        return $state;
    }

    /**
     * Withdraws the plugin's own entry from core's plugin update list (deactivation): without the plugin loaded
     * nothing would check the package before core installs it, so nothing is offered until core checks again.
     */
    public static function withdrawOffer(string $basename): void
    {
        $updates = get_site_transient('update_plugins');
        if (! is_object($updates)) {
            return;
        }
        $changed = false;
        foreach (['response', 'no_update'] as $list) {
            if (isset($updates->$list) && is_array($updates->$list) && array_key_exists($basename, $updates->$list)) {
                unset($updates->$list[$basename]);
                $changed = true;
            }
        }
        if ($changed) {
            set_site_transient('update_plugins', $updates);
        }
    }

    /**
     * Whether the plugin is loaded where its update is installed, so its install check runs (review R1). Always on a
     * single site (reads nothing). On a network core installs from Network Admin, which runs as the main site: the
     * plugin must be network-active or active on the main site; activated on another site only, nothing is offered.
     */
    public static function installable(string $basename): bool
    {
        if (! is_multisite()) {
            return true;
        }

        return self::networkActive($basename) || self::mainSiteActive($basename);
    }

    /** Network-activated (multisite only). */
    public static function networkActive(string $basename): bool
    {
        $sitewide = get_site_option('active_sitewide_plugins', []);

        return is_array($sitewide) && isset($sitewide[$basename]);
    }

    /** Activated on the main site of the network itself (multisite only). */
    public static function mainSiteActive(string $basename): bool
    {
        $active = is_main_site() ? get_option('active_plugins', []) : get_blog_option(get_main_site_id(), 'active_plugins', []);

        return is_array($active) && in_array($basename, $active, true);
    }

    /** Whether a version is one the plugin may offer (and put into the package URL). */
    public static function offerable(string $version): bool
    {
        return 1 === preg_match(self::VERSION, $version);
    }

    /**
     * A stamp later than now (the clock was moved back) never holds a check off: it counts as elapsed.
     *
     * @param State $state
     */
    private function due(array $state, bool $forced): bool
    {
        if ($forced) {
            return $this->elapsed(max($state['checked_at'], $state['failed_at']), self::FORCED_FLOOR_SECONDS);
        }
        if ($state['failures'] > 0) {
            return $this->elapsed($state['failed_at'], self::backoff($state['failures']));
        }

        return $this->elapsed($state['checked_at'], self::FRESH_SECONDS);
    }

    private function elapsed(int $since, int $seconds): bool
    {
        $now = $this->env->now();

        return $since > $now || $now - $since >= $seconds;
    }

    public static function backoff(int $failures): int
    {
        return self::BACKOFF_SECONDS[min(max($failures, 1), count(self::BACKOFF_SECONDS)) - 1];
    }

    /**
     * @param State $state
     * @return State
     */
    private function fetch(array $state): array
    {
        $headers = ['Accept' => 'text/plain'];
        if ('' !== $state['etag'] && '' !== $state['version']) {
            $headers['If-None-Match'] = $state['etag'];
        }
        try {
            $response = $this->http->get($this->source->rawUrl(), $headers, self::TIMEOUT, null, self::MAX_BYTES);
        } catch (GitHubError) {
            return $this->failed($state, 'transport', 0);
        }
        $status = $response['status'];
        if (304 === $status && '' !== $state['version']) {
            return $this->succeeded($state, 304);
        }
        if (200 !== $status) {
            $kind = match (true) {
                404 === $status => 'not_found',
                403 === $status, 429 === $status => 'rate_limited',
                default => 'http',
            };

            return $this->failed($state, $kind, $status);
        }
        if (strlen($response['body']) > self::MAX_BYTES) {
            return $this->failed($state, 'too_large', 200);
        }
        $header = self::headers($response['body']);
        if (self::prerelease($header['version'])) {
            return $this->failed($state, 'prerelease', 200);
        }
        if (! self::offerable($header['version']) || ! self::requirement($header['requires_php']) || ! self::requirement($header['requires'])) {
            return $this->failed($state, 'invalid', 200);
        }
        $state['version'] = $header['version'];
        $state['requires_php'] = $header['requires_php'];
        $state['requires'] = $header['requires'];
        $state['etag'] = substr($response['headers']['etag'] ?? '', 0, 200);

        return $this->succeeded($state, 200);
    }

    /**
     * @param State $state
     * @return State
     */
    private function succeeded(array $state, int $status): array
    {
        $state['checked_at'] = $this->env->now();
        // A failure stamp left behind would still count in the floor of a forced check (the later of both stamps).
        $state['failed_at'] = 0;
        $state['failures'] = 0;
        $state['error'] = '';
        $state['status'] = $status;

        return $state;
    }

    /**
     * The last good value stays; only the failure is recorded.
     *
     * @param State $state
     * @return State
     */
    private function failed(array $state, string $kind, int $status): array
    {
        $state['failed_at'] = $this->env->now();
        $state['failures'] = min($state['failures'] + 1, 100);
        $state['error'] = $kind;
        $state['status'] = $status;

        return $state;
    }

    /** @return array{version: string, requires_php: string, requires: string} */
    private static function headers(string $body): array
    {
        $head = substr($body, 0, 8192);
        $read = static function (string $name) use ($head): string {
            /* As get_file_data() in core: an optional opening PHP tag before the comment marks; a closing comment or PHP tag is cut off. */
            if (1 !== preg_match('/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote($name, '/') . ':(.*)$/mi', $head, $m)) {
                return '';
            }

            return trim((string) preg_replace('/\s*(?:\*\/|\?>).*/', '', $m[1]));
        };

        return ['version' => $read('Version'), 'requires_php' => $read('Requires PHP'), 'requires' => $read('Requires at least')];
    }

    private static function prerelease(string $version): bool
    {
        return 1 === preg_match('/^\d+\.\d+\.\d+-/', $version);
    }

    private static function requirement(string $value): bool
    {
        return '' === $value || 1 === preg_match(self::REQUIREMENT, $value);
    }

    /**
     * @param State $state
     * @return Release|null
     */
    private static function release(array $state): ?array
    {
        if (! self::offerable($state['version'])) {
            return null;
        }

        return ['version' => $state['version'], 'requires_php' => $state['requires_php'], 'requires' => $state['requires']];
    }

    /** @return State */
    private static function empty(string $source): array
    {
        return ['source' => $source, 'version' => '', 'requires_php' => '', 'requires' => '', 'etag' => '', 'checked_at' => 0, 'failed_at' => 0, 'failures' => 0, 'error' => '', 'status' => 0];
    }
}
