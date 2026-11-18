<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Webhook;

use ProCoders\ThemeRelease\Support\Keys;

/**
 * WordPress REST adapter for the webhook: the only public endpoint of the plugin.
 */
final class RestRoute
{
    public function __construct(private readonly WebhookController $controller)
    {
    }

    public function register(): void
    {
        register_rest_route(Keys::REST_NAMESPACE, '/webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'handle'],
            'permission_callback' => [$this, 'permission'],
        ]);
    }

    public function permission(\WP_REST_Request $request): bool|\WP_Error
    {
        $refusal = $this->controller->authorize($this->toRequest($request));
        if (null === $refusal) {
            return true;
        }

        return new \WP_Error('pc_theme_release_webhook', (string) ($refusal->body['error'] ?? 'Error.'), ['status' => $refusal->status]);
    }

    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $result = $this->controller->process($this->toRequest($request));
        $response = new \WP_REST_Response($result->body, $result->status);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    private function toRequest(\WP_REST_Request $request): WebhookRequest
    {
        return new WebhookRequest(
            (string) $request->get_body(),
            (string) $request->get_header('content_type'),
            $request->get_header('x_hub_signature_256'),
            $request->get_header('x_github_event'),
            $request->get_header('x_github_delivery'),
            self::realMethod(),
        );
    }

    private static function realMethod(): string
    {
        return isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))) : '';
    }
}
