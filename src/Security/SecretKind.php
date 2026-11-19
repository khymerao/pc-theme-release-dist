<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Security;

use ProCoders\ThemeRelease\Support\Keys;

/**
 * The two secrets and their rules. The value is the storage slot and part of the encryption context.
 */
enum SecretKind: string
{
    case Token = 'token';
    case WebhookSecret = 'webhook_secret';

    public const int TOKEN_MIN = 20;
    public const int TOKEN_MAX = 255;
    public const int WEBHOOK_SECRET_MIN = 16;
    public const int WEBHOOK_SECRET_MAX = 255;
    private const string TOKEN_PATTERN = '/^[A-Za-z0-9_]+$/';
    /** The prefix of GitHub's fine-grained personal access tokens, required for new tokens (audit N8). */
    public const string FINE_GRAINED_PREFIX = 'github_pat_';
    /** Example values from documentation that must never work as a secret. */
    private const array PLACEHOLDERS = ['a-long-random-string', 'put your unique phrase here', 'your-webhook-secret', 'change-me-change-me'];

    public function constantName(): string
    {
        return match ($this) {
            self::Token => Keys::CONST_TOKEN,
            default => Keys::CONST_WEBHOOK_SECRET,
        };
    }

    /** The reason (Admin\MessageCode) an invalid value of this kind is refused with. */
    public function invalidReason(): string
    {
        return match ($this) {
            self::Token => 'invalid_token',
            default => 'invalid_webhook_secret',
        };
    }

    /**
     * One rule for both sources (wp-config constant and stored value): token 20–255 of [A-Za-z0-9_];
     * webhook secret 16–255 without control characters and not a documented placeholder.
     */
    /**
     * The rule for a value saved now: isValid(), and a token must be fine-grained (audit N8). A stored or
     * wp-config token of another format keeps working through isValid() (Health warns about it).
     */
    public function isValidNew(string $value): bool
    {
        return $this->isValid($value) && (self::Token !== $this || str_starts_with($value, self::FINE_GRAINED_PREFIX));
    }

    public function isValid(string $value): bool
    {
        $length = strlen($value);
        if (self::Token === $this) {
            return $length >= self::TOKEN_MIN && $length <= self::TOKEN_MAX && 1 === preg_match(self::TOKEN_PATTERN, $value);
        }

        return $length >= self::WEBHOOK_SECRET_MIN
            && $length <= self::WEBHOOK_SECRET_MAX
            && 1 !== preg_match('/[\x00-\x1F\x7F]/', $value)
            && ! in_array(strtolower($value), self::PLACEHOLDERS, true);
    }
}
