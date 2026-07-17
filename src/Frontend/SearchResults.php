<?php

declare(strict_types=1);

namespace Appinio\Frontend;

use Appinio\Api\Client;
use Appinio\I18n\LanguageResolver;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Powers the WordPress/WooCommerce search results page (/?s=) with Appinio AI search.
 *
 * The dropdown widget only enhances one input; the results page a shopper reaches
 * via the sidebar search form, a bookmark, the back button or mobile is served by
 * native keyword search (0 results on typos/semantic queries). This takes over the
 * main search query using the standard WordPress pattern (pre_get_posts + post__in
 * + nullified posts_search), the same primitives every search plugin uses.
 *
 * Products are matched by Appinio AI; other post types (posts, pages) keep native
 * keyword matching, and the two are merged into one result set so a store with a
 * blog does not lose non-product search.
 */
class SearchResults
{
    private const PRODUCT_LIMIT = 100;

    private const NATIVE_LIMIT = 50;

    /**
     * Client is injectable (and the native lookup is a protected seam) so the
     * takeover can be unit-tested without real HTTP or a WordPress database.
     */
    public function __construct(
        private ?Client $client = null,
        private ?LanguageResolver $lang = null,
    ) {}

    public function register(): void
    {
        // Priority 20 runs after WooCommerce's own pre_get_posts (priority 10) so our
        // orderby => post__in (AI relevance) is written last and is not reset to
        // WooCommerce's default catalog ordering.
        add_action('pre_get_posts', [$this, 'takeover'], 20);
        add_filter('posts_search', [$this, 'nullifyNativeSql'], 10, 2);
    }

    public function takeover(\WP_Query $query): void
    {
        if (! $this->shouldTakeover($query)) {
            return;
        }

        $term = trim((string) $query->get('s'));

        if ($term === '') {
            return;
        }

        $lang = ($this->lang ??= new LanguageResolver)->currentLanguage();

        $productIds = ($this->client ??= new Client)->searchProducts($term, self::PRODUCT_LIMIT, $lang, $this->categoryId($query));

        // null = AI request failed. Leave the query untouched (flag stays unset, so
        // nullifyNativeSql does nothing either) → native search runs in full,
        // including its own product keyword matching. Graceful fallback.
        if ($productIds === null) {
            return;
        }

        if ($this->isProductContext($query)) {
            // Product-scoped query (a product search or a product-category archive
            // carrying a term). Leave the native product query intact — WooCommerce
            // catalog-visibility, the layered-nav price/attribute filters and the
            // category tax_query keep intersecting post__in — only swap the matched
            // set and its order. Do NOT widen post_type.
            $ids = $productIds === [] ? [0] : $productIds;
        } else {
            // Generic /?s= search: merge AI-ranked products with native non-product
            // matches (posts, pages) so a store with a blog keeps non-product search.
            $merged = array_values(array_unique([...$productIds, ...$this->nativeNonProductIds($term)]));
            $ids = $merged === [] ? [0] : $merged;
            $query->set('post_type', 'any');
        }

        $query->set('appinio_ai_used', true);
        // [0] forces an empty result set when nothing matched (honest "no results"),
        // since the native SQL match is nullified below.
        $query->set('post__in', $ids);
        $query->set('orderby', 'post__in');
    }

    /**
     * A product-scoped main query — a product search (`post_type=product`) or a
     * product taxonomy archive (category/tag) carrying a search term. Read from the
     * query's own vars, never the global conditional tags, which are unreliable
     * during pre_get_posts.
     */
    private function isProductContext(\WP_Query $query): bool
    {
        return $query->get('post_type') === 'product'
            || (string) $query->get('product_cat') !== ''
            || (string) $query->get('product_tag') !== '';
    }

    /**
     * Resolve the product-category term id from the query's `product_cat` slug var
     * (a DB lookup, unlike get_queried_object_id() which needs a resolved main query).
     */
    private function categoryId(\WP_Query $query): ?int
    {
        $slug = (string) $query->get('product_cat');

        if ($slug === '' || ! \function_exists('get_term_by')) {
            return null;
        }

        $term = get_term_by('slug', $slug, 'product_cat');

        return $term ? (int) $term->term_id : null;
    }

    /**
     * Strip the native search WHERE clause once Appinio has resolved the query,
     * otherwise WordPress would AND its own title/content LIKE on top of post__in
     * and drop semantic matches that lack the literal keyword.
     */
    public function nullifyNativeSql(string $search, \WP_Query $query): string
    {
        return $query->get('appinio_ai_used') ? '' : $search;
    }

    private function shouldTakeover(\WP_Query $query): bool
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return false;
        }

        if (! $query->is_main_query() || ! $query->is_search()) {
            return false;
        }

        if (! get_option('appinio_results_page', true)) {
            return false;
        }

        return get_option('appinio_api_key', '') !== '';
    }

    /**
     * Native keyword matches for every searchable public post type except products.
     * Runs as a secondary query (not the main query), so takeover() skips it.
     *
     * @return list<int>
     */
    protected function nativeNonProductIds(string $term): array
    {
        $types = array_values(array_diff(
            get_post_types(['public' => true, 'exclude_from_search' => false]),
            ['product']
        ));

        if ($types === []) {
            return [];
        }

        $query = new \WP_Query([
            's' => $term,
            'post_type' => $types,
            'fields' => 'ids',
            'posts_per_page' => self::NATIVE_LIMIT,
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
        ]);

        return array_map('intval', $query->posts);
    }
}
