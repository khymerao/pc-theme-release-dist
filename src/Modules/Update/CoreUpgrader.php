<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Modules\Update;

/**
 * Core's upgrader machinery: Theme_Upgrader, the WP_Upgrader option lock and WP_Filesystem on the
 * unpacked package. The boundary the install flow is tested against.
 */
interface CoreUpgrader
{
    /** Runs core's Theme_Upgrader::upgrade() for one theme; true on success. */
    public function upgrade(string $stylesheet): bool|\WP_Error;

    /** Takes the plugin's upgrader lock; false when another run holds it. */
    public function acquireLock(): bool;

    public function releaseLock(): void;

    /** @return list<string> Entry names directly inside $dir (no "." / ".."). */
    public function children(string $dir): array;

    public function isDir(string $path): bool;

    public function isFile(string $path): bool;

    /** @return array{name: string, text_domain: string, version: string} */
    public function themeHeaders(string $styleCss): array;

    public function move(string $from, string $to): bool;
}
