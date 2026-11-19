<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Core\Support;

/**
 * A user input or a requested change was refused. The reason maps to a message shown to the admin
 * (Admin\MessageCode).
 */
final class ValidationError extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
