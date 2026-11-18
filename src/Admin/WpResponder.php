<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Admin;

final class WpResponder implements Responder
{
    public function redirect(MessageCode $code, array $keep = [], Tab $tab = Tab::Themes): never
    {
        wp_safe_redirect(self::url($code, $keep, $tab));
        exit;
    }

    /**
     * The redirect target: the tab the form lives on, the message code, then the kept fields.
     *
     * @param array<string, string> $keep
     */
    public static function url(MessageCode $code, array $keep, Tab $tab): string
    {
        $args = ['pctr_msg' => $code->value];
        foreach ($keep as $key => $value) {
            $args['pctr_' . $key] = rawurlencode($value);
        }

        return $tab->url($args);
    }

    public function deny(int $status): never
    {
        wp_die(
            esc_html__('Sorry, you are not allowed to do that.', 'pc-theme-release'),
            esc_html__('Not allowed', 'pc-theme-release'),
            ['response' => absint($status)]
        );
    }
}
