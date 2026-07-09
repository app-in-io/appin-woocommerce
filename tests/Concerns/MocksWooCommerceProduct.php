<?php

declare(strict_types=1);

namespace AppInIo\Tests\Concerns;

use Brain\Monkey\Functions;
use Mockery;

/**
 * Shared WC_Product test double for the mapper-driven tests. Kept in one place so a new
 * field read by ProductMapper only needs its stub added here — not in every test file.
 */
trait MocksWooCommerceProduct
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeWcProduct(array $overrides = []): \WC_Product
    {
        $product = Mockery::mock('WC_Product');
        $this->applyWcProductStubs($product, $overrides);

        return $product;
    }

    /**
     * Stub the base WC_Product method surface + the WP globals the mapper reads.
     * `__brand` overrides the fallback "brand" attribute value.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function applyWcProductStubs(object $product, array $overrides = []): void
    {
        $brand = $overrides['__brand'] ?? '';
        unset($overrides['__brand']);

        $values = array_merge([
            'get_id' => 1,
            'get_name' => 'Test Product',
            'get_short_description' => 'Short desc',
            'get_description' => 'Full desc',
            'get_price' => '29.99',
            'get_regular_price' => '29.99',
            'get_image_id' => 0,
            'is_in_stock' => true,
            'is_on_sale' => false,
            'get_type' => 'simple',
            'get_sku' => 'TEST-001',
            'get_average_rating' => '4.5',
            'get_review_count' => 10,
            'get_attributes' => [],
            'get_catalog_visibility' => 'visible',
        ], $overrides);

        foreach ($values as $method => $return) {
            $product->allows($method)->andReturn($return);
        }
        $product->allows('get_attribute')->with('brand')->andReturn($brand);

        Functions\when('get_permalink')->justReturn('https://shop.test/product/test');
        Functions\when('get_woocommerce_currency')->justReturn('EUR');
        Functions\when('wp_strip_all_tags')->returnArg();
        Functions\when('wp_get_attachment_url')->justReturn('https://shop.test/image.jpg');
    }
}
