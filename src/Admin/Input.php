<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Admin;

/**
 * The submitted form, unslashed. Values are read as strings; callers validate them.
 */
final readonly class Input
{
    /** @param array<string, mixed> $post */
    public function __construct(public string $method, private array $post)
    {
    }

    public static function fromGlobals(): self
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))) : 'GET';
        // Nonce and capability are verified by Actions::guard() before any value is used.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $post = 'POST' === $method ? wp_unslash($_POST) : [];

        return new self($method, $post);
    }

    public function text(string $key): string
    {
        $value = $this->post[$key] ?? '';

        return is_string($value) ? sanitize_text_field($value) : '';
    }

    /** Raw secret input: not passed through sanitize_text_field (it would alter characters); validated by Secrets. */
    public function secret(string $key): string
    {
        $value = $this->post[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    public function nonce(): string
    {
        return $this->text('_wpnonce');
    }
}
