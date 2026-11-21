<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Core\Security;

use ProCoders\ThemeRelease\Core\Support\Environment;
use ProCoders\ThemeRelease\Core\Support\Keys;
use ProCoders\ThemeRelease\Core\Support\Storage;
use ProCoders\ThemeRelease\Core\Support\ValidationError;

/**
 * The GitHub token and the webhook secret: wp-config constant first, else encrypted in a
 * non-autoloaded option. Plaintext only ever lives in memory for the current request.
 */
final class Secrets
{
    /** The "ends with …" hint is shown only for secrets at least this long (audit S9). */
    public const int HINT_MIN_LENGTH = 32;
    /** Value of Keys::SECRETS_FORMAT once every value is bound to its slot; legacy values are refused after. */
    private const int BOUND_FORMAT = 2;
    /** Sub-key of Keys::SECRETS holding the per-theme webhook secrets (stylesheet => ciphertext). */
    private const string THEMES = 'themes';
    /** How long a freshly generated theme secret waits to be shown once to the admin who generated it, in seconds. */
    public const int SHOWN_TTL = 600;

    public function __construct(
        private readonly Storage $storage,
        private readonly Environment $env,
        private readonly SecretVault $vault,
    ) {
    }

    public function token(): ?string
    {
        return $this->resolve(SecretKind::Token);
    }

    public function webhookSecret(): ?string
    {
        return $this->resolve(SecretKind::WebhookSecret);
    }

    public function source(SecretKind $kind): SecretSource
    {
        if ($this->constantDefined($kind)) {
            return null === $this->constantValue($kind) ? SecretSource::InvalidConstant : SecretSource::Constant;
        }
        $stored = $this->stored()[$kind->value] ?? null;
        if (! is_string($stored) || '' === $stored) {
            return SecretSource::None;
        }

        return null === $this->plain($kind) ? SecretSource::Undecryptable : SecretSource::Database;
    }

    /** A usable token (stored or wp-config) that is not a fine-grained one: it works, Health asks to replace it. */
    public function tokenIsLegacyFormat(): bool
    {
        $token = $this->token();

        return null !== $token && ! str_starts_with($token, SecretKind::FINE_GRAINED_PREFIX);
    }

    /** Last four characters for display ("ends with …abcd"), never more, and only for long secrets. */
    public function hint(SecretKind $kind): ?string
    {
        $value = $this->resolve($kind);
        if (null === $value || self::HINT_MIN_LENGTH > strlen($value)) {
            return null;
        }

        return substr($value, -4);
    }

    /** @throws ValidationError */
    public function save(SecretKind $kind, string $value): void
    {
        $value = trim($value);
        if (! $kind->isValidNew($value)) {
            throw new ValidationError($kind->invalidReason());
        }
        $this->store($kind, $value);
    }

    /**
     * Generates, stores and returns a new webhook secret (32 random bytes as hex); the old one stops working.
     *
     * @throws ValidationError
     */
    public function generateWebhookSecret(): string
    {
        $secret = bin2hex(random_bytes(32));
        $this->store(SecretKind::WebhookSecret, $secret);

        return $secret;
    }

    public function delete(SecretKind $kind): void
    {
        $stored = $this->stored();
        unset($stored[$kind->value]);
        $this->write($stored);
    }

    /**
     * The secret that verifies deliveries about this theme's repository (spec calls 4-6): the wp-config constant when
     * defined (an invalid one verifies nothing), else the theme's own secret when it has one (the global secret is
     * then never accepted for it, even when the own one cannot be decrypted), else the global secret.
     */
    public function webhookSecretFor(string $stylesheet, string $repo): ?string
    {
        if ($this->constantDefined(SecretKind::WebhookSecret)) {
            return $this->constantValue(SecretKind::WebhookSecret);
        }
        if (SecretSource::None !== $this->themeSecretState($stylesheet, $repo)) {
            return $this->themePlain($stylesheet, $repo);
        }

        return $this->plain(SecretKind::WebhookSecret);
    }

    /** The theme's own secret: None, Database (usable) or Undecryptable (salts changed, or written for another slot). */
    public function themeSecretState(string $stylesheet, string $repo): SecretSource
    {
        $stored = $this->themeCiphers()[$stylesheet] ?? null;
        if (! is_string($stored) || '' === $stored) {
            return SecretSource::None;
        }

        return null === $this->themePlain($stylesheet, $repo) ? SecretSource::Undecryptable : SecretSource::Database;
    }

    /** Whether a secret can be stored encrypted here (strong salts). */
    public function canStore(): bool
    {
        return $this->vault->saltsAreStrong();
    }

    /**
     * Whether a theme relies on the global secret: it has no secret of its own (the constant, when defined, verifies
     * every theme instead, so then none does).
     *
     * @param array<string, string> $themes stylesheet => owner/repo of every managed theme
     */
    public function globalSecretInUse(array $themes): bool
    {
        if ($this->constantDefined(SecretKind::WebhookSecret)) {
            return false;
        }
        foreach ($themes as $stylesheet => $repo) {
            if (SecretSource::None === $this->themeSecretState((string) $stylesheet, $repo)) {
                return true;
            }
        }

        return false;
    }

    /** A webhook secret exists anywhere: a valid constant, the global secret, or any theme's own. */
    public function anyWebhookSecret(): bool
    {
        if ($this->constantDefined(SecretKind::WebhookSecret)) {
            return null !== $this->constantValue(SecretKind::WebhookSecret);
        }

        return null !== $this->plain(SecretKind::WebhookSecret) || [] !== $this->themeCiphers();
    }

    /**
     * Generates, stores and returns the theme's own secret (32 random bytes as hex), bound to the theme and its
     * repository; the theme's previous secret stops working, every other secret is unchanged.
     *
     * @throws ValidationError secret_in_constant, weak_salts
     */
    public function generateThemeSecret(string $stylesheet, string $repo): string
    {
        if ($this->constantDefined(SecretKind::WebhookSecret)) {
            throw new ValidationError('secret_in_constant');
        }
        $secret = bin2hex(random_bytes(32));
        $cipher = $this->vault->encrypt($secret, SecretKind::WebhookSecret, self::themeScope($stylesheet, $repo));
        if (null === $cipher) {
            throw new ValidationError('weak_salts');
        }
        // Read fresh: another request may have written another theme's secret meanwhile.
        $stored = $this->storage->freshArray(Keys::SECRETS);
        $themes = is_array($stored[self::THEMES] ?? null) ? $stored[self::THEMES] : [];
        $themes[$stylesheet] = $cipher;
        $stored[self::THEMES] = $themes;
        $this->write($stored);

        return $secret;
    }

    public function deleteThemeSecret(string $stylesheet): void
    {
        $stored = $this->storage->freshArray(Keys::SECRETS);
        $themes = is_array($stored[self::THEMES] ?? null) ? $stored[self::THEMES] : [];
        if (! array_key_exists($stylesheet, $themes)) {
            return;
        }
        unset($themes[$stylesheet]);
        if ([] === $themes) {
            unset($stored[self::THEMES]);
        } else {
            $stored[self::THEMES] = $themes;
        }
        $this->write($stored);
    }

    /**
     * Keeps a just-generated theme secret for one display to this user (spec call 2): encrypted, never in a URL,
     * at most SHOWN_TTL seconds.
     */
    public function holdForDisplay(int $user, string $stylesheet, string $repo, string $secret): void
    {
        $cipher = $this->vault->encrypt($secret, SecretKind::WebhookSecret, self::displayScope($user, $stylesheet, $repo));
        if (null !== $cipher) {
            $this->storage->setTransient(self::shownKey($user, $stylesheet), $cipher, self::SHOWN_TTL);
        }
    }

    /**
     * The secret held for this user's display, removed by this read. Null when there is none, it expired, or it is no
     * longer the theme's current secret (replaced since).
     */
    public function takeForDisplay(int $user, string $stylesheet, string $repo): ?string
    {
        $key = self::shownKey($user, $stylesheet);
        $cipher = $this->storage->transient($key);
        if (null === $cipher) {
            return null;
        }
        $this->storage->deleteTransient($key);
        $secret = is_string($cipher) ? $this->vault->decrypt($cipher, SecretKind::WebhookSecret, self::displayScope($user, $stylesheet, $repo)) : null;

        return null !== $secret && $secret === $this->themePlain($stylesheet, $repo) ? $secret : null;
    }

    /** The display transient of one user and theme; the stylesheet is hashed (transient names are short). */
    public static function shownKey(int $user, string $stylesheet): string
    {
        return Keys::SECRET_SHOWN_PREFIX . $user . '_' . md5($stylesheet);
    }

    private function themePlain(string $stylesheet, string $repo): ?string
    {
        $stored = $this->themeCiphers()[$stylesheet] ?? null;

        return is_string($stored) ? $this->vault->decrypt($stored, SecretKind::WebhookSecret, self::themeScope($stylesheet, $repo)) : null;
    }

    /** @return array<mixed> stylesheet => ciphertext */
    private function themeCiphers(): array
    {
        $themes = $this->stored()[self::THEMES] ?? null;

        return is_array($themes) ? $themes : [];
    }

    /** Binds a ciphertext to one theme and its repository (owner/repo, case-insensitive like GitHub). */
    private static function themeScope(string $stylesheet, string $repo): string
    {
        return 'theme' . "\0" . $stylesheet . "\0" . strtolower($repo);
    }

    private static function displayScope(int $user, string $stylesheet, string $repo): string
    {
        return 'display' . "\0" . $user . "\0" . self::themeScope($stylesheet, $repo);
    }

    /** Drops every database-stored secret (constants are untouched). */
    public function deleteStored(): void
    {
        $this->storage->delete(Keys::SECRETS);
    }

    /** A defined constant is authoritative: when it is invalid nothing works (no fallback to the database). */
    private function resolve(SecretKind $kind): ?string
    {
        if ($this->constantDefined($kind)) {
            return $this->constantValue($kind);
        }

        return $this->plain($kind);
    }

    /**
     * The stored plaintext of a slot. A legacy (unbound) value is read once: every legacy slot is then
     * rewritten bound to its slot and the format is recorded, after which legacy values are refused.
     */
    private function plain(SecretKind $kind): ?string
    {
        $stored = $this->stored();
        $value = $stored[$kind->value] ?? null;
        if (! is_string($value) || '' === $value) {
            return null;
        }
        if (SecretVault::isBound($value)) {
            return $this->vault->decrypt($value, $kind);
        }
        if ($this->bound() || ! $this->vault->saltsAreStrong()) {
            return null;
        }
        $this->migrate($stored);
        $migrated = $this->stored()[$kind->value] ?? null;

        return is_string($migrated) && SecretVault::isBound($migrated) ? $this->vault->decrypt($migrated, $kind) : null;
    }

    /** @param array<string, mixed> $stored */
    private function migrate(array $stored): void
    {
        foreach (SecretKind::cases() as $kind) {
            $value = $stored[$kind->value] ?? null;
            if (! is_string($value) || SecretVault::isBound($value)) {
                continue;
            }
            $plain = $this->vault->decryptLegacy($value);
            $bound = null === $plain ? null : $this->vault->encrypt($plain, $kind);
            if (null !== $bound) {
                $stored[$kind->value] = $bound;
            }
        }
        $this->write($stored);
        $this->markBound();
    }

    /** Whether legacy (unbound) values were migrated, or never existed: then they are never read again. */
    private function bound(): bool
    {
        return self::BOUND_FORMAT === $this->storage->get(Keys::SECRETS_FORMAT);
    }

    /** Its own option, so deleting both secrets does not reopen legacy reading (removed on uninstall only). */
    private function markBound(): void
    {
        if (! $this->bound()) {
            $this->storage->set(Keys::SECRETS_FORMAT, self::BOUND_FORMAT, false);
        }
    }

    /** @throws ValidationError */
    private function store(SecretKind $kind, string $plaintext): void
    {
        if ($this->constantDefined($kind)) {
            throw new ValidationError('secret_in_constant');
        }
        $cipher = $this->vault->encrypt($plaintext, $kind);
        if (null === $cipher) {
            throw new ValidationError('weak_salts');
        }
        $stored = $this->stored();
        if (! $this->bound()) {
            // Bind the other slot's legacy value first, so recording the format does not lock it out.
            $this->migrate($stored);
            $stored = $this->stored();
        }
        $stored[$kind->value] = $cipher;
        $this->write($stored);
        $this->markBound();
    }

    private function constantDefined(SecretKind $kind): bool
    {
        $value = $this->env->constant($kind->constantName());

        return null !== $value && '' !== trim($value);
    }

    /** The constant's value when it passes the same rule as a stored secret, else null. */
    private function constantValue(SecretKind $kind): ?string
    {
        $value = trim((string) $this->env->constant($kind->constantName()));

        return '' !== $value && $kind->isValid($value) ? $value : null;
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        return $this->storage->getArray(Keys::SECRETS);
    }

    /** @param array<string, mixed> $stored */
    private function write(array $stored): void
    {
        if ([] === $stored) {
            $this->storage->delete(Keys::SECRETS);
            return;
        }
        $this->storage->set(Keys::SECRETS, $stored, false);
    }
}
