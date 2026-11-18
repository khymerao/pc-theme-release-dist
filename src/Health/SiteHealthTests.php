<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Health;

/**
 * Registers three direct Site Health tests (Tools → Site Health is admin-only in core).
 */
final class SiteHealthTests
{
    public function __construct(
        private readonly HealthReport $report,
        private readonly CronHealth $cron,
        private readonly RepoHealth $repos,
    ) {
    }

    /**
     * @param array<string, mixed> $tests
     * @return array<string, mixed>
     */
    public function register(array $tests): array
    {
        $tests['direct']['pc_theme_release_config'] = [
            'label' => __('ProCoders Theme Release configuration', 'pc-theme-release'),
            'test' => [$this, 'configTest'],
        ];
        $tests['direct']['pc_theme_release_cron'] = [
            'label' => __('ProCoders Theme Release scheduled check', 'pc-theme-release'),
            'test' => [$this, 'cronTest'],
        ];

        $tests['direct']['pc_theme_release_repos'] = [
            'label' => __('ProCoders Theme Release repositories', 'pc-theme-release'),
            'test' => [$this, 'reposTest'],
        ];

        return $tests;
    }

    /** @return array<string, mixed> */
    public function reposTest(): array
    {
        return $this->result('pc_theme_release_repos', __('ProCoders Theme Release repositories', 'pc-theme-release'), $this->repos->items());
    }

    /** @return array<string, mixed> */
    public function configTest(): array
    {
        return $this->result('pc_theme_release_config', __('ProCoders Theme Release configuration', 'pc-theme-release'), $this->report->items());
    }

    /** @return array<string, mixed> */
    public function cronTest(): array
    {
        $items = $this->cron->items();
        $result = $this->result('pc_theme_release_cron', __('ProCoders Theme Release scheduled check', 'pc-theme-release'), $items);
        if (HealthItem::GOOD !== $result['status']) {
            $result['description'] .= '<p>' . esc_html(CronHealth::serverCronHint()) . '</p>';
        }

        return $result;
    }

    /**
     * @param list<HealthItem> $items
     * @return array{label: string, status: string, badge: array{label: string, color: string}, description: string, test: string}
     */
    private function result(string $test, string $label, array $items): array
    {
        $description = '';
        foreach ($items as $item) {
            $description .= '<p>' . esc_html($item->label) . '</p>';
        }

        return [
            'label' => $label,
            'status' => HealthItem::worst($items),
            'badge' => ['label' => __('Theme Release', 'pc-theme-release'), 'color' => 'blue'],
            'description' => $description,
            'test' => $test,
        ];
    }
}
