<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Webhook;

final readonly class WebhookRequest
{
    public function __construct(
        public string $body,
        public string $contentType,
        public ?string $signature,
        public ?string $event,
        public ?string $delivery,
        /** The real HTTP method of the request, not WordPress's `_method` / X-HTTP-Method-Override view. */
        public string $method = 'POST',
    ) {
    }
}
