<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Webhook;

use ProCoders\ThemeRelease\Support\Keys;
use ProCoders\ThemeRelease\Support\TransientStore;

/**
 * Replay protection for 24 hours. The X-GitHub-Delivery id is not covered by the signature, so a
 * delivery counts as seen when either its id (a UUID, case-insensitive) or the SHA-256 of its signed
 * raw body was seen before.
 */
final class ReplayGuard
{
    public const int TTL = 86400;
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private readonly TransientStore $store)
    {
    }

    public static function isValidId(?string $delivery): bool
    {
        return null !== $delivery && 1 === preg_match(self::UUID, strtolower(trim($delivery)));
    }

    /** True for a replay by id or by body. Writes nothing. */
    public function seen(string $delivery, string $rawBody): bool
    {
        return null !== $this->store->get(self::idKey($delivery)) || null !== $this->store->get(self::bodyKey($rawBody));
    }

    /** Remembers id and body for 24 hours: called only for a delivery that is going to be processed. */
    public function remember(string $delivery, string $rawBody): void
    {
        $this->store->set(self::idKey($delivery), 1, self::TTL);
        $this->store->set(self::bodyKey($rawBody), 1, self::TTL);
    }

    private static function idKey(string $delivery): string
    {
        return Keys::DELIVERY_PREFIX . 'id_' . md5(strtolower(trim($delivery)));
    }

    private static function bodyKey(string $rawBody): string
    {
        return Keys::DELIVERY_PREFIX . 'body_' . hash('sha256', $rawBody);
    }
}
