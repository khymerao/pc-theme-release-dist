<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Domain\Release;

use ProCoders\ThemeRelease\Core\GitHub\GitHubClient;
use ProCoders\ThemeRelease\Core\GitHub\RepoName;
use ProCoders\ThemeRelease\Core\Support\Keys;
use ProCoders\ThemeRelease\Core\Support\Storage;
use ProCoders\ThemeRelease\Core\Support\ValidationError;

/**
 * Core's `update_themes` site transient, as far as the plugin's own entries are concerned: the entry
 * shape, which entries are the plugin's, re-saving and stripping them.
 */
final class UpdateTransient
{
    /** Set on every `update_themes` entry this plugin writes, so only those are ever withdrawn (audit S5). */
    public const string ENTRY_MARKER = 'pc_theme_release_managed';
    private const string FILTER = 'pre_set_site_transient_update_themes';

    /** @var list<string> repositories of the stored mappings while stripOwnEntries() runs */
    private static array $stripRepos = [];

    /**
     * One theme's entry as core reads it.
     *
     * @return array<string, mixed>
     */
    public static function entry(string $stylesheet, string $version, string $url, string $package, bool $marked): array
    {
        $entry = [
            'theme' => $stylesheet,
            'new_version' => $version,
            'url' => $url,
            'package' => $package,
            'requires' => '',
            'requires_php' => '',
        ];
        if ($marked) {
            $entry[self::ENTRY_MARKER] = true;
        }

        return $entry;
    }

    /**
     * Re-runs the transient through core's set path so the plugin's filter re-injects;
     * touches nothing but `update_themes`.
     */
    public static function refresh(): void
    {
        $current = get_site_transient('update_themes');
        if (is_object($current)) {
            set_site_transient('update_themes', $current);
        }
    }

    /**
     * Removes every entry this plugin wrote (deactivation, uninstall). The stored mappings tell which
     * unmarked (v1.0.0) entries are the plugin's, so this runs before the options are deleted.
     */
    public static function stripOwnEntries(Storage $storage): void
    {
        $current = get_site_transient('update_themes');
        if (! is_object($current)) {
            return;
        }
        self::$stripRepos = self::storedRepos($storage);
        // On deactivation the plugin is still loaded and its own filter rewrites this write: strip after it.
        add_filter(self::FILTER, [self::class, 'strip'], PHP_INT_MAX);
        set_site_transient('update_themes', $current);
        remove_filter(self::FILTER, [self::class, 'strip'], PHP_INT_MAX);
    }

    /** Filter callback while stripOwnEntries() rewrites the transient. */
    public static function strip(mixed $transient): mixed
    {
        return self::stripOwn($transient, self::$stripRepos);
    }

    /**
     * Removes every entry this plugin wrote from a transient value.
     *
     * @param list<string> $repos owner/repo of the stored mappings, to recognise unmarked entries of v1.0.0
     */
    public static function stripOwn(mixed $transient, array $repos = []): mixed
    {
        if (! $transient instanceof \stdClass) {
            return $transient;
        }
        foreach (['response', 'no_update'] as $list) {
            if (isset($transient->$list) && is_array($transient->$list)) {
                $transient->$list = array_filter($transient->$list, static fn (mixed $entry): bool => ! self::isOwnEntry($entry, $repos));
            }
        }

        return $transient;
    }

    /**
     * An entry this plugin wrote: marked, or (written by v1.0.0, before the marker) an own-form package
     * of a repository that is mapped here.
     *
     * @param list<string> $repos owner/repo, lower case
     */
    public static function isOwnEntry(mixed $entry, array $repos = []): bool
    {
        if (! is_array($entry)) {
            return false;
        }
        if (true === ($entry[self::ENTRY_MARKER] ?? null)) {
            return true;
        }
        $package = is_string($entry['package'] ?? null) ? $entry['package'] : '';
        if ([] === $repos || 1 !== preg_match(GitHubClient::PACKAGE_PATTERN, $package, $m)) {
            return false;
        }

        return in_array(strtolower(rawurldecode($m[1]) . '/' . rawurldecode($m[2])), $repos, true);
    }

    /** @return list<string> */
    private static function storedRepos(Storage $storage): array
    {
        $repos = [];
        foreach ($storage->getArray(Keys::THEMES) as $data) {
            try {
                $repos[] = RepoName::fromString(is_array($data) && is_string($data['repo'] ?? null) ? $data['repo'] : '')->full();
            } catch (ValidationError) {
                continue;
            }
        }

        return $repos;
    }
}
