<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Domain\Theme;

/**
 * Read access to the installed themes (wp_get_theme / wp_get_themes).
 */
final class Themes
{
    private const string GIT_UPDATER_PLUGIN = 'git-updater/git-updater.php';

    public function exists(string $stylesheet): bool
    {
        return '' !== $stylesheet && wp_get_theme($stylesheet)->exists();
    }

    /** @return array<string, string> Installed stylesheet → display name. */
    public function installed(): array
    {
        $list = [];
        foreach (wp_get_themes() as $stylesheet => $theme) {
            $list[(string) $stylesheet] = (string) $theme->get('Name');
        }

        return $list;
    }

    public function version(string $stylesheet): string
    {
        return (string) wp_get_theme($stylesheet)->get('Version');
    }

    public function name(string $stylesheet): string
    {
        return (string) wp_get_theme($stylesheet)->get('Name');
    }

    public function textDomain(string $stylesheet): string
    {
        return (string) wp_get_theme($stylesheet)->get('TextDomain');
    }

    /** True when Git Updater is active and manages this theme (spec call 19). */
    public function managedByGitUpdater(string $stylesheet): bool
    {
        if (! $this->gitUpdaterActive()) {
            return false;
        }
        $file = wp_get_theme($stylesheet)->get_stylesheet_directory() . '/style.css';
        if (! is_readable($file)) {
            return false;
        }
        $headers = get_file_data($file, ['uri' => 'GitHub Theme URI']);

        return '' !== trim((string) ($headers['uri'] ?? ''));
    }

    private function gitUpdaterActive(): bool
    {
        $active = get_option('active_plugins', []);

        return is_array($active) && in_array(self::GIT_UPDATER_PLUGIN, $active, true);
    }
}
