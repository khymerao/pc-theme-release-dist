<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Modules\Update;

use ProCoders\ThemeRelease\Core\GitHub\GitHubClient;
use ProCoders\ThemeRelease\Core\Support\ValidationError;
use ProCoders\ThemeRelease\Domain\Release\ChannelRelease;
use ProCoders\ThemeRelease\Domain\Release\UpdateTransient;
use ProCoders\ThemeRelease\Domain\Status\Offers;
use ProCoders\ThemeRelease\Domain\Status\ThemeStatus;
use ProCoders\ThemeRelease\Domain\Theme\MappingRepository;

/**
 * The plugin screen's installs — a rollback to a chosen channel tag, or a switch to the newest channel
 * tag — through core's Theme_Upgrader::upgrade() (temp backup included). The Update module's one public
 * service. The commit step re-validates everything the confirmation showed (U4) and holds the plugin
 * lock (D3).
 */
final class InstallFlow
{
    /** @var array<string, mixed>|null the update entry core reads while commit() runs */
    private ?array $pendingEntry = null;

    public function __construct(
        private readonly MappingRepository $mappings,
        private readonly Offers $offers,
        private readonly Installer $installer,
        private readonly CoreUpgrader $core,
    ) {
    }

    /**
     * The rollback target as it stands now; used by the confirmation view and re-checked on commit.
     *
     * @throws ValidationError
     */
    public function target(string $stylesheet, string $tag): ChannelRelease
    {
        foreach ($this->available($stylesheet)->rollbackTargets as $release) {
            if ($release->tag === $tag) {
                return $release;
            }
        }
        throw new ValidationError('rollback_target_gone');
    }

    /**
     * The newest channel tag a theme with an out-of-channel installed tag moves to; used by the
     * "Switch to <channel>" confirmation and re-checked on commit.
     *
     * @throws ValidationError
     */
    public function switchTarget(string $stylesheet): ChannelRelease
    {
        $status = $this->available($stylesheet);
        if ($status->mapping->frozen) {
            throw new ValidationError('theme_frozen');
        }

        return $status->switchTarget ?? throw new ValidationError('switch_not_needed');
    }

    /**
     * Installs the rollback target the admin confirmed, and freezes the theme.
     *
     * @throws ValidationError When anything changed since the confirmation, or another run holds the lock.
     */
    public function commit(string $stylesheet, string $tag, string $expectedSha): bool|\WP_Error
    {
        self::requireFileMods();
        $target = $this->target($stylesheet, $tag);
        if ($target->sha !== $expectedSha) {
            throw new ValidationError('rollback_target_changed');
        }

        return $this->install($stylesheet, $target, InstallMode::Rollback);
    }

    /**
     * Installs the switch target the admin confirmed, without freezing. Only the newest channel tag
     * can be installed this way, so a crafted request never turns into an unfrozen rollback.
     *
     * @throws ValidationError When anything changed since the confirmation, or another run holds the lock.
     */
    public function commitSwitch(string $stylesheet, string $tag, string $expectedSha): bool|\WP_Error
    {
        self::requireFileMods();
        $target = $this->switchTarget($stylesheet);
        if ($target->tag !== $tag || $target->sha !== $expectedSha) {
            throw new ValidationError('switch_target_changed');
        }

        return $this->install($stylesheet, $target, InstallMode::Switch);
    }

    /** Filter `site_transient_update_themes`, only while commit() runs: points core at the target package. */
    public function injectPending(mixed $transient): mixed
    {
        if (null === $this->pendingEntry) {
            return $transient;
        }
        $transient = $transient instanceof \stdClass ? $transient : new \stdClass();
        $response = isset($transient->response) && is_array($transient->response) ? $transient->response : [];
        $response[$this->pendingEntry['theme']] = $this->pendingEntry;
        $transient->response = $response;

        return $transient;
    }

    /**
     * The status of a managed theme that can be installed over.
     *
     * @throws ValidationError
     */
    private function available(string $stylesheet): ThemeStatus
    {
        $status = $this->offers->evaluate($this->mappings->require($stylesheet));
        if ($status->unavailable()) {
            throw new ValidationError('theme_unavailable');
        }

        return $status;
    }

    /** @throws ValidationError */
    private static function requireFileMods(): void
    {
        if (! wp_is_file_mod_allowed('pc_theme_release_install')) {
            throw new ValidationError('file_mods_disallowed');
        }
    }

    /** @throws ValidationError When another run holds the lock. */
    private function install(string $stylesheet, ChannelRelease $target, InstallMode $mode): bool|\WP_Error
    {
        if (! $this->core->acquireLock()) {
            throw new ValidationError('install_running');
        }
        $mapping = $this->mappings->get($stylesheet);
        try {
            $this->installer->expect($stylesheet, $target->sha, $mode, $target->tag);
            $this->pendingEntry = null === $mapping
                ? null
                : UpdateTransient::entry($stylesheet, $target->versionLabel(), '', GitHubClient::packageUrl($mapping->repo, $target->sha), false);
            add_filter('site_transient_update_themes', [$this, 'injectPending'], 99);

            return $this->core->upgrade($stylesheet, self::title($mode, $target));
        } finally {
            remove_filter('site_transient_update_themes', [$this, 'injectPending'], 99);
            $this->pendingEntry = null;
            $this->installer->clearExpected($stylesheet);
            $this->core->releaseLock();
        }
    }

    /** The upgrader screen's heading: what this install does. */
    private static function title(InstallMode $mode, ChannelRelease $target): string
    {
        return match ($mode) {
            InstallMode::Switch => sprintf(
                /* translators: %s: tag, e.g. v1.2.0 */
                __('Switching theme to %s', 'pc-theme-release'),
                $target->tag
            ),
            default => __('Rolling back theme', 'pc-theme-release'),
        };
    }
}
