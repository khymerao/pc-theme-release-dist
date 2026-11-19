<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Webhook;

/**
 * Generic answers only: they never say whether a repository is managed or why a check failed.
 */
final readonly class WebhookResult
{
    /** @param array<string, string|bool> $body */
    private function __construct(public int $status, public array $body)
    {
    }

    public static function ok(): self
    {
        return new self(200, ['ok' => true]);
    }

    public static function ignored(int $status = 202): self
    {
        return new self($status, ['status' => 'ignored']);
    }

    public static function error(int $status): self
    {
        $message = match ($status) {
            400 => 'Bad request.',
            401 => 'Unauthorized.',
            405 => 'Method not allowed.',
            413 => 'Payload too large.',
            415 => 'Unsupported media type.',
            429 => 'Too many requests.',
            default => 'Error.',
        };

        return new self($status, ['error' => $message]);
    }
}
