<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Domain\Theme;

use ProCoders\ThemeRelease\Core\Config\SiteContext;
use ProCoders\ThemeRelease\Core\GitHub\RepoName;
use ProCoders\ThemeRelease\Core\Support\Keys;
use ProCoders\ThemeRelease\Core\Support\Storage;
use ProCoders\ThemeRelease\Core\Support\ValidationError;

/**
 * Theme ↔ repository pairs, keyed by stylesheet (never by display name).
 */
final class MappingRepository
{
    public function __construct(private readonly Storage $storage, private readonly Themes $themes)
    {
    }

    /** @return array<string, Mapping> */
    public function all(): array
    {
        $mappings = [];
        foreach ($this->storage->getArray(Keys::THEMES) as $stylesheet => $data) {
            $mapping = is_array($data) ? self::hydrate((string) $stylesheet, $data) : null;
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

    /** @throws ValidationError "mapping_gone" when the theme is not managed (any more). */
    public function require(string $stylesheet): Mapping
    {
        return $this->get($stylesheet) ?? throw new ValidationError('mapping_gone');
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

    public function findByRepoId(int $repoId): ?Mapping
    {
        foreach ($this->all() as $mapping) {
            if ($repoId === $mapping->repoId) {
                return $mapping;
            }
        }

        return null;
    }

    /** Stores the repository's GitHub id (trust on first use); never overwrites one already stored. */
    public function setRepoId(string $stylesheet, int $repoId): void
    {
        $mapping = $this->get($stylesheet);
        if (null !== $mapping && null === $mapping->repoId) {
            $this->save($mapping->withRepoId($repoId));
        }
    }

    /**
     * @param (callable(RepoName): ?int)|null $identify Runs after every other check and before the save:
     *        the repository's id (null: not known yet), or a ValidationError (a public repository).
     *
     * @throws ValidationError
     */
    public function add(string $stylesheet, string $repo, string $branchOverride, ?callable $identify = null): Mapping
    {
        // Only a key of wp_get_themes(): wp_get_theme() also "finds" paths such as ../uploads.
        if (! self::isSafeStylesheet($stylesheet) || ! array_key_exists($stylesheet, $this->themes->installed())) {
            throw new ValidationError('theme_not_installed');
        }
        $name = RepoName::fromInput($repo);
        $branch = '' === trim($branchOverride) ? '' : SiteContext::validBranch($branchOverride);
        if (null !== $this->get($stylesheet)) {
            throw new ValidationError('theme_already_mapped');
        }
        if (null !== $this->findByRepo($name)) {
            throw new ValidationError('repo_already_mapped');
        }
        $mapping = new Mapping($stylesheet, $name, $branch, null, false, null === $identify ? null : $identify($name));
        $this->save($mapping);

        return $mapping;
    }

    public function save(Mapping $mapping): void
    {
        $raw = $this->storage->getArray(Keys::THEMES);
        $raw[$mapping->stylesheet] = $mapping->toArray();
        $this->storage->set(Keys::THEMES, $raw, false);
    }

    /** Stores what was installed; a rollback also freezes the theme, an update or switch leaves it unfrozen. */
    public function recordInstall(string $stylesheet, InstallRecord $record, bool $frozen): void
    {
        $mapping = $this->get($stylesheet);
        if (null !== $mapping) {
            $this->save($mapping->withRecord($record, $frozen));
        }
    }

    public function setFrozen(string $stylesheet, bool $frozen): void
    {
        $mapping = $this->get($stylesheet);
        if (null !== $mapping) {
            $this->save($mapping->withFrozen($frozen));
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
        $raw = $this->storage->get(Keys::THEMES, []);
        if (! is_array($raw)) {
            return [];
        }
        $dropped = [];
        foreach ($raw as $stylesheet => $data) {
            if (! is_array($data) || null === self::hydrate((string) $stylesheet, $data)) {
                $dropped[] = (string) $stylesheet;
                unset($raw[$stylesheet]);
            }
        }
        if ([] !== $dropped) {
            $this->storage->set(Keys::THEMES, $raw, false);
        }

        return $dropped;
    }

    /** Removes the pair with its record and freeze flag; theme files are not touched. */
    public function remove(string $stylesheet): ?Mapping
    {
        $mapping = $this->get($stylesheet);
        $raw = $this->storage->get(Keys::THEMES, []);
        if (is_array($raw) && isset($raw[$stylesheet])) {
            unset($raw[$stylesheet]);
            $this->storage->set(Keys::THEMES, $raw, false);
        }

        return $mapping;
    }

    /** @param array<mixed> $data */
    private static function hydrate(string $stylesheet, array $data): ?Mapping
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
            is_int($data['repo_id'] ?? null) && $data['repo_id'] > 0 ? $data['repo_id'] : null,
        );
    }
}
