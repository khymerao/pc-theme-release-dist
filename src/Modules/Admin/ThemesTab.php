<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Modules\Admin;

use ProCoders\ThemeRelease\Core\Config\SiteContext;
use ProCoders\ThemeRelease\Core\Security\SecretKind;
use ProCoders\ThemeRelease\Core\Security\Secrets;
use ProCoders\ThemeRelease\Core\Security\SecretSource;
use ProCoders\ThemeRelease\Core\Support\Environment;
use ProCoders\ThemeRelease\Core\Support\ValidationError;
use ProCoders\ThemeRelease\Domain\Release\ChannelRelease;
use ProCoders\ThemeRelease\Domain\Release\StateStore;
use ProCoders\ThemeRelease\Domain\Status\Offers;
use ProCoders\ThemeRelease\Domain\Status\ThemeStatus;
use ProCoders\ThemeRelease\Domain\Theme\Themes;
use ProCoders\ThemeRelease\Modules\Update\InstallFlow;

/**
 * The Themes tab (the managed-themes list table, the Add theme sub-view) and the rollback / switch confirmation
 * and commit views it leads to.
 */
final class ThemesTab
{
    public function __construct(
        private readonly SiteContext $site,
        private readonly Offers $offers,
        private readonly Themes $themes,
        private readonly InstallFlow $installs,
        private readonly StateStore $state,
        private readonly Secrets $secrets,
    ) {
    }

    /** The Themes tab: the managed-themes list table, or the Add theme sub-view (`view=add`). */
    public function render(Input $input): void
    {
        $statuses = $this->offers->all();
        if ('add' === Screen::query('view')) {
            $this->renderAddView($statuses);
            return;
        }
        if (! class_exists('WP_List_Table', false)) {
            // Core's list table is loaded only here, right before the table is built (not on other tabs or views).
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }
        $states = $this->webhookStates($statuses);
        $table = new ThemesListTable($statuses, $this->themes, self::installsAllowed(), $this->addUrl($statuses), $states, $this->webhookLinks($statuses, $states), $this->shownSecret($statuses), Screen::query('edit'), Screen::query('mode'), Screen::query('webhook'), '1' === Screen::query('confirm'));
        $table->prepare_items();
        $table->display();
        if (! self::installsAllowed()) {
            echo '<p class="description">' . esc_html(self::installsDisabledText()) . '</p>';
        }
    }

    /** The screen title on the Themes table view: core's inline heading with the "Add theme" page-title button. */
    public function renderTitle(): void
    {
        echo '<h1 class="wp-heading-inline">' . esc_html__('Theme Release', 'pc-theme-release') . '</h1>';
        $url = $this->addUrl($this->offers->all());
        if ('' !== $url) {
            echo ' <a href="' . esc_url($url) . '" class="page-title-action">' . esc_html__('Add theme', 'pc-theme-release') . '</a>';
        }
        echo '<hr class="wp-header-end">';
    }

    public function renderRollbackConfirmation(string $stylesheet, string $tag, ?MessageCode $error): void
    {
        $target = $this->confirmable($error, fn (): ChannelRelease => $this->installs->target($stylesheet, $tag));
        if (null === $target) {
            return;
        }
        $this->renderConfirmation(
            __('Confirm rollback', 'pc-theme-release'),
            $stylesheet,
            $target,
            __('After the rollback the theme is frozen: no update notices until you unfreeze it.', 'pc-theme-release'),
            Action::Rollback,
            __('Roll back and freeze', 'pc-theme-release'),
        );
    }

    /** "Switch to <channel>": same confirmation as the rollback, installs the newest channel tag, never freezes. */
    public function renderSwitchConfirmation(string $stylesheet, ?MessageCode $error): void
    {
        $target = $this->confirmable($error, fn (): ChannelRelease => $this->installs->switchTarget($stylesheet));
        if (null === $target) {
            return;
        }
        $channel = $this->site->channel();
        $name = null === $channel ? '' : $channel->lowerLabel();
        $this->renderConfirmation(
            /* translators: %s: channel name (staging or production). */
            sprintf(__('Confirm switch to %s', 'pc-theme-release'), $name),
            $stylesheet,
            $target,
            /* translators: %s: channel name. */
            sprintf(__('The installed tag does not belong to the %1$s channel. This installs the newest %1$s tag. The theme is not frozen: newer %1$s tags are offered as usual.', 'pc-theme-release'), $name),
            Action::SwitchChannel,
            /* translators: %s: channel name. */
            sprintf(__('Switch to %s', 'pc-theme-release'), $name),
        );
    }

    /** POST of the rollback confirmation (guarded by Module::guardScreenPost()). */
    public function commitRollback(Input $input): void
    {
        $stylesheet = $input->text('theme');
        $tag = $input->text('tag');
        try {
            $result = $this->installs->commit($stylesheet, $tag, $input->text('sha'));
        } catch (ValidationError $error) {
            $this->renderRollbackConfirmation($stylesheet, $tag, MessageCode::fromReason($error->reason));
            return;
        }
        self::renderInstallResult($result);
    }

    /** POST of the switch confirmation (guarded by Module::guardScreenPost()). */
    public function commitSwitch(Input $input): void
    {
        $stylesheet = $input->text('theme');
        try {
            $result = $this->installs->commitSwitch($stylesheet, $input->text('tag'), $input->text('sha'));
        } catch (ValidationError $error) {
            // Shows the difference (a fresh preview) or why nothing can be installed any more.
            $this->renderSwitchConfirmation($stylesheet, MessageCode::fromReason($error->reason));
            return;
        }
        self::renderInstallResult($result);
    }

    /**
     * The webhook secret state of every theme's Webhook setup panel (per-theme secret spec calls 1, 5, 6): the
     * wp-config constant (valid or not) for all; else the theme's own secret, the shared global one, or none.
     *
     * @param array<string, ThemeStatus> $statuses
     * @return array<string, string> stylesheet => ThemesListTable::SECRET_* state
     */
    private function webhookStates(array $statuses): array
    {
        $source = $this->secrets->source(SecretKind::WebhookSecret);
        $global = SecretSource::None !== $source;
        $states = [];
        foreach ($statuses as $stylesheet => $status) {
            if ($source->isConstant()) {
                $states[$stylesheet] = SecretSource::Constant === $source ? ThemesListTable::SECRET_CONSTANT : ThemesListTable::SECRET_CONSTANT_INVALID;
                continue;
            }
            $own = $this->secrets->themeSecretState((string) $stylesheet, $status->mapping->repo->full());
            $state = match ($own) {
                SecretSource::Database => ThemesListTable::SECRET_OWN,
                SecretSource::Undecryptable => ThemesListTable::SECRET_UNDECRYPTABLE,
                default => $global ? ThemesListTable::SECRET_SHARED : ThemesListTable::SECRET_NONE,
            };
            // Nothing can be stored with weak salts: the panel says so instead of offering a button.
            $states[$stylesheet] = ThemesListTable::SECRET_OWN !== $state && ! $this->secrets->canStore() ? $state . ThemesListTable::WEAK_SALTS : $state;
        }

        return $states;
    }

    /**
     * The themes whose row shows "Webhook setup…" (spec call 1): no hook of this site is known yet (the stored outcome
     * counts, not its 48-hour display age), or the theme has no secret of its own (unless the constant verifies all).
     *
     * @param array<string, ThemeStatus> $statuses
     * @param array<string, string>      $states
     * @return list<string> stylesheets
     */
    private function webhookLinks(array $statuses, array $states): array
    {
        $links = [];
        foreach ($statuses as $stylesheet => $status) {
            $stored = $this->state->delivery($status->mapping->repo);
            $hookKnown = null !== $stored && $stored->knowsHook();
            $state = $states[$stylesheet] ?? '';
            $ownOrConstant = in_array($state, [ThemesListTable::SECRET_OWN, ThemesListTable::SECRET_CONSTANT, ThemesListTable::SECRET_CONSTANT_INVALID], true);
            if (! $hookKnown || ! $ownOrConstant) {
                $links[] = (string) $stylesheet;
            }
        }

        return $links;
    }

    /**
     * The secret generated for this user that waits to be shown once (spec call 2), and its theme; at most one is
     * taken (and so deleted) per page, the one whose panel this page opens.
     *
     * @param array<string, ThemeStatus> $statuses
     * @return array{0: string, 1: string} stylesheet, secret ('' and '' when none)
     */
    private function shownSecret(array $statuses): array
    {
        $user = get_current_user_id();
        foreach ($statuses as $stylesheet => $status) {
            $secret = $this->secrets->takeForDisplay($user, (string) $stylesheet, $status->mapping->repo->full());
            if (null !== $secret) {
                return [(string) $stylesheet, $secret];
            }
        }

        return ['', ''];
    }

    /**
     * Where "Add theme" leads, or '' when every installed theme is already managed.
     *
     * @param array<string, ThemeStatus> $statuses
     */
    private function addUrl(array $statuses): string
    {
        return [] === array_diff_key($this->themes->installed(), $statuses) ? '' : Tab::Themes->url(['view' => 'add']);
    }

    /**
     * The Add theme sub-view; a refused add comes back here with the message and the kept fields.
     *
     * @param array<string, ThemeStatus> $statuses
     */
    private function renderAddView(array $statuses): void
    {
        $available = array_diff_key($this->themes->installed(), $statuses);
        echo '<h2>' . esc_html__('Add a theme', 'pc-theme-release') . '</h2>';
        if ([] === $available) {
            echo '<p>' . esc_html__('Every installed theme is already managed.', 'pc-theme-release') . '</p>';
            View::backLink(Tab::Themes, __('Back to Theme Release', 'pc-theme-release'));
            return;
        }
        View::formStart(Action::AddMapping);
        echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row"><label for="pctr-theme">' . esc_html__('Installed theme', 'pc-theme-release') . '</label></th><td><select id="pctr-theme" name="theme" required>';
        $keptTheme = Screen::query('pctr_theme');
        foreach ($available as $stylesheet => $name) {
            echo '<option value="' . esc_attr((string) $stylesheet) . '"' . selected($keptTheme, (string) $stylesheet, false) . '>' . esc_html($name . ' (' . $stylesheet . ')') . '</option>';
        }
        echo '</select></td></tr>';
        View::textRow('pctr-repo', 'repo', __('Repository (owner/repo)', 'pc-theme-release'), Screen::query('pctr_repo'), __('A GitHub URL works too: it is trimmed to owner/repo.', 'pc-theme-release'), true);
        View::textRow('pctr-branch', 'branch', __('Branch override (optional)', 'pc-theme-release'), Screen::query('pctr_branch'), __('Empty: use the site\'s staging or production branch.', 'pc-theme-release'));
        echo '</tbody></table><p class="submit">';
        View::submit(__('Add theme', 'pc-theme-release'), 'primary', false);
        echo ' <a class="button" href="' . esc_url(Tab::Themes->url()) . '">' . esc_html__('Cancel', 'pc-theme-release') . '</a></p></form>';
    }

    /**
     * The target to confirm, or null after showing why there is none: installs are disabled here, or
     * the target is gone (the refusal's message once, under the commit's own error when it differs).
     *
     * @param \Closure(): ChannelRelease $target
     */
    private function confirmable(?MessageCode $error, \Closure $target): ?ChannelRelease
    {
        if (null !== $error) {
            View::notice('error', $error->message());
        }
        if (! self::installsAllowed()) {
            View::notice('error', self::installsDisabledText(), false);
            View::backLink(Tab::Themes, __('Back to Theme Release', 'pc-theme-release'));
            return null;
        }
        try {
            return $target();
        } catch (ValidationError $failure) {
            $code = MessageCode::fromReason($failure->reason);
            if ($code !== $error) {
                View::notice('error', $code->message());
            }
            View::backLink(Tab::Themes, __('Back to Theme Release', 'pc-theme-release'));
            return null;
        }
    }

    /** The shared confirmation screen: what is installed now, what will be installed, one primary button. */
    private function renderConfirmation(string $heading, string $stylesheet, ChannelRelease $target, string $note, Action $action, string $button): void
    {
        $status = $this->offers->all()[$stylesheet] ?? null;
        echo '<h2>' . esc_html($heading) . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        View::valueRow(__('Theme', 'pc-theme-release'), $this->themes->name($stylesheet) . ' (' . $stylesheet . ')');
        View::valueRow(__('Installed now', 'pc-theme-release'), null === $status ? '—' : $status->installedLabel);
        View::valueRow(__('Will install', 'pc-theme-release'), $target->tag . ' · ' . substr($target->sha, 0, 7) . (null === $target->date ? '' : ' · ' . Environment::date('Y-m-d H:i', $target->date)));
        echo '</tbody></table><p>' . esc_html($note) . '</p>';
        View::screenFormStart($action, Tab::Themes);
        echo '<input type="hidden" name="theme" value="' . esc_attr($stylesheet) . '">'
            . '<input type="hidden" name="tag" value="' . esc_attr($target->tag) . '"><input type="hidden" name="sha" value="' . esc_attr($target->sha) . '">';
        View::submit($button, 'primary', false);
        echo ' <a class="button" href="' . esc_url(Tab::Themes->url()) . '">' . esc_html__('Cancel', 'pc-theme-release') . '</a></form>';
    }

    private static function renderInstallResult(bool|\WP_Error $result): void
    {
        if (true !== $result) {
            View::notice('error', MessageCode::Failed->message());
        }
        View::backLink(Tab::Themes, __('Back to Theme Release', 'pc-theme-release'), true);
    }

    private static function installsAllowed(): bool
    {
        return wp_is_file_mod_allowed('pc_theme_release_install') && current_user_can('update_themes');
    }

    private static function installsDisabledText(): string
    {
        return __('Updates and rollbacks are disabled: file modifications are not allowed on this site (DISALLOW_FILE_MODS) or your role cannot update themes.', 'pc-theme-release');
    }
}
