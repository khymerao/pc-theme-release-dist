<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\GitHub;

/**
 * Reads GitHub's token expiry header and decides when to warn (≤ 14 days left, spec call 7).
 */
final class TokenExpiry
{
    public const int WARN_SECONDS = 14 * 86400;

    /** Unix time of expiry, or null when the header is absent or unreadable (plan O1). */
    public static function parse(?string $header): ?int
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

    public static function shouldWarn(int $expiresAt, int $now): bool
    {
        return $expiresAt > 0 && $expiresAt - $now <= self::WARN_SECONDS;
    }
}
