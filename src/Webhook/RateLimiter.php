<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Webhook;

use ProCoders\ThemeRelease\Support\Clock;
use ProCoders\ThemeRelease\Support\Keys;
use ProCoders\ThemeRelease\Support\TransientStore;

/**
 * Site-wide rolling window for valid webhook deliveries (plan O5): 30 per 60 seconds.
 * Counts only deliveries that passed the signature check, so a flood of forged calls cannot lock GitHub out.
 */
final class RateLimiter
{
    public const int LIMIT = 30;
    public const int WINDOW = 60;

    public function __construct(private readonly TransientStore $store, private readonly Clock $clock)
    {
    }

    public function allow(): bool
    {
        $now = $this->clock->now();
        $raw = $this->store->get(Keys::RATE_WINDOW);
        $hits = array_values(array_filter(
            is_array($raw) ? $raw : [],
            static fn (mixed $time): bool => is_int($time) && $time > $now - self::WINDOW,
        ));
        if (count($hits) >= self::LIMIT) {
            return false;
        }
        $hits[] = $now;
        $this->store->set(Keys::RATE_WINDOW, $hits, self::WINDOW);

        return true;
    }
}
