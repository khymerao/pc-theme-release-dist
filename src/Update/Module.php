<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Update;

use ProCoders\ThemeRelease\Config\SiteContext;
use ProCoders\ThemeRelease\GitHub\GitHubClient;
use ProCoders\ThemeRelease\Release\Checker;
use ProCoders\ThemeRelease\Release\StatusLog;
use ProCoders\ThemeRelease\Release\UpdateTransient;
use ProCoders\ThemeRelease\Status\Offers;
use ProCoders\ThemeRelease\Status\ThemeStatus;
use ProCoders\ThemeRelease\Support\Environment;
use ProCoders\ThemeRelease\Theme\MappingRepository;
use ProCoders\ThemeRelease\Theme\Themes;

/**
 * Feeds managed themes into WordPress core's own theme-update flow, keeps wordpress.org and automatic
 * updates out of it, and supplies the packages core installs (Installer). The plugin screen's rollback
 * and switch go through installs().
 */
final class Module
{
    private const string WPORG_CHECK = 'api.wordpress.org/themes/update-check/';

    /** @var (\Closure(): self)|null Builds this module (and the services it needs) on first use. */
    private static ?\Closure $factory = null;
    private static ?self $instance = null;

    private readonly Installer $installer;
    private readonly InstallFlow $installs;

    public function __construct(
        private readonly SiteContext $site,
        private readonly MappingRepository $mappings,
        private readonly Offers $offers,
        GitHubClient $github,
        Themes $themes,
        StatusLog $log,
        Environment $env,
        Checker $checker,
        CoreUpgrader $core = new WpCoreUpgrader(),
        ?callable $tempFile = null,
    ) {
        $this->installer = new Installer($site, $mappings, $offers, $github, $core, $themes, $log, $env, $checker, $tempFile);
        $this->installs = new InstallFlow($mappings, $offers, $this->installer, $core);
    }

    /**
     * Registers the hooks with named static callbacks; the module is built only when one of them needs it,
     * so a frontend request builds nothing (footprint 1). The `shutdown` lock release is registered by the
     * installer when a download takes the lock (footprint 3).
     *
     * @param \Closure(): self $factory
     */
    public static function hook(\Closure $factory): void
    {
        self::$factory = $factory;
        self::$instance = null;
        add_filter('pre_set_site_transient_update_themes', [self::class, 'onUpdateTransient'], 20);
        add_filter('http_request_args', [self::class, 'onHttpRequestArgs'], 10, 2);
        add_filter('auto_update_theme', [self::class, 'onAutoUpdate'], 20, 2);
        add_filter('theme_auto_update_setting_html', [self::class, 'onAutoUpdateHtml'], 20, 2);
        add_filter('theme_auto_update_setting_template', [self::class, 'onAutoUpdateTemplate'], 20);
        add_filter('upgrader_pre_download', [self::class, 'onPreDownload'], 10, 4);
        add_filter('upgrader_source_selection', [self::class, 'onSourceSelection'], 10, 4);
        add_filter('upgrader_post_install', [self::class, 'onPostInstall'], 20, 3);
        // A core update holds the upgrader lock from the download until the whole run ends (audit S6).
        add_action('upgrader_process_complete', [self::class, 'onProcessComplete'], 10, 0);
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

    public static function onUpdateTransient(mixed $transient): mixed
    {
        return self::instance()->filterTransient($transient);
    }

    /** Looks at the URL first: any request but wordpress.org's theme update check builds nothing. */
    public static function onHttpRequestArgs(mixed $args, mixed $url = ''): mixed
    {
        if (! is_array($args) || ! self::isWpOrgThemeCheck($url)) {
            return $args;
        }

        return self::instance()->stripWpOrgRequest($args, $url);
    }

    public static function onAutoUpdate(mixed $update, mixed $item = null): mixed
    {
        return self::instance()->disableAutoUpdate($update, $item);
    }

    public static function onAutoUpdateHtml(mixed $html, mixed $stylesheet = ''): mixed
    {
        return is_string($html) && is_string($stylesheet) ? self::instance()->autoUpdateHtml($html, $stylesheet) : $html;
    }

    public static function onAutoUpdateTemplate(mixed $template): mixed
    {
        return is_string($template) ? self::instance()->autoUpdateTemplate($template) : $template;
    }

    public static function onPreDownload(mixed $reply, mixed $package, mixed $upgrader = null, mixed $hookExtra = []): mixed
    {
        return self::instance()->installer->preDownload($reply, $package, $upgrader, is_array($hookExtra) ? $hookExtra : []);
    }

    /** Only a package this request's installer supplied is looked at; nothing is built for others. */
    public static function onSourceSelection(mixed $source, mixed $remoteSource = null, mixed $upgrader = null, mixed $hookExtra = []): mixed
    {
        if (null === self::$instance || ! is_array($hookExtra)) {
            return $source;
        }

        return self::$instance->installer->select($source, $remoteSource, $upgrader, $hookExtra);
    }

    public static function onPostInstall(mixed $response, mixed $hookExtra = [], mixed $result = null): mixed
    {
        return null === self::$instance ? $response : self::$instance->installer->afterInstall($response, $hookExtra, $result);
    }

    /** Releases the lock a core update of this request took; nothing to do when nothing was built. */
    public static function onProcessComplete(): void
    {
        self::$instance?->installer->releaseLock();
    }

    /** The rollback and switch installs, for the plugin screen. */
    public function installs(): InstallFlow
    {
        return $this->installs;
    }

    public function installer(): Installer
    {
        return $this->installer;
    }

    /**
     * Filter `pre_set_site_transient_update_themes`: own the entries of managed themes.
     */
    public function filterTransient(mixed $transient): mixed
    {
        if (! $transient instanceof \stdClass) {
            return $transient;
        }
        $response = isset($transient->response) && is_array($transient->response) ? $transient->response : [];
        $noUpdate = isset($transient->no_update) && is_array($transient->no_update) ? $transient->no_update : [];
        $operational = null !== $this->site->channel();
        $statuses = $this->offers->all();
        $repos = array_values(array_map(static fn (ThemeStatus $status): string => $status->mapping->repo->full(), $statuses));

        // An entry this plugin wrote for a theme it no longer manages is withdrawn; other updaters' are kept.
        $keep = static fn (mixed $entry, mixed $stylesheet): bool => isset($statuses[$stylesheet]) || ! UpdateTransient::isOwnEntry($entry, $repos);
        $response = array_filter($response, $keep, ARRAY_FILTER_USE_BOTH);
        $noUpdate = array_filter($noUpdate, $keep, ARRAY_FILTER_USE_BOTH);

        foreach ($statuses as $stylesheet => $status) {
            unset($response[$stylesheet], $noUpdate[$stylesheet]);
            $url = 'https://github.com/' . $status->mapping->repo->full();
            if ($operational && null !== $status->offer) {
                $package = GitHubClient::packageUrl($status->mapping->repo, $status->offer->sha);
                $response[$stylesheet] = UpdateTransient::entry($stylesheet, $status->offer->versionLabel(), $url, $package, true);
                continue;
            }
            $noUpdate[$stylesheet] = UpdateTransient::entry($stylesheet, $status->installedLabel, $url, '', true);
        }
        $transient->response = $response;
        $transient->no_update = $noUpdate;

        return $transient;
    }

    /**
     * Filter `http_request_args`: managed themes are never sent to wordpress.org (no name leak, no collision).
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function stripWpOrgRequest(array $args, string $url): array
    {
        if (! self::isWpOrgThemeCheck($url) || ! is_array($args['body'] ?? null) || ! is_string($args['body']['themes'] ?? null)) {
            return $args;
        }
        $request = json_decode($args['body']['themes'], true);
        if (! is_array($request)) {
            return $args;
        }
        foreach (array_keys($this->mappings->all()) as $stylesheet) {
            if (is_array($request['themes'] ?? null)) {
                unset($request['themes'][$stylesheet]);
            }
            if (($request['active'] ?? null) === $stylesheet) {
                $request['active'] = '';
            }
        }
        $args['body']['themes'] = (string) wp_json_encode($request);

        return $args;
    }

    /**
     * wordpress.org's theme update check, by the parsed host and path (review F3), never by a substring
     * of the whole URL: https://api.wordpress.org/themes/update-check/… only.
     */
    public static function isWpOrgThemeCheck(mixed $url): bool
    {
        if (! is_string($url)) {
            return false;
        }
        $parts = parse_url($url);
        [$host, $path] = explode('/', self::WPORG_CHECK, 2);

        return is_array($parts)
            && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && $host === strtolower($parts['host'] ?? '')
            && ! isset($parts['user'])
            && ! isset($parts['port'])
            && str_starts_with($parts['path'] ?? '', '/' . $path);
    }

    /** Filter `auto_update_theme`: managed themes are updated manually only. */
    public function disableAutoUpdate(mixed $update, mixed $item): mixed
    {
        $stylesheet = is_object($item) && isset($item->theme) && is_string($item->theme) ? $item->theme : '';

        return null !== $this->mappings->get($stylesheet) ? false : $update;
    }

    /** Filter `theme_auto_update_setting_html` (list table and single theme screen). */
    public function autoUpdateHtml(string $html, string $stylesheet): string
    {
        return null !== $this->mappings->get($stylesheet) ? esc_html(self::managedText()) : $html;
    }

    /** Filter `theme_auto_update_setting_template` (Themes grid modal, Underscore template). */
    public function autoUpdateTemplate(string $template): string
    {
        $managed = array_values(array_filter(
            array_map('strval', array_keys($this->mappings->all())),
            static fn (string $stylesheet): bool => 1 === preg_match('/^[A-Za-z0-9._-]+$/', $stylesheet),
        ));
        if ([] === $managed) {
            return $template;
        }
        $list = (string) wp_json_encode($managed, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return '<# if ( ' . $list . '.indexOf( data.id ) !== -1 ) { #><p>' . esc_html(self::managedText()) . '</p><# } else { #>'
            . $template . '<# } #>';
    }

    private static function managedText(): string
    {
        return __('Managed by ProCoders Theme Release (manual updates only)', 'pc-theme-release');
    }
}
