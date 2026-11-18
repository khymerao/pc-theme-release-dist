<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Config;

use ProCoders\ThemeRelease\Support\Environment;

/**
 * Binds the configuration to the site host so a database cloned to another site goes inert.
 * Only the host counts: scheme and a leading "www." are ignored (spec call 18).
 */
final class SiteBinding
{
    public function __construct(private readonly Environment $environment)
    {
    }

    public static function normalise(string $host): string
    {
        $host = strtolower(trim($host));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    public function currentHost(): string
    {
        return self::normalise($this->environment->siteHost());
    }

    /** True when a channel was saved for another host than the current one. */
    public function hostChanged(Settings $settings): bool
    {
        return '' !== $settings->boundHost && $settings->boundHost !== $this->currentHost();
    }

    /** The channel that is in force right now, or null when the plugin must stay inert. */
    public function effectiveChannel(Settings $settings): ?Channel
    {
        if ($settings->channelFromConstant) {
            return $settings->channel;
        }
        if (null === $settings->channel || $this->hostChanged($settings)) {
            return null;
        }

        return $settings->channel;
    }
}
