<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Update;

use ProCoders\ThemeRelease\Theme\ThemeDirectory;

/**
 * Filter `upgrader_source_selection`: light integrity check of a package the plugin supplied
 * (spec call 3) and rename of GitHub's "owner-repo-sha" folder to the theme's stylesheet.
 */
final class SourceGuard
{
    public function __construct(
        private readonly InstallContext $context,
        private readonly ThemeDirectory $themes,
        private readonly PackageFiles $files,
    ) {
    }

    /** @param array<string, mixed> $hookExtra */
    public function select(mixed $source, mixed $remoteSource, mixed $upgrader = null, array $hookExtra = []): mixed
    {
        $stylesheet = is_string($hookExtra['theme'] ?? null) ? $hookExtra['theme'] : '';
        $run = $this->context->run($stylesheet);
        if (null === $run || ! is_string($source) || ! is_string($remoteSource)) {
            return $source;
        }
        $root = rtrim($remoteSource, '/');
        $entries = $this->files->children($root);
        if (1 !== count($entries) || ! $this->files->isDir($root . '/' . $entries[0])) {
            return $this->fail(__('The package must contain exactly one top-level folder.', 'pc-theme-release'));
        }
        $folder = $root . '/' . $entries[0];
        if (rtrim($source, '/') !== $folder || ! $this->files->isFile($folder . '/style.css')) {
            return $this->fail(__('The package has no style.css in its top-level folder.', 'pc-theme-release'));
        }
        $headers = $this->files->themeHeaders($folder . '/style.css');
        if (! $this->identifies($headers, $stylesheet)) {
            return $this->fail(__('The package is not the mapped theme (Theme Name and Text Domain do not match).', 'pc-theme-release'));
        }
        $run->packageVersion = $headers['version'];

        $target = $root . '/' . $stylesheet;
        if ($folder !== $target && ! $this->files->move($folder, $target)) {
            return $this->fail(__('The package folder could not be renamed.', 'pc-theme-release'));
        }

        return $target . '/';
    }

    /** @param array{name: string, text_domain: string, version: string} $headers */
    private function identifies(array $headers, string $stylesheet): bool
    {
        $domain = $this->themes->textDomain($stylesheet);
        $name = $this->themes->name($stylesheet);

        return ('' !== $domain && $headers['text_domain'] === $domain)
            || ('' !== $name && $headers['name'] === $name);
    }

    private function fail(string $message): \WP_Error
    {
        return new \WP_Error('pc_theme_release_package', $message);
    }
}
