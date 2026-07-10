<?php

declare(strict_types=1);

namespace AppInIo\Frontend;

if (! defined('ABSPATH')) {
    exit;
}

final class SearchWidget
{
    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_filter('script_loader_tag', [$this, 'addModuleType'], 10, 2);
        add_action('wp_footer', [$this, 'renderElement']);
    }

    public function enqueueAssets(): void
    {
        if (empty($this->getSearchKey())) {
            return;
        }

        // null prevents WordPress from appending a ?ver= query. The CDN build is
        // versioned via its /v1/ path, and a spurious ?ver breaks the dev server.
        // phpcs:disable WordPress.WP.EnqueuedResourceParameters.MissingVersion
        wp_enqueue_script(
            'appinio-search-widget',
            APPINIO_CDN_URL,
            [],
            null,
            ['strategy' => 'defer', 'in_footer' => true]
        );
        // phpcs:enable WordPress.WP.EnqueuedResourceParameters.MissingVersion
    }

    public function renderElement(): void
    {
        $key = $this->getSearchKey();

        if (empty($key)) {
            return;
        }

        $attrs = \sprintf('api-key="%s" platform="woocommerce"', esc_attr($key));

        if (\function_exists('is_product_category') && is_product_category()) {
            $attrs .= \sprintf(' category-id="%d"', get_queried_object_id());
        }

        $selector = get_option('appinio_search_selector', '');

        if (! empty($selector)) {
            $attrs .= \sprintf(' input-selector="%s"', esc_attr($selector));
        }

        // Canonical "Show all results" target. The widget is platform-agnostic and only
        // knows a generic default; this store's real search URL (permalink-aware) is the
        // plugin's job. Built via get_search_link() so it honors both plain (?s=) and
        // pretty (/search/term/) permalinks, matching the results-page takeover, which
        // hooks is_search() + the `s` var for either form.
        $token = '__APPINIO_Q__';
        $showAllUrl = str_replace($token, '{query}', esc_url(get_search_link($token)));
        $attrs .= \sprintf(' show-all-url="%s"', $showAllUrl);

        // Widget appearance chosen by the store owner (the widget honors an explicit
        // `theme`, but which theme the store wants is the plugin's decision). `auto` is
        // resolved client-side from the host background — see themeScript().
        $theme = $this->getThemeOption();

        if ($theme === 'dark') {
            $attrs .= ' theme="dark"';
        }

        echo wp_kses(
            \sprintf('<semantic-search %s></semantic-search>', $attrs),
            ['semantic-search' => ['api-key' => true, 'platform' => true, 'category-id' => true, 'input-selector' => true, 'show-all-url' => true, 'theme' => true]]
        );

        if ($theme === 'auto') {
            wp_print_inline_script_tag($this->themeScript());
        }
    }

    /**
     * Resolve the widget appearance setting to one of light|dark|auto (defaults to light).
     */
    private function getThemeOption(): string
    {
        $theme = (string) get_option('appinio_widget_theme', 'light');

        return \in_array($theme, ['light', 'dark', 'auto'], true) ? $theme : 'light';
    }

    /**
     * Client-side "auto" theme: match the widget to the host store's background by
     * measuring body luminance after load and flipping <semantic-search> to dark on a
     * dark background. Runs once, reads only our own element, no external file. There is
     * no standard WordPress light/dark flag, so background luminance is the portable signal.
     */
    private function themeScript(): string
    {
        return <<<'JS'
            (function () {
                function apply() {
                    var el = document.querySelector('semantic-search');
                    if (!el) { return; }
                    var m = (getComputedStyle(document.body).backgroundColor || '').match(/[\d.]+/g);
                    if (!m || m.length < 3) { return; }
                    var a = m.length > 3 ? +m[3] : 1;
                    if (a === 0) { return; }
                    var lum = (0.2126 * +m[0] + 0.7152 * +m[1] + 0.0722 * +m[2]) / 255;
                    if (lum < 0.5) { el.setAttribute('theme', 'dark'); }
                }
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', apply);
                } else {
                    apply();
                }
            })();
            JS;
    }

    public function addModuleType(string $tag, string $handle): string
    {
        if ($handle !== 'appinio-search-widget') {
            return $tag;
        }

        return str_replace('<script ', '<script type="module" ', $tag);
    }

    private function getSearchKey(): string
    {
        return get_option('appinio_public_key', '');
    }
}
