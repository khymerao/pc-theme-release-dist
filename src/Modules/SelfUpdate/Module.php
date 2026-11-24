<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Modules\SelfUpdate;

use ProCoders\ThemeRelease\Domain\SelfUpdate\Lookup;
use ProCoders\ThemeRelease\Domain\SelfUpdate\Source;

/**
 * The plugin's own updates through core's plugin update flow (spec C1–C8): the `Update URI` header names
 * github.com, so core asks `update_plugins_github.com` for this plugin; the answer offers the tag archive of the
 * dist repository. "View details" is answered here (never wordpress.org), and the unpacked archive is checked and
 * renamed to the installed folder before core replaces anything. Registered before the multisite inert gate, so a
 * check run from a subsite keeps the offer in the network-wide list (C10), as long as the plugin is network-active or
 * active on the main site, where Network Admin installs it (review R1). Builds nothing until a hook needs it.
 */
final class Module
{
    /** The slug core uses for "View details" (plugin-install.php?tab=plugin-information&plugin=…). */
    public const string SLUG = 'pc-theme-release';
    private const string ERROR = 'pc_theme_release_self_update_package';

    /** @var (\Closure(): self)|null Builds this module (and the services it needs) on first use. */
    private static ?\Closure $factory = null;
    private static ?self $instance = null;

    public function __construct(private readonly Lookup $lookup)
    {
    }

    /**
     * Registers the hooks with named static callbacks; each looks at its arguments before building anything, so
     * another plugin's update check, another slug's details and another package's install build nothing.
     *
     * @param \Closure(): self $factory
     */
    public static function hook(\Closure $factory): void
    {
        self::$factory = $factory;
        self::$instance = null;
        add_filter('update_plugins_github.com', [self::class, 'onUpdateCheck'], 10, 4);
        add_filter('plugins_api', [self::class, 'onPluginsApi'], 20, 3);
        add_filter('upgrader_source_selection', [self::class, 'onSourceSelection'], 10, 4);
    }

    /** The module, built on the first hook that needs it. */
    private static function instance(): self
    {
        if (null === self::$instance) {
            $factory = self::$factory ?? throw new \LogicException('Module not hooked.');
            self::$instance = $factory();
        }

        return self::$instance;
    }

    /** Whether anything in this request needed the module (frontend requests never do). */
    public static function built(): bool
    {
        return null !== self::$instance;
    }

    /** This plugin as WordPress knows it ("<installed folder>/pc-theme-release.php"). */
    public static function basename(): string
    {
        return plugin_basename(self::mainFile());
    }

    private static function mainFile(): string
    {
        return dirname(__DIR__, 3) . '/' . Source::MAIN_FILE;
    }

    /**
     * Filter `update_plugins_github.com` (core's wp_update_plugins(), once per plugin whose Update URI host is
     * github.com): only this plugin's call is answered; any other plugin's passes through untouched.
     */
    public static function onUpdateCheck(mixed $update, mixed $pluginData = [], mixed $pluginFile = '', mixed $locales = []): mixed
    {
        if (! is_string($pluginFile) || self::basename() !== $pluginFile) {
            return $update;
        }
        // Network Admin installs the update without the plugin loaded unless it is network-active or active on the
        // main site: then nothing renames the unpacked folder, so nothing is offered (review R1).
        if (! Lookup::installable($pluginFile)) {
            return $update;
        }

        return self::instance()->entry($update, self::forced());
    }

    /**
     * "Check again" on Dashboard → Updates: core runs its plugin check from `load-update-core.php`; with
     * force-check the plugin's own freshness window and back-off are skipped too (60 s floor in Lookup).
     */
    private static function forced(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag of core's own "Check again" link.
        return doing_action('load-update-core.php') && ! empty($_GET['force-check']) && current_user_can('update_plugins');
    }

    /**
     * The update entry for core: returned even when it is not newer, so core files it under no_update and shows its
     * auto-update toggle (C7). With no good lookup ever, $update is returned untouched (nothing offered).
     */
    public function entry(mixed $update, bool $forced = false): mixed
    {
        $release = $this->lookup->latest($forced);
        if (null === $release) {
            return $update;
        }
        $source = $this->lookup->source();

        return (object) [
            'slug' => self::SLUG,
            'plugin' => self::basename(),
            'version' => $release['version'],
            'new_version' => $release['version'],
            'package' => $source->packageUrl($release['version']),
            'url' => $source->homepage(),
            'requires_php' => $release['requires_php'],
            'requires' => $release['requires'],
        ];
    }

    /** Filter `plugins_api`: "View details" of this plugin is answered here, without any request (C8). */
    public static function onPluginsApi(mixed $result, mixed $action = '', mixed $args = null): mixed
    {
        if (false !== $result || 'plugin_information' !== $action || ! is_object($args) || ! isset($args->slug) || self::SLUG !== $args->slug) {
            return $result;
        }

        return self::instance()->information();
    }

    /** The plugin information modal: the last known release, or the installed version before any check. */
    public function information(): \stdClass
    {
        $source = $this->lookup->source();
        $release = $this->lookup->known();
        $installed = get_file_data(self::mainFile(), ['version' => 'Version', 'requires_php' => 'Requires PHP', 'requires' => 'Requires at least']);
        $info = [
            'name' => 'ProCoders Theme Release',
            'slug' => self::SLUG,
            // Not a wordpress.org plugin: core then prints no "WordPress.org Plugin Page" link (review R4).
            'external' => true,
            'version' => $release['version'] ?? (string) ($installed['version'] ?? ''),
            'author' => 'ProCoders',
            'homepage' => $source->homepage(),
            'requires' => $release['requires'] ?? (string) ($installed['requires'] ?? ''),
            'requires_php' => $release['requires_php'] ?? (string) ($installed['requires_php'] ?? ''),
            'sections' => [
                'changes' => '<p>' . esc_html__('Every release is a tag of the distribution repository:', 'pc-theme-release')
                    . ' <a href="' . esc_url($source->tagsUrl()) . '" target="_blank" rel="noopener noreferrer">' . esc_html($source->repo->full()) . '</a></p>',
            ],
        ];
        // "Install Now" in the modal is core's plugin install, which passes no hook_extra['plugin']: the package is not
        // renamed then, so it would land beside a renamed folder as a second copy (review R5). And where the install
        // check is not loaded, no install is offered at all (R1).
        $basename = self::basename();
        if (null !== $release && self::SLUG === dirname($basename) && Lookup::installable($basename)) {
            $info['download_link'] = $source->packageUrl($release['version']);
        }

        return (object) $info;
    }

    /**
     * Filter `upgrader_source_selection`: only an update of this plugin (core's single and bulk upgraders pass
     * `hook_extra['plugin']`) is looked at; anything else passes through and builds nothing.
     */
    public static function onSourceSelection(mixed $source, mixed $remoteSource = null, mixed $upgrader = null, mixed $hookExtra = []): mixed
    {
        if (! is_array($hookExtra) || ! isset($hookExtra['plugin']) || self::basename() !== $hookExtra['plugin']) {
            return $source;
        }

        return self::instance()->checkPackage($source, $remoteSource);
    }

    /**
     * The install integrity check (C5): the update core is installing must be the archive of its offered version in
     * the configured repository, and the unpacked folder must hold the main file with exactly that Version. Then the
     * folder ("pc-theme-release-dist-1.7.0") is renamed to the installed folder, which core installs into.
     * Anything else is refused before core deletes the installed copy.
     */
    public function checkPackage(mixed $source, mixed $remoteSource): mixed
    {
        if (! is_string($source) || ! is_string($remoteSource)) {
            return $source;
        }
        $offered = self::offered();
        if (null === $offered || $this->lookup->source()->packageUrl($offered['version']) !== $offered['package']) {
            return self::refuse(__('This ProCoders Theme Release update is not the one offered from its distribution repository. Nothing was replaced. Check for updates again.', 'pc-theme-release'));
        }
        $dir = rtrim($source, '/');
        $root = rtrim($remoteSource, '/');
        $noMain = __('The ProCoders Theme Release package has no pc-theme-release.php in its top-level folder. Nothing was replaced.', 'pc-theme-release');
        if (dirname($dir) !== $root) {
            return self::refuse($noMain);
        }
        global $wp_filesystem;
        $filesystem = $wp_filesystem instanceof \WP_Filesystem_Base ? $wp_filesystem : null;
        // $source is a path of $wp_filesystem (an FTP or SSH root); PHP's own file functions need the local one, as
        // core's check_package() translates it (review R2). move() and the return value keep the remote paths.
        $local = self::localPath($dir, $filesystem);
        $main = $local . '/' . Source::MAIN_FILE;
        // The folder is there (the filesystem sees it) but PHP cannot read it: say so, not "no pc-theme-release.php".
        $seen = is_dir($local) || (! is_link($local) && null !== $filesystem && $filesystem->is_dir($dir));
        if ($seen && ! is_readable($local)) {
            return self::refuse(__('The ProCoders Theme Release package folder could not be read. Nothing was replaced.', 'pc-theme-release'));
        }
        // A symlinked folder or main file could point outside the package: never followed.
        if (is_link($local) || is_link($main) || ! is_dir($local) || ! is_file($main)) {
            return self::refuse($noMain);
        }
        if (! is_readable($main)) {
            return self::refuse(__('The ProCoders Theme Release package folder could not be read. Nothing was replaced.', 'pc-theme-release'));
        }
        $headers = get_file_data($main, ['version' => 'Version', 'requires_php' => 'Requires PHP', 'requires' => 'Requires at least']);
        if (($headers['version'] ?? '') !== $offered['version']) {
            /* translators: %s: offered version. */
            return self::refuse(sprintf(__('The ProCoders Theme Release package is not version %s. Nothing was replaced.', 'pc-theme-release'), $offered['version']));
        }
        // Core's single-plugin upgrader checks no requirement (only the bulk one does): an update this site cannot run
        // is refused here, before the working copy is deleted.
        $php = (string) ($headers['requires_php'] ?? '');
        $wp = (string) ($headers['requires'] ?? '');
        if (! is_php_version_compatible($php)) {
            /* translators: 1: version, 2: required PHP version. */
            return self::refuse(sprintf(__('ProCoders Theme Release %1$s needs PHP %2$s or newer. Nothing was replaced.', 'pc-theme-release'), $offered['version'], $php));
        }
        if (! is_wp_version_compatible($wp)) {
            /* translators: 1: version, 2: required WordPress version. */
            return self::refuse(sprintf(__('ProCoders Theme Release %1$s needs WordPress %2$s or newer. Nothing was replaced.', 'pc-theme-release'), $offered['version'], $wp));
        }
        $folder = dirname(self::basename());
        $target = $root . '/' . $folder;
        if ('.' === $folder || '' === $folder) {
            return self::refuse(__('The installed ProCoders Theme Release folder is unknown. Nothing was replaced.', 'pc-theme-release'));
        }
        if ($dir === $target) {
            return $target . '/';
        }
        if (null === $filesystem || ! $filesystem->move($dir, $target, true)) {
            return self::refuse(__('The ProCoders Theme Release package folder could not be renamed. Nothing was replaced.', 'pc-theme-release'));
        }

        return $target . '/';
    }

    /** A path of the upgrader's filesystem as a local path: core's own translation (class-plugin-upgrader.php). */
    private static function localPath(string $path, ?\WP_Filesystem_Base $filesystem): string
    {
        $contentDir = null === $filesystem ? false : $filesystem->wp_content_dir();
        // Unknown: the path is used as is (the checks then refuse what PHP cannot read).
        if (! is_string($contentDir) || '' === $contentDir) {
            return $path;
        }

        return str_replace($contentDir, trailingslashit(WP_CONTENT_DIR), $path);
    }

    /**
     * The entry core is installing now (`update_plugins` → response): its version and package.
     *
     * @return array{version: string, package: string}|null
     */
    private static function offered(): ?array
    {
        $transient = get_site_transient('update_plugins');
        $response = is_object($transient) && isset($transient->response) && is_array($transient->response) ? $transient->response : [];
        $entry = $response[self::basename()] ?? null;
        if (is_array($entry)) {
            $entry = (object) $entry;
        }
        if (! is_object($entry) || ! isset($entry->new_version, $entry->package) || ! is_string($entry->new_version) || ! is_string($entry->package)) {
            return null;
        }
        if (! Lookup::offerable($entry->new_version)) {
            return null;
        }

        return ['version' => $entry->new_version, 'package' => $entry->package];
    }

    private static function refuse(string $message): \WP_Error
    {
        return new \WP_Error(self::ERROR, $message);
    }
}
