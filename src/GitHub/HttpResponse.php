<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\GitHub;

final readonly class HttpResponse
{
    /** @param array<string, string> $headers Lower-cased header names. */
    public function __construct(public int $status, public array $headers, public string $body)
    {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
