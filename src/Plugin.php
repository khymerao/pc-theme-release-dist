<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease;

use ProCoders\ThemeRelease\Core\GitHub\TlsPolicy;
use ProCoders\ThemeRelease\Modules\Admin\Module as AdminModule;
use ProCoders\ThemeRelease\Modules\Health\Module as HealthModule;
use ProCoders\ThemeRelease\Modules\Lifecycle\Module as LifecycleModule;
use ProCoders\ThemeRelease\Modules\Update\Module as UpdateModule;
use ProCoders\ThemeRelease\Modules\Webhook\Module as WebhookModule;

/**
 * Composition root (`plugins_loaded`): each module registers its hooks with named static callbacks and
 * is built, with the shared services it needs, only when one of them fires. A frontend request builds
 * nothing and reads no option (footprint 1). Modules talk only through the shared services.
 */
final class Plugin
{
    public static function boot(): void
    {
        if (LifecycleModule::inert()) {
            LifecycleModule::registerInert();
            return;
        }
        $s = new Services();
        TlsPolicy::register();
        LifecycleModule::hook(static fn (): LifecycleModule => new LifecycleModule($s->checker()));
        UpdateModule::hook(static fn (): UpdateModule => $s->update());
        WebhookModule::hook(static fn (): WebhookModule => new WebhookModule($s->site(), $s->secrets(), $s->checker(), $s->state(), $s->log(), $s->storage(), $s->env(), $s->mappings()));
        HealthModule::hook(static fn (): HealthModule => new HealthModule($s->health()));
        if (is_admin()) {
            AdminModule::hook(static fn (): AdminModule => new AdminModule($s->site(), $s->secrets(), $s->offers(), $s->themes(), $s->update()->installs(), $s->health(), $s->log(), $s->mappings(), $s->state(), $s->checker(), $s->env()));
        }
    }
}
