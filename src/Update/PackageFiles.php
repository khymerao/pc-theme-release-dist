<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Update;

/**
 * File operations on an unpacked package (WP_Filesystem in production).
 */
interface PackageFiles
{
    /** @return list<string> Entry names directly inside $dir (no "." / ".."). */
    public function children(string $dir): array;

    public function isDir(string $path): bool;

    public function isFile(string $path): bool;

    /** @return array{name: string, text_domain: string, version: string} */
    public function themeHeaders(string $styleCss): array;

    public function move(string $from, string $to): bool;
}
