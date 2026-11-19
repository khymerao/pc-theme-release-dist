<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Security;

use ProCoders\ThemeRelease\Support\Environment;
use ProCoders\ThemeRelease\Support\Keys;
use ProCoders\ThemeRelease\Support\Storage;
use ProCoders\ThemeRelease\Support\ValidationError;

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
        if (! $kind->isValid($value)) {
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
