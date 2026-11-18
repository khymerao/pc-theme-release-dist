<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Security;

/**
 * Authenticated symmetric encryption (libsodium secretbox, random nonce) with a key derived from the salts.
 * WordPress bundles sodium_compat, so these functions exist on every supported host.
 */
final class SecretVault
{
    private const string CONTEXT = 'pc-theme-release/secrets/v1';

    public function __construct(private readonly SaltPolicy $salts)
    {
    }

    public function canEncrypt(): bool
    {
        return $this->salts->isStrong();
    }

    /** Returns base64(nonce . box), or null when the salts are too weak to derive a key. */
    public function encrypt(string $plaintext): ?string
    {
        $key = $this->key();
        if (null === $key) {
            return null;
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = sodium_crypto_secretbox($plaintext, $nonce, $key);
        self::wipe($key);

        return base64_encode($nonce . $box);
    }

    /** Returns the plaintext, or null when the value is corrupt or the salts changed since it was stored. */
    public function decrypt(string $stored): ?string
    {
        $key = $this->key();
        $raw = base64_decode($stored, true);
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

    private function key(): ?string
    {
        self::loadSodium();
        $material = $this->salts->keyMaterial();
        if (null === $material) {
            return null;
        }

        return sodium_crypto_generichash(self::CONTEXT . "\0" . $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
