<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\GitHub;

/**
 * WordPress HTTP API adapter. Redirects are never followed here: GitHubClient decides where a
 * redirect may go and which headers travel with it.
 */
final class WpHttpClient implements HttpClient
{
    public function get(string $url, array $headers, int $timeout, ?string $saveTo, int $maxBytes): array
    {
        $args = [
            'method' => 'GET',
            'timeout' => $timeout,
            // Explicit, but other code can still switch it off through `http_request_args` or
            // `https_ssl_verify`: GitHub\TlsPolicy forces it back on for the GitHub hosts, filtered last (audit N9).
            'sslverify' => true,
            'limit_response_size' => $maxBytes + 1,
            'redirection' => 0,
            'headers' => $headers,
            'user-agent' => 'pc-theme-release',
            'reject_unsafe_urls' => true,
        ];
        if (null !== $saveTo) {
            $args['stream'] = true;
            $args['filename'] = $saveTo;
        }
        $response = wp_safe_remote_request($url, $args);
        if (is_wp_error($response)) {
            throw new GitHubError(GitHubErrorKind::Transport, 0);
        }

        $normalised = [];
        foreach (wp_remote_retrieve_headers($response) as $name => $value) {
            $normalised[strtolower((string) $name)] = is_array($value) ? (string) end($value) : (string) $value;
        }

        return [
            'status' => (int) wp_remote_retrieve_response_code($response),
            'headers' => $normalised,
            'body' => null === $saveTo ? (string) wp_remote_retrieve_body($response) : '',
        ];
    }
}
