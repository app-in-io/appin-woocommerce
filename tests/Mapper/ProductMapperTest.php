<?php

declare(strict_types=1);

namespace AppIn\WooCommerce\Tests\Mapper;

use AppIn\WooCommerce\Mapper\ProductMapper;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

class ProductMapperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_to_api_data_includes_category_and_category_id(): void
    {
        $product = $this->createMockProduct();

        $term = Mockery::mock('WP_Term');
        $term->name = 'Shoes';
        $term->term_id = 42;
        $term->parent = 0;

        Functions\when('get_the_terms')->alias(fn ($id, $taxonomy) => match ($taxonomy) {
            'product_cat' => [$term],
            default => false,
        });

        $mapper = new ProductMapper;
        $data = $mapper->toApiData($product);

        self::assertSame('Shoes', $data['category']);
        self::assertSame(42, $data['category_id']);
    }

    public function test_category_and_category_id_null_when_no_terms(): void
    {
        $product = $this->createMockProduct();

        Functions\when('get_the_terms')->justReturn(false);

        $mapper = new ProductMapper;
        $data = $mapper->toApiData($product);

        self::assertArrayNotHasKey('category', $data);
        self::assertArrayNotHasKey('category_id', $data);
    }

    public function test_category_uses_deepest_term(): void
    {
        $product = $this->createMockProduct();

        $parent = Mockery::mock('WP_Term');
        $parent->name = 'Clothing';
        $parent->term_id = 10;
        $parent->parent = 0;

        $child = Mockery::mock('WP_Term');
        $child->name = 'Shoes';
        $child->term_id = 42;
        $child->parent = 10;

        Functions\when('get_the_terms')->alias(fn ($id, $taxonomy) => match ($taxonomy) {
            'product_cat' => [$parent, $child],
            default => false,
        });

        $mapper = new ProductMapper;
        $data = $mapper->toApiData($product);

        self::assertSame('Shoes', $data['category']);
        self::assertSame(42, $data['category_id']);
    }

    private function createMockProduct(): \WC_Product
    {
        $product = Mockery::mock('WC_Product');
        $product->allows('get_id')->andReturn(1);
        $product->allows('get_name')->andReturn('Test Product');
        $product->allows('get_short_description')->andReturn('Short desc');
        $product->allows('get_description')->andReturn('Full desc');
        $product->allows('get_price')->andReturn('29.99');
        $product->allows('get_regular_price')->andReturn('29.99');
        $product->allows('get_image_id')->andReturn(0);
        $product->allows('is_in_stock')->andReturn(true);
        $product->allows('is_on_sale')->andReturn(false);
        $product->allows('get_type')->andReturn('simple');
        $product->allows('get_sku')->andReturn('TEST-001');
        $product->allows('get_average_rating')->andReturn('4.5');
        $product->allows('get_review_count')->andReturn(10);
        $product->allows('get_attributes')->andReturn([]);
        $product->allows('get_attribute')->with('brand')->andReturn('');

        Functions\when('get_permalink')->justReturn('https://shop.test/product/test');
        Functions\when('get_woocommerce_currency')->justReturn('EUR');
        Functions\when('wp_strip_all_tags')->returnArg();
        Functions\when('wp_get_attachment_url')->justReturn(null);

        return $product;
    }
}
