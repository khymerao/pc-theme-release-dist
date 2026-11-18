<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Support;

/**
 * A user input was refused. The code maps to a message shown to the admin (Admin\MessageCode).
 */
final class ValidationError extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $field = '')
    {
        parent::__construct($reason);
    }
}
