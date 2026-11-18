<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Update;

use ProCoders\ThemeRelease\Release\StatusLog;
use ProCoders\ThemeRelease\Support\Clock;
use ProCoders\ThemeRelease\Theme\InstallRecord;
use ProCoders\ThemeRelease\Theme\MappingRepository;

/**
 * Filter `upgrader_post_install` (fires per theme, also inside core's bulk upgrader, and only after the
 * files were installed): writes the version record. A failed install never reaches it (A21).
 */
final class InstallRecorder
{
    public function __construct(
        private readonly InstallContext $context,
        private readonly MappingRepository $mappings,
        private readonly StatusLog $log,
        private readonly Clock $clock,
    ) {
    }

    public function afterInstall(mixed $response, mixed $hookExtra = [], mixed $result = null): mixed
    {
        $stylesheet = is_array($hookExtra) && is_string($hookExtra['theme'] ?? null) ? $hookExtra['theme'] : '';
        $run = $this->context->finish($stylesheet);
        if (null === $run) {
            return $response;
        }
        if (is_wp_error($response) || false === $response) {
            $this->log->add('install_failed', $run->repo->full(), $run->tag);
            return $response;
        }
        $mismatch = '' !== $run->packageVersion && ltrim($run->tag, 'vV') !== $run->packageVersion;
        $this->mappings->recordInstall(
            $run->stylesheet,
            new InstallRecord($run->tag, $run->sha, $this->clock->now(), $mismatch),
            InstallMode::Rollback === $run->mode,
        );
        $this->context->clearExpected($run->stylesheet);
        $event = match ($run->mode) {
            InstallMode::Rollback => 'rolled_back',
            InstallMode::Switch => 'switched',
            default => 'updated',
        };
        $this->log->add($event, $run->repo->full(), $run->tag);

        return $response;
    }
}
