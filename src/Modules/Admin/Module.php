<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Modules\Admin;

use ProCoders\ThemeRelease\Core\Config\SiteContext;
use ProCoders\ThemeRelease\Core\Security\Secrets;
use ProCoders\ThemeRelease\Core\Support\Environment;
use ProCoders\ThemeRelease\Core\Support\Keys;
use ProCoders\ThemeRelease\Domain\Release\Checker;
use ProCoders\ThemeRelease\Domain\Release\StateStore;
use ProCoders\ThemeRelease\Domain\Release\StatusLog;
use ProCoders\ThemeRelease\Domain\Status\Health;
use ProCoders\ThemeRelease\Domain\Status\Offers;
use ProCoders\ThemeRelease\Domain\Theme\MappingRepository;
use ProCoders\ThemeRelease\Domain\Theme\Themes;
use ProCoders\ThemeRelease\Modules\Update\InstallFlow;

/**
 * The plugin screen (Appearance → Theme Release), its admin-post actions and the site-wide notices.
 * One capability rule and one request guard for all of them.
 */
final class Module
{
    /** Health findings shown as site-wide notices, and the tab where the admin fixes each. */
    private const array NOTICE_TABS = [
        'host_changed' => Tab::Channel,
        'token_undecryptable' => Tab::GitHub,
        'token_expiring' => Tab::GitHub,
        'channel_constant_invalid' => Tab::Channel,
        'token_constant_invalid' => Tab::GitHub,
        'webhook_secret_constant_invalid' => Tab::GitHub,
    ];

    /** @var (\Closure(): self)|null Builds this module (and the services it needs) on first use. */
    private static ?\Closure $factory = null;
    private static ?self $instance = null;

    private readonly Screen $screen;
    private readonly Actions $actions;

    public function __construct(
        SiteContext $site,
        Secrets $secrets,
        Offers $offers,
        Themes $themes,
        InstallFlow $installs,
        private readonly Health $health,
        StatusLog $log,
        MappingRepository $mappings,
        StateStore $state,
        Checker $checker,
        Environment $env,
    ) {
        $this->screen = new Screen($site, $secrets, $offers, $health, $log, new ThemesTab($site, $offers, $themes, $installs));
        $this->actions = new Actions($site, $secrets, $mappings, $state, $offers, $checker, $log, $env);
    }

    /**
     * Admin requests only; the screen and its services are built when a hook below fires.
     *
     * @param \Closure(): self $factory
     */
    public static function hook(\Closure $factory): void
    {
        self::$factory = $factory;
        self::$instance = null;
        add_action('admin_menu', [self::class, 'onMenu']);
        add_action('admin_notices', [self::class, 'onNotices']);
        foreach (Action::adminPost() as $action) {
            add_action('admin_post_' . $action->value, [self::class, 'onAdminPost']);
        }
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

    public static function onMenu(): void
    {
        self::instance()->addMenu();
    }

    public static function onNotices(): void
    {
        self::instance()->renderNotices();
    }

    /** `admin_post_{action}`. */
    public static function onAdminPost(): void
    {
        self::instance()->actions->dispatch();
    }

    /**
     * The one capability rule for the plugin's screen and every plugin action: the configuration feeds core's
     * theme-update flow, so it needs `manage_options` and `update_themes` (audit S1). Where file modifications
     * are disallowed (DISALLOW_FILE_MODS) core grants `update_themes` to nobody and nothing can be installed,
     * so `manage_options` alone keeps the settings usable there (parent spec); installs stay disabled.
     */
    public static function canManage(): bool
    {
        return current_user_can('manage_options')
            && (current_user_can('update_themes') || ! wp_is_file_mod_allowed('pc_theme_release_install'));
    }

    /** `admin_menu`. Users without both capabilities get no menu entry and core's own "not allowed" page. */
    public function addMenu(): void
    {
        if (! self::canManage()) {
            return;
        }
        $hook = add_theme_page(
            __('Theme Release', 'pc-theme-release'),
            __('Theme Release', 'pc-theme-release'),
            'manage_options',
            Keys::PAGE_SLUG,
            [$this->screen, 'render']
        );
        if (is_string($hook)) {
            add_action('load-' . $hook, [$this, 'authorize']);
        }
    }

    /**
     * `load-{page}`: runs before any output. Never cache the screen (it can carry a one-time secret,
     * plan O10), and refuse a POST without capability or nonce here, so the refusal is a real 403/405.
     */
    public function authorize(): void
    {
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
        if (! self::canManage()) {
            self::deny(403);
        }
        self::guardScreenPost(Input::fromGlobals());
    }

    /**
     * The action a POST to the plugin screen asks for, after its guard (nonce, the capability rule, and
     * `update_themes` for installs); null for any other request. Run by the load- hook and again by the
     * page itself, so the page never acts on a request the hook did not see.
     */
    public static function guardScreenPost(Input $input): ?Action
    {
        $action = Action::tryFrom($input->text('action'));
        if ('POST' !== $input->method || null === $action || ! $action->postsToScreen()) {
            return null;
        }
        self::guard($action, $input);
        if ($action->installs() && ! current_user_can('update_themes')) {
            self::deny(403);
        }

        return $action;
    }

    /** POST only, the capability rule, and the action's own nonce. */
    public static function guard(Action $action, Input $input): void
    {
        if ('POST' !== $input->method) {
            self::deny(405);
        }
        if (! self::canManage()) {
            self::deny(403);
        }
        if (false === wp_verify_nonce($input->nonce(), $action->value)) {
            self::deny(403);
        }
    }

    public static function deny(int $status): never
    {
        wp_die(
            esc_html__('Sorry, you are not allowed to do that.', 'pc-theme-release'),
            esc_html__('Not allowed', 'pc-theme-release'),
            ['response' => absint($status)]
        );
    }

    /**
     * Back to the tab (and sub-view) the form lives on, with the message code, then the kept fields.
     *
     * @param array<string, string> $keep Non-secret inputs to show again after an error.
     */
    public static function redirect(MessageCode $code, Tab $tab, array $keep = [], string $view = ''): never
    {
        wp_safe_redirect(self::redirectUrl($code, $tab, $keep, $view));
        exit;
    }

    /** @param array<string, string> $keep */
    public static function redirectUrl(MessageCode $code, Tab $tab, array $keep = [], string $view = ''): string
    {
        $args = '' === $view ? [] : ['view' => $view];
        $args['pctr_msg'] = $code->value;
        foreach ($keep as $key => $value) {
            $args['pctr_' . $key] = $value; // Tab::url() encodes it.
        }

        return $tab->url($args);
    }

    /**
     * `admin_notices`: the problems that need the admin's action, for administrators, on every screen
     * but the plugin's own (it shows them inline).
     */
    public function renderNotices(): void
    {
        if (! self::canManage()) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (null !== $screen && str_ends_with((string) $screen->id, Keys::PAGE_SLUG)) {
            return;
        }
        foreach ($this->health->config() as $item) {
            $tab = self::NOTICE_TABS[$item->code] ?? null;
            if (null === $tab) {
                continue;
            }
            printf(
                '<div class="notice notice-%1$s"><p><strong>%2$s</strong> %3$s <a href="%4$s">%5$s</a></p></div>',
                esc_attr($item->noticeLevel()),
                esc_html__('Theme Release:', 'pc-theme-release'),
                esc_html($item->label),
                esc_url($tab->url()),
                esc_html__('Open settings', 'pc-theme-release')
            );
        }
    }
}
