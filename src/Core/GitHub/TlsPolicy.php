<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Core\GitHub;

/**
 * TLS verification for the two GitHub hosts cannot be switched off by another plugin (audit N9): both
 * places WordPress lets code change it, filtered last. Other hosts are left alone. Builds nothing.
 */
final class TlsPolicy
{
    private const array HOSTS = ['api.github.com', GitHubClient::DOWNLOAD_HOST];

    public static function register(): void
    {
        add_filter('http_request_args', [self::class, 'forceArgs'], PHP_INT_MAX, 2);
        add_filter('https_ssl_verify', [self::class, 'forceVerify'], PHP_INT_MAX, 2);
    }

    /** Filter `http_request_args` (the args array; anything else is returned untouched). */
    public static function forceArgs(mixed $args, mixed $url = ''): mixed
    {
        if (is_array($args) && self::isGitHub($url)) {
            $args['sslverify'] = true;
        }

        return $args;
    }

    /**
     * Filter `https_ssl_verify` (WP_Http::request() passes the URL since WordPress 5.1). With verification on,
     * WordPress passes the path of its CA bundle: a path is kept; anything else becomes that bundle again
     * (or plain verification when it is unknown), never "off".
     */
    public static function forceVerify(mixed $verify, mixed $url = ''): mixed
    {
        if (! self::isGitHub($url) || (is_string($verify) && '' !== $verify)) {
            return $verify;
        }
        $bundle = defined('ABSPATH') && defined('WPINC') ? ABSPATH . WPINC . '/certificates/ca-bundle.crt' : '';

        return '' !== $bundle && is_readable($bundle) ? $bundle : true;
    }

    public static function isGitHub(mixed $url): bool
    {
        if (! is_string($url) || '' === $url) {
            return false;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, self::HOSTS, true);
    }
}
