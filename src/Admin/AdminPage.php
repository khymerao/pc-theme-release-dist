<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Admin;

use ProCoders\ThemeRelease\Config\Channel;
use ProCoders\ThemeRelease\Config\SiteContext;
use ProCoders\ThemeRelease\Health\CronHealth;
use ProCoders\ThemeRelease\Health\HealthItem;
use ProCoders\ThemeRelease\Health\HealthReport;
use ProCoders\ThemeRelease\Release\ChannelRelease;
use ProCoders\ThemeRelease\Release\StatusLog;
use ProCoders\ThemeRelease\Security\SecretKind;
use ProCoders\ThemeRelease\Security\Secrets;
use ProCoders\ThemeRelease\Security\SecretSource;
use ProCoders\ThemeRelease\Support\Keys;
use ProCoders\ThemeRelease\Support\ValidationError;
use ProCoders\ThemeRelease\Theme\ThemeDirectory;
use ProCoders\ThemeRelease\Update\RollbackRunner;
use ProCoders\ThemeRelease\Update\ThemeStatus;
use ProCoders\ThemeRelease\Update\UpdateOffer;

/**
 * Appearance → Theme Release. WordPress core admin markup only (DESIGN.md).
 */
final class AdminPage
{
    public const string SLUG = Keys::PAGE_SLUG;
    public const string GENERATE_SECRET = 'pctr_generate_webhook_secret';
    public const string ROLLBACK = 'pctr_rollback';
    public const string SWITCH = 'pctr_switch_channel';

    public function __construct(
        private readonly SiteContext $site,
        private readonly Secrets $secrets,
        private readonly UpdateOffer $offers,
        private readonly ThemeDirectory $themes,
        private readonly RollbackRunner $rollback,
        private readonly HealthReport $health,
        private readonly CronHealth $cron,
        private readonly StatusLog $log,
        private readonly Responder $responder,
    ) {
    }

    public function register(): void
    {
        // Users without both capabilities get no menu entry and core's own "not allowed" page.
        if (! Capability::canManage()) {
            return;
        }
        $hook = add_theme_page(
            __('Theme Release', 'pc-theme-release'),
            __('Theme Release', 'pc-theme-release'),
            'manage_options',
            self::SLUG,
            [$this, 'render']
        );
        if (is_string($hook)) {
            add_action('load-' . $hook, [$this, 'sendNoStore']);
        }
    }

    /**
     * `load-{page}`: runs before any output. Never cache the screen (it can carry a one-time secret,
     * plan O10), and refuse a POST without capability or nonce here, so the refusal is a real 403/405.
     */
    public function sendNoStore(): void
    {
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
        if (! Capability::canManage()) {
            $this->responder->deny(403);
        }
        $input = Input::fromGlobals();
        $action = $input->text('action');
        if ('POST' === $input->method && in_array($action, [self::GENERATE_SECRET, self::ROLLBACK, self::SWITCH], true)) {
            Actions::guard($this->responder, $action, $input);
        }
        if ('POST' === $input->method && in_array($action, [self::ROLLBACK, self::SWITCH], true) && ! current_user_can('update_themes')) {
            $this->responder->deny(403);
        }
    }

    public function render(): void
    {
        if (! Capability::canManage()) {
            $this->responder->deny(403);
        }
        $input = Input::fromGlobals();
        $action = $input->text('action');
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Theme Release', 'pc-theme-release') . '</h1>';

        if ('POST' === $input->method && self::GENERATE_SECRET === $action) {
            $this->generateSecret($input);
        } elseif ('POST' === $input->method && self::ROLLBACK === $action) {
            $this->commitRollback($input);
        } elseif ('POST' === $input->method && self::SWITCH === $action) {
            $this->commitSwitch($input);
        } elseif ('rollback' === $this->query('view')) {
            $this->renderRollbackConfirmation($this->query('theme'), $this->query('tag'), null);
        } elseif ('switch' === $this->query('view')) {
            $this->renderSwitchConfirmation($this->query('theme'), null);
        } else {
            $this->renderMain();
        }
        echo '</div>';
    }

    /** Things that need attention sit above the tabs on every tab; each tab then holds one job. */
    private function renderMain(): void
    {
        $tab = Tab::fromQuery($this->query('tab'));
        $items = array_merge($this->health->items(), $this->cron->items());
        $this->renderMessage();
        // The Health & log tab lists every check itself, so its problems are not repeated above it.
        $this->renderAttention($items, Tab::Health !== $tab);
        $this->renderTabs($tab);
        if (Tab::Channel === $tab) {
            $this->renderChannel();
        } elseif (Tab::GitHub === $tab) {
            $this->renderToken();
            $this->renderWebhook();
        } elseif (Tab::Health === $tab) {
            $this->renderHealthTable($items);
            $this->renderLog();
        } else {
            $this->renderThemes();
        }
    }

    /** Core admin tabs: plain links, the current one marked for sight and for assistive technology. */
    private function renderTabs(Tab $current): void
    {
        echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr(__('Theme Release sections', 'pc-theme-release')) . '">';
        foreach (Tab::cases() as $tab) {
            $active = $tab === $current;
            echo '<a href="' . esc_url($tab->url()) . '" class="' . esc_attr($active ? 'nav-tab nav-tab-active' : 'nav-tab') . '"'
                . ($active ? ' aria-current="page"' : '') . '>' . esc_html($tab->label()) . '</a>';
        }
        echo '</nav>';
    }

    private function renderMessage(): void
    {
        $code = MessageCode::tryFrom($this->query('pctr_msg'));
        if (null === $code) {
            return;
        }
        // The reasons come from the stored state, never from the URL.
        $details = [];
        if (in_array($code, [MessageCode::CheckFailed, MessageCode::CheckPartial], true)) {
            $details = StatusText::failures($this->offers->all());
            if ([] === $details) {
                return; // Nothing is failing now (reload after a later success, or a typed URL).
            }
        }
        View::notice($code->level(), $code->message(), true, $details);
    }

    /** @param list<HealthItem> $items */
    private function renderAttention(array $items, bool $withProblems): void
    {
        if (null === $this->site->channel() && ! $this->site->hostChanged()) {
            echo '<h2>' . esc_html__('Set up', 'pc-theme-release') . '</h2><ol>';
            // Each step links to the tab where it is done.
            $steps = [
                [Tab::Channel, __('Choose the channel of this site.', 'pc-theme-release')],
                [Tab::GitHub, __('Set the GitHub token (fine-grained, Contents: read and Metadata: read on the theme repositories).', 'pc-theme-release')],
                [Tab::GitHub, __('Generate the webhook secret.', 'pc-theme-release')],
                [Tab::Themes, __('Add each theme and its repository.', 'pc-theme-release')],
                [Tab::GitHub, __('In each GitHub repository add the webhook URL and secret (content type application/json, events: Branch or tag creation, Pushes).', 'pc-theme-release')],
            ];
            foreach ($steps as [$tab, $step]) {
                echo '<li><a href="' . esc_url($tab->url()) . '">' . esc_html($step) . '</a></li>';
            }
            echo '</ol>';
        }
        if ($this->site->hostChanged()) {
            $this->renderHostConfirmation();
        }
        if (! $withProblems) {
            return;
        }
        $problems = array_values(array_filter($items, static fn (HealthItem $i): bool => HealthItem::GOOD !== $i->status));
        foreach ($problems as $item) {
            View::notice(HealthItem::CRITICAL === $item->status ? 'error' : 'warning', $item->label, false);
        }
        self::renderCronHint($problems);
    }

    /** @param list<HealthItem> $items */
    private static function renderCronHint(array $items): void
    {
        $problems = array_filter($items, static fn (HealthItem $i): bool => HealthItem::GOOD !== $i->status);
        if ([] !== array_intersect(['cron_disabled', 'cron_stale', 'cron_missing'], array_map(static fn (HealthItem $i): string => $i->code, $problems))) {
            echo '<p class="description">' . esc_html(CronHealth::serverCronHint()) . '</p>';
        }
    }

    /**
     * Every health and cron check, good ones included.
     *
     * @param list<HealthItem> $items
     */
    private function renderHealthTable(array $items): void
    {
        echo '<h2>' . esc_html__('Health', 'pc-theme-release') . '</h2>';
        echo '<table class="widefat striped" id="pctr-health"><caption class="screen-reader-text">' . esc_html__('Health checks', 'pc-theme-release') . '</caption><thead><tr>'
            . '<th scope="col">' . esc_html__('Check', 'pc-theme-release') . '</th>'
            . '<th scope="col">' . esc_html__('Status', 'pc-theme-release') . '</th></tr></thead><tbody>';
        foreach ($items as $item) {
            echo '<tr data-code="' . esc_attr($item->code) . '"><td>' . esc_html($item->label) . '</td><td>' . esc_html(self::statusLabel($item->status)) . '</td></tr>';
        }
        echo '</tbody></table>';
        self::renderCronHint($items);
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            HealthItem::GOOD => __('Good', 'pc-theme-release'),
            HealthItem::RECOMMENDED => __('Recommended', 'pc-theme-release'),
            HealthItem::CRITICAL => __('Critical', 'pc-theme-release'),
            default => $status,
        };
    }

    private function renderHostConfirmation(): void
    {
        $settings = $this->site->settings();
        echo '<h2>' . esc_html__('Confirm this site', 'pc-theme-release') . '</h2>';
        echo '<p>' . esc_html(sprintf(
            /* translators: 1: old host, 2: new host. */
            __('The configuration was saved for %1$s, but this site is %2$s.', 'pc-theme-release'),
            $settings->boundHost,
            (string) wp_parse_url(home_url(), PHP_URL_HOST)
        )) . '</p>';
        View::formStart(Actions::CONFIRM_HOST);
        if (! $settings->channelFromConstant) {
            echo '<p>' . esc_html__('Choose the channel again. Stored secrets are deleted and must be entered again for this site.', 'pc-theme-release') . '</p>';
            $this->channelRadios(null);
        }
        View::submit(__('Confirm this site', 'pc-theme-release'));
        echo '</form>';
    }

    private function renderChannel(): void
    {
        $settings = $this->site->settings();
        echo '<h2>' . esc_html__('Channel', 'pc-theme-release') . '</h2>';
        View::formStart(Actions::SAVE_SETTINGS);
        echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row">' . esc_html__('Site channel', 'pc-theme-release') . '</th><td>';
        if ($settings->channelFromConstant && null !== $settings->channel) {
            echo '<p><strong>' . esc_html($settings->channel->label()) . '</strong> — ' . esc_html__('set in wp-config.php (PC_THEME_RELEASE_CHANNEL)', 'pc-theme-release') . '</p>';
        } else {
            $this->channelRadios($this->site->channel());
        }
        echo '</td></tr>';
        View::textRow('pctr-staging-branch', 'staging_branch', __('Staging branch', 'pc-theme-release'), $settings->stagingBranch, __('Staging takes tags with a suffix (v1.2.3-rc.1) whose commit is in this branch.', 'pc-theme-release'));
        View::textRow('pctr-production-branch', 'production_branch', __('Production branch', 'pc-theme-release'), $settings->productionBranch, __('Production takes tags without a suffix (v1.2.3) whose commit is in this branch.', 'pc-theme-release'));
        echo '</tbody></table>';
        View::submit(__('Save channel', 'pc-theme-release'));
        echo '</form>';
    }

    private function channelRadios(?Channel $selected): void
    {
        echo '<fieldset><legend class="screen-reader-text">' . esc_html__('Site channel', 'pc-theme-release') . '</legend>';
        foreach (Channel::cases() as $channel) {
            $id = 'pctr-channel-' . $channel->value;
            echo '<label for="' . esc_attr($id) . '"><input type="radio" id="' . esc_attr($id) . '" name="channel" value="' . esc_attr($channel->value) . '"'
                . checked(true, $selected === $channel, false) . ' required> ' . esc_html($channel->label()) . '</label><br>';
        }
        echo '</fieldset>';
    }

    private function renderToken(): void
    {
        echo '<h2>' . esc_html__('GitHub token', 'pc-theme-release') . '</h2>';
        $this->renderSecret(SecretKind::Token, Actions::SAVE_TOKEN, Actions::DELETE_TOKEN, 'token', __('Fine-grained personal access token', 'pc-theme-release'));
    }

    private function renderWebhook(): void
    {
        echo '<h2>' . esc_html__('Webhook', 'pc-theme-release') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row">' . esc_html__('Payload URL', 'pc-theme-release') . '</th><td><code>'
            . esc_html(rest_url(Keys::REST_NAMESPACE . '/webhook')) . '</code><p class="description">'
            . esc_html__('Content type: application/json. Events: Branch or tag creation, Pushes.', 'pc-theme-release') . '</p></td></tr></tbody></table>';
        $this->renderSecret(SecretKind::WebhookSecret, Actions::SAVE_WEBHOOK_SECRET, Actions::DELETE_WEBHOOK_SECRET, 'webhook_secret', __('Webhook secret', 'pc-theme-release'));
        if (! in_array($this->secrets->source(SecretKind::WebhookSecret), [SecretSource::Constant, SecretSource::InvalidConstant], true)) {
            echo '<form method="post" action="' . esc_url(Tab::GitHub->url()) . '">';
            wp_nonce_field(self::GENERATE_SECRET);
            echo '<input type="hidden" name="action" value="' . esc_attr(self::GENERATE_SECRET) . '">';
            View::submit(__('Generate a new webhook secret', 'pc-theme-release'), 'secondary');
            echo '</form>';
        }
    }

    private function renderSecret(SecretKind $kind, string $saveAction, string $deleteAction, string $field, string $label): void
    {
        $source = $this->secrets->source($kind);
        $id = 'pctr-' . str_replace('_', '-', $field);
        echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td>';
        if (SecretSource::Constant === $source) {
            echo '<p>' . esc_html__('Set in wp-config.php.', 'pc-theme-release') . '</p></td></tr></tbody></table>';
            return;
        }
        if (SecretSource::InvalidConstant === $source) {
            echo '<p>' . esc_html__('Defined in wp-config.php but invalid, so it is not used. Fix it there.', 'pc-theme-release') . '</p></td></tr></tbody></table>';
            return;
        }
        $hint = SecretSource::Database === $source ? $this->secrets->hint($kind) : null;
        if (SecretSource::Database === $source && null === $hint) {
            // Short secrets get no hint: four characters of 16 would give too much away (audit S9).
            echo '<p>' . esc_html__('Set.', 'pc-theme-release') . '</p>';
        } elseif (SecretSource::Database === $source) {
            /* translators: %s: last four characters of the secret. */
            echo '<p>' . esc_html(sprintf(__('Set · ends with …%s', 'pc-theme-release'), $hint)) . '</p>';
        } elseif (SecretSource::Undecryptable === $source) {
            echo '<p>' . esc_html__('Stored but cannot be decrypted (salts changed). Enter it again.', 'pc-theme-release') . '</p>';
        } else {
            echo '<p>' . esc_html__('Not set.', 'pc-theme-release') . '</p>';
        }
        View::formStart($saveAction);
        echo '<input type="password" class="regular-text" id="' . esc_attr($id) . '" name="' . esc_attr($field) . '" autocomplete="off" spellcheck="false" required> ';
        View::submit(SecretSource::None === $source ? __('Save', 'pc-theme-release') : __('Replace', 'pc-theme-release'), 'secondary', false);
        echo '</form>';
        if (SecretSource::None !== $source) {
            View::formStart($deleteAction);
            View::submit(__('Delete', 'pc-theme-release'), 'link-delete', false);
            echo '</form>';
        }
        echo '</td></tr></tbody></table>';
    }

    private function renderThemes(): void
    {
        echo '<h2>' . esc_html__('Themes', 'pc-theme-release') . '</h2>';
        View::formStart(Actions::CHECK_NOW);
        View::submit(__('Check now', 'pc-theme-release'), 'secondary');
        echo '</form>';

        $statuses = $this->offers->all();
        $fileMods = self::installsAllowed();
        echo '<table class="widefat fixed striped" id="pctr-themes"><caption class="screen-reader-text">' . esc_html__('Managed themes', 'pc-theme-release') . '</caption><thead><tr>'
            . '<th scope="col">' . esc_html__('Theme', 'pc-theme-release') . '</th>'
            . '<th scope="col">' . esc_html__('Repository', 'pc-theme-release') . '</th>'
            . '<th scope="col">' . esc_html__('Installed', 'pc-theme-release') . '</th>'
            . '<th scope="col">' . esc_html__('Status', 'pc-theme-release') . '</th></tr></thead><tbody>';
        if ([] === $statuses) {
            echo '<tr><td colspan="4">' . esc_html__('No themes are managed yet. Add one below.', 'pc-theme-release') . '</td></tr>';
        }
        foreach ($statuses as $status) {
            $this->renderThemeRow($status, $fileMods);
        }
        echo '</tbody></table>';
        if (! $fileMods) {
            echo '<p class="description">' . esc_html(self::installsDisabledText()) . '</p>';
        }
        $this->renderAddMapping($statuses);
    }

    private function renderThemeRow(ThemeStatus $status, bool $fileMods): void
    {
        $mapping = $status->mapping;
        $name = $status->themeMissing ? $mapping->stylesheet : $this->themes->name($mapping->stylesheet);
        echo '<tr><td><strong>' . esc_html($name) . '</strong><br><code>' . esc_html($mapping->stylesheet) . '</code></td>';
        echo '<td>' . esc_html($mapping->repo->full());
        if ('' !== $mapping->branchOverride) {
            echo '<br><span class="description">' . esc_html(sprintf(/* translators: %s: branch. */ __('branch %s', 'pc-theme-release'), $mapping->branchOverride)) . '</span>';
        }
        echo '</td><td>' . esc_html('' !== $status->installedLabel ? $status->installedLabel : '—');
        if (null === $mapping->record) {
            echo '<br><span class="description">' . esc_html__('(from style.css)', 'pc-theme-release') . '</span>';
        }
        echo '</td><td>' . wp_kses_post(implode('<br>', array_map('esc_html', StatusText::lines($status)))) . '</td></tr>';
        // Actions get a full-width row of their own so the forms fit narrow screens.
        echo '<tr class="pctr-actions" data-theme="' . esc_attr($mapping->stylesheet) . '"><td colspan="4">';
        $this->renderRowActions($status, $fileMods);
        echo '</td></tr>';
    }

    private function renderRowActions(ThemeStatus $status, bool $fileMods): void
    {
        $mapping = $status->mapping;
        $stylesheet = $mapping->stylesheet;
        if (null === $mapping->record && [] !== $status->state->releases) {
            $id = 'pctr-seed-' . sanitize_html_class($stylesheet);
            View::formStart(Actions::SEED_TAG);
            echo '<input type="hidden" name="theme" value="' . esc_attr($stylesheet) . '">';
            echo '<label for="' . esc_attr($id) . '">' . esc_html__('Installed tag', 'pc-theme-release') . '</label> <select id="' . esc_attr($id) . '" name="tag">';
            $styleVersion = $status->themeMissing ? '' : $this->themes->version($stylesheet);
            foreach ($status->state->releases as $release) {
                $selected = ltrim($release->tag, 'vV') === ltrim($styleVersion, 'vV');
                echo '<option value="' . esc_attr($release->tag) . '"' . selected($selected, true, false) . '>' . esc_html($release->tag) . '</option>';
            }
            echo '</select> ';
            View::submit(__('Record', 'pc-theme-release'), 'secondary', false);
            echo '</form>';
        }
        if ($mapping->frozen) {
            View::formStart(Actions::UNFREEZE);
            echo '<input type="hidden" name="theme" value="' . esc_attr($stylesheet) . '">';
            View::submit(__('Unfreeze', 'pc-theme-release'), 'secondary', false);
            echo '</form>';
        }
        if ($fileMods && null !== $status->switchTarget && ! $status->themeMissing && ! $status->gitUpdaterConflict) {
            // Moves an out-of-channel theme to this channel's newest tag, without freezing (confirmation first).
            echo '<form method="get" action="' . esc_url(admin_url('themes.php')) . '">';
            echo '<input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '"><input type="hidden" name="view" value="switch"><input type="hidden" name="theme" value="' . esc_attr($stylesheet) . '">';
            /* translators: %s: tag. */
            View::submit(sprintf(__('Install %s', 'pc-theme-release'), $status->switchTarget->tag), 'secondary', false);
            echo '</form>';
        }
        if ($fileMods && [] !== $status->rollbackTargets && ! $status->themeMissing && ! $status->gitUpdaterConflict) {
            $id = 'pctr-rollback-' . sanitize_html_class($stylesheet);
            echo '<form method="get" action="' . esc_url(admin_url('themes.php')) . '">';
            echo '<input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '"><input type="hidden" name="view" value="rollback"><input type="hidden" name="theme" value="' . esc_attr($stylesheet) . '">';
            echo '<label for="' . esc_attr($id) . '">' . esc_html__('Install tag', 'pc-theme-release') . '</label> <select id="' . esc_attr($id) . '" name="tag">';
            foreach ($status->rollbackTargets as $release) {
                $date = null === $release->date ? '' : ' (' . View::date('Y-m-d', $release->date) . ')';
                echo '<option value="' . esc_attr($release->tag) . '">' . esc_html($release->tag . $date) . '</option>';
            }
            echo '</select> ';
            View::submit(__('Review rollback…', 'pc-theme-release'), 'secondary', false);
            echo '</form>';
        }
        View::formStart(Actions::REMOVE_MAPPING);
        echo '<input type="hidden" name="theme" value="' . esc_attr($stylesheet) . '">';
        View::submit(__('Remove', 'pc-theme-release'), 'link-delete', false);
        echo '</form>';
    }

    /** @param array<string, ThemeStatus> $statuses */
    private function renderAddMapping(array $statuses): void
    {
        $available = array_diff_key($this->themes->installed(), $statuses);
        echo '<h3>' . esc_html__('Add a theme', 'pc-theme-release') . '</h3>';
        if ([] === $available) {
            echo '<p>' . esc_html__('Every installed theme is already managed.', 'pc-theme-release') . '</p>';
            return;
        }
        View::formStart(Actions::ADD_MAPPING);
        echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row"><label for="pctr-theme">' . esc_html__('Installed theme', 'pc-theme-release') . '</label></th><td><select id="pctr-theme" name="theme" required>';
        $keptTheme = $this->query('pctr_theme');
        foreach ($available as $stylesheet => $name) {
            echo '<option value="' . esc_attr((string) $stylesheet) . '"' . selected($keptTheme, (string) $stylesheet, false) . '>' . esc_html($name . ' (' . $stylesheet . ')') . '</option>';
        }
        echo '</select></td></tr>';
        View::textRow('pctr-repo', 'repo', __('Repository (owner/repo)', 'pc-theme-release'), $this->query('pctr_repo'), '', true);
        View::textRow('pctr-branch', 'branch', __('Branch override (optional)', 'pc-theme-release'), $this->query('pctr_branch'), __('Empty: use the site\'s staging or production branch.', 'pc-theme-release'));
        echo '</tbody></table>';
        View::submit(__('Add theme', 'pc-theme-release'));
        echo '</form>';
    }

    private function renderLog(): void
    {
        $entries = $this->log->entries();
        if ([] === $entries) {
            echo '<h2>' . esc_html__('Recent events', 'pc-theme-release') . '</h2><p>' . esc_html__('No events yet.', 'pc-theme-release') . '</p>';
            return;
        }
        echo '<h2>' . esc_html__('Recent events', 'pc-theme-release') . '</h2><table class="widefat fixed striped"><thead><tr>'
            . '<th scope="col">' . esc_html__('Time', 'pc-theme-release') . '</th><th scope="col">' . esc_html__('Event', 'pc-theme-release') . '</th>'
            . '<th scope="col">' . esc_html__('Repository', 'pc-theme-release') . '</th><th scope="col">' . esc_html__('Tag', 'pc-theme-release') . '</th>'
            . '<th scope="col">' . esc_html__('HTTP', 'pc-theme-release') . '</th></tr></thead><tbody>';
        foreach (array_slice($entries, 0, 20) as $entry) {
            echo '<tr><td>' . esc_html(View::date('Y-m-d H:i', $entry['time'])) . '</td><td>' . esc_html($entry['event']) . '</td><td>'
                . esc_html($entry['repo']) . '</td><td>' . esc_html($entry['tag']) . '</td><td>' . esc_html(0 === $entry['http'] ? '' : (string) $entry['http']) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function renderRollbackConfirmation(string $stylesheet, string $tag, ?MessageCode $error): void
    {
        if (null !== $error) {
            View::notice('error', $error->message());
        }
        if (! self::installsAllowed()) {
            $this->renderInstallsDisabled();
            return;
        }
        try {
            $target = $this->rollback->target($stylesheet, $tag);
        } catch (ValidationError $failure) {
            $this->renderRefusal($failure, $error);
            return;
        }
        $this->renderConfirmation(
            __('Confirm rollback', 'pc-theme-release'),
            $stylesheet,
            $target,
            __('After the rollback the theme is frozen: no update notices until you unfreeze it.', 'pc-theme-release'),
            self::ROLLBACK,
            __('Roll back and freeze', 'pc-theme-release'),
        );
    }

    /** "Switch to <channel>": same confirmation as the rollback, installs the newest channel tag, never freezes. */
    private function renderSwitchConfirmation(string $stylesheet, ?MessageCode $error): void
    {
        if (null !== $error) {
            View::notice('error', $error->message());
        }
        if (! self::installsAllowed()) {
            $this->renderInstallsDisabled();
            return;
        }
        try {
            $target = $this->rollback->switchTarget($stylesheet);
        } catch (ValidationError $failure) {
            $this->renderRefusal($failure, $error);
            return;
        }
        $channel = $this->site->channel();
        $name = null === $channel ? '' : $channel->name();
        $this->renderConfirmation(
            /* translators: %s: channel name (staging or production). */
            sprintf(__('Confirm switch to %s', 'pc-theme-release'), $name),
            $stylesheet,
            $target,
            /* translators: %s: channel name. */
            sprintf(__('The installed tag does not belong to the %1$s channel. This installs the newest %1$s tag. The theme is not frozen: newer %1$s tags are offered as usual.', 'pc-theme-release'), $name),
            self::SWITCH,
            /* translators: %s: channel name. */
            sprintf(__('Switch to %s', 'pc-theme-release'), $name),
        );
    }

    private static function installsAllowed(): bool
    {
        return wp_is_file_mod_allowed('pc_theme_release_install') && current_user_can('update_themes');
    }

    private static function installsDisabledText(): string
    {
        return __('Updates and rollbacks are disabled: file modifications are not allowed on this site (DISALLOW_FILE_MODS) or your role cannot update themes.', 'pc-theme-release');
    }

    /** A confirmation screen without a form: nothing can be installed by this user on this site. */
    private function renderInstallsDisabled(): void
    {
        View::notice('error', self::installsDisabledText(), false);
        echo '<p><a href="' . esc_url(Tab::Themes->url()) . '">' . esc_html__('Back to Theme Release', 'pc-theme-release') . '</a></p>';
    }

    private function renderRefusal(ValidationError $failure, ?MessageCode $shown): void
    {
        $code = MessageCode::fromReason($failure->reason);
        if ($code !== $shown) {
            View::notice('error', $code->message());
        }
        echo '<p><a href="' . esc_url(Tab::Themes->url()) . '">' . esc_html__('Back to Theme Release', 'pc-theme-release') . '</a></p>';
    }

    /** The shared confirmation screen: what is installed now, what will be installed, one primary button. */
    private function renderConfirmation(string $heading, string $stylesheet, ChannelRelease $target, string $note, string $action, string $button): void
    {
        $status = $this->offers->all()[$stylesheet] ?? null;
        echo '<h2>' . esc_html($heading) . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        View::valueRow(__('Theme', 'pc-theme-release'), $this->themes->name($stylesheet) . ' (' . $stylesheet . ')');
        View::valueRow(__('Installed now', 'pc-theme-release'), null === $status ? '—' : $status->installedLabel);
        View::valueRow(__('Will install', 'pc-theme-release'), $target->tag . ' · ' . substr($target->sha, 0, 7) . (null === $target->date ? '' : ' · ' . View::date('Y-m-d H:i', $target->date)));
        echo '</tbody></table><p>' . esc_html($note) . '</p>';
        echo '<form method="post" action="' . esc_url(Tab::Themes->url()) . '">';
        wp_nonce_field($action);
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '"><input type="hidden" name="theme" value="' . esc_attr($stylesheet) . '">'
            . '<input type="hidden" name="tag" value="' . esc_attr($target->tag) . '"><input type="hidden" name="sha" value="' . esc_attr($target->sha) . '">';
        View::submit($button, 'primary', false);
        echo ' <a class="button" href="' . esc_url(Tab::Themes->url()) . '">' . esc_html__('Cancel', 'pc-theme-release') . '</a></form>';
    }

    private function commitRollback(Input $input): void
    {
        Actions::guard($this->responder, self::ROLLBACK, $input);
        if (! current_user_can('update_themes')) {
            $this->responder->deny(403);
        }
        $stylesheet = $input->text('theme');
        $tag = $input->text('tag');
        try {
            $result = $this->rollback->commit($stylesheet, $tag, $input->text('sha'));
        } catch (ValidationError $error) {
            $this->renderRollbackConfirmation($stylesheet, $tag, MessageCode::fromReason($error->reason));
            return;
        }
        $this->renderInstallResult($result);
    }

    private function commitSwitch(Input $input): void
    {
        Actions::guard($this->responder, self::SWITCH, $input);
        if (! current_user_can('update_themes')) {
            $this->responder->deny(403);
        }
        $stylesheet = $input->text('theme');
        try {
            $result = $this->rollback->commitSwitch($stylesheet, $input->text('tag'), $input->text('sha'));
        } catch (ValidationError $error) {
            // Shows the difference (a fresh preview) or why nothing can be installed any more.
            $this->renderSwitchConfirmation($stylesheet, MessageCode::fromReason($error->reason));
            return;
        }
        $this->renderInstallResult($result);
    }

    private function renderInstallResult(bool|\WP_Error $result): void
    {
        if (true !== $result) {
            View::notice('error', MessageCode::Failed->message());
        }
        echo '<p><a class="button button-primary" href="' . esc_url(Tab::Themes->url()) . '">' . esc_html__('Back to Theme Release', 'pc-theme-release') . '</a></p>';
    }

    /** Shows a fresh webhook secret once, in this POST response only (plan O10; admin pages send no-store). */
    private function generateSecret(Input $input): void
    {
        Actions::guard($this->responder, self::GENERATE_SECRET, $input);
        try {
            $secret = $this->secrets->generateWebhookSecret();
        } catch (ValidationError $error) {
            View::notice('error', MessageCode::fromReason($error->reason)->message());
            echo '<p><a href="' . esc_url(Tab::GitHub->url()) . '">' . esc_html__('Back to Theme Release', 'pc-theme-release') . '</a></p>';
            return;
        }
        View::notice('success', __('New webhook secret generated. The previous secret no longer works.', 'pc-theme-release'));
        echo '<p>' . esc_html__('Copy it into the webhook settings of each GitHub repository now. It is shown only this once.', 'pc-theme-release') . '</p>';
        echo '<p><label for="pctr-new-secret">' . esc_html__('Webhook secret', 'pc-theme-release') . '</label><br>'
            . '<code id="pctr-new-secret" class="pctr-secret">' . esc_html($secret) . '</code></p>';
        echo '<p><a class="button button-primary" href="' . esc_url(Tab::GitHub->url()) . '">' . esc_html__('Done', 'pc-theme-release') . '</a></p>';
    }

    private function query(string $key): string
    {
        // Read-only display parameters; every state change goes through a nonce-checked POST.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if (! isset($_GET[$key]) || ! is_string($_GET[$key])) {
            return '';
        }
        $value = sanitize_text_field(wp_unslash($_GET[$key]));
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        return sanitize_text_field(rawurldecode($value));
    }
}
