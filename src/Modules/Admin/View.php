<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Modules\Admin;

/**
 * The few markup patterns the screen repeats, in WordPress core admin classes only.
 */
final class View
{
    /** @param list<string> $details Further lines, one paragraph each. */
    public static function notice(string $type, string $message, bool $dismissible = true, array $details = []): void
    {
        $class = 'notice notice-' . $type . ($dismissible ? ' is-dismissible' : '');
        echo '<div class="' . esc_attr($class) . '"' . ('error' === $type ? ' role="alert"' : '') . '><p>' . esc_html($message) . '</p>';
        foreach ($details as $detail) {
            echo '<p>' . esc_html($detail) . '</p>';
        }
        echo '</div>';
    }

    /**
     * The webhook delivery alert: one error notice (not dismissible while the failure lasts) listing every failing
     * repository with a link to its webhook on GitHub, then a link to the Health and log tab. Nothing when empty.
     *
     * @param list<array{label: string, link: string}> $alerts
     */
    public static function deliveryAlert(array $alerts): void
    {
        if ([] === $alerts) {
            return;
        }
        echo '<div class="notice notice-error pctr-delivery-alert"><p><strong>' . esc_html__('Theme Release:', 'pc-theme-release') . '</strong> '
            . esc_html__('GitHub cannot deliver webhooks to this site, so new tags arrive only with the 12-hour check.', 'pc-theme-release') . '</p><ul>';
        foreach ($alerts as $alert) {
            echo '<li>' . esc_html($alert['label']) . ' <a href="' . esc_url($alert['link']) . '">' . esc_html__('Webhook settings on GitHub', 'pc-theme-release') . '</a></li>';
        }
        echo '</ul><p><a href="' . esc_url(Tab::Health->url()) . '">' . esc_html__('Open Health & log', 'pc-theme-release') . '</a></p></div>';
    }

    /** Opens a POST form to admin-post.php with the action and its nonce (and an id for `form=` buttons). Caller closes it. */
    public static function formStart(Action $action, string $id = ''): void
    {
        echo '<form' . ('' === $id ? '' : ' id="' . esc_attr($id) . '"') . ' method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr($action->value) . '">';
        wp_nonce_field($action->value);
    }

    /** Opens a POST form to the plugin screen itself (on $tab) with the action and its nonce. Caller closes it. */
    public static function screenFormStart(Action $action, Tab $tab): void
    {
        echo '<form method="post" action="' . esc_url($tab->url()) . '">';
        wp_nonce_field($action->value);
        echo '<input type="hidden" name="action" value="' . esc_attr($action->value) . '">';
    }

    public static function hidden(string $name, string $value): void
    {
        echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
    }

    /** "Back to Theme Release" (or another label) as a plain link, or as the primary button. */
    public static function backLink(Tab $tab, string $label, bool $button = false): void
    {
        echo '<p><a ' . ($button ? 'class="button button-primary" ' : '') . 'href="' . esc_url($tab->url()) . '">' . esc_html($label) . '</a></p>';
    }

    public static function submit(string $label, string $kind = 'primary', bool $wrap = true): void
    {
        $class = match ($kind) {
            'secondary' => 'button',
            'link-delete' => 'button-link button-link-delete',
            default => 'button button-primary',
        };
        $button = '<button type="submit" class="' . esc_attr($class) . '">' . esc_html($label) . '</button>';
        echo $wrap ? '<p class="submit">' . $button . '</p>' : $button; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
    }

    public static function textRow(string $id, string $name, string $label, string $value, string $help = '', bool $required = false): void
    {
        echo '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td>'
            . '<input type="text" class="regular-text" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"'
            . ($required ? ' required' : '') . ' autocomplete="off" spellcheck="false">';
        if ('' !== $help) {
            echo '<p class="description">' . esc_html($help) . '</p>';
        }
        echo '</td></tr>';
    }

    public static function valueRow(string $label, string $value): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
    }
}
