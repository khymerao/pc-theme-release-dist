<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Webhook;

use ProCoders\ThemeRelease\Config\SiteContext;
use ProCoders\ThemeRelease\GitHub\RepoName;
use ProCoders\ThemeRelease\Release\Checker;
use ProCoders\ThemeRelease\Release\StateKey;
use ProCoders\ThemeRelease\Release\StateStore;
use ProCoders\ThemeRelease\Release\StatusLog;
use ProCoders\ThemeRelease\Security\Secrets;
use ProCoders\ThemeRelease\Support\Clock;
use ProCoders\ThemeRelease\Support\ValidationError;

/**
 * POST /wp-json/pc-theme-release/v1/webhook. The payload is trusted for nothing beyond
 * "repository X changed": the plugin re-reads tags from the API itself.
 * Order (plan C9): size → content type → signature; only a signed delivery touches any state.
 */
final class WebhookController
{
    public const int MAX_BODY = 1048576;

    public function __construct(
        private readonly SiteContext $site,
        private readonly Secrets $secrets,
        private readonly SignatureVerifier $verifier,
        private readonly RateLimiter $limiter,
        private readonly ReplayGuard $replays,
        private readonly Checker $checker,
        private readonly StateStore $state,
        private readonly StatusLog $log,
        private readonly Clock $clock,
    ) {
    }

    /** Returns null when the request may proceed, else the refusal. Writes nothing. */
    public function authorize(WebhookRequest $request): ?WebhookResult
    {
        // WP_REST_Server honours `?_method=` and X-HTTP-Method-Override; only a real POST may run.
        if ('POST' !== $request->method) {
            return WebhookResult::error(405);
        }
        if (strlen($request->body) > self::MAX_BODY) {
            return WebhookResult::error(413);
        }
        if (1 !== preg_match('#^application/json\s*(;|$)#i', trim($request->contentType))) {
            return WebhookResult::error(415);
        }
        $secret = $this->secrets->webhookSecret();
        if (null === $secret || null === $this->site->channel()) {
            return WebhookResult::error(401);
        }
        if (! $this->verifier->verify($secret, $request->body, $request->signature)) {
            return WebhookResult::error(401);
        }

        return null;
    }

    public function process(WebhookRequest $request): WebhookResult
    {
        $refusal = $this->authorize($request);
        if (null !== $refusal) {
            return $refusal;
        }
        if (! ReplayGuard::isValidId($request->delivery)) {
            return WebhookResult::error(400);
        }
        $payload = json_decode($request->body, true);
        if (! is_array($payload)) {
            return WebhookResult::error(400);
        }
        if (! $this->limiter->allow()) {
            return WebhookResult::error(429);
        }
        if (! $this->replays->firstSeen((string) $request->delivery, $request->body)) {
            return WebhookResult::ignored(200);
        }
        $this->state->setInt(StateKey::LAST_WEBHOOK_AT, $this->clock->now());

        $event = (string) $request->event;
        if ('ping' === $event) {
            $this->log->add('webhook_ping');
            return WebhookResult::ok();
        }
        if (! self::isTagEvent($event, $payload)) {
            return WebhookResult::ignored();
        }
        $full = is_array($payload['repository'] ?? null) ? ($payload['repository']['full_name'] ?? null) : null;
        try {
            $repo = RepoName::fromString(is_string($full) ? $full : '');
        } catch (ValidationError) {
            return WebhookResult::ignored();
        }
        if (! $this->checker->checkRepo($repo, 'webhook')) {
            return WebhookResult::ignored();
        }

        return WebhookResult::ok();
    }

    /** @param array<mixed> $payload */
    private static function isTagEvent(string $event, array $payload): bool
    {
        return match ($event) {
            'create' => 'tag' === ($payload['ref_type'] ?? null),
            'push' => is_string($payload['ref'] ?? null) && str_starts_with($payload['ref'], 'refs/tags/'),
            default => false,
        };
    }
}
