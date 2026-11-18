<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Config;

use ProCoders\ThemeRelease\Support\Environment;
use ProCoders\ThemeRelease\Support\Keys;
use ProCoders\ThemeRelease\Support\OptionStore;
use ProCoders\ThemeRelease\Support\ValidationError;

final class SettingsRepository
{
    private const string BRANCH_PATTERN = '/^[A-Za-z0-9._\/-]{1,255}$/';

    public function __construct(
        private readonly OptionStore $options,
        private readonly Environment $environment,
        private readonly SiteBinding $binding,
    ) {
    }

    public function load(): Settings
    {
        $stored = $this->stored();
        $constant = $this->environment->constant(Keys::CONST_CHANNEL);
        $constantChannel = null === $constant ? null : Channel::fromValue($constant);

        return new Settings(
            null === $constant ? Channel::fromValue($stored['channel'] ?? null) : $constantChannel,
            null !== $constantChannel,
            null !== $constant && null === $constantChannel,
            is_string($stored['host'] ?? null) ? $stored['host'] : '',
            $this->branchOrDefault($stored['staging_branch'] ?? null, Settings::DEFAULT_STAGING_BRANCH),
            $this->branchOrDefault($stored['production_branch'] ?? null, Settings::DEFAULT_PRODUCTION_BRANCH),
        );
    }

    /**
     * Saves channel and branches and binds them to the current host.
     *
     * @throws ValidationError
     */
    public function save(?Channel $channel, string $stagingBranch, string $productionBranch): void
    {
        $stored = $this->stored();
        if (null !== $channel) {
            $stored['channel'] = $channel->value;
        }
        $stored['staging_branch'] = self::validBranch($stagingBranch, 'staging_branch');
        $stored['production_branch'] = self::validBranch($productionBranch, 'production_branch');
        $stored['host'] = $this->binding->currentHost();
        $this->options->set(Keys::SETTINGS, $stored, true);
    }

    /** Rebinds the configuration to the current host (after the admin confirmed a host change). */
    public function rebind(): void
    {
        $stored = $this->stored();
        $stored['host'] = $this->binding->currentHost();
        $this->options->set(Keys::SETTINGS, $stored, true);
    }

    /**
     * Validates a branch name; an empty string is allowed only where a caller treats it as "inherit".
     *
     * @throws ValidationError
     */
    public static function validBranch(string $branch, string $field): string
    {
        $branch = trim($branch);
        if (1 !== preg_match(self::BRANCH_PATTERN, $branch) || str_contains($branch, '..')) {
            throw new ValidationError('invalid_branch', $field);
        }

        return $branch;
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        $stored = $this->options->get(Keys::SETTINGS, []);

        return is_array($stored) ? $stored : [];
    }

    private function branchOrDefault(mixed $value, string $default): string
    {
        return is_string($value) && '' !== $value ? $value : $default;
    }
}
