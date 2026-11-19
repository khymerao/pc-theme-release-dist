<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Config;

use ProCoders\ThemeRelease\Security\SecretKind;
use ProCoders\ThemeRelease\Security\Secrets;
use ProCoders\ThemeRelease\Support\Environment;
use ProCoders\ThemeRelease\Support\Keys;
use ProCoders\ThemeRelease\Support\Storage;
use ProCoders\ThemeRelease\Support\ValidationError;

/**
 * The site's configuration and the one answer to "may the plugin act on this site right now, and for
 * which channel?". The configuration is bound to the site host so a database cloned to another site goes
 * inert; only the host counts: scheme and a leading "www." are ignored (spec call 18).
 */
final class SiteContext
{
    private const string BRANCH_PATTERN = '/^[A-Za-z0-9._\/-]{1,255}$/';

    public function __construct(
        private readonly Storage $storage,
        private readonly Environment $env,
        private readonly Secrets $secrets,
    ) {
    }

    public function settings(): Settings
    {
        $stored = $this->storage->getArray(Keys::SETTINGS);
        $constant = $this->env->constant(Keys::CONST_CHANNEL);
        $constantChannel = null === $constant ? null : Channel::fromValue($constant);

        return new Settings(
            null === $constant ? Channel::fromValue($stored['channel'] ?? null) : $constantChannel,
            null !== $constantChannel,
            null !== $constant && null === $constantChannel,
            is_string($stored['host'] ?? null) ? $stored['host'] : '',
            self::branchOrDefault($stored['staging_branch'] ?? null, Settings::DEFAULT_STAGING_BRANCH),
            self::branchOrDefault($stored['production_branch'] ?? null, Settings::DEFAULT_PRODUCTION_BRANCH),
        );
    }

    /** The channel that is in force right now, or null when the plugin must stay inert. */
    public function channel(): ?Channel
    {
        $settings = $this->settings();
        if ($settings->channelFromConstant || ! $this->hostChangedFor($settings)) {
            return $settings->channel;
        }

        return null;
    }

    /** True when a channel was saved for another host than the current one. */
    public function hostChanged(): bool
    {
        return $this->hostChangedFor($this->settings());
    }

    /** Channel chosen and in force, and a usable token: GitHub may be called. */
    public function isOperational(): bool
    {
        // The source is read first: reading a legacy secret migrates it, whatever the channel.
        $source = $this->secrets->source(SecretKind::Token);

        return null !== $this->channel() && $source->isUsable();
    }

    /**
     * Saves channel and branches and binds them to the current host.
     *
     * @throws ValidationError
     */
    public function save(?Channel $channel, string $stagingBranch, string $productionBranch): void
    {
        $stored = $this->storage->getArray(Keys::SETTINGS);
        if (null !== $channel) {
            $stored['channel'] = $channel->value;
        }
        $stored['staging_branch'] = self::validBranch($stagingBranch);
        $stored['production_branch'] = self::validBranch($productionBranch);
        $stored['host'] = $this->currentHost();
        $this->storage->set(Keys::SETTINGS, $stored, true);
    }

    /** Rebinds the configuration to the current host (after the admin confirmed a host change). */
    public function rebind(): void
    {
        $stored = $this->storage->getArray(Keys::SETTINGS);
        $stored['host'] = $this->currentHost();
        $this->storage->set(Keys::SETTINGS, $stored, true);
    }

    /**
     * A branch name: letters, digits, ".", "_", "-" and "/", up to 255 characters, no "..".
     *
     * @throws ValidationError
     */
    public static function validBranch(string $branch): string
    {
        $branch = trim($branch);
        if (1 !== preg_match(self::BRANCH_PATTERN, $branch) || str_contains($branch, '..')) {
            throw new ValidationError('invalid_branch');
        }

        return $branch;
    }

    public static function normaliseHost(string $host): string
    {
        $host = strtolower(trim($host));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    public function currentHost(): string
    {
        return self::normaliseHost($this->env->siteHost());
    }

    private function hostChangedFor(Settings $settings): bool
    {
        return '' !== $settings->boundHost && $settings->boundHost !== $this->currentHost();
    }

    private static function branchOrDefault(mixed $value, string $default): string
    {
        return is_string($value) && '' !== $value ? $value : $default;
    }
}
