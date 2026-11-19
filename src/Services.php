<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease;

use ProCoders\ThemeRelease\Config\SiteContext;
use ProCoders\ThemeRelease\GitHub\GitHubClient;
use ProCoders\ThemeRelease\GitHub\WpHttpClient;
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

/**
 * The shared services of one request, each built on first use and then shared by every module, so a
 * request that needs none of them (a frontend page view) builds none (footprint 1).
 */
final class Services
{
    private ?Storage $storage = null;
    private ?Environment $env = null;
    private ?SecretVault $vault = null;
    private ?Secrets $secrets = null;
    private ?SiteContext $site = null;
    private ?Themes $themes = null;
    private ?MappingRepository $mappings = null;
    private ?StateStore $state = null;
    private ?StatusLog $log = null;
    private ?GitHubClient $github = null;
    private ?Offers $offers = null;
    private ?Checker $checker = null;
    private ?Health $health = null;
    private ?UpdateModule $update = null;

    public function storage(): Storage
    {
        return $this->storage ??= new Storage();
    }

    public function env(): Environment
    {
        return $this->env ??= new Environment();
    }

    public function vault(): SecretVault
    {
        return $this->vault ??= new SecretVault($this->env());
    }

    public function secrets(): Secrets
    {
        return $this->secrets ??= new Secrets($this->storage(), $this->env(), $this->vault());
    }

    public function site(): SiteContext
    {
        return $this->site ??= new SiteContext($this->storage(), $this->env(), $this->secrets());
    }

    public function themes(): Themes
    {
        return $this->themes ??= new Themes();
    }

    public function mappings(): MappingRepository
    {
        return $this->mappings ??= new MappingRepository($this->storage(), $this->themes());
    }

    public function state(): StateStore
    {
        return $this->state ??= new StateStore($this->storage());
    }

    public function log(): StatusLog
    {
        return $this->log ??= new StatusLog($this->storage(), $this->env());
    }

    public function github(): GitHubClient
    {
        return $this->github ??= new GitHubClient(new WpHttpClient(), $this->secrets(), $this->storage(), $this->env());
    }

    public function offers(): Offers
    {
        return $this->offers ??= new Offers($this->mappings(), $this->state(), $this->themes(), $this->site());
    }

    public function checker(): Checker
    {
        return $this->checker ??= new Checker($this->site(), $this->mappings(), $this->github(), $this->state(), $this->log(), $this->storage(), $this->env());
    }

    public function health(): Health
    {
        return $this->health ??= new Health($this->site(), $this->secrets(), $this->vault(), $this->state(), $this->env(), $this->offers());
    }

    /** The Update module is a service too: the plugin screen's rollback and switch go through its installs(). */
    public function update(): UpdateModule
    {
        return $this->update ??= new UpdateModule($this->site(), $this->mappings(), $this->offers(), $this->github(), $this->themes(), $this->log(), $this->env(), $this->checker());
    }
}
