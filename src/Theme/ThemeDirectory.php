<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Theme;

/**
 * Read access to installed themes (wp_get_theme / wp_get_themes).
 */
interface ThemeDirectory
{
    public function exists(string $stylesheet): bool;

    /** @return array<string, string> Installed stylesheet → display name. */
    public function installed(): array;

    public function version(string $stylesheet): string;

    public function name(string $stylesheet): string;

    public function textDomain(string $stylesheet): string;

    /** True when Git Updater is active and manages this theme (spec call 19). */
    public function managedByGitUpdater(string $stylesheet): bool;
}
