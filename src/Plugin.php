<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease;

use ProCoders\ThemeRelease\Admin\Actions;
use ProCoders\ThemeRelease\Admin\AdminPage;
use ProCoders\ThemeRelease\Admin\Notices;
use ProCoders\ThemeRelease\Admin\WpResponder;
use ProCoders\ThemeRelease\Config\SettingsRepository;
use ProCoders\ThemeRelease\Config\SiteBinding;
use ProCoders\ThemeRelease\Config\SiteContext;
use ProCoders\ThemeRelease\GitHub\GitHubClient;
use ProCoders\ThemeRelease\GitHub\WpHttpClient;
use ProCoders\ThemeRelease\Health\CronHealth;
use ProCoders\ThemeRelease\Health\HealthReport;
use ProCoders\ThemeRelease\Health\RepoHealth;
use ProCoders\ThemeRelease\Health\SiteHealthTests;
use ProCoders\ThemeRelease\Health\WpCronInspector;
use ProCoders\ThemeRelease\Lifecycle\Scheduler;
use ProCoders\ThemeRelease\Lifecycle\WpScheduler;
use ProCoders\ThemeRelease\Release\Checker;
use ProCoders\ThemeRelease\Release\ReleaseResolver;
use ProCoders\ThemeRelease\Release\StateStore;
use ProCoders\ThemeRelease\Release\StatusLog;
use ProCoders\ThemeRelease\Security\SaltPolicy;
use ProCoders\ThemeRelease\Security\Secrets;
use ProCoders\ThemeRelease\Security\SecretVault;
use ProCoders\ThemeRelease\Support\Keys;
use ProCoders\ThemeRelease\Support\SystemClock;
use ProCoders\ThemeRelease\Support\WpEnvironment;
use ProCoders\ThemeRelease\Support\WpOptionStore;
use ProCoders\ThemeRelease\Support\WpTransientStore;
use ProCoders\ThemeRelease\Theme\MappingRepository;
use ProCoders\ThemeRelease\Theme\WpThemeDirectory;
use ProCoders\ThemeRelease\Update\InstallContext;
use ProCoders\ThemeRelease\Update\InstallRecorder;
use ProCoders\ThemeRelease\Update\PackageDownloader;
use ProCoders\ThemeRelease\Update\RollbackRunner;
use ProCoders\ThemeRelease\Update\SourceGuard;
use ProCoders\ThemeRelease\Update\UpdateInjector;
use ProCoders\ThemeRelease\Update\UpdateOffer;
use ProCoders\ThemeRelease\Update\WpPackageFiles;
use ProCoders\ThemeRelease\Update\WpThemeUpgrader;
use ProCoders\ThemeRelease\Update\WpUpgraderLock;
use ProCoders\ThemeRelease\Webhook\RateLimiter;
use ProCoders\ThemeRelease\Webhook\ReplayGuard;
use ProCoders\ThemeRelease\Webhook\RestRoute;
use ProCoders\ThemeRelease\Webhook\SignatureVerifier;
use ProCoders\ThemeRelease\Webhook\WebhookController;

/**
 * Composition root: builds the object graph once per request and registers every hook
 * with a named method so any of them can be removed.
 */
final class Plugin
{
    private static ?self $instance = null;

    private function __construct(
        public readonly UpdateInjector $injector,
        public readonly PackageDownloader $downloader,
        public readonly SourceGuard $sourceGuard,
        public readonly InstallRecorder $recorder,
        public readonly Checker $checker,
        public readonly RestRoute $restRoute,
        public readonly SiteHealthTests $siteHealth,
        public readonly AdminPage $page,
        public readonly Actions $actions,
        public readonly Notices $notices,
    ) {
    }

    public static function instance(): self
    {
        return self::$instance ??= self::build();
    }

    public static function boot(): void
    {
        $plugin = self::instance();
        self::registerSelfHeal(new WpScheduler());

        add_filter('pre_set_site_transient_update_themes', [$plugin->injector, 'filterTransient'], 20);
        add_filter('http_request_args', [$plugin->injector, 'stripWpOrgRequest'], 10, 2);
        add_filter('auto_update_theme', [$plugin->injector, 'disableAutoUpdate'], 20, 2);
        add_filter('theme_auto_update_setting_html', [$plugin->injector, 'autoUpdateHtml'], 20, 2);
        add_filter('theme_auto_update_setting_template', [$plugin->injector, 'autoUpdateTemplate'], 20);
        add_filter('upgrader_pre_download', [$plugin->downloader, 'preDownload'], 10, 4);
        add_filter('upgrader_source_selection', [$plugin->sourceGuard, 'select'], 10, 4);
        add_filter('upgrader_post_install', [$plugin->recorder, 'afterInstall'], 20, 3);
        add_action(Keys::CRON_HOOK, [$plugin->checker, 'runScheduled']);
        add_action('rest_api_init', [$plugin->restRoute, 'register']);
        add_filter('site_status_tests', [$plugin->siteHealth, 'register']);

        if (is_admin()) {
            add_action('admin_menu', [$plugin->page, 'register']);
            add_action('admin_notices', [$plugin->notices, 'render']);
            $plugin->actions->register();
        }
    }

    /**
     * Schedules the 12-hour check on every request where it is missing, so it does not depend on the
     * activation hook having fired (symlinked installs, activation before vendor/ existed).
     */
    public static function registerSelfHeal(Scheduler $scheduler): void
    {
        add_action('init', [$scheduler, 'schedule']);
    }

    private static function build(): self
    {
        $options = new WpOptionStore();
        $transients = new WpTransientStore();
        $clock = new SystemClock();
        $environment = new WpEnvironment();
        $themes = new WpThemeDirectory();
        $responder = new WpResponder();

        $binding = new SiteBinding($environment);
        $settings = new SettingsRepository($options, $environment, $binding);
        $salts = new SaltPolicy($environment);
        $secrets = new Secrets($options, $environment, new SecretVault($salts));
        $site = new SiteContext($settings, $binding, $secrets);

        $state = new StateStore($options);
        $log = new StatusLog($options, $clock);
        $mappings = new MappingRepository($options, $themes);
        $github = new GitHubClient(new WpHttpClient(), $secrets, $state, $clock);
        $offers = new UpdateOffer($mappings, $state, $themes, $site);
        $injector = new UpdateInjector($offers, $mappings, $site);
        $checker = new Checker($site, $mappings, $github, new ReleaseResolver($github, $transients, $clock), $state, $injector, $log, $clock);

        $context = new InstallContext();
        $lock = new WpUpgraderLock();
        $rollback = new RollbackRunner($mappings, $offers, $context, $lock, new WpThemeUpgrader());
        $health = new HealthReport($site, $secrets, $salts, $state, $clock);
        $cron = new CronHealth($environment, $state, new WpCronInspector(), $clock);

        $webhook = new WebhookController(
            $site,
            $secrets,
            new SignatureVerifier(),
            new RateLimiter($transients, $clock),
            new ReplayGuard($transients),
            $checker,
            $state,
            $log,
            $clock
        );

        return new self(
            $injector,
            new PackageDownloader($site, $mappings, $offers, $github, $lock, $context, $log),
            new SourceGuard($context, $themes, new WpPackageFiles()),
            new InstallRecorder($context, $mappings, $log, $clock),
            $checker,
            new RestRoute($webhook),
            new SiteHealthTests($health, $cron, new RepoHealth($offers)),
            new AdminPage($site, $secrets, $offers, $themes, $rollback, $health, $cron, $log, $responder),
            new Actions($settings, $site, $secrets, $mappings, $state, $offers, $checker, $injector, $log, $clock, $responder),
            new Notices($health),
        );
    }
}
