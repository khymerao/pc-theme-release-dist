<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Update;

use ProCoders\ThemeRelease\Config\SiteContext;
use ProCoders\ThemeRelease\GitHub\GitHubClient;
use ProCoders\ThemeRelease\GitHub\GitHubError;
use ProCoders\ThemeRelease\GitHub\GitHubErrorKind;
use ProCoders\ThemeRelease\GitHub\RepoName;
use ProCoders\ThemeRelease\Release\StatusLog;
use ProCoders\ThemeRelease\Support\ValidationError;
use ProCoders\ThemeRelease\Theme\MappingRepository;

/**
 * Filter `upgrader_pre_download`: supplies the zip for packages the plugin itself offered, so the
 * token never reaches core's generic downloader. A package of the plugin's own form that is not offered
 * now is refused (never downloaded by core either); any other package passes through untouched.
 */
final class PackageDownloader
{
    /** @var \Closure(): string */
    private \Closure $tempFile;

    /** True while a core update in this request holds the upgrader lock (taken here, released by releaseLock()). */
    private bool $holdsLock = false;

    /** @param (callable(): string)|null $tempFile */
    public function __construct(
        private readonly SiteContext $site,
        private readonly MappingRepository $mappings,
        private readonly UpdateOffer $offers,
        private readonly GitHubClient $github,
        private readonly Lock $lock,
        private readonly InstallContext $context,
        private readonly StatusLog $log,
        ?callable $tempFile = null,
    ) {
        $this->tempFile = null === $tempFile
            ? static fn (): string => (string) wp_tempnam('pc-theme-release.zip')
            : \Closure::fromCallable($tempFile);
    }

    /**
     * @param array<string, mixed> $hookExtra
     */
    public function preDownload(mixed $reply, mixed $package, mixed $upgrader, array $hookExtra = []): mixed
    {
        if (false !== $reply || ! is_string($package)) {
            return $reply;
        }
        $run = $this->match($package, $hookExtra);
        if (null === $run || $run instanceof \WP_Error) {
            return $run ?? $reply;
        }
        if (! wp_is_file_mod_allowed('pc_theme_release_install')) {
            return new \WP_Error('pc_theme_release_file_mods', __('File modifications are disabled on this site (DISALLOW_FILE_MODS).', 'pc-theme-release'));
        }
        // A core update takes the lock and holds it until the run ends, so no rollback or switch can start
        // meanwhile (D3, audit S6). Taken once per request: a bulk run installs several themes under it.
        // The runner's own rollback/switch already holds it, so only the core update takes it.
        if (InstallMode::Update === $run->mode && ! $this->holdsLock) {
            if (! $this->lock->acquire()) {
                return new \WP_Error('pc_theme_release_locked', __('Another theme update or rollback is running. Try again in a minute.', 'pc-theme-release'));
            }
            $this->holdsLock = true;
        }
        $file = ($this->tempFile)();
        try {
            $this->github->downloadZip($run->repo, $run->sha, $file);
        } catch (GitHubError $error) {
            wp_delete_file($file);
            $this->log->add('download_failed', $run->repo->full(), $run->tag, $error->httpStatus);
            if (GitHubErrorKind::TooLarge === $error->kind) {
                /* translators: %d: size limit in megabytes. */
                return new \WP_Error('pc_theme_release_too_large', sprintf(__('The theme package is larger than %d MB, so it was not installed.', 'pc-theme-release'), intdiv(GitHubClient::MAX_ZIP_BYTES, 1024 * 1024)));
            }

            return new \WP_Error('pc_theme_release_download', __('The theme package could not be downloaded from GitHub. See Appearance → Theme Release.', 'pc-theme-release'));
        }
        $this->context->begin($run);

        return $file;
    }

    /**
     * `upgrader_process_complete` (once per single or bulk run) and `shutdown` (failed runs end early):
     * releases the lock only when this request's core update took it, never the rollback runner's.
     */
    public function releaseLock(): void
    {
        if (! $this->holdsLock) {
            return;
        }
        $this->holdsLock = false;
        $this->lock->release();
    }

    /**
     * The run for one of this plugin's own packages; a WP_Error for a package of the plugin's own form
     * that is not offered now (unknown repo or SHA, another theme, frozen theme, no channel, stale update
     * transient), so core never downloads it by itself either (fail closed, audit S3). Null: not ours.
     *
     * @param array<string, mixed> $hookExtra
     */
    private function match(string $package, array $hookExtra): InstallRun|\WP_Error|null
    {
        if (1 !== preg_match(UpdateOffer::PACKAGE_PATTERN, $package, $m)) {
            return null;
        }
        $theme = is_string($hookExtra['theme'] ?? null) ? $hookExtra['theme'] : null;
        // The plugin offers themes only: another updater's plugin zipball is not ours (plan O3).
        if (null === $theme && isset($hookExtra['plugin'])) {
            return null;
        }
        try {
            $repo = RepoName::fromString(rawurldecode($m[1]) . '/' . rawurldecode($m[2]));
        } catch (ValidationError) {
            $repo = null;
        }
        $mapping = null === $repo ? null : $this->mappings->findByRepo($repo);
        // Ours: a repository mapped here, a theme managed here, or an update entry this plugin wrote.
        // Another updater's GitHub zipball for a theme this plugin does not manage passes through (S5).
        $ours = null !== $mapping || (null !== $theme && (null !== $this->mappings->get($theme) || $this->offeredByUs($theme)));
        if (! $ours) {
            return null;
        }
        if (null === $this->site->channel() || null === $repo) {
            return self::notOurs();
        }
        // Core's bulk upgrader (Dashboard → Updates, Themes AJAX) passes only 'theme' (class-theme-upgrader.php:452).
        if (null === $mapping || null === $theme || $theme !== $mapping->stylesheet) {
            return self::notOurs();
        }
        $release = $this->offers->currentState($mapping)->releaseBySha($m[3]);
        if (null === $release) {
            return self::notOurs();
        }
        $mode = $this->context->expectedMode($theme, $release->sha);
        if (null === $mode) {
            // A core update installs only what is offered now: never a frozen theme, never an older tag.
            if ($this->offers->evaluate($mapping)->offer?->sha !== $release->sha) {
                return new \WP_Error('pc_theme_release_not_offered', __('This theme version is not offered any more. Reload the updates screen. Nothing was installed.', 'pc-theme-release'));
            }
            $mode = InstallMode::Update;
        }

        return new InstallRun($mapping->stylesheet, $repo, $release->tag, $release->sha, $mode);
    }

    /** Whether core's current update entry for the theme was written by this plugin (marked). */
    private function offeredByUs(string $theme): bool
    {
        $transient = get_site_transient('update_themes');
        $entry = is_object($transient) && isset($transient->response) && is_array($transient->response) ? ($transient->response[$theme] ?? null) : null;

        return UpdateInjector::isOwnEntry($entry);
    }

    private static function notOurs(): \WP_Error
    {
        return new \WP_Error('pc_theme_release_unknown_package', __('This theme package is not offered on this site now. Reload the updates screen. Nothing was installed.', 'pc-theme-release'));
    }
}
