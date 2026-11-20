<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Modules\Admin;

use ProCoders\ThemeRelease\Core\Support\Environment;
use ProCoders\ThemeRelease\Core\Support\Keys;
use ProCoders\ThemeRelease\Domain\Release\ChannelRelease;
use ProCoders\ThemeRelease\Domain\Release\SemVer;
use ProCoders\ThemeRelease\Domain\Status\ThemeStatus;
use ProCoders\ThemeRelease\Domain\Theme\Themes;

/**
 * The managed-themes table of the Themes tab, on core's list table: row actions under the theme name, an inline
 * Quick-Edit-style row for one theme (`edit=<stylesheet>`, optionally `mode=record`), Check now in the top tablenav.
 * No bulk actions, sorting, pagination or screen options. Built only by ThemesTab, after it loaded the core class.
 * Every value is escaped here; core echoes what the column methods return.
 */
final class ThemesListTable extends \WP_List_Table
{
    // phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- core's WP_List_Table method names.
    private const string MODE_ROLLBACK = 'rollback';
    private const string MODE_RECORD = 'record';
    private const string MODE_WEBHOOK = 'webhook';

    /** Stylesheet of the theme whose inline row is open ('' when none) and the row's mode. */
    private readonly string $editing;
    private readonly string $editMode;

    /**
     * @param array<string, ThemeStatus> $statuses Keyed by stylesheet.
     * @param string                     $addUrl   Where "Add theme" leads; '' when every installed theme is managed.
     * @param list<string>               $webhookCreatable Stylesheets that get Create webhook (ThemesTab decides).
     * @param string                     $edit     The display-only `edit` query arg (honoured only when valid).
     * @param string                     $mode     The display-only `mode` query arg.
     * @param string                     $webhook  The display-only `webhook` query arg: the theme whose Create webhook row is open.
     */
    public function __construct(
        private readonly array $statuses,
        private readonly Themes $themes,
        private readonly bool $installsAllowed,
        private readonly string $addUrl,
        private readonly array $webhookCreatable,
        string $edit,
        string $mode,
        string $webhook = '',
    ) {
        parent::__construct(['plural' => 'pctr-themes', 'singular' => 'pctr-theme', 'ajax' => false]);
        $webhookStatus = $this->statuses[$webhook] ?? null;
        // Its own query arg, not edit=: the Create webhook row never stands for Change version.
        [$this->editing, $this->editMode] = null !== $webhookStatus && $this->canCreateWebhook($webhookStatus) ? [$webhook, self::MODE_WEBHOOK] : $this->inlineRow($edit, $mode);
    }

    /** @return array<string, string> */
    public function get_columns(): array
    {
        return [
            'theme' => __('Theme', 'pc-theme-release'),
            'repository' => __('Repository', 'pc-theme-release'),
            'installed' => __('Installed', 'pc-theme-release'),
            'available' => __('Available', 'pc-theme-release'),
            'status' => __('Status', 'pc-theme-release'),
        ];
    }

    public function prepare_items(): void
    {
        $this->items = array_values($this->statuses);
        // Set directly: no hidden or sortable columns, so core never reads screen options.
        $this->_column_headers = [$this->get_columns(), [], [], 'theme'];
        $count = count($this->items);
        $this->set_pagination_args(['total_items' => $count, 'total_pages' => 1, 'per_page' => max(1, $count)]);
    }

    public function no_items(): void
    {
        echo esc_html__('No themes are managed yet.', 'pc-theme-release');
        if ('' !== $this->addUrl) {
            echo ' <a href="' . esc_url($this->addUrl) . '">' . esc_html__('Add theme', 'pc-theme-release') . '</a>';
        }
    }

    /**
     * The theme's row, then its inline row when it is the one being edited.
     *
     * @param object|array<mixed> $item
     */
    public function single_row($item): void
    {
        parent::single_row($item);
        if ($item instanceof ThemeStatus && $item->mapping->stylesheet === $this->editing) {
            $this->renderInlineRow($item);
        }
    }

    public function column_theme(ThemeStatus $status): string
    {
        $stylesheet = $status->mapping->stylesheet;
        $html = '<strong>' . esc_html($this->name($status)) . '</strong>' . self::note(esc_html($stylesheet));
        // One-button POST forms; their buttons sit in the row actions (form= attribute), so no form nests or breaks the line.
        if ($status->mapping->frozen) {
            $html .= self::postForm(Action::Unfreeze, $stylesheet);
        }

        return $html . self::postForm(Action::RemoveMapping, $stylesheet);
    }

    public function column_repository(ThemeStatus $status): string
    {
        $mapping = $status->mapping;
        $html = esc_html($mapping->repo->full());
        if ('' !== $mapping->branchOverride) {
            /* translators: %s: branch. */
            $html .= self::note(esc_html(sprintf(__('branch: %s (override)', 'pc-theme-release'), $mapping->branchOverride)));
        } elseif ('' !== $status->branch) {
            /* translators: %s: branch. */
            $html .= self::note(esc_html(sprintf(__('branch: %s (site default)', 'pc-theme-release'), $status->branch)));
        }
        if ($this->canCreateWebhook($status)) {
            // In this cell, not in the row actions: a third row action wraps in the Theme column and its targets
            // come closer than WCAG 2.5.8 allows. Always visible while no hook of this site is known.
            /* translators: %s: theme name. */
            $html .= self::note(self::link(Tab::Themes->url(['webhook' => $mapping->stylesheet]), __('Create webhook…', 'pc-theme-release'), sprintf(__('Create webhook for %s', 'pc-theme-release'), $this->name($status))));
        }

        return $html;
    }

    public function column_installed(ThemeStatus $status): string
    {
        $record = $status->mapping->record;
        $html = esc_html('' !== $status->installedLabel ? $status->installedLabel : '—');
        if (null === $record) {
            return $html . self::note(esc_html__('(from style.css)', 'pc-theme-release'));
        }

        return $html . self::note(esc_html(substr($record->sha, 0, 7) . ' · ' . Environment::date('Y-m-d', $record->installedAt)));
    }

    public function column_available(ThemeStatus $status): string
    {
        $channel = $status->channel;
        if (null !== $status->switchTarget && null !== $channel) {
            /* translators: %s: channel name (staging or production). */
            return esc_html($status->switchTarget->tag) . self::note(esc_html(sprintf(__('%s channel', 'pc-theme-release'), $channel->lowerLabel())));
        }
        if (null !== $status->offer) {
            return esc_html($status->offer->tag) . self::note('<a href="' . esc_url(admin_url('update-core.php')) . '">' . esc_html__('update in Dashboard → Updates', 'pc-theme-release') . '</a>');
        }
        $held = self::heldBack($status);
        if (null !== $held) {
            return esc_html($held->tag) . self::note(esc_html__('not offered while frozen', 'pc-theme-release'));
        }

        return '—';
    }

    /** The same lines as Site Health and the Check now notice: the first as the headline, the rest under it. */
    public function column_status(ThemeStatus $status): string
    {
        $lines = $status->lines();
        $headline = array_shift($lines);
        $html = esc_html($headline ?? '—');
        foreach ($lines as $line) {
            $html .= self::note(esc_html($line));
        }

        return $html;
    }

    /**
     * @param mixed  $item
     * @param string $column_name
     * @param string $primary
     */
    protected function handle_row_actions($item, $column_name, $primary): string
    {
        if ($column_name !== $primary || ! $item instanceof ThemeStatus) {
            return '';
        }

        return $this->row_actions($this->actions($item));
    }

    protected function get_default_primary_column_name(): string
    {
        return 'theme';
    }

    /**
     * Core's tablenav, top only and without the bulk-action nonce: there are no bulk actions and no form around the table
     * (the row forms would nest in it).
     *
     * @param 'top'|'bottom' $which
     */
    protected function display_tablenav($which): void
    {
        if ('top' !== $which) {
            return; // One tablenav: the count is not repeated under a table of a handful of rows.
        }
        echo '<div class="tablenav ' . esc_attr($which) . '">';
        $this->extra_tablenav($which);
        $this->pagination($which);
        echo '<br class="clear"></div>';
    }

    /** @param 'top'|'bottom' $which */
    protected function extra_tablenav($which): void
    {
        if ('top' !== $which) {
            return;
        }
        // Not core's `actions` class: core hides `.tablenav.top .actions` below 782 px, and Check now must stay reachable.
        echo '<div class="alignleft">';
        View::formStart(Action::CheckNow);
        View::submit(__('Check now', 'pc-theme-release'), 'secondary', false);
        echo '</form></div>';
    }

    /**
     * The row actions of one theme, Remove last. State changes are POST buttons; the rest are plain links.
     *
     * @return array<string, string>
     */
    private function actions(ThemeStatus $status): array
    {
        $mapping = $status->mapping;
        $stylesheet = $mapping->stylesheet;
        $name = $this->name($status);
        $canInstall = $this->canInstall($status);
        $actions = [];
        if (self::canRecord($status)) {
            /* translators: %s: theme name. */
            $actions['record'] = self::link(Tab::Themes->url(['edit' => $stylesheet, 'mode' => self::MODE_RECORD]), __('Record installed tag', 'pc-theme-release'), sprintf(__('Record the installed tag of %s', 'pc-theme-release'), $name));
        }
        if ($canInstall && null !== $status->switchTarget) {
            // add_query_arg() does not encode: the stylesheet may hold `+`, `&` or `#`.
            $url = add_query_arg(['page' => Keys::PAGE_SLUG, 'view' => 'switch', 'theme' => rawurlencode($stylesheet)], admin_url('themes.php'));
            /* translators: %s: tag. */
            $label = sprintf(__('Install %s', 'pc-theme-release'), $status->switchTarget->tag);
            /* translators: 1: tag, 2: theme name. */
            $actions['switch'] = self::link($url, $label, sprintf(__('Install %1$s of %2$s', 'pc-theme-release'), $status->switchTarget->tag, $name));
        }
        if ($mapping->frozen) {
            /* translators: %s: theme name. */
            $actions['unfreeze'] = self::formButton(Action::Unfreeze, $stylesheet, __('Unfreeze', 'pc-theme-release'), sprintf(__('Unfreeze %s', 'pc-theme-release'), $name), 'button-link');
        }
        if ($canInstall && [] !== $status->rollbackTargets) {
            /* translators: %s: theme name. */
            $actions['edit'] = self::link(Tab::Themes->url(['edit' => $stylesheet]), __('Change version', 'pc-theme-release'), sprintf(__('Change version of %s', 'pc-theme-release'), $name));
        }
        /* translators: %s: theme name. */
        $actions['delete'] = self::formButton(Action::RemoveMapping, $stylesheet, __('Remove', 'pc-theme-release'), sprintf(__('Remove %s', 'pc-theme-release'), $name), 'button-link button-link-delete');

        return $actions;
    }

    /**
     * Which inline row the display args ask for, if they name a managed theme that can have it.
     *
     * @return array{0: string, 1: string} stylesheet ('' for none), mode
     */
    private function inlineRow(string $edit, string $mode): array
    {
        $status = $this->statuses[$edit] ?? null;
        if (null === $status) {
            return ['', ''];
        }
        if (self::MODE_RECORD === $mode && self::canRecord($status)) {
            return [$edit, self::MODE_RECORD];
        }
        if ($this->canInstall($status) && [] !== $status->rollbackTargets) {
            return [$edit, self::MODE_ROLLBACK];
        }

        return ['', ''];
    }

    /** The Quick-Edit-style row under the theme: install another tag (to the rollback confirmation) or record one. */
    private function renderInlineRow(ThemeStatus $status): void
    {
        $stylesheet = $status->mapping->stylesheet;
        $name = $this->name($status);
        if (self::MODE_WEBHOOK === $this->editMode) {
            $this->renderWebhookRow($stylesheet, $name);
            return;
        }
        $record = self::MODE_RECORD === $this->editMode;
        // A plain cell (core's padding and type), not core's Quick Edit row: its CSS uppercases, floats and re-pads.
        echo '<tr class="pctr-inline-row" id="pctr-inline-edit"><td colspan="' . esc_attr((string) $this->get_column_count()) . '" class="colspanchange">';
        if ($record) {
            View::formStart(Action::SeedTag);
            View::hidden('theme', $stylesheet);
            /* translators: %s: theme name. */
            $legend = sprintf(__('Record the installed tag of %s', 'pc-theme-release'), $name);
            $releases = $status->state->releases;
            $styleVersion = $status->themeMissing ? '' : ltrim($this->themes->version($stylesheet), 'vV');
            $button = __('Record', 'pc-theme-release');
            $description = __('Choose the tag of this site\'s channel that is installed now.', 'pc-theme-release');
        } else {
            echo '<form method="get" action="' . esc_url(admin_url('themes.php')) . '">';
            View::hidden('page', Keys::PAGE_SLUG);
            View::hidden('view', 'rollback');
            View::hidden('theme', $stylesheet);
            /* translators: %s: theme name. */
            $legend = sprintf(__('Install another version of %s', 'pc-theme-release'), $name);
            $releases = $status->rollbackTargets;
            $styleVersion = '';
            $button = __('Rollback…', 'pc-theme-release');
            $description = __('Only tags of this site\'s channel are listed. An older tag freezes the theme until you unfreeze it.', 'pc-theme-release');
        }
        echo '<p><label for="pctr-inline-tag"><strong>' . esc_html($legend) . '</strong></label><br>'
            . '<select id="pctr-inline-tag" name="tag">';
        foreach ($releases as $release) {
            $selected = '' !== $styleVersion && $release->versionLabel() === $styleVersion;
            echo '<option value="' . esc_attr($release->tag) . '"' . selected($selected, true, false) . '>' . esc_html(self::optionLabel($release)) . '</option>';
        }
        echo '</select> ';
        View::submit($button, 'primary', false);
        echo ' <a class="button" href="' . esc_url(Tab::Themes->url()) . '">' . esc_html__('Cancel', 'pc-theme-release') . '</a></p>'
            . '<p class="description">' . esc_html($description) . '</p></form></td></tr>';
    }

    /**
     * The Create webhook row: a one-time token (password field, never filled in again) posted to admin-post.php.
     */
    private function renderWebhookRow(string $stylesheet, string $name): void
    {
        echo '<tr class="pctr-inline-row" id="pctr-inline-edit"><td colspan="' . esc_attr((string) $this->get_column_count()) . '" class="colspanchange">';
        View::formStart(Action::CreateWebhook);
        View::hidden('theme', $stylesheet);
        /* translators: %s: theme name. */
        echo '<p><strong>' . esc_html(sprintf(__('Create the GitHub webhook for %s', 'pc-theme-release'), $name)) . '</strong></p>'
            . '<p><label for="pctr-webhook-token">' . esc_html__('One-time GitHub token (Webhooks: Read and write)', 'pc-theme-release') . '</label><br>'
            . '<input type="password" class="regular-text" id="pctr-webhook-token" name="webhook_token" autocomplete="off" spellcheck="false" required aria-describedby="pctr-webhook-token-note"> ';
        View::submit(__('Create webhook', 'pc-theme-release'), 'primary', false);
        echo ' <a class="button" href="' . esc_url(Tab::Themes->url()) . '">' . esc_html__('Cancel', 'pc-theme-release') . '</a></p>'
            . '<p class="description" id="pctr-webhook-token-note">' . esc_html__('Used once for this repository and not saved. Revoke it on GitHub afterwards.', 'pc-theme-release') . '</p></form></td></tr>';
    }

    private function canCreateWebhook(ThemeStatus $status): bool
    {
        return in_array($status->mapping->stylesheet, $this->webhookCreatable, true);
    }

    private function name(ThemeStatus $status): string
    {
        return $status->themeMissing ? $status->mapping->stylesheet : $this->themes->name($status->mapping->stylesheet);
    }

    /** Rollback and switch need installs allowed here and the theme present and not under Git Updater. */
    private function canInstall(ThemeStatus $status): bool
    {
        return $this->installsAllowed && ! $status->unavailable();
    }

    /** Nothing recorded yet and the channel's tags are known (Actions::seedTag() re-checks both). */
    private static function canRecord(ThemeStatus $status): bool
    {
        return null === $status->mapping->record && [] !== $status->state->releases;
    }

    /** Frozen, and the channel has a newer tag than the installed one, which is not offered while frozen. */
    private static function heldBack(ThemeStatus $status): ?ChannelRelease
    {
        $newest = $status->state->offerable;
        if (! $status->mapping->frozen || null === $newest) {
            return null;
        }
        $installed = SemVer::parse($status->installedLabel);

        return null === $installed || $newest->version()->compare($installed) > 0 ? $newest : null;
    }

    /** "tag — sha7 — Y-m-d", the date left out when unknown. */
    private static function optionLabel(ChannelRelease $release): string
    {
        $label = $release->tag . ' — ' . substr($release->sha, 0, 7);

        return null === $release->date ? $label : $label . ' — ' . Environment::date('Y-m-d', $release->date);
    }

    /** A second line in the core description style. $html is already escaped. */
    private static function note(string $html): string
    {
        return '<br><span class="description">' . $html . '</span>';
    }

    private static function link(string $url, string $label, string $ariaLabel): string
    {
        return '<a href="' . esc_url($url) . '" aria-label="' . esc_attr($ariaLabel) . '">' . esc_html($label) . '</a>';
    }

    private static function formId(Action $action, string $stylesheet): string
    {
        // A hash, not a sanitised slug: "acme.old" and "acmeold" (or two non-ASCII slugs) must not share a form id.
        return 'pctr-' . str_replace('_', '-', substr($action->value, 5)) . '-' . md5($stylesheet);
    }

    /** An empty POST form (action, nonce, theme) that a row-action button submits. */
    private static function postForm(Action $action, string $stylesheet): string
    {
        ob_start();
        View::formStart($action, self::formId($action, $stylesheet));
        View::hidden('theme', $stylesheet);
        echo '</form>';

        return (string) ob_get_clean();
    }

    private static function formButton(Action $action, string $stylesheet, string $label, string $ariaLabel, string $class): string
    {
        return '<button type="submit" form="' . esc_attr(self::formId($action, $stylesheet)) . '" class="' . esc_attr($class) . '" aria-label="' . esc_attr($ariaLabel) . '">' . esc_html($label) . '</button>';
    }
}
