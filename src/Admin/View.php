<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Admin;

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

    /** Opens a POST form to admin-post.php with the action and its nonce. Caller closes it. */
    public static function formStart(string $action): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        wp_nonce_field($action);
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

    /** A site-timezone date for display; empty when WordPress cannot format it. */
    public static function date(string $format, int $timestamp): string
    {
        $formatted = wp_date($format, $timestamp);

        return is_string($formatted) ? $formatted : '';
    }

    public static function valueRow(string $label, string $value): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
    }
}
