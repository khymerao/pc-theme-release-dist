<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Security;

use ProCoders\ThemeRelease\GitHub\TokenProvider;
use ProCoders\ThemeRelease\Support\Environment;
use ProCoders\ThemeRelease\Support\Keys;
use ProCoders\ThemeRelease\Support\OptionStore;
use ProCoders\ThemeRelease\Support\ValidationError;

/**
 * The GitHub token and the webhook secret: wp-config constant first, else encrypted in a
 * non-autoloaded option. Plaintext only ever lives in memory for the current request.
 */
final class Secrets implements TokenProvider
{
    public const int TOKEN_MIN = 20;
    public const int TOKEN_MAX = 255;
    public const int WEBHOOK_SECRET_MIN = 16;
    public const int WEBHOOK_SECRET_MAX = 255;
    /** The "ends with …" hint is shown only for secrets at least this long (audit S9). */
    public const int HINT_MIN_LENGTH = 32;
    /** Value of Keys::SECRETS_FORMAT once every value is bound to its slot; legacy values are refused after. */
    private const int BOUND_FORMAT = 2;
    private const string TOKEN_PATTERN = '/^[A-Za-z0-9_]+$/';
    /** Example values from documentation that must never work as a secret. */
    private const array PLACEHOLDERS = ['a-long-random-string', 'put your unique phrase here', 'your-webhook-secret', 'change-me-change-me'];

    public function __construct(
        private readonly OptionStore $options,
        private readonly Environment $environment,
        private readonly SecretVault $vault,
    ) {
    }

    public function token(): ?string
    {
        return $this->value(SecretKind::Token);
    }

    public function webhookSecret(): ?string
    {
        return $this->value(SecretKind::WebhookSecret);
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

    /** Last four characters for display ("ends with …abcd"), never more, and only for long secrets. */
    public function hint(SecretKind $kind): ?string
    {
        $value = $this->value($kind);
        if (null === $value || self::HINT_MIN_LENGTH > strlen($value)) {
            return null;
        }

        return substr($value, -4);
    }

    /** @throws ValidationError */
    public function saveToken(string $token): void
    {
        $token = trim($token);
        if (! self::isValid(SecretKind::Token, $token)) {
            throw new ValidationError('invalid_token', 'token');
        }
        $this->store(SecretKind::Token, $token);
    }

    /**
     * One rule for both sources (wp-config constant and stored value): token 20–255 of [A-Za-z0-9_];
     * webhook secret 16–255 without control characters and not a documented placeholder.
     */
    public static function isValid(SecretKind $kind, string $value): bool
    {
        $length = strlen($value);
        if (SecretKind::Token === $kind) {
            return $length >= self::TOKEN_MIN && $length <= self::TOKEN_MAX && 1 === preg_match(self::TOKEN_PATTERN, $value);
        }

        return $length >= self::WEBHOOK_SECRET_MIN
            && $length <= self::WEBHOOK_SECRET_MAX
            && 1 !== preg_match('/[\x00-\x1F\x7F]/', $value)
            && ! in_array(strtolower($value), self::PLACEHOLDERS, true);
    }

    /** @throws ValidationError */
    public function saveWebhookSecret(string $secret): void
    {
        $secret = trim($secret);
        if (! self::isValid(SecretKind::WebhookSecret, $secret)) {
            throw new ValidationError('invalid_webhook_secret', 'webhook_secret');
        }
        $this->store(SecretKind::WebhookSecret, $secret);
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
        if ($this->bound() || ! $this->vault->canEncrypt()) {
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
        return self::BOUND_FORMAT === $this->options->get(Keys::SECRETS_FORMAT);
    }

    /** Its own option, so deleting both secrets does not reopen legacy reading (removed on uninstall only). */
    private function markBound(): void
    {
        if (! $this->bound()) {
            $this->options->set(Keys::SECRETS_FORMAT, self::BOUND_FORMAT, false);
        }
    }

    /** Drops every database-stored secret (constants are untouched). */
    public function deleteStored(): void
    {
        $this->options->delete(Keys::SECRETS);
    }

    /** @throws ValidationError */
    private function store(SecretKind $kind, string $plaintext): void
    {
        if ($this->constantDefined($kind)) {
            throw new ValidationError('secret_in_constant', $kind->value);
        }
        $cipher = $this->vault->encrypt($plaintext, $kind);
        if (null === $cipher) {
            throw new ValidationError('weak_salts', $kind->value);
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

    private function value(SecretKind $kind): ?string
    {
        // A defined constant is authoritative: when it is invalid nothing works (no fallback to the DB).
        if ($this->constantDefined($kind)) {
            return $this->constantValue($kind);
        }
        return $this->plain($kind);
    }

    private function constantDefined(SecretKind $kind): bool
    {
        $value = $this->environment->constant($kind->constantName());

        return null !== $value && '' !== trim($value);
    }

    /** The constant's value when it passes the same rule as a stored secret, else null. */
    private function constantValue(SecretKind $kind): ?string
    {
        $value = trim((string) $this->environment->constant($kind->constantName()));

        return '' !== $value && self::isValid($kind, $value) ? $value : null;
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        $stored = $this->options->get(Keys::SECRETS, []);

        return is_array($stored) ? $stored : [];
    }

    /** @param array<string, mixed> $stored */
    private function write(array $stored): void
    {
        if ([] === $stored) {
            $this->options->delete(Keys::SECRETS);
            return;
        }
        $this->options->set(Keys::SECRETS, $stored, false);
    }
}
