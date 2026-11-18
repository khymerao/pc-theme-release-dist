<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Release;

/**
 * Names of the scalar entries kept in StateStore.
 */
final class StateKey
{
    public const string RATE_LIMITED_UNTIL = 'rate_limited_until';
    public const string TOKEN_EXPIRES_AT = 'token_expires_at';
    public const string LAST_CRON_AT = 'last_cron_at';
    public const string LAST_WEBHOOK_AT = 'last_webhook_at';
    public const string LAST_CHECK_AT = 'last_check_at';
}
