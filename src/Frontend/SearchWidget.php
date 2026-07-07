<?php

declare(strict_types=1);

namespace AppIn\WooCommerce\Frontend;

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
            'appin-search-widget',
            APPIN_CDN_URL,
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

        $selector = get_option('appin_search_selector', '');

        if (! empty($selector)) {
            $attrs .= \sprintf(' input-selector="%s"', esc_attr($selector));
        }

        echo wp_kses(
            \sprintf('<semantic-search %s></semantic-search>', $attrs),
            ['semantic-search' => ['api-key' => true, 'platform' => true, 'category-id' => true, 'input-selector' => true]]
        );
    }

    public function addModuleType(string $tag, string $handle): string
    {
        if ($handle !== 'appin-search-widget') {
            return $tag;
        }

        return str_replace('<script ', '<script type="module" ', $tag);
    }

    private function getSearchKey(): string
    {
        return get_option('appin_public_key', '');
    }
}
