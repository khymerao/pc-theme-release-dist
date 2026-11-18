<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Security;

use ProCoders\ThemeRelease\Support\Environment;

/**
 * Decides whether the wp-config salts are strong enough to derive the encryption key from.
 * Weak salts mean WordPress falls back to salts stored in the database, so a dump would reveal the key.
 */
final class SaltPolicy
{
    public const int MIN_LENGTH = 32;
    private const string PLACEHOLDER = 'put your unique phrase here';

    public function __construct(private readonly Environment $environment)
    {
    }

    public function isStrong(): bool
    {
        return null !== $this->keyMaterial();
    }

    /** Concatenated salts, or null when they are missing, short or the placeholder. */
    public function keyMaterial(): ?string
    {
        $auth = $this->environment->constant('AUTH_KEY');
        $secure = $this->environment->constant('SECURE_AUTH_KEY');
        if (! self::acceptable($auth) || ! self::acceptable($secure)) {
            return null;
        }

        return $auth . "\0" . $secure;
    }

    private static function acceptable(?string $salt): bool
    {
        return null !== $salt
            && strlen($salt) >= self::MIN_LENGTH
            && ! str_contains($salt, self::PLACEHOLDER);
    }
}
