<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Security;

use ProCoders\ThemeRelease\Support\Keys;

enum SecretKind: string
{
    case Token = 'token';
    case WebhookSecret = 'webhook_secret';

    public function constantName(): string
    {
        return match ($this) {
            self::Token => Keys::CONST_TOKEN,
            self::WebhookSecret => Keys::CONST_WEBHOOK_SECRET,
        };
    }
}
