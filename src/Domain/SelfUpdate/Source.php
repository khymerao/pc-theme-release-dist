<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Domain\SelfUpdate;

use ProCoders\ThemeRelease\Core\GitHub\RepoName;
use ProCoders\ThemeRelease\Core\Support\Environment;
use ProCoders\ThemeRelease\Core\Support\ValidationError;

/**
 * Where the plugin's own updates come from: the dist repository and the branch whose main file names the latest
 * release. Defaults in code; a developer may point them at a fork or branch with wp-config constants. Never an
 * option, so no admin-option write can redirect updates. An invalid constant is ignored (the default is used) and
 * named in invalid() for the Health row.
 */
final readonly class Source
{
    public const string DEFAULT_REPO = 'khymerao/pc-theme-release-dist';
    public const string DEFAULT_BRANCH = 'main';
    public const string CONST_REPO = 'PCTR_SELF_UPDATE_REPO';
    public const string CONST_BRANCH = 'PCTR_SELF_UPDATE_BRANCH';
    /** The plugin's main file, at the root of the repository and of its tag archive. */
    public const string MAIN_FILE = 'pc-theme-release.php';

    /** @param list<string> $invalid names of the constants that were set but ignored */
    private function __construct(public RepoName $repo, public string $branch, public array $invalid)
    {
    }

    public static function fromEnvironment(Environment $env): self
    {
        $invalid = [];
        $repo = RepoName::fromString(self::DEFAULT_REPO);
        $repoValue = $env->constant(self::CONST_REPO);
        if (null !== $repoValue) {
            try {
                $repo = RepoName::fromString($repoValue);
            } catch (ValidationError) {
                $invalid[] = self::CONST_REPO;
            }
        }
        $branch = self::DEFAULT_BRANCH;
        $branchValue = $env->constant(self::CONST_BRANCH);
        if (null !== $branchValue) {
            if (self::validBranch($branchValue)) {
                $branch = $branchValue;
            } else {
                $invalid[] = self::CONST_BRANCH;
            }
        }

        return new self($repo, $branch, $invalid);
    }

    /**
     * A branch name git and a URL path both take as is: letters, digits, ".", "_", "-" and "/" between parts; no part
     * empty or starting with "." or "-", no "..", not ending in ".lock" (git check-ref-format, narrowed).
     */
    public static function validBranch(string $branch): bool
    {
        if (1 !== preg_match('#^[A-Za-z0-9._/-]{1,100}$#D', $branch) || str_contains($branch, '..') || str_ends_with($branch, '.lock')) {
            return false;
        }
        foreach (explode('/', $branch) as $part) {
            if ('' === $part || '.' === $part[0] || '-' === $part[0]) {
                return false;
            }
        }

        return true;
    }

    /** "owner/repo@branch": the cache is valid only for the source it was read from. */
    public function key(): string
    {
        return $this->repo->full() . '@' . $this->branch;
    }

    /** The main file on the branch (raw.githubusercontent.com: no API quota, about 1.5 KB). */
    public function rawUrl(): string
    {
        $branch = implode('/', array_map('rawurlencode', explode('/', $this->branch)));

        return 'https://raw.githubusercontent.com/' . $this->repo->full() . '/' . $branch . '/' . self::MAIN_FILE;
    }

    /** The tag archive of a version (github.com answers 302 to codeload; no API quota, no Authorization). */
    public function packageUrl(string $version): string
    {
        return $this->homepage() . '/archive/refs/tags/v' . rawurlencode($version) . '.zip';
    }

    public function homepage(): string
    {
        return 'https://github.com/' . $this->repo->full();
    }

    public function tagsUrl(): string
    {
        return $this->homepage() . '/tags';
    }
}
