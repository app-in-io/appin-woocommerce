<?php

declare(strict_types=1);

namespace AppInIo\Mapper;

use AppInIo\I18n\LanguageResolver;
use WC_Product;
use WC_Product_Variable;

if (! defined('ABSPATH')) {
    exit;
}

final class ProductMapper
{
    public function __construct(
        private LanguageResolver $lang = new LanguageResolver,
    ) {}

    /**
     * Map a WC_Product to the AppIn API index format.
     *
     * @return array<string, mixed>
     */
    public function toApiData(WC_Product $product): array
    {
        $data = [
            'id' => (string) $product->get_id(),
            'url' => get_permalink($product->get_id()),
            'title' => $this->decode($product->get_name()),
            'content' => $this->buildContent($product),
            'lang' => $this->lang->postLanguage((int) $product->get_id()),
            'price' => $this->resolvePrice($product),
            'currency' => get_woocommerce_currency(),
            'image_url' => $this->resolveImageUrl($product),
            'in_stock' => $product->is_in_stock(),
            'on_sale' => $product->is_on_sale(),
            'product_type' => $product->get_type(),
            'sku' => $product->get_sku() ?: null,
            'rating' => (float) $product->get_average_rating(),
            'reviews_count' => (int) $product->get_review_count(),
            'category' => $this->resolveCategory($product),
            'category_id' => $this->resolveCategoryId($product),
            'tags' => $this->resolveTags($product),
            'attributes' => $this->resolveAttributes($product),
        ];

        if ($product instanceof WC_Product_Variable) {
            $prices = $product->get_variation_prices(true)['price'] ?? [];
            if ($prices) {
                $data['price_min'] = (float) min($prices);
                $data['price_max'] = (float) max($prices);
            }
        }

        $compareAtPrice = $this->resolveCompareAtPrice($product);
        if ($compareAtPrice !== null) {
            $data['compare_at_price'] = $compareAtPrice;
        }

        $brand = $this->resolveBrand($product);
        if ($brand !== null) {
            $data['brand'] = $brand;
        }

        $children = $this->resolveChildren($product);
        if ($children !== []) {
            $data['children'] = $children;
        }

        // Drop null / empty-string / empty-array values. Booleans (in_stock, on_sale) are
        // kept: false is distinct from null/'' under the strict comparisons below.
        return array_filter($data, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    private function buildContent(WC_Product $product): string
    {
        $parts = array_filter([
            $this->cleanText($product->get_short_description()),
            $this->cleanText($product->get_description()),
        ]);

        return implode("\n\n", $parts) ?: $this->decode($product->get_name());
    }

    /**
     * Turn stored rich-text (descriptions) into plain text for the index, in this order:
     *   1. wp_strip_all_tags — remove the real HTML markup (in valid stored HTML a literal "<"
     *      is always encoded as "&lt;", so no legit text is at risk here);
     *   2. decode entities — reveal the wanted plain-text form ("&amp;" → "&", "&lt;" → "<");
     *   3. strip only tag-like sequences the decode may have revealed (encoded markup such as
     *      "&lt;div&gt;"). A "<" followed by a letter or "/" is markup; "< -5°C" / "<1L"
     *      (space or digit after "<") is legit spec text and survives.
     *
     * Decoding BEFORE stripping is wrong: a description legitimately containing an encoded
     * comparison operator (e.g. "Rated to &lt; -5°C") would decode to a bare "<" that
     * wp_strip_all_tags treats as an unclosed tag and eats to the next ">" — destroying content.
     */
    private function cleanText(string $value): string
    {
        $text = $this->decode(wp_strip_all_tags($value));

        return (string) preg_replace('/<\/?[a-zA-Z][^>]*>/', '', $text);
    }

    /**
     * WordPress stores taxonomy/term text HTML-entity-encoded (e.g. "Drinkware &amp; Bottles").
     * The AppIn index is plain text, so decode entities before sending — otherwise the escaped
     * literal shows up in the search widget. Term names never contain markup, so this plain
     * decode is enough for category/tag/brand/attribute fields (rich text goes through cleanText).
     */
    private function decode(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function resolvePrice(WC_Product $product): ?float
    {
        $price = $product->get_price();

        return $price !== '' ? (float) $price : null;
    }

    /**
     * Compare-at price = regular price when product is on sale.
     */
    private function resolveCompareAtPrice(WC_Product $product): ?float
    {
        if (! $product->is_on_sale()) {
            return null;
        }

        $regular = $product->get_regular_price();

        return $regular !== '' ? (float) $regular : null;
    }

    private function resolveImageUrl(WC_Product $product): ?string
    {
        $imageId = (int) $product->get_image_id();

        return $imageId ? (string) wp_get_attachment_url($imageId) : null;
    }

    private function resolveCategory(WC_Product $product): ?string
    {
        $terms = $this->getProductCategoryTerms($product);

        return $terms ? $this->decode($terms[0]->name) : null;
    }

    private function resolveCategoryId(WC_Product $product): ?int
    {
        $terms = $this->getProductCategoryTerms($product);

        return $terms ? (int) $terms[0]->term_id : null;
    }

    /**
     * @return \WP_Term[]|null
     */
    private function getProductCategoryTerms(WC_Product $product): ?array
    {
        $terms = get_the_terms($product->get_id(), 'product_cat');

        if (! $terms || is_wp_error($terms)) {
            return null;
        }

        // Return deepest (most specific) category first
        usort($terms, fn ($a, $b) => $b->parent <=> $a->parent);

        return $terms;
    }

    /**
     * @return list<string>
     */
    private function resolveTags(WC_Product $product): array
    {
        $terms = get_the_terms($product->get_id(), 'product_tag');

        if (! $terms || is_wp_error($terms)) {
            return [];
        }

        return array_values(array_map(fn ($t) => $this->decode($t->name), $terms));
    }

    /**
     * Brand from taxonomy pa_brand or attribute "Brand".
     */
    private function resolveBrand(WC_Product $product): ?string
    {
        // Check taxonomy first (e.g. Perfect Brands plugin)
        $terms = get_the_terms($product->get_id(), 'product_brand');
        if ($terms && ! is_wp_error($terms)) {
            return $this->decode($terms[0]->name);
        }

        // Fallback: product attribute named "Brand"
        $brand = $product->get_attribute('brand');

        return $brand !== '' ? $this->decode($brand) : null;
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    private function resolveAttributes(WC_Product $product): array
    {
        $attributes = [];

        foreach ($product->get_attributes() as $attribute) {
            $name = $attribute instanceof \WC_Product_Attribute
                ? $this->decode(wc_attribute_label($attribute->get_name()))
                : $this->decode((string) $attribute);

            if ($attribute instanceof \WC_Product_Attribute) {
                $values = $attribute->is_taxonomy()
                    ? wc_get_product_terms($product->get_id(), $attribute->get_name(), ['fields' => 'names'])
                    : $attribute->get_options();

                foreach ($values as $value) {
                    $attributes[] = ['name' => $name, 'value' => $this->decode((string) $value)];
                }
            }
        }

        return $attributes;
    }

    /**
     * @return list<array{id: string, title: string, url: string, price: ?float, sku: ?string, image_url: ?string, in_stock: bool}>
     */
    private function resolveChildren(WC_Product $product): array
    {
        if ($product->get_type() !== 'grouped') {
            return [];
        }

        $children = [];

        foreach ($product->get_children() as $childId) {
            $child = wc_get_product($childId);
            if (! $child || $child->get_status() !== 'publish') {
                continue;
            }

            $children[] = [
                'id' => (string) $child->get_id(),
                'title' => $this->decode($child->get_name()),
                'url' => (string) get_permalink($child->get_id()),
                'price' => $child->get_price() !== '' ? (float) $child->get_price() : null,
                'sku' => $child->get_sku() ?: null,
                'image_url' => $child->get_image_id() ? (string) wp_get_attachment_url((int) $child->get_image_id()) : null,
                'in_stock' => $child->is_in_stock(),
            ];
        }

        return $children;
    }
}
