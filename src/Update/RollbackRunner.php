<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Update;

use ProCoders\ThemeRelease\Release\ChannelRelease;
use ProCoders\ThemeRelease\Support\ValidationError;
use ProCoders\ThemeRelease\Theme\MappingRepository;

/**
 * Rollback to a chosen channel tag, or a switch to the newest channel tag, through core's
 * Theme_Upgrader::upgrade() (temp backup included).
 * The commit step re-validates everything the confirmation showed (U4) and holds the plugin lock (D3).
 */
final class RollbackRunner
{
    private ?string $pendingStylesheet = null;
    private ?string $pendingPackage = null;
    private ?string $pendingVersion = null;

    public function __construct(
        private readonly MappingRepository $mappings,
        private readonly UpdateOffer $offers,
        private readonly InstallContext $context,
        private readonly Lock $lock,
        private readonly ThemeUpgrader $upgrader,
    ) {
    }

    /**
     * The target as it stands now; used by the confirmation view and re-checked on commit.
     *
     * @throws ValidationError
     */
    public function target(string $stylesheet, string $tag): ChannelRelease
    {
        $mapping = $this->mappings->get($stylesheet);
        if (null === $mapping) {
            throw new ValidationError('mapping_gone');
        }
        $status = $this->offers->evaluate($mapping);
        if ($status->themeMissing || $status->gitUpdaterConflict) {
            throw new ValidationError('theme_unavailable');
        }
        foreach ($status->rollbackTargets as $release) {
            if ($release->tag === $tag) {
                return $release;
            }
        }
        throw new ValidationError('rollback_target_gone');
    }

    /**
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
     * The newest channel tag a theme with an out-of-channel installed tag moves to; used by the
     * "Switch to <channel>" confirmation and re-checked on commit.
     *
     * @throws ValidationError
     */
    public function switchTarget(string $stylesheet): ChannelRelease
    {
        $mapping = $this->mappings->get($stylesheet);
        if (null === $mapping) {
            throw new ValidationError('mapping_gone');
        }
        $status = $this->offers->evaluate($mapping);
        if ($status->themeMissing || $status->gitUpdaterConflict) {
            throw new ValidationError('theme_unavailable');
        }
        if ($mapping->frozen) {
            throw new ValidationError('theme_frozen');
        }
        if (null === $status->switchTarget) {
            throw new ValidationError('switch_not_needed');
        }

        return $status->switchTarget;
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
        if (! $this->lock->acquire()) {
            throw new ValidationError('install_running');
        }
        $mapping = $this->mappings->get($stylesheet);
        try {
            $this->context->expect($stylesheet, $target->sha, $mode);
            $this->pendingStylesheet = $stylesheet;
            $this->pendingPackage = null === $mapping ? null : UpdateOffer::packageUrl($mapping->repo, $target->sha);
            $this->pendingVersion = ltrim($target->tag, 'vV');
            add_filter('site_transient_update_themes', [$this, 'injectPending'], 99);

            return $this->upgrader->upgrade($stylesheet);
        } finally {
            remove_filter('site_transient_update_themes', [$this, 'injectPending'], 99);
            $this->pendingStylesheet = null;
            $this->pendingPackage = null;
            $this->pendingVersion = null;
            $this->context->clearExpected($stylesheet);
            $this->lock->release();
        }
    }

    /** Filter `site_transient_update_themes`, only while commit() runs: points core at the target package. */
    public function injectPending(mixed $transient): mixed
    {
        if (null === $this->pendingStylesheet || null === $this->pendingPackage) {
            return $transient;
        }
        $transient = $transient instanceof \stdClass ? $transient : new \stdClass();
        $response = isset($transient->response) && is_array($transient->response) ? $transient->response : [];
        $response[$this->pendingStylesheet] = [
            'theme' => $this->pendingStylesheet,
            'new_version' => (string) $this->pendingVersion,
            'url' => '',
            'package' => $this->pendingPackage,
            'requires' => '',
            'requires_php' => '',
        ];
        $transient->response = $response;

        return $transient;
    }
}
