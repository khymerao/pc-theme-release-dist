<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Update;

use ProCoders\ThemeRelease\Config\SiteContext;
use ProCoders\ThemeRelease\GitHub\GitHubClient;
use ProCoders\ThemeRelease\Theme\MappingRepository;

/**
 * Feeds managed themes into WordPress core's own theme-update flow, and keeps wordpress.org out of it.
 */
final class UpdateInjector
{
    private const string WPORG_CHECK = 'api.wordpress.org/themes/update-check/';

    public function __construct(
        private readonly UpdateOffer $offers,
        private readonly MappingRepository $mappings,
        private readonly SiteContext $site,
    ) {
    }

    /**
     * Filter `pre_set_site_transient_update_themes`: own the entries of managed themes.
     */
    public function filterTransient(mixed $transient): mixed
    {
        if (! $transient instanceof \stdClass) {
            return $transient;
        }
        $response = isset($transient->response) && is_array($transient->response) ? $transient->response : [];
        $noUpdate = isset($transient->no_update) && is_array($transient->no_update) ? $transient->no_update : [];
        $operational = null !== $this->site->channel();
        $statuses = $this->offers->all();

        // An offer this plugin made for a theme it no longer manages is withdrawn.
        foreach ($response as $stylesheet => $entry) {
            $package = is_array($entry) && is_string($entry['package'] ?? null) ? $entry['package'] : '';
            if (! isset($statuses[$stylesheet]) && str_starts_with($package, GitHubClient::API_BASE . '/repos/')) {
                unset($response[$stylesheet]);
            }
        }

        foreach ($statuses as $stylesheet => $status) {
            unset($response[$stylesheet], $noUpdate[$stylesheet]);
            $entry = [
                'theme' => $stylesheet,
                'new_version' => $status->installedLabel,
                'url' => 'https://github.com/' . $status->mapping->repo->full(),
                'package' => '',
                'requires' => '',
                'requires_php' => '',
            ];
            if ($operational && null !== $status->offer) {
                $entry['new_version'] = ltrim($status->offer->tag, 'vV');
                $entry['package'] = UpdateOffer::packageUrl($status->mapping->repo, $status->offer->sha);
                $response[$stylesheet] = $entry;
                continue;
            }
            $noUpdate[$stylesheet] = $entry;
        }
        $transient->response = $response;
        $transient->no_update = $noUpdate;

        return $transient;
    }

    /**
     * Filter `http_request_args`: managed themes are never sent to wordpress.org (no name leak, no collision).
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function stripWpOrgRequest(array $args, string $url): array
    {
        if (! str_contains($url, self::WPORG_CHECK) || ! is_array($args['body'] ?? null) || ! is_string($args['body']['themes'] ?? null)) {
            return $args;
        }
        $request = json_decode($args['body']['themes'], true);
        if (! is_array($request)) {
            return $args;
        }
        $managed = array_keys($this->mappings->all());
        foreach ($managed as $stylesheet) {
            if (is_array($request['themes'] ?? null)) {
                unset($request['themes'][$stylesheet]);
            }
            if (($request['active'] ?? null) === $stylesheet) {
                $request['active'] = '';
            }
        }
        $args['body']['themes'] = (string) wp_json_encode($request);

        return $args;
    }

    /** Filter `auto_update_theme`: managed themes are updated manually only. */
    public function disableAutoUpdate(mixed $update, mixed $item): mixed
    {
        $stylesheet = is_object($item) && isset($item->theme) && is_string($item->theme) ? $item->theme : '';

        return null !== $this->mappings->get($stylesheet) ? false : $update;
    }

    /** Filter `theme_auto_update_setting_html` (list table and single theme screen). */
    public function autoUpdateHtml(string $html, string $stylesheet): string
    {
        return null !== $this->mappings->get($stylesheet) ? esc_html(self::managedText()) : $html;
    }

    /** Filter `theme_auto_update_setting_template` (Themes grid modal, Underscore template). */
    public function autoUpdateTemplate(string $template): string
    {
        $managed = array_values(array_filter(
            array_map('strval', array_keys($this->mappings->all())),
            static fn (string $stylesheet): bool => 1 === preg_match('/^[A-Za-z0-9._-]+$/', $stylesheet),
        ));
        if ([] === $managed) {
            return $template;
        }
        $list = (string) wp_json_encode($managed, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return '<# if ( ' . $list . '.indexOf( data.id ) !== -1 ) { #><p>' . esc_html(self::managedText()) . '</p><# } else { #>'
            . $template . '<# } #>';
    }

    /**
     * Re-runs the transient through core's set path so the filter above re-injects;
     * touches nothing but `update_themes`.
     */
    public function refresh(): void
    {
        $current = get_site_transient('update_themes');
        if (is_object($current)) {
            set_site_transient('update_themes', $current);
        }
    }

    private static function managedText(): string
    {
        return __('Managed by ProCoders Theme Release (manual updates only)', 'pc-theme-release');
    }
}
