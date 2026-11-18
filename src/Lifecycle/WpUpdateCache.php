<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Lifecycle;

use ProCoders\ThemeRelease\GitHub\RepoName;
use ProCoders\ThemeRelease\Support\Keys;
use ProCoders\ThemeRelease\Support\OptionStore;
use ProCoders\ThemeRelease\Support\WpOptionStore;
use ProCoders\ThemeRelease\Support\ValidationError;
use ProCoders\ThemeRelease\Update\UpdateInjector;

final class WpUpdateCache implements UpdateCache
{
    private const string FILTER = 'pre_set_site_transient_update_themes';

    /** @var list<string> repositories of the stored mappings while stripOwnEntries() runs */
    private array $repos = [];

    public function __construct(private readonly OptionStore $options = new WpOptionStore())
    {
    }

    public function stripOwnEntries(): void
    {
        $current = get_site_transient('update_themes');
        if (! is_object($current)) {
            return;
        }
        $this->repos = $this->storedRepos();
        // On deactivation the plugin is still loaded and its injector filters this write: strip after it.
        add_filter(self::FILTER, [$this, 'strip'], PHP_INT_MAX);
        set_site_transient('update_themes', $current);
        remove_filter(self::FILTER, [$this, 'strip'], PHP_INT_MAX);
    }

    /** Filter callback while stripOwnEntries() rewrites the transient. */
    public function strip(mixed $transient): mixed
    {
        return UpdateInjector::stripOwn($transient, $this->repos);
    }

    /** @return list<string> */
    private function storedRepos(): array
    {
        $raw = $this->options->get(Keys::THEMES, []);
        $repos = [];
        foreach (is_array($raw) ? $raw : [] as $data) {
            try {
                $repos[] = RepoName::fromString(is_array($data) && is_string($data['repo'] ?? null) ? $data['repo'] : '')->full();
            } catch (ValidationError) {
                continue;
            }
        }

        return $repos;
    }
}
