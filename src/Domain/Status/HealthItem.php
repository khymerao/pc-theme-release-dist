<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Domain\Status;

/**
 * One health finding: a Site Health status (good, recommended, critical), a stable code and its text.
 */
final readonly class HealthItem
{
    public const string GOOD = 'good';
    public const string RECOMMENDED = 'recommended';
    public const string CRITICAL = 'critical';
    private const array RANK = [self::GOOD => 0, self::RECOMMENDED => 1, self::CRITICAL => 2];

    public function __construct(public string $status, public string $code, public string $label)
    {
    }

    public function isProblem(): bool
    {
        return self::GOOD !== $this->status;
    }

    /** The admin notice class for a problem: critical is an error, recommended a warning. */
    public function noticeLevel(): string
    {
        return self::CRITICAL === $this->status ? 'error' : 'warning';
    }

    /** The status as the Health & log tab shows it. */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::GOOD => __('Good', 'pc-theme-release'),
            self::RECOMMENDED => __('Recommended', 'pc-theme-release'),
            self::CRITICAL => __('Critical', 'pc-theme-release'),
            default => $this->status,
        };
    }

    /** @param list<HealthItem> $items */
    public static function worst(array $items): string
    {
        $worst = self::GOOD;
        foreach ($items as $item) {
            if (self::RANK[$item->status] > self::RANK[$worst]) {
                $worst = $item->status;
            }
        }

        return $worst;
    }
}
