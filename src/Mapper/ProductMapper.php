<?php

declare(strict_types=1);

namespace AppIn\WooCommerce\Mapper;

use WC_Product;
use WC_Product_Variable;

final class ProductMapper
{
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
            'title' => $product->get_name(),
            'content' => $this->buildContent($product),
            'price' => $this->resolvePrice($product),
            'currency' => get_woocommerce_currency(),
            'image_url' => $this->resolveImageUrl($product),
            'in_stock' => $product->is_in_stock(),
            'sku' => $product->get_sku() ?: null,
            'rating' => (float) $product->get_average_rating(),
            'reviews_count' => (int) $product->get_review_count(),
            'category' => $this->resolveCategory($product),
            'tags' => $this->resolveTags($product),
            'attributes' => $this->resolveAttributes($product),
        ];

        $compareAtPrice = $this->resolveCompareAtPrice($product);
        if ($compareAtPrice !== null) {
            $data['compare_at_price'] = $compareAtPrice;
        }

        $brand = $this->resolveBrand($product);
        if ($brand !== null) {
            $data['brand'] = $brand;
        }

        return array_filter($data, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    private function buildContent(WC_Product $product): string
    {
        $parts = array_filter([
            wp_strip_all_tags($product->get_short_description()),
            wp_strip_all_tags($product->get_description()),
        ]);

        return implode("\n\n", $parts) ?: $product->get_name();
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
        $imageId = $product->get_image_id();

        return $imageId ? (string) wp_get_attachment_url($imageId) : null;
    }

    private function resolveCategory(WC_Product $product): ?string
    {
        $terms = get_the_terms($product->get_id(), 'product_cat');

        if (! $terms || is_wp_error($terms)) {
            return null;
        }

        // Return deepest (most specific) category
        usort($terms, fn ($a, $b) => $b->parent <=> $a->parent);

        return $terms[0]->name;
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

        return array_values(array_map(fn ($t) => $t->name, $terms));
    }

    /**
     * Brand from taxonomy pa_brand or attribute "Brand".
     */
    private function resolveBrand(WC_Product $product): ?string
    {
        // Check taxonomy first (e.g. Perfect Brands plugin)
        $terms = get_the_terms($product->get_id(), 'product_brand');
        if ($terms && ! is_wp_error($terms)) {
            return $terms[0]->name;
        }

        // Fallback: product attribute named "Brand"
        $brand = $product->get_attribute('brand');

        return $brand !== '' ? $brand : null;
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    private function resolveAttributes(WC_Product $product): array
    {
        $attributes = [];

        foreach ($product->get_attributes() as $attribute) {
            $name = $attribute instanceof \WC_Product_Attribute
                ? wc_attribute_label($attribute->get_name())
                : (string) $attribute;

            if ($attribute instanceof \WC_Product_Attribute) {
                $values = $attribute->is_taxonomy()
                    ? wc_get_product_terms($product->get_id(), $attribute->get_name(), ['fields' => 'names'])
                    : $attribute->get_options();

                foreach ($values as $value) {
                    $attributes[] = ['name' => $name, 'value' => (string) $value];
                }
            }
        }

        return $attributes;
    }
}
