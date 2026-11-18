<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Update;

use ProCoders\ThemeRelease\GitHub\RepoName;

/**
 * One package the plugin supplied to the core upgrader during this request.
 */
final class InstallRun
{
    public string $packageVersion = '';

    public function __construct(
        public readonly string $stylesheet,
        public readonly RepoName $repo,
        public readonly string $tag,
        public readonly string $sha,
        public readonly InstallMode $mode,
    ) {
    }
}
