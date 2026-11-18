<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Webhook;

/**
 * GitHub's X-Hub-Signature-256: "sha256=" + hex HMAC-SHA256 of the raw body, compared in constant time.
 */
final class SignatureVerifier
{
    public function verify(string $secret, string $rawBody, ?string $header): bool
    {
        if ('' === $secret || null === $header || ! str_starts_with($header, 'sha256=')) {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $header);
    }
}
