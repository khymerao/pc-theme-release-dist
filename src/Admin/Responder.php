<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Admin;

/**
 * Ends an admin request: redirect to the plugin screen with a message, or refuse.
 */
interface Responder
{
    /**
     * @param array<string, string> $keep Non-secret inputs to show again after an error.
     * @param Tab                   $tab  The tab the submitted form lives on.
     */
    public function redirect(MessageCode $code, array $keep = [], Tab $tab = Tab::Themes): never;

    public function deny(int $status): never;
}
