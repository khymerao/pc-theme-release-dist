<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Modules\Admin;

use ProCoders\ThemeRelease\Core\Config\Channel;
use ProCoders\ThemeRelease\Core\Config\SiteContext;
use ProCoders\ThemeRelease\Core\Security\SecretKind;
use ProCoders\ThemeRelease\Core\Security\Secrets;
use ProCoders\ThemeRelease\Core\Security\SecretSource;
use ProCoders\ThemeRelease\Core\Support\Environment;
use ProCoders\ThemeRelease\Core\Support\Keys;
use ProCoders\ThemeRelease\Core\Support\ValidationError;
use ProCoders\ThemeRelease\Domain\Release\StatusLog;
use ProCoders\ThemeRelease\Domain\Status\Health;
use ProCoders\ThemeRelease\Domain\Status\HealthItem;
use ProCoders\ThemeRelease\Domain\Status\Offers;
use ProCoders\ThemeRelease\Domain\Status\ThemeStatus;

/**
 * The plugin screen: which view a request gets, the parts every tab shares (message, things that need
 * attention, tab navigation) and the Channel, GitHub and Health & log tabs. The Themes tab and the install
 * views are ThemesTab.
 */
final class Screen
{
    public function __construct(
        private readonly SiteContext $site,
        private readonly Secrets $secrets,
        private readonly Offers $offers,
        private readonly Health $health,
        private readonly StatusLog $log,
        private readonly ThemesTab $themes,
    ) {
    }

    /** The page callback. A POST to it is guarded here as well as in Module::authorize() (load- hook). */
    public function render(): void
    {
        if (! Module::canManage()) {
            Module::deny(403);
        }
        $input = Input::fromGlobals();
        $posted = Module::guardScreenPost($input);
        echo '<div class="wrap">';
        $view = self::query('view');
        $themesTable = null === $posted && ! in_array($view, ['rollback', 'switch', 'add'], true) && Tab::Themes === Tab::fromQuery(self::query('tab'));
        if ($themesTable) {
            $this->themes->renderTitle();
        } else {
            echo '<h1>' . esc_html__('Theme Release', 'pc-theme-release') . '</h1>';
        }
        if (Action::GenerateWebhookSecret === $posted) {
            $this->generateSecret();
        } elseif (Action::Rollback === $posted) {
            $this->themes->commitRollback($input);
        } elseif (Action::SwitchChannel === $posted) {
            $this->themes->commitSwitch($input);
        } elseif ('rollback' === $view) {
            $this->themes->renderRollbackConfirmation(self::query('theme'), self::query('tag'), null);
        } elseif ('switch' === $view) {
            $this->themes->renderSwitchConfirmation(self::query('theme'), null);
        } else {
            $this->renderMain($input);
        }
        echo '</div>';
    }

    /** A read-only display parameter of the URL; every state change goes through a nonce-checked POST. */
    public static function query(string $key): string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if (! isset($_GET[$key]) || ! is_string($_GET[$key])) {
            return '';
        }
        $value = sanitize_text_field(wp_unslash($_GET[$key]));
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        return sanitize_text_field(rawurldecode($value));
    }

    /** Things that need attention sit above the tabs on every tab; each tab then holds one job. */
    private function renderMain(Input $input): void
    {
        $tab = Tab::fromQuery(self::query('tab'));
        $items = array_merge($this->health->config(), $this->health->cron());
        $this->renderMessage();
        if ($this->site->hostChanged()) {
            $this->renderHostConfirmation();
        }
        // The Health & log tab lists every check itself, so its problems are not repeated above it.
        if (Tab::Health !== $tab) {
            $this->renderProblems($items);
            View::deliveryAlert($this->health->deliveryAlerts());
        }
        $this->renderTabs($tab);
        if (Tab::Channel === $tab) {
            $this->renderChannel();
        } elseif (Tab::GitHub === $tab) {
            $this->renderGitHub();
        } elseif (Tab::Health === $tab) {
            $this->renderHealth(array_merge($items, $this->health->webhook()));
        } else {
            $this->themes->render($input);
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
        $code = MessageCode::tryFrom(self::query('pctr_msg'));
        if (null === $code) {
            return;
        }
        // The reasons come from the stored state, never from the URL.
        $details = [];
        if (in_array($code, [MessageCode::CheckFailed, MessageCode::CheckPartial], true)) {
            $details = ThemeStatus::failures($this->offers->all());
            if ([] === $details) {
                return; // Nothing is failing now (reload after a later success, or a typed URL).
            }
        }
        if ($this->renderWebhookMessage($code)) {
            return;
        }
        View::notice($code->level(), $code->message(), true, $details);
    }

    /**
     * The Create webhook answers: the theme named by `pctr_theme` must be managed (else the plain message); the link
     * comes from the stored hook id; GitHub's refusal text is read once from this user's transient and escaped.
     */
    private function renderWebhookMessage(MessageCode $code): bool
    {
        $links = [
            MessageCode::WebhookCreated->value => __('Webhook settings on GitHub', 'pc-theme-release'),
            MessageCode::WebhookExists->value => __('Webhook settings on GitHub', 'pc-theme-release'),
            MessageCode::WebhookDuplicate->value => __('Webhooks of this repository on GitHub', 'pc-theme-release'),
            MessageCode::WebhookForbidden->value => __('Add it by hand: webhooks of this repository on GitHub', 'pc-theme-release'),
            MessageCode::WebhookRejected->value => __('Webhooks of this repository on GitHub', 'pc-theme-release'),
        ];
        $label = $links[$code->value] ?? null;
        if (null === $label) {
            return false;
        }
        $details = [];
        if (MessageCode::WebhookRejected === $code) {
            $key = Keys::WEBHOOK_MESSAGE_PREFIX . get_current_user_id();
            $text = get_transient($key);
            delete_transient($key);
            $details[] = is_string($text) && '' !== $text ? $text : __('(GitHub gave no reason, or it was already shown.)', 'pc-theme-release');
        }
        $status = $this->offers->all()[self::query('pctr_theme')] ?? null;
        if (null === $status) {
            View::notice($code->level(), $code->message(), true, $details);
            return true;
        }
        $repo = $status->mapping->repo;
        $url = in_array($code, [MessageCode::WebhookCreated, MessageCode::WebhookExists], true) ? $this->health->hookLink($repo) : Health::hooksPage($repo);
        /* translators: %s: owner/repo. */
        $details[] = sprintf(__('Repository: %s', 'pc-theme-release'), $repo->full());
        View::linkNotice($code->level(), $code->message(), $details, $url, $label);

        return true;
    }

    /** The setup steps until a channel is chosen, and the host confirmation after a host change. */
    /** @param list<HealthItem> $items */
    private function renderProblems(array $items): void
    {
        foreach (array_filter($items, static fn (HealthItem $item): bool => $item->isProblem()) as $item) {
            View::notice($item->noticeLevel(), $item->label, false);
        }
        self::renderCronHint($items);
    }

    /** @param list<HealthItem> $items */
    private static function renderCronHint(array $items): void
    {
        if (Health::needsServerCronHint($items)) {
            echo '<p class="description">' . esc_html(Health::serverCronHint()) . '</p>';
        }
    }

    /**
     * Every health and cron check, good ones included, then the recent events.
     *
     * @param list<HealthItem> $items
     */
    private function renderHealth(array $items): void
    {
        echo '<h2>' . esc_html__('Health', 'pc-theme-release') . '</h2>';
        echo '<table class="widefat striped" id="pctr-health"><caption class="screen-reader-text">' . esc_html__('Health checks', 'pc-theme-release') . '</caption><thead><tr>'
            . '<th scope="col">' . esc_html__('Check', 'pc-theme-release') . '</th>'
            . '<th scope="col">' . esc_html__('Status', 'pc-theme-release') . '</th></tr></thead><tbody>';
        foreach ($items as $item) {
            echo '<tr data-code="' . esc_attr($item->code) . '"><td>' . esc_html($item->label) . '</td><td>' . esc_html($item->statusLabel()) . '</td></tr>';
        }
        echo '</tbody></table>';
        self::renderCronHint($items);
        $this->renderLog();
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
        View::formStart(Action::ConfirmHost);
        if (! $settings->channelFromConstant) {
            echo '<p>' . esc_html__('Choose the channel again. Stored secrets are deleted and must be entered again for this site.', 'pc-theme-release') . '</p>';
            self::renderChannelRadios(null);
        }
        View::submit(__('Confirm this site', 'pc-theme-release'));
        echo '</form>';
    }

    private function renderChannel(): void
    {
        $settings = $this->site->settings();
        echo '<h2>' . esc_html__('Channel', 'pc-theme-release') . '</h2>';
        View::formStart(Action::SaveSettings);
        echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row">' . esc_html__('Site channel', 'pc-theme-release') . '</th><td>';
        if ($settings->channelFromConstant && null !== $settings->channel) {
            echo '<p><strong>' . esc_html($settings->channel->label()) . '</strong> — ' . esc_html__('set in wp-config.php (PC_THEME_RELEASE_CHANNEL)', 'pc-theme-release') . '</p>';
        } else {
            self::renderChannelRadios($this->site->channel());
        }
        echo '</td></tr>';
        View::textRow('pctr-staging-branch', 'staging_branch', __('Staging branch', 'pc-theme-release'), $settings->stagingBranch, __('Staging takes tags with a suffix (v1.2.3-rc.1) whose commit is in this branch.', 'pc-theme-release'));
        View::textRow('pctr-production-branch', 'production_branch', __('Production branch', 'pc-theme-release'), $settings->productionBranch, __('Production takes tags without a suffix (v1.2.3) whose commit is in this branch.', 'pc-theme-release'));
        echo '</tbody></table>';
        View::submit(__('Save channel', 'pc-theme-release'));
        echo '</form>';
    }

    private static function renderChannelRadios(?Channel $selected): void
    {
        echo '<fieldset><legend class="screen-reader-text">' . esc_html__('Site channel', 'pc-theme-release') . '</legend>';
        foreach (Channel::cases() as $channel) {
            $id = 'pctr-channel-' . $channel->value;
            echo '<label for="' . esc_attr($id) . '"><input type="radio" id="' . esc_attr($id) . '" name="channel" value="' . esc_attr($channel->value) . '"'
                . checked(true, $selected === $channel, false) . ' required> ' . esc_html($channel->label()) . '</label><br>';
        }
        echo '</fieldset>';
    }

    /** The GitHub tab: the token, then the webhook URL, its secret and the generate button. */
    private function renderGitHub(): void
    {
        echo '<h2>' . esc_html__('GitHub token', 'pc-theme-release') . '</h2>';
        $this->renderSecret(SecretKind::Token, Action::SaveToken, Action::DeleteToken, __('Fine-grained personal access token', 'pc-theme-release'));
        echo '<h2>' . esc_html__('Webhook', 'pc-theme-release') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row">' . esc_html__('Payload URL', 'pc-theme-release') . '</th><td><code>'
            . esc_html(rest_url(Keys::REST_NAMESPACE . '/webhook')) . '</code><p class="description">'
            . esc_html__('Content type: application/json. Events: Branch or tag creation, Pushes.', 'pc-theme-release') . '</p></td></tr></tbody></table>';
        $this->renderSecret(SecretKind::WebhookSecret, Action::SaveWebhookSecret, Action::DeleteWebhookSecret, __('Webhook secret', 'pc-theme-release'));
        if (! $this->secrets->source(SecretKind::WebhookSecret)->isConstant()) {
            View::screenFormStart(Action::GenerateWebhookSecret, Tab::GitHub);
            View::submit(__('Generate a new webhook secret', 'pc-theme-release'), 'secondary');
            echo '</form>';
        }
    }

    /** One secret's row: where it comes from, and the save/replace and delete forms when the screen may change it. */
    private function renderSecret(SecretKind $kind, Action $save, Action $delete, string $label): void
    {
        $source = $this->secrets->source($kind);
        $id = 'pctr-' . str_replace('_', '-', $kind->value);
        echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td>';
        echo '<p>' . esc_html($this->secretState($kind, $source)) . '</p>';
        if (! $source->isConstant()) {
            View::formStart($save);
            echo '<input type="password" class="regular-text" id="' . esc_attr($id) . '" name="' . esc_attr($kind->value) . '" autocomplete="off" spellcheck="false" required> ';
            View::submit(SecretSource::None === $source ? __('Save', 'pc-theme-release') : __('Replace', 'pc-theme-release'), 'secondary', false);
            echo '</form>';
        }
        if (! $source->isConstant() && SecretSource::None !== $source) {
            View::formStart($delete);
            View::submit(__('Delete', 'pc-theme-release'), 'link-delete', false);
            echo '</form>';
        }
        echo '</td></tr></tbody></table>';
    }

    private function secretState(SecretKind $kind, SecretSource $source): string
    {
        // Short secrets get no hint: four characters of 16 would give too much away (audit S9).
        $hint = SecretSource::Database === $source ? $this->secrets->hint($kind) : null;

        return match ($source) {
            SecretSource::Constant => __('Set in wp-config.php.', 'pc-theme-release'),
            SecretSource::InvalidConstant => __('Defined in wp-config.php but invalid, so it is not used. Fix it there.', 'pc-theme-release'),
            /* translators: %s: last four characters of the secret. */
            SecretSource::Database => null === $hint ? __('Set.', 'pc-theme-release') : sprintf(__('Set · ends with …%s', 'pc-theme-release'), $hint),
            SecretSource::Undecryptable => __('Stored but cannot be decrypted (salts changed). Enter it again.', 'pc-theme-release'),
            default => __('Not set.', 'pc-theme-release'),
        };
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
            echo '<tr><td>' . esc_html(Environment::date('Y-m-d H:i', $entry['time'])) . '</td><td>' . esc_html($entry['event']) . '</td><td>'
                . esc_html($entry['repo']) . '</td><td>' . esc_html($entry['tag']) . '</td><td>' . esc_html(0 === $entry['http'] ? '' : (string) $entry['http']) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /** Shows a fresh webhook secret once, in this POST response only (plan O10; admin pages send no-store). */
    private function generateSecret(): void
    {
        try {
            $secret = $this->secrets->generateWebhookSecret();
        } catch (ValidationError $error) {
            View::notice('error', MessageCode::fromReason($error->reason)->message());
            View::backLink(Tab::GitHub, __('Back to Theme Release', 'pc-theme-release'));
            return;
        }
        View::notice('success', __('New webhook secret generated. The previous secret no longer works.', 'pc-theme-release'));
        echo '<p>' . esc_html__('Copy it into the webhook settings of each GitHub repository now. It is shown only this once.', 'pc-theme-release') . '</p>';
        echo '<p><label for="pctr-new-secret">' . esc_html__('Webhook secret', 'pc-theme-release') . '</label><br>'
            . '<code id="pctr-new-secret" class="pctr-secret">' . esc_html($secret) . '</code></p>';
        View::backLink(Tab::GitHub, __('Done', 'pc-theme-release'), true);
    }
}
