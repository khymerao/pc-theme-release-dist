<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Domain\Release;

/**
 * What the last check read from GitHub about the deliveries of this site webhook for one repository.
 * Stored next to the repository state (StateStore "webhooks"); holds no URL, secret or payload.
 */
final readonly class DeliveryStatus
{
    public const string OK = 'ok';
    public const string FAILING = 'failing';
    public const string MISCONFIGURED = 'misconfigured';
    public const string NO_HOOK = 'no_hook';
    public const string NO_DELIVERIES = 'no_deliveries';
    public const string NO_PERMISSION = 'no_permission';
    public const string UNKNOWN = 'unknown';
    private const array OUTCOMES = [self::OK, self::FAILING, self::MISCONFIGURED, self::NO_HOOK, self::NO_DELIVERIES, self::NO_PERMISSION, self::UNKNOWN];
    /** A result older than this (48 hours) is shown as unknown (spec call 12): strictly more than this many seconds. */
    public const int STALE_AFTER = 172800;
    /** Longest stored reason text, in characters. */
    public const int MAX_REASON = 200;
    /** Problem codes of a matched hook: disabled on GitHub, no tag events subscribed, not sending JSON. */
    public const string INACTIVE = 'inactive';
    public const string NO_TAG_EVENTS = 'events';
    public const string NOT_JSON = 'content_type';
    private const array PROBLEMS = [self::INACTIVE, self::NO_TAG_EVENTS, self::NOT_JSON];

    /**
     * @param string       $reason     GitHub status text of the failed newest delivery ("" otherwise).
     * @param int          $statusCode Its HTTP status code (0: no HTTP response).
     * @param list<string> $problems   Problem codes of the matched hook (INACTIVE, NO_TAG_EVENTS, NOT_JSON).
     * @param int          $failedAt   When the failing state began: the failed delivery time, or the check time.
     * @param string       $viaHost    The host of the matched hook when it is not this site host (a tunnel or
     *                                 proxy, spec call 2); "" for an exact match.
     */
    public function __construct(
        public string $outcome,
        public int $hookId,
        public string $reason,
        public int $statusCode,
        public array $problems,
        public int $lastDeliveryAt,
        public int $lastOkAt,
        public int $failedAt,
        public int $checkedAt,
        public string $viaHost = '',
    ) {
    }

    /** Failing or misconfigured: the red notice and the Site Health critical item. */
    public function isAlert(): bool
    {
        return in_array($this->outcome, [self::FAILING, self::MISCONFIGURED], true);
    }

    /**
     * What is shown now: unknown once the result is older than 48 hours; a failure is shown as ok when the site
     * received a valid delivery for this repository strictly later than the failed delivery (spec call 13).
     */
    public function effective(int $receivedAt, int $now): self
    {
        if ($now - $this->checkedAt > self::STALE_AFTER) {
            return new self(self::UNKNOWN, $this->hookId, '', 0, [], $this->lastDeliveryAt, $this->lastOkAt, 0, $this->checkedAt, $this->viaHost);
        }
        if (self::FAILING === $this->outcome && $receivedAt > $this->failedAt) {
            return new self(self::OK, $this->hookId, '', 0, [], max($this->lastDeliveryAt, $receivedAt), max($this->lastOkAt, $receivedAt), 0, $this->checkedAt, $this->viaHost);
        }

        return $this;
    }

    /** The same result, read from a hook on $host (a host name and port only; anything else is dropped). */
    public function via(string $host): self
    {
        $clean = 1 === preg_match('/^[a-z0-9.\-]{1,253}(:[0-9]{1,5})?$/', $host) ? $host : '';

        return new self($this->outcome, $this->hookId, $this->reason, $this->statusCode, $this->problems, $this->lastDeliveryAt, $this->lastOkAt, $this->failedAt, $this->checkedAt, $clean);
    }

    /** GitHub text made safe to keep: no control characters, at most MAX_REASON characters. */
    public static function cleanReason(string $text): string
    {
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text));

        return mb_substr($clean, 0, self::MAX_REASON);
    }

    /** @return array<string, int|string|list<string>> */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'hook_id' => $this->hookId,
            'reason' => $this->reason,
            'status_code' => $this->statusCode,
            'problems' => $this->problems,
            'last_delivery_at' => $this->lastDeliveryAt,
            'last_ok_at' => $this->lastOkAt,
            'failed_at' => $this->failedAt,
            'checked_at' => $this->checkedAt,
        ] + ('' === $this->viaHost ? [] : ['via_host' => $this->viaHost]);
    }

    /** @param array<mixed> $data Null when the stored entry is not a known outcome. */
    public static function fromArray(array $data): ?self
    {
        $outcome = $data['outcome'] ?? null;
        if (! is_string($outcome) || ! in_array($outcome, self::OUTCOMES, true)) {
            return null;
        }
        $int = static fn (string $key): int => is_int($data[$key] ?? null) ? $data[$key] : 0;

        return (new self(
            $outcome,
            $int('hook_id'),
            is_string($data['reason'] ?? null) ? self::cleanReason($data['reason']) : '',
            $int('status_code'),
            array_values(array_intersect(array_filter(is_array($data['problems'] ?? null) ? $data['problems'] : [], 'is_string'), self::PROBLEMS)),
            $int('last_delivery_at'),
            $int('last_ok_at'),
            $int('failed_at'),
            $int('checked_at'),
        ))->via(is_string($data['via_host'] ?? null) ? $data['via_host'] : '');
    }
}
