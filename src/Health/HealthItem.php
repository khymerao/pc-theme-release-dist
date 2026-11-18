<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Health;

final readonly class HealthItem
{
    public const string GOOD = 'good';
    public const string RECOMMENDED = 'recommended';
    public const string CRITICAL = 'critical';

    public function __construct(public string $status, public string $code, public string $label)
    {
    }

    /** @param list<HealthItem> $items */
    public static function worst(array $items): string
    {
        $rank = [self::GOOD => 0, self::RECOMMENDED => 1, self::CRITICAL => 2];
        $worst = self::GOOD;
        foreach ($items as $item) {
            if ($rank[$item->status] > $rank[$worst]) {
                $worst = $item->status;
            }
        }

        return $worst;
    }
}
