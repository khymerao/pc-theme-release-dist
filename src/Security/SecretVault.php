<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Security;

/**
 * Authenticated symmetric encryption (libsodium secretbox, random nonce) with a key derived from the salts
 * and the slot (token or webhook secret), so a ciphertext only opens in the slot it was written for
 * (audit S9). WordPress bundles sodium_compat, so these functions exist on every supported host.
 */
final class SecretVault
{
    /** Values written before slots were bound (read once, then migrated by Secrets). */
    private const string LEGACY_CONTEXT = 'pc-theme-release/secrets/v1';
    private const string CONTEXT = 'pc-theme-release/secrets/v2';
    private const string BOUND_PREFIX = 'v2:';

    public function __construct(private readonly SaltPolicy $salts)
    {
    }

    public function canEncrypt(): bool
    {
        return $this->salts->isStrong();
    }

    /** Returns "v2:" . base64(nonce . box) bound to the slot, or null when the salts are too weak to derive a key. */
    public function encrypt(string $plaintext, SecretKind $kind): ?string
    {
        $key = $this->key(self::CONTEXT . "\0" . $kind->value);
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
    public function decrypt(string $stored, SecretKind $kind): ?string
    {
        if (! self::isBound($stored)) {
            return null;
        }

        return $this->open(substr($stored, strlen(self::BOUND_PREFIX)), self::CONTEXT . "\0" . $kind->value);
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
        $material = $this->salts->keyMaterial();
        if (null === $material) {
            return null;
        }

        return sodium_crypto_generichash($context . "\0" . $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
