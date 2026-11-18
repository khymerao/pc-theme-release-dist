<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Config;

use ProCoders\ThemeRelease\Security\SecretKind;
use ProCoders\ThemeRelease\Security\Secrets;
use ProCoders\ThemeRelease\Security\SecretSource;

/**
 * The one answer to "may the plugin act on this site right now, and for which channel?".
 */
final class SiteContext
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly SiteBinding $binding,
        private readonly Secrets $secrets,
    ) {
    }

    public function settings(): Settings
    {
        return $this->settings->load();
    }

    public function channel(): ?Channel
    {
        return $this->binding->effectiveChannel($this->settings->load());
    }

    public function hostChanged(): bool
    {
        return $this->binding->hostChanged($this->settings->load());
    }

    /** Channel chosen and in force, and a usable token: GitHub may be called. */
    public function isOperational(): bool
    {
        $source = $this->secrets->source(SecretKind::Token);

        return null !== $this->channel()
            && in_array($source, [SecretSource::Constant, SecretSource::Database], true);
    }
}
