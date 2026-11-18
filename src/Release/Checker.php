<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Release;

use ProCoders\ThemeRelease\Config\SiteContext;
use ProCoders\ThemeRelease\GitHub\GitHubClient;
use ProCoders\ThemeRelease\GitHub\GitHubError;
use ProCoders\ThemeRelease\GitHub\RepoName;
use ProCoders\ThemeRelease\Theme\Mapping;
use ProCoders\ThemeRelease\Theme\MappingRepository;
use ProCoders\ThemeRelease\Update\UpdateInjector;
use ProCoders\ThemeRelease\Support\Clock;

/**
 * Re-checks GitHub for one or all mapped repositories (cron, Check now, webhook) and re-injects offers.
 * Never installs anything.
 */
final class Checker
{
    public function __construct(
        private readonly SiteContext $site,
        private readonly MappingRepository $mappings,
        private readonly GitHubClient $github,
        private readonly ReleaseResolver $resolver,
        private readonly StateStore $state,
        private readonly UpdateInjector $injector,
        private readonly StatusLog $log,
        private readonly Clock $clock,
    ) {
    }

    /** @return int Number of repositories checked (0 when the site is not operational). */
    public function checkAll(string $trigger): int
    {
        // A stored pair the plugin no longer accepts (e.g. theme "../uploads") is removed and logged once.
        foreach ($this->mappings->dropInvalid() as $stylesheet) {
            $this->log->add('mapping_dropped_invalid', $stylesheet);
        }
        if (! $this->site->isOperational()) {
            return 0;
        }
        $count = 0;
        foreach ($this->mappings->all() as $mapping) {
            $this->checkMapping($mapping, $trigger);
            $count++;
        }
        $this->state->setInt(StateKey::LAST_CHECK_AT, $this->clock->now());
        $this->injector->refresh();

        return $count;
    }

    /** Checks only the mapping of $repo; false when it is not managed or the site is not operational. */
    public function checkRepo(RepoName $repo, string $trigger): bool
    {
        $mapping = $this->mappings->findByRepo($repo);
        if (null === $mapping || ! $this->site->isOperational()) {
            return false;
        }
        $this->checkMapping($mapping, $trigger);
        $this->injector->refresh();

        return true;
    }

    /** Cron entry point. */
    public function runScheduled(): void
    {
        $this->state->setInt(StateKey::LAST_CRON_AT, $this->clock->now());
        $this->checkAll('cron');
    }

    private function checkMapping(Mapping $mapping, string $trigger): void
    {
        $channel = $this->site->channel();
        if (null === $channel) {
            return;
        }
        $previous = $this->state->repo($mapping->repo);
        $branch = $mapping->branch($this->site->settings(), $channel);
        try {
            $tags = $this->github->tags($mapping->repo);
            $state = $this->resolver->resolve($mapping->repo, $channel, $branch, $tags);
        } catch (GitHubError $error) {
            // Record why this round failed, for the channel and branch in force now, so the screen shows it.
            // Keep what we knew only when it was learnt for this same channel and branch.
            $context = RepoState::contextFor($channel, $branch);
            $same = $previous->context === $context;
            $state = new RepoState(
                $same ? $previous->releases : [],
                null,
                $same ? $previous->tagShas : [],
                $this->clock->now(),
                $error->kind->value,
                $mapping->repo->full(),
                $same ? $previous->warnings : [],
                $context,
            );
            $this->log->add($trigger . '_failed', $mapping->repo->full(), '', $error->httpStatus);
            $this->state->saveRepo($mapping->repo, $state);
            return;
        }
        $this->state->saveRepo($mapping->repo, $state);
        // A round whose newest tag could not be confirmed is reported as failed by Check now; log it apart.
        $event = in_array('containment_unknown', $state->warnings, true) ? '_unconfirmed' : '_checked';
        $this->log->add($trigger . $event, $mapping->repo->full(), null === $state->offerable ? '' : $state->offerable->tag);
    }
}
