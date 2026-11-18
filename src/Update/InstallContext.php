<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Update;

/**
 * Request-scoped hand-over between the upgrader hooks (download → source selection → post install).
 * Keyed by stylesheet because core's bulk upgrader runs several themes in one request.
 */
final class InstallContext
{
    /** @var array<string, InstallRun> */
    private array $runs = [];

    /** @var array<string, array{sha: string, mode: InstallMode}> stylesheet → what the rollback runner expects */
    private array $expected = [];

    public function begin(InstallRun $run): void
    {
        $this->runs[$run->stylesheet] = $run;
    }

    public function run(string $stylesheet): ?InstallRun
    {
        return $this->runs[$stylesheet] ?? null;
    }

    public function finish(string $stylesheet): ?InstallRun
    {
        $run = $this->runs[$stylesheet] ?? null;
        unset($this->runs[$stylesheet]);

        return $run;
    }

    /** The runner is about to install this SHA itself (rollback or channel switch), holding the lock. */
    public function expect(string $stylesheet, string $sha, InstallMode $mode): void
    {
        $this->expected[$stylesheet] = ['sha' => $sha, 'mode' => $mode];
    }

    /** The mode the runner announced for exactly this SHA, or null (a core update). */
    public function expectedMode(string $stylesheet, string $sha): ?InstallMode
    {
        $expected = $this->expected[$stylesheet] ?? null;

        return null !== $expected && $expected['sha'] === $sha ? $expected['mode'] : null;
    }

    public function clearExpected(string $stylesheet): void
    {
        unset($this->expected[$stylesheet]);
    }
}
