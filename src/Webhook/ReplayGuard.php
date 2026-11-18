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

    /** True the first time (and remembers id and body), false for a replay by id or by body. */
    public function firstSeen(string $delivery, string $rawBody): bool
    {
        $idKey = Keys::DELIVERY_PREFIX . 'id_' . md5(strtolower(trim($delivery)));
        $bodyKey = Keys::DELIVERY_PREFIX . 'body_' . hash('sha256', $rawBody);
        if (null !== $this->store->get($idKey) || null !== $this->store->get($bodyKey)) {
            return false;
        }
        $this->store->set($idKey, 1, self::TTL);
        $this->store->set($bodyKey, 1, self::TTL);

        return true;
    }
}
