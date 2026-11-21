<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Modules\Webhook;

use ProCoders\ThemeRelease\Core\Config\SiteContext;
use ProCoders\ThemeRelease\Core\GitHub\RepoName;
use ProCoders\ThemeRelease\Core\Security\Secrets;
use ProCoders\ThemeRelease\Core\Support\Environment;
use ProCoders\ThemeRelease\Core\Support\Keys;
use ProCoders\ThemeRelease\Core\Support\Storage;
use ProCoders\ThemeRelease\Core\Support\ValidationError;
use ProCoders\ThemeRelease\Domain\Release\Checker;
use ProCoders\ThemeRelease\Domain\Release\StateStore;
use ProCoders\ThemeRelease\Domain\Release\StatusLog;
use ProCoders\ThemeRelease\Domain\Theme\Mapping;
use ProCoders\ThemeRelease\Domain\Theme\MappingRepository;

/**
 * POST /wp-json/pc-theme-release/v1/webhook, the only public endpoint of the plugin. The payload is
 * trusted for nothing beyond "repository X changed": the plugin re-reads tags from the API itself.
 * Order (plan C9): method → size → content type → repository → that theme's signature; only a signed delivery
 * touches any state.
 */
final class Module
{
    public const int MAX_BODY = 1048576;
    /** Valid deliveries per rolling window, site-wide (plan O5). */
    public const int RATE_LIMIT = 30;
    public const int RATE_WINDOW = 60;
    /** Seconds after a webhook-triggered check of a repository during which later deliveries trigger none. */
    public const int CHECK_INTERVAL = 60;
    /** Replay protection lasts this long. */
    public const int REPLAY_TTL = 86400;
    /** JSON nesting read from an unsigned body; GitHub's payloads are far shallower. */
    public const int MAX_DEPTH = 64;
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** @var (\Closure(): self)|null Builds this module (and the services it needs) on first use. */
    private static ?\Closure $factory = null;
    private static ?self $instance = null;

    public function __construct(
        private readonly SiteContext $site,
        private readonly Secrets $secrets,
        private readonly Checker $checker,
        private readonly StateStore $state,
        private readonly StatusLog $log,
        private readonly Storage $storage,
        private readonly Environment $env,
        private readonly MappingRepository $mappings,
    ) {
    }

    /**
     * The route is registered on `rest_api_init`; the module is built only when a delivery arrives.
     *
     * @param \Closure(): self $factory
     */
    public static function hook(\Closure $factory): void
    {
        self::$factory = $factory;
        self::$instance = null;
        add_action('rest_api_init', [self::class, 'registerRoute']);
    }

    /** The module, built on the first hook that needs it. */
    private static function instance(): self
    {
        if (null === self::$instance) {
            $factory = self::$factory ?? throw new \LogicException('Module not hooked.');
            self::$instance = $factory();
        }

        return self::$instance;
    }

    /** Whether anything in this request needed the module (frontend requests never do). */
    public static function built(): bool
    {
        return null !== self::$instance;
    }

    /** `rest_api_init`. */
    public static function registerRoute(): void
    {
        register_rest_route(Keys::REST_NAMESPACE, '/webhook', [
            'methods' => 'POST',
            'callback' => [self::class, 'onDelivery'],
            'permission_callback' => [self::class, 'onPermission'],
        ]);
    }

    public static function onPermission(\WP_REST_Request $request): bool|\WP_Error
    {
        return self::instance()->permission($request);
    }

    public static function onDelivery(\WP_REST_Request $request): \WP_REST_Response
    {
        return self::instance()->handle($request);
    }

    public function permission(\WP_REST_Request $request): bool|\WP_Error
    {
        $refusal = $this->authorize($request);
        if (null === $refusal) {
            return true;
        }

        return new \WP_Error('pc_theme_release_webhook', (string) ($refusal->body['error'] ?? 'Error.'), ['status' => $refusal->status]);
    }

    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $result = $this->process($request);
        $response = new \WP_REST_Response($result->body, $result->status);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    /** Null when the request may proceed, else the refusal. Writes nothing. */
    public function authorize(\WP_REST_Request $request): ?WebhookResult
    {
        $verified = $this->verify($request);

        return $verified instanceof WebhookResult ? $verified : null;
    }

    /**
     * A delivery that passed the permission check. It is authorised again here, so the callback never
     * relies on WordPress having run the permission callback first.
     */
    public function process(\WP_REST_Request $request): WebhookResult
    {
        $verified = $this->verify($request);
        if ($verified instanceof WebhookResult) {
            return $verified;
        }
        [$mapping, $payload] = $verified;
        $delivery = $request->get_header('x_github_delivery');
        if (! self::isValidDeliveryId($delivery)) {
            return WebhookResult::error(400);
        }
        $body = (string) $request->get_body();
        $refusal = $this->accept((string) $delivery, $body);
        if (null !== $refusal) {
            return $refusal;
        }
        // A first-seen valid delivery (ping included) proves GitHub reaches this site for that repository: its time
        // clears an older failed delivery of that repository (webhook alert spec call 13).
        $this->state->setReceived($mapping->repo, $this->env->now());

        return $this->dispatch((string) $request->get_header('x_github_event'), $payload, $mapping);
    }

    /**
     * Method, size and content type first; then the unsigned body is decoded (depth-capped) only to find the managed
     * theme(s) it names, and the signature is checked with each one's secret (per-theme secret spec call 4). The
     * verified mapping and the decoded payload are returned, so nothing is looked up by the payload again.
     *
     * @return WebhookResult|array{0: Mapping, 1: array<mixed>}
     */
    private function verify(\WP_REST_Request $request): WebhookResult|array
    {
        $body = (string) $request->get_body();
        // WP_REST_Server honours `?_method=` and X-HTTP-Method-Override; only a real POST may run.
        if ('POST' !== self::realMethod()) {
            return WebhookResult::error(405);
        }
        if (strlen($body) > self::MAX_BODY) {
            return WebhookResult::error(413);
        }
        if (1 !== preg_match('#^application/json\s*(;|$)#i', trim((string) $request->get_header('content_type')))) {
            return WebhookResult::error(415);
        }
        if (null === $this->site->channel()) {
            return WebhookResult::error(401);
        }
        $payload = json_decode($body, true, self::MAX_DEPTH);
        if (! is_array($payload)) {
            return WebhookResult::error(401);
        }
        $header = $request->get_header('x_hub_signature_256');
        foreach ($this->candidates($payload) as $mapping) {
            $secret = $this->secrets->webhookSecretFor($mapping->stylesheet, $mapping->repo->full());
            if (null !== $secret && self::signatureMatches($secret, $body, $header)) {
                return [$mapping, $payload];
            }
        }

        // An unknown repository gets the same answer as a bad signature.
        return WebhookResult::error(401);
    }

    /**
     * The managed themes a delivery may be about: by the repository's GitHub id first (a renamed repository keeps its
     * id), else by name when the stored id (if any) is the payload's. A payload that names no repository (a ping,
     * plan O1) may be about any managed theme; one that names an unmanaged repository is about none.
     *
     * @param array<mixed> $payload
     * @return list<Mapping>
     */
    private function candidates(array $payload): array
    {
        $repository = $payload['repository'] ?? null;
        if (! is_array($repository)) {
            return array_values($this->mappings->all());
        }
        $id = $repository['id'] ?? null;
        $id = is_int($id) && $id > 0 ? $id : null;
        $candidates = [];
        foreach ($this->mappings->all() as $mapping) {
            if (null !== $id && $id === $mapping->repoId) {
                $candidates[$mapping->stylesheet] = $mapping;
            }
        }
        $full = $repository['full_name'] ?? null;
        try {
            $name = RepoName::fromString(is_string($full) ? $full : '');
        } catch (ValidationError) {
            $name = null;
        }
        $byName = null === $name ? null : $this->mappings->findByRepo($name);
        if (null !== $byName && (null === $byName->repoId || null === $id || $id === $byName->repoId)) {
            $candidates[$byName->stylesheet] = $byName;
        }

        return array_values($candidates);
    }

    /**
     * Replays first: only first-seen deliveries count toward the limit (audit S4), and a delivery
     * refused with 429 is not remembered, so GitHub's redelivery of it is processed later.
     */
    private function accept(string $delivery, string $body): ?WebhookResult
    {
        if ($this->seen($delivery, $body)) {
            return WebhookResult::ignored(200);
        }
        if (! $this->allowRate()) {
            return WebhookResult::error(429);
        }
        $this->storage->setTransient(self::deliveryKey($delivery), 1, self::REPLAY_TTL);
        $this->storage->setTransient(self::bodyKey($body), 1, self::REPLAY_TTL);
        $this->state->setInt(Keys::LAST_WEBHOOK_AT, $this->env->now());

        return null;
    }

    /** @param array<mixed> $payload */
    private function dispatch(string $event, array $payload, Mapping $mapping): WebhookResult
    {
        if ('ping' === $event) {
            $this->log->add('webhook_ping');
            return WebhookResult::ok();
        }
        if (! self::isTagEvent($event, $payload)) {
            return WebhookResult::ignored();
        }
        $repo = $mapping->repo;
        // At most one webhook-triggered check per repository per CHECK_INTERVAL (review F1): a burst of
        // signed tag events is accepted, but cannot spend the token's hourly GitHub quota.
        if (null !== $this->storage->transient(self::checkKey($repo))) {
            return WebhookResult::ok();
        }
        if (! $this->checker->checkRepo($repo, 'webhook')) {
            return WebhookResult::ignored();
        }
        $this->storage->setTransient(self::checkKey($repo), 1, self::CHECK_INTERVAL);

        return WebhookResult::ok();
    }

    private static function checkKey(RepoName $repo): string
    {
        return Keys::DELIVERY_PREFIX . 'chk_' . md5($repo->full());
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

    /** GitHub's X-Hub-Signature-256: "sha256=" + hex HMAC-SHA256 of the raw body, compared in constant time. */
    private static function signatureMatches(string $secret, string $body, ?string $header): bool
    {
        if ('' === $secret || null === $header || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256=' . hash_hmac('sha256', $body, $secret), $header);
    }

    private static function isValidDeliveryId(?string $delivery): bool
    {
        return null !== $delivery && 1 === preg_match(self::UUID, strtolower(trim($delivery)));
    }

    /**
     * The X-GitHub-Delivery id is not covered by the signature, so a delivery counts as seen when either
     * its id (a UUID, case-insensitive) or the SHA-256 of its signed raw body was seen in the last 24 hours.
     */
    private function seen(string $delivery, string $body): bool
    {
        return null !== $this->storage->transient(self::deliveryKey($delivery))
            || null !== $this->storage->transient(self::bodyKey($body));
    }

    /** Site-wide rolling window that counts only deliveries that passed the signature check. */
    private function allowRate(): bool
    {
        $now = $this->env->now();
        $raw = $this->storage->transient(Keys::RATE_WINDOW);
        $hits = array_values(array_filter(
            is_array($raw) ? $raw : [],
            static fn (mixed $time): bool => is_int($time) && $time > $now - self::RATE_WINDOW,
        ));
        if (count($hits) >= self::RATE_LIMIT) {
            return false;
        }
        $hits[] = $now;
        $this->storage->setTransient(Keys::RATE_WINDOW, $hits, self::RATE_WINDOW);

        return true;
    }

    private static function deliveryKey(string $delivery): string
    {
        return Keys::DELIVERY_PREFIX . 'id_' . md5(strtolower(trim($delivery)));
    }

    private static function bodyKey(string $body): string
    {
        return Keys::DELIVERY_PREFIX . 'body_' . hash('sha256', $body);
    }

    /** The real HTTP method of the request, not WordPress's `_method` / X-HTTP-Method-Override view. */
    private static function realMethod(): string
    {
        return isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))) : '';
    }
}
