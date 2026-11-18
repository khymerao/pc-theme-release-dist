<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Update;

final class WpPackageFiles implements PackageFiles
{
    public function children(string $dir): array
    {
        $list = $this->fs()?->dirlist($dir);

        return is_array($list) ? array_map('strval', array_keys($list)) : [];
    }

    public function isDir(string $path): bool
    {
        return true === $this->fs()?->is_dir($path);
    }

    public function isFile(string $path): bool
    {
        return true === $this->fs()?->is_file($path);
    }

    public function themeHeaders(string $styleCss): array
    {
        $headers = get_file_data($styleCss, ['name' => 'Theme Name', 'text_domain' => 'Text Domain', 'version' => 'Version']);

        return [
            'name' => (string) ($headers['name'] ?? ''),
            'text_domain' => (string) ($headers['text_domain'] ?? ''),
            'version' => (string) ($headers['version'] ?? ''),
        ];
    }

    public function move(string $from, string $to): bool
    {
        return true === $this->fs()?->move($from, $to, true);
    }

    private function fs(): ?\WP_Filesystem_Base
    {
        global $wp_filesystem;

        return $wp_filesystem instanceof \WP_Filesystem_Base ? $wp_filesystem : null;
    }
}
