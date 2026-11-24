<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Modules\Lifecycle;

use ProCoders\ThemeRelease\Core\GitHub\GitHubClient;
use ProCoders\ThemeRelease\Core\Support\Keys;
use ProCoders\ThemeRelease\Core\Support\Storage;
use ProCoders\ThemeRelease\Domain\Release\Checker;
use ProCoders\ThemeRelease\Domain\Release\UpdateTransient;
use ProCoders\ThemeRelease\Domain\SelfUpdate\Lookup;
use ProCoders\ThemeRelease\Domain\SelfUpdate\Source;

/**
 * Activation, deactivation and uninstall (spec call 12), the 12-hour check's schedule, and the inert
 * mode of a multisite site other than the main site (audit S1): there the plugin offers nothing, handles
 * no package and only says so. The update transient is network-wide.
 */
final class Module
{
    /** @var (\Closure(): self)|null Builds this module (and the services it needs) on first use. */
    private static ?\Closure $factory = null;
    private static ?self $instance = null;

    public function __construct(private readonly Checker $checker)
    {
    }

    /**
     * Schedules the 12-hour check on admin requests where it is missing, so it does not depend on the
     * activation hook having fired (symlinked installs, activation before vendor/ existed) and costs a
     * frontend request nothing (footprint 2), and runs it. The checker is built only when the event fires.
     *
     * @param \Closure(): self $factory
     */
    public static function hook(\Closure $factory): void
    {
        self::$factory = $factory;
        self::$instance = null;
        add_action('admin_init', [self::class, 'heal']);
        add_action(Keys::CRON_HOOK, [self::class, 'onScheduledCheck']);
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

    /** The scheduled check (WP-Cron, ALTERNATE_WP_CRON, `wp cron event run`). */
    public static function onScheduledCheck(): void
    {
        self::instance()->checker->runScheduled();
    }

    /** `admin_init`: the missing schedule, and the settings row of older versions moved out of autoload. */
    public static function heal(): void
    {
        self::schedule();
        self::stopAutoloadingSettings();
    }

    /**
     * Older versions saved the settings autoloaded, so every request loaded them. Switched off once: a row
     * that is not autoloaded is not in core's cached alloptions, so later calls do no query (footprint 4).
     */
    public static function stopAutoloadingSettings(): void
    {
        if (array_key_exists(Keys::SETTINGS, wp_load_alloptions())) {
            wp_set_option_autoload(Keys::SETTINGS, false);
        }
    }

    public static function inert(): bool
    {
        return is_multisite() && ! is_main_site();
    }

    /** An inert site: a notice, and the plugin's own packages are refused (audit S1, S3). */
    public static function registerInert(): void
    {
        add_action('admin_notices', [self::class, 'renderInertNotice']);
        add_filter('upgrader_pre_download', [self::class, 'refuseOwnPackage'], 10, 4);
    }

    public static function onActivate(): void
    {
        // On a multisite site other than the main site the plugin does nothing (audit S1).
        if (self::inert()) {
            return;
        }
        self::schedule();
        self::stopAutoloadingSettings();
    }

    /**
     * Unschedules the check and withdraws the plugin's entries from core's update lists (audit S3): the theme
     * entries, and its own plugin update, whose install check (self-update C5) is not loaded while it is inactive.
     */
    public static function onDeactivate(): void
    {
        self::unschedule();
        UpdateTransient::stripOwnEntries(new Storage());
        Lookup::withdrawOffer(plugin_basename(dirname(__DIR__, 3) . '/' . Source::MAIN_FILE));
    }

    /**
     * Removes every option, transient and cron event the plugin created, and its update entries.
     * Theme files are untouched.
     */
    public static function uninstall(): void
    {
        $storage = new Storage();
        self::unschedule();
        // First, while the mappings still tell which unmarked (v1.0.0) entries are the plugin's.
        UpdateTransient::stripOwnEntries($storage);
        foreach (Keys::options() as $option) {
            $storage->delete($option);
        }
        $storage->deleteSite(Keys::SELF_UPDATE);
        $storage->purgeTransients(Keys::PREFIX);
    }

    public static function schedule(): void
    {
        if (false === wp_next_scheduled(Keys::CRON_HOOK)) {
            wp_schedule_event(time() + 300, 'twicedaily', Keys::CRON_HOOK);
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(Keys::CRON_HOOK);
    }

    /** `admin_notices` on an inert site. */
    public static function renderInertNotice(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        echo '<div class="notice notice-warning"><p>'
            . esc_html__('ProCoders Theme Release runs on the main site of this network only. It does nothing on this site.', 'pc-theme-release')
            . '</p></div>';
    }

    /**
     * Filter `upgrader_pre_download` on an inert site: a package of this plugin is never downloaded here,
     * not even by core without the token (fail closed, audit S3).
     *
     * @param array<string, mixed> $hookExtra
     */
    public static function refuseOwnPackage(mixed $reply, mixed $package, mixed $upgrader = null, array $hookExtra = []): mixed
    {
        if (false !== $reply || ! is_string($package) || 1 !== preg_match(GitHubClient::PACKAGE_PATTERN, $package)) {
            return $reply;
        }
        // The plugin offers themes only: another updater's plugin zipball is not ours (plan O3).
        if (! isset($hookExtra['theme']) && isset($hookExtra['plugin'])) {
            return $reply;
        }

        return new \WP_Error('pc_theme_release_main_site_only', __('ProCoders Theme Release installs themes on the main site of the network only. Nothing was installed.', 'pc-theme-release'));
    }
}
