<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Health;

use ProCoders\ThemeRelease\Status\Health;
use ProCoders\ThemeRelease\Status\HealthItem;

/**
 * Three direct Site Health tests (Tools → Site Health is admin-only in core).
 */
final class Module
{
    /** Site Health test id → the Health group it shows. */
    private const array TESTS = [
        'pc_theme_release_config' => 'configTest',
        'pc_theme_release_cron' => 'cronTest',
        'pc_theme_release_repos' => 'reposTest',
    ];

    public function __construct(private readonly Health $health)
    {
    }

    public function register(): void
    {
        add_filter('site_status_tests', [$this, 'addTests']);
    }

    /**
     * Filter `site_status_tests`.
     *
     * @param array<string, mixed> $tests
     * @return array<string, mixed>
     */
    public function addTests(array $tests): array
    {
        foreach (self::TESTS as $id => $method) {
            $tests['direct'][$id] = ['label' => self::label($id), 'test' => [$this, $method]];
        }

        return $tests;
    }

    /** @return array<string, mixed> */
    public function configTest(): array
    {
        return self::result('pc_theme_release_config', $this->health->config());
    }

    /** @return array<string, mixed> */
    public function cronTest(): array
    {
        $result = self::result('pc_theme_release_cron', $this->health->cron());
        if (HealthItem::GOOD !== $result['status']) {
            $result['description'] .= '<p>' . esc_html(Health::serverCronHint()) . '</p>';
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function reposTest(): array
    {
        return self::result('pc_theme_release_repos', $this->health->repos());
    }

    private static function label(string $test): string
    {
        return match ($test) {
            'pc_theme_release_config' => __('ProCoders Theme Release configuration', 'pc-theme-release'),
            'pc_theme_release_cron' => __('ProCoders Theme Release scheduled check', 'pc-theme-release'),
            default => __('ProCoders Theme Release repositories', 'pc-theme-release'),
        };
    }

    /**
     * @param list<HealthItem> $items
     * @return array{label: string, status: string, badge: array{label: string, color: string}, description: string, test: string}
     */
    private static function result(string $test, array $items): array
    {
        $description = '';
        foreach ($items as $item) {
            $description .= '<p>' . esc_html($item->label) . '</p>';
        }

        return [
            'label' => self::label($test),
            'status' => HealthItem::worst($items),
            'badge' => ['label' => __('Theme Release', 'pc-theme-release'), 'color' => 'blue'],
            'description' => $description,
            'test' => $test,
        ];
    }
}
