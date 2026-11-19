<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Update;

use ProCoders\ThemeRelease\Config\SiteContext;
use ProCoders\ThemeRelease\GitHub\GitHubClient;
use ProCoders\ThemeRelease\GitHub\GitHubError;
use ProCoders\ThemeRelease\GitHub\GitHubErrorKind;
use ProCoders\ThemeRelease\GitHub\RepoName;
use ProCoders\ThemeRelease\Release\StatusLog;
use ProCoders\ThemeRelease\Release\UpdateTransient;
use ProCoders\ThemeRelease\Status\Offers;
use ProCoders\ThemeRelease\Support\Environment;
use ProCoders\ThemeRelease\Support\ValidationError;
use ProCoders\ThemeRelease\Theme\InstallRecord;
use ProCoders\ThemeRelease\Theme\MappingRepository;
use ProCoders\ThemeRelease\Theme\Themes;

/**
 * The plugin's part of core's upgrader run, one package at a time (keyed by stylesheet because core's
 * bulk upgrader runs several themes in one request):
 *
 * - `upgrader_pre_download` supplies the zip for packages the plugin itself offered, so the token never
 *   reaches core's generic downloader. A package of the plugin's own form that is not offered now is
 *   refused (never downloaded by core either); any other package passes through untouched.
 * - `upgrader_source_selection` checks the unpacked package (spec call 3) and renames GitHub's
 *   "owner-repo-sha" folder to the theme's stylesheet.
 * - `upgrader_post_install` (fires per theme, and only after the files were installed) writes the
 *   install record. A failed install never reaches it.
 *
 * @phpstan-type Run array{stylesheet: string, repo: RepoName, tag: string, sha: string, mode: InstallMode, packageVersion: string}
 */
final class Installer
{
    /** @var \Closure(): string */
    private \Closure $tempFile;

    /** True while a core update in this request holds the upgrader lock (taken here, released by releaseLock()). */
    private bool $holdsLock = false;

    /** @var array<string, Run> stylesheet → the package this request supplied */
    private array $runs = [];

    /** @var array<string, array{sha: string, mode: InstallMode}> stylesheet → what InstallFlow installs itself */
    private array $expected = [];

    /** @param (callable(): string)|null $tempFile */
    public function __construct(
        private readonly SiteContext $site,
        private readonly MappingRepository $mappings,
        private readonly Offers $offers,
        private readonly GitHubClient $github,
        private readonly CoreUpgrader $core,
        private readonly Themes $themes,
        private readonly StatusLog $log,
        private readonly Environment $env,
        ?callable $tempFile = null,
    ) {
        $this->tempFile = null === $tempFile
            ? static fn (): string => (string) wp_tempnam('pc-theme-release.zip')
            : \Closure::fromCallable($tempFile);
    }

    /** InstallFlow is about to install this SHA itself (rollback or channel switch), holding the lock. */
    public function expect(string $stylesheet, string $sha, InstallMode $mode): void
    {
        $this->expected[$stylesheet] = ['sha' => $sha, 'mode' => $mode];
    }

    public function clearExpected(string $stylesheet): void
    {
        unset($this->expected[$stylesheet]);
    }

    /**
     * Filter `upgrader_pre_download`.
     *
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
        // InstallFlow's own rollback/switch already holds it, so only the core update takes it.
        if (InstallMode::Update === $run['mode'] && ! $this->holdsLock) {
            if (! $this->core->acquireLock()) {
                return new \WP_Error('pc_theme_release_locked', __('Another theme update or rollback is running. Try again in a minute.', 'pc-theme-release'));
            }
            $this->holdsLock = true;
        }
        $file = ($this->tempFile)();
        try {
            $this->github->downloadZip($run['repo'], $run['sha'], $file);
        } catch (GitHubError $error) {
            wp_delete_file($file);
            $this->log->add('download_failed', $run['repo']->full(), $run['tag'], $error->httpStatus);
            if (GitHubErrorKind::TooLarge === $error->kind) {
                /* translators: %d: size limit in megabytes. */
                return new \WP_Error('pc_theme_release_too_large', sprintf(__('The theme package is larger than %d MB, so it was not installed.', 'pc-theme-release'), intdiv(GitHubClient::MAX_ZIP_BYTES, 1024 * 1024)));
            }

            return new \WP_Error('pc_theme_release_download', __('The theme package could not be downloaded from GitHub. See Appearance → Theme Release.', 'pc-theme-release'));
        }
        $this->runs[$run['stylesheet']] = $run;

        return $file;
    }

    /**
     * `upgrader_process_complete` (once per single or bulk run) and `shutdown` (failed runs end early):
     * releases the lock only when this request's core update took it, never InstallFlow's.
     */
    public function releaseLock(): void
    {
        if (! $this->holdsLock) {
            return;
        }
        $this->holdsLock = false;
        $this->core->releaseLock();
    }

    /**
     * Filter `upgrader_source_selection`: light integrity check of a package the plugin supplied.
     *
     * @param array<string, mixed> $hookExtra
     */
    public function select(mixed $source, mixed $remoteSource, mixed $upgrader = null, array $hookExtra = []): mixed
    {
        $stylesheet = is_string($hookExtra['theme'] ?? null) ? $hookExtra['theme'] : '';
        if (! isset($this->runs[$stylesheet]) || ! is_string($source) || ! is_string($remoteSource)) {
            return $source;
        }
        $root = rtrim($remoteSource, '/');
        $entries = $this->core->children($root);
        if (1 !== count($entries) || ! $this->core->isDir($root . '/' . $entries[0])) {
            return self::refusePackage(__('The package must contain exactly one top-level folder.', 'pc-theme-release'));
        }
        $folder = $root . '/' . $entries[0];
        if (rtrim($source, '/') !== $folder || ! $this->core->isFile($folder . '/style.css')) {
            return self::refusePackage(__('The package has no style.css in its top-level folder.', 'pc-theme-release'));
        }
        $headers = $this->core->themeHeaders($folder . '/style.css');
        if (! $this->identifies($headers, $stylesheet)) {
            return self::refusePackage(__('The package is not the mapped theme (Theme Name and Text Domain do not match).', 'pc-theme-release'));
        }
        $this->runs[$stylesheet]['packageVersion'] = $headers['version'];

        $target = $root . '/' . $stylesheet;
        if ($folder !== $target && ! $this->core->move($folder, $target)) {
            return self::refusePackage(__('The package folder could not be renamed.', 'pc-theme-release'));
        }

        return $target . '/';
    }

    /** Filter `upgrader_post_install`: records what was installed (a rollback also freezes). */
    public function afterInstall(mixed $response, mixed $hookExtra = [], mixed $result = null): mixed
    {
        $stylesheet = is_array($hookExtra) && is_string($hookExtra['theme'] ?? null) ? $hookExtra['theme'] : '';
        $run = $this->runs[$stylesheet] ?? null;
        unset($this->runs[$stylesheet]);
        if (null === $run) {
            return $response;
        }
        if (is_wp_error($response) || false === $response) {
            $this->log->add('install_failed', $run['repo']->full(), $run['tag']);
            return $response;
        }
        $mismatch = '' !== $run['packageVersion'] && ltrim($run['tag'], 'vV') !== $run['packageVersion'];
        $this->mappings->recordInstall(
            $run['stylesheet'],
            new InstallRecord($run['tag'], $run['sha'], $this->env->now(), $mismatch),
            InstallMode::Rollback === $run['mode'],
        );
        $this->clearExpected($run['stylesheet']);
        $event = match ($run['mode']) {
            InstallMode::Rollback => 'rolled_back',
            InstallMode::Switch => 'switched',
            default => 'updated',
        };
        $this->log->add($event, $run['repo']->full(), $run['tag']);

        return $response;
    }

    /**
     * The run for one of this plugin's own packages; a WP_Error for a package of the plugin's own form
     * that is not offered now (unknown repo or SHA, another theme, frozen theme, no channel, stale update
     * transient), so core never downloads it by itself either (fail closed, audit S3). Null: not ours.
     *
     * @param array<string, mixed> $hookExtra
     * @return Run|\WP_Error|null
     */
    private function match(string $package, array $hookExtra): array|\WP_Error|null
    {
        if (1 !== preg_match(GitHubClient::PACKAGE_PATTERN, $package, $m)) {
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
        $ours = null !== $mapping || (null !== $theme && (null !== $this->mappings->get($theme) || self::offeredByUs($theme)));
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
        $mode = $this->expectedMode($theme, $release->sha);
        if (null === $mode) {
            // A core update installs only what is offered now: never a frozen theme, never an older tag.
            if ($this->offers->evaluate($mapping)->offer?->sha !== $release->sha) {
                return new \WP_Error('pc_theme_release_not_offered', __('This theme version is not offered any more. Reload the updates screen. Nothing was installed.', 'pc-theme-release'));
            }
            $mode = InstallMode::Update;
        }

        return ['stylesheet' => $mapping->stylesheet, 'repo' => $repo, 'tag' => $release->tag, 'sha' => $release->sha, 'mode' => $mode, 'packageVersion' => ''];
    }

    /** The mode InstallFlow announced for exactly this SHA, or null (a core update). */
    private function expectedMode(string $stylesheet, string $sha): ?InstallMode
    {
        $expected = $this->expected[$stylesheet] ?? null;

        return null !== $expected && $expected['sha'] === $sha ? $expected['mode'] : null;
    }

    /** Whether core's current update entry for the theme was written by this plugin (marked). */
    private static function offeredByUs(string $theme): bool
    {
        $transient = get_site_transient('update_themes');
        $entry = is_object($transient) && isset($transient->response) && is_array($transient->response) ? ($transient->response[$theme] ?? null) : null;

        return UpdateTransient::isOwnEntry($entry);
    }

    /** @param array{name: string, text_domain: string, version: string} $headers */
    private function identifies(array $headers, string $stylesheet): bool
    {
        $domain = $this->themes->textDomain($stylesheet);
        $name = $this->themes->name($stylesheet);

        return ('' !== $domain && $headers['text_domain'] === $domain)
            || ('' !== $name && $headers['name'] === $name);
    }

    private static function notOurs(): \WP_Error
    {
        return new \WP_Error('pc_theme_release_unknown_package', __('This theme package is not offered on this site now. Reload the updates screen. Nothing was installed.', 'pc-theme-release'));
    }

    private static function refusePackage(string $message): \WP_Error
    {
        return new \WP_Error('pc_theme_release_package', $message);
    }
}
