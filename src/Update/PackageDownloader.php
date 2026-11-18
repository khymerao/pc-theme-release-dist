<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Update;

use ProCoders\ThemeRelease\Config\SiteContext;
use ProCoders\ThemeRelease\GitHub\GitHubClient;
use ProCoders\ThemeRelease\GitHub\GitHubError;
use ProCoders\ThemeRelease\GitHub\RepoName;
use ProCoders\ThemeRelease\Release\StatusLog;
use ProCoders\ThemeRelease\Support\ValidationError;
use ProCoders\ThemeRelease\Theme\MappingRepository;

/**
 * Filter `upgrader_pre_download`: supplies the zip for packages the plugin itself offered, so the
 * token never reaches core's generic downloader. Any other package passes through untouched.
 */
final class PackageDownloader
{
    private const string PACKAGE_PATTERN = '#^https://api\.github\.com/repos/([^/]+)/([^/]+)/zipball/([0-9a-f]{40})$#';

    /** @var \Closure(): string */
    private \Closure $tempFile;

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
        // A plugin rollback or switch in another request holds the lock: a core update must wait (D3).
        // The runner's own rollback/switch already holds it, so only the core update checks.
        if (InstallMode::Update === $run->mode && ! $this->lockIsFree()) {
            return new \WP_Error('pc_theme_release_locked', __('Another theme update or rollback is running. Try again in a minute.', 'pc-theme-release'));
        }
        $file = ($this->tempFile)();
        try {
            $this->github->downloadZip($run->repo, $run->sha, $file);
        } catch (GitHubError $error) {
            wp_delete_file($file);
            $this->log->add('download_failed', $run->repo->full(), $run->tag, $error->httpStatus);

            return new \WP_Error('pc_theme_release_download', __('The theme package could not be downloaded from GitHub. See Appearance → Theme Release.', 'pc-theme-release'));
        }
        $this->context->begin($run);

        return $file;
    }

    private function lockIsFree(): bool
    {
        if (! $this->lock->acquire()) {
            return false;
        }
        $this->lock->release();

        return true;
    }

    /**
     * The run for one of this plugin's own packages; a WP_Error for an own package that is not offered
     * now (frozen theme, stale update transient), so core never downloads it by itself either.
     *
     * @param array<string, mixed> $hookExtra
     */
    private function match(string $package, array $hookExtra): InstallRun|\WP_Error|null
    {
        if (1 !== preg_match(self::PACKAGE_PATTERN, $package, $m) || null === $this->site->channel()) {
            return null;
        }
        try {
            $repo = RepoName::fromString(rawurldecode($m[1]) . '/' . rawurldecode($m[2]));
        } catch (ValidationError) {
            return null;
        }
        $mapping = $this->mappings->findByRepo($repo);
        $theme = is_string($hookExtra['theme'] ?? null) ? $hookExtra['theme'] : null;
        // Core's bulk upgrader (Dashboard → Updates, Themes AJAX) passes only 'theme' (class-theme-upgrader.php:452).
        if (null === $mapping || null === $theme || $theme !== $mapping->stylesheet) {
            return null;
        }
        $release = $this->offers->currentState($mapping)->releaseBySha($m[3]);
        if (null === $release) {
            return null;
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
}
