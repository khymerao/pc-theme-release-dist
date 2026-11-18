<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Theme;

use ProCoders\ThemeRelease\Config\SettingsRepository;
use ProCoders\ThemeRelease\GitHub\RepoName;
use ProCoders\ThemeRelease\Support\Keys;
use ProCoders\ThemeRelease\Support\OptionStore;
use ProCoders\ThemeRelease\Support\ValidationError;

/**
 * Theme ↔ repository pairs, keyed by stylesheet (never by display name).
 */
final class MappingRepository
{
    public function __construct(private readonly OptionStore $options, private readonly ThemeDirectory $themes)
    {
    }

    /** @return array<string, Mapping> */
    public function all(): array
    {
        $raw = $this->options->get(Keys::THEMES, []);
        $mappings = [];
        foreach (is_array($raw) ? $raw : [] as $stylesheet => $data) {
            $mapping = is_array($data) ? $this->hydrate((string) $stylesheet, $data) : null;
            if (null !== $mapping) {
                $mappings[$mapping->stylesheet] = $mapping;
            }
        }

        return $mappings;
    }

    public function get(string $stylesheet): ?Mapping
    {
        return $this->all()[$stylesheet] ?? null;
    }

    public function findByRepo(RepoName $repo): ?Mapping
    {
        foreach ($this->all() as $mapping) {
            if ($mapping->repo->equals($repo)) {
                return $mapping;
            }
        }

        return null;
    }

    /**
     * A plain theme directory name: no path separator, no leading dot (so never "." or ".."), no control
     * character. Core deletes the old theme directory by this name on update or rollback (audit S2).
     * Dots inside a name ("a..b") are an ordinary directory name.
     */
    public static function isSafeStylesheet(string $stylesheet): bool
    {
        return '' !== $stylesheet
            && strlen($stylesheet) <= 200
            && '.' !== $stylesheet[0]
            && 1 !== preg_match('#[/\\\\\x00-\x1F\x7F]#', $stylesheet);
    }

    /** @throws ValidationError */
    public function add(string $stylesheet, string $repo, string $branchOverride): Mapping
    {
        // Only a key of wp_get_themes(): wp_get_theme() also "finds" paths such as ../uploads.
        if (! self::isSafeStylesheet($stylesheet) || ! array_key_exists($stylesheet, $this->themes->installed())) {
            throw new ValidationError('theme_not_installed', 'theme');
        }
        $name = RepoName::fromString($repo);
        $branch = '' === trim($branchOverride) ? '' : SettingsRepository::validBranch($branchOverride, 'branch');
        if (null !== $this->get($stylesheet)) {
            throw new ValidationError('theme_already_mapped', 'theme');
        }
        if (null !== $this->findByRepo($name)) {
            throw new ValidationError('repo_already_mapped', 'repo');
        }
        $mapping = new Mapping($stylesheet, $name, $branch, null, false);
        $this->save($mapping);

        return $mapping;
    }

    public function save(Mapping $mapping): void
    {
        $raw = $this->options->get(Keys::THEMES, []);
        $raw = is_array($raw) ? $raw : [];
        $raw[$mapping->stylesheet] = $mapping->toArray();
        $this->options->set(Keys::THEMES, $raw, false);
    }

    public function recordInstall(string $stylesheet, InstallRecord $record, bool $frozen): void
    {
        $mapping = $this->get($stylesheet);
        if (null !== $mapping) {
            $this->save(new Mapping($mapping->stylesheet, $mapping->repo, $mapping->branchOverride, $record, $frozen));
        }
    }

    public function setFrozen(string $stylesheet, bool $frozen): void
    {
        $mapping = $this->get($stylesheet);
        if (null !== $mapping) {
            $this->save(new Mapping($mapping->stylesheet, $mapping->repo, $mapping->branchOverride, $mapping->record, $frozen));
        }
    }

    /**
     * Removes stored pairs that all() ignores (unsafe theme key, invalid repository) and returns their
     * theme keys, so the caller can report each one once.
     *
     * @return list<string>
     */
    public function dropInvalid(): array
    {
        $raw = $this->options->get(Keys::THEMES, []);
        if (! is_array($raw)) {
            return [];
        }
        $dropped = [];
        foreach ($raw as $stylesheet => $data) {
            if (! is_array($data) || null === $this->hydrate((string) $stylesheet, $data)) {
                $dropped[] = (string) $stylesheet;
                unset($raw[$stylesheet]);
            }
        }
        if ([] !== $dropped) {
            $this->options->set(Keys::THEMES, $raw, false);
        }

        return $dropped;
    }

    /** Removes the pair with its record and freeze flag; theme files are not touched. */
    public function remove(string $stylesheet): ?Mapping
    {
        $mapping = $this->get($stylesheet);
        $raw = $this->options->get(Keys::THEMES, []);
        if (is_array($raw) && isset($raw[$stylesheet])) {
            unset($raw[$stylesheet]);
            $this->options->set(Keys::THEMES, $raw, false);
        }

        return $mapping;
    }

    /** @param array<mixed> $data */
    private function hydrate(string $stylesheet, array $data): ?Mapping
    {
        // The shape only: a mapping of a theme that is missing on disk right now is kept (status "missing").
        if (! self::isSafeStylesheet($stylesheet)) {
            return null;
        }
        try {
            $repo = RepoName::fromString(is_string($data['repo'] ?? null) ? $data['repo'] : '');
        } catch (ValidationError) {
            return null;
        }
        $record = is_array($data['record'] ?? null) ? InstallRecord::fromArray($data['record']) : null;

        return new Mapping(
            $stylesheet,
            $repo,
            is_string($data['branch'] ?? null) ? $data['branch'] : '',
            $record,
            true === ($data['frozen'] ?? false),
        );
    }
}
