<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Core\Security;

use ProCoders\ThemeRelease\Core\Support\Environment;

/**
 * Authenticated symmetric encryption (libsodium secretbox, random nonce) with a key derived from the salts
 * and the slot (token or webhook secret), so a ciphertext only opens in the slot it was written for
 * (audit S9). WordPress bundles sodium_compat, so these functions exist on every supported host.
 *
 * The key comes from AUTH_KEY and SECURE_AUTH_KEY only when both are strong: weak salts mean WordPress
 * falls back to salts stored in the database, so a dump would reveal the key.
 */
final class SecretVault
{
    /** Values written before slots were bound (read once, then migrated by Secrets). */
    private const string LEGACY_CONTEXT = 'pc-theme-release/secrets/v1';
    private const string CONTEXT = 'pc-theme-release/secrets/v2';
    private const string BOUND_PREFIX = 'v2:';
    public const int SALT_MIN_LENGTH = 32;
    private const string SALT_PLACEHOLDER = 'put your unique phrase here';

    public function __construct(private readonly Environment $env)
    {
    }

    /** Whether the wp-config salts are strong enough to derive the encryption key from. */
    public function saltsAreStrong(): bool
    {
        return null !== $this->keyMaterial();
    }

    /**
     * Returns "v2:" . base64(nonce . box) bound to the slot (and to $scope, e.g. one theme and its repository), or
     * null when the salts are too weak to derive a key. An empty scope is the slot's own context.
     */
    public function encrypt(string $plaintext, SecretKind $kind, string $scope = ''): ?string
    {
        $key = $this->key(self::context($kind, $scope));
        if (null === $key) {
            return null;
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = sodium_crypto_secretbox($plaintext, $nonce, $key);
        self::wipe($key);

        return self::BOUND_PREFIX . base64_encode($nonce . $box);
    }

    public static function isBound(string $stored): bool
    {
        return str_starts_with($stored, self::BOUND_PREFIX);
    }

    /**
     * Returns the plaintext of a value written for this slot, or null when it is corrupt, written for
     * another slot, unbound (legacy), or the salts changed since it was stored.
     */
    public function decrypt(string $stored, SecretKind $kind, string $scope = ''): ?string
    {
        if (! self::isBound($stored)) {
            return null;
        }

        return $this->open(substr($stored, strlen(self::BOUND_PREFIX)), self::context($kind, $scope));
    }

    private static function context(SecretKind $kind, string $scope): string
    {
        return self::CONTEXT . "\0" . $kind->value . ('' === $scope ? '' : "\0" . $scope);
    }

    /** The plaintext of a value written before slots were bound, for the one-time migration only. */
    public function decryptLegacy(string $stored): ?string
    {
        return self::isBound($stored) ? null : $this->open($stored, self::LEGACY_CONTEXT);
    }

    private function open(string $encoded, string $context): ?string
    {
        $key = $this->key($context);
        $raw = base64_decode($encoded, true);
        if (null === $key || false === $raw || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($box, $nonce, $key);
        self::wipe($key);

        return false === $plain ? null : $plain;
    }

    /** Best effort: only ext-sodium can wipe memory; sodium_compat throws on memzero. */
    private static function wipe(?string &$key): void
    {
        if (extension_loaded('sodium')) {
            sodium_memzero($key);
        }
    }

    /** Hosts without ext-sodium get WordPress's bundled sodium_compat (functions and constants). */
    private static function loadSodium(): void
    {
        if (! function_exists('sodium_crypto_secretbox') && defined('ABSPATH') && defined('WPINC')) {
            require_once ABSPATH . WPINC . '/sodium_compat/autoload.php';
        }
    }

    private function key(string $context): ?string
    {
        self::loadSodium();
        $material = $this->keyMaterial();
        if (null === $material) {
            return null;
        }

        return sodium_crypto_generichash($context . "\0" . $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    /** Concatenated salts, or null when they are missing, short or the placeholder. */
    private function keyMaterial(): ?string
    {
        $auth = $this->env->constant('AUTH_KEY');
        $secure = $this->env->constant('SECURE_AUTH_KEY');
        if (! self::acceptableSalt($auth) || ! self::acceptableSalt($secure)) {
            return null;
        }

        return $auth . "\0" . $secure;
    }

    private static function acceptableSalt(?string $salt): bool
    {
        return null !== $salt
            && strlen($salt) >= self::SALT_MIN_LENGTH
            && ! str_contains($salt, self::SALT_PLACEHOLDER);
    }
}
