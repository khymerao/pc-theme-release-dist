<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease;

use ProCoders\ThemeRelease\Admin\Module as AdminModule;
use ProCoders\ThemeRelease\Config\SiteContext;
use ProCoders\ThemeRelease\GitHub\GitHubClient;
use ProCoders\ThemeRelease\GitHub\WpHttpClient;
use ProCoders\ThemeRelease\Health\Module as HealthModule;
use ProCoders\ThemeRelease\Lifecycle\Module as LifecycleModule;
use ProCoders\ThemeRelease\Release\Checker;
use ProCoders\ThemeRelease\Release\StateStore;
use ProCoders\ThemeRelease\Release\StatusLog;
use ProCoders\ThemeRelease\Security\Secrets;
use ProCoders\ThemeRelease\Security\SecretVault;
use ProCoders\ThemeRelease\Status\Health;
use ProCoders\ThemeRelease\Status\Offers;
use ProCoders\ThemeRelease\Support\Environment;
use ProCoders\ThemeRelease\Support\Storage;
use ProCoders\ThemeRelease\Theme\MappingRepository;
use ProCoders\ThemeRelease\Theme\Themes;
use ProCoders\ThemeRelease\Update\Module as UpdateModule;
use ProCoders\ThemeRelease\Webhook\Module as WebhookModule;

/**
 * Composition root (`plugins_loaded`): builds the shared services once per request and lets each module
 * register its own hooks. Modules talk only through these services.
 */
final class Plugin
{
    public static function boot(): void
    {
        if (LifecycleModule::inert()) {
            LifecycleModule::registerInert();
            return;
        }
        $storage = new Storage();
        $env = new Environment();
        $vault = new SecretVault($env);
        $secrets = new Secrets($storage, $env, $vault);
        $site = new SiteContext($storage, $env, $secrets);
        $themes = new Themes();
        $mappings = new MappingRepository($storage, $themes);
        $state = new StateStore($storage);
        $log = new StatusLog($storage, $env);
        $github = new GitHubClient(new WpHttpClient(), $secrets, $storage, $env);
        $offers = new Offers($mappings, $state, $themes, $site);
        $checker = new Checker($site, $mappings, $github, $state, $log, $storage, $env);
        $health = new Health($site, $secrets, $vault, $state, $env, $offers);

        $update = new UpdateModule($site, $mappings, $offers, $github, $themes, $log, $env);
        (new LifecycleModule($checker))->register();
        $update->register();
        (new WebhookModule($site, $secrets, $checker, $state, $log, $storage, $env))->register();
        (new HealthModule($health))->register();
        if (is_admin()) {
            (new AdminModule($site, $secrets, $offers, $themes, $update->installs(), $health, $log, $mappings, $state, $checker, $env))->register();
        }
    }
}
