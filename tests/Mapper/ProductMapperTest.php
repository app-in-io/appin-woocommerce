<?php

declare(strict_types=1);

namespace AppIn\WooCommerce\Tests\Mapper;

use AppIn\WooCommerce\Mapper\ProductMapper;
use AppIn\WooCommerce\Tests\Concerns\MocksWooCommerceProduct;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

class ProductMapperTest extends TestCase
{
    use MocksWooCommerceProduct;

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
        $product = $this->makeWcProduct();

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
        $product = $this->makeWcProduct();

        Functions\when('get_the_terms')->justReturn(false);

        $mapper = new ProductMapper;
        $data = $mapper->toApiData($product);

        self::assertArrayNotHasKey('category', $data);
        self::assertArrayNotHasKey('category_id', $data);
    }

    public function test_category_uses_deepest_term(): void
    {
        $product = $this->makeWcProduct();

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

    public function test_maps_base_scalar_fields(): void
    {
        $product = $this->makeWcProduct();
        Functions\when('get_the_terms')->justReturn(false);

        $data = (new ProductMapper)->toApiData($product);

        self::assertSame('1', $data['id']);
        self::assertSame('Test Product', $data['title']);
        self::assertSame("Short desc\n\nFull desc", $data['content']);
        self::assertSame(29.99, $data['price']);
        self::assertSame('EUR', $data['currency']);
        self::assertTrue($data['in_stock']);
        self::assertFalse($data['on_sale']);
        self::assertSame('simple', $data['product_type']);
        self::assertSame('TEST-001', $data['sku']);
        self::assertSame(4.5, $data['rating']);
        self::assertSame(10, $data['reviews_count']);
        // Not on sale and no image → these keys are filtered out.
        self::assertArrayNotHasKey('compare_at_price', $data);
        self::assertArrayNotHasKey('image_url', $data);
    }

    public function test_sku_absent_when_empty(): void
    {
        $product = $this->makeWcProduct(['get_sku' => '']);
        Functions\when('get_the_terms')->justReturn(false);

        $data = (new ProductMapper)->toApiData($product);

        self::assertArrayNotHasKey('sku', $data);
    }

    public function test_compare_at_price_is_regular_price_when_on_sale(): void
    {
        $product = $this->makeWcProduct([
            'is_on_sale' => true,
            'get_price' => '30.00',
            'get_regular_price' => '40.00',
        ]);
        Functions\when('get_the_terms')->justReturn(false);

        $data = (new ProductMapper)->toApiData($product);

        self::assertTrue($data['on_sale']);
        self::assertSame(30.0, $data['price']);
        self::assertSame(40.0, $data['compare_at_price']);
    }

    public function test_image_url_resolved_when_image_present(): void
    {
        $product = $this->makeWcProduct(['get_image_id' => 77]);
        Functions\when('get_the_terms')->justReturn(false);

        $data = (new ProductMapper)->toApiData($product);

        self::assertSame('https://shop.test/image.jpg', $data['image_url']);
    }

    public function test_tags_mapped_from_product_tag_terms(): void
    {
        $product = $this->makeWcProduct();

        $summer = Mockery::mock('WP_Term');
        $summer->name = 'Summer';
        $sale = Mockery::mock('WP_Term');
        $sale->name = 'Sale';

        Functions\when('get_the_terms')->alias(fn ($id, $taxonomy) => match ($taxonomy) {
            'product_tag' => [$summer, $sale],
            default => false,
        });

        $data = (new ProductMapper)->toApiData($product);

        self::assertSame(['Summer', 'Sale'], $data['tags']);
    }

    public function test_custom_attributes_mapped_to_name_value_pairs(): void
    {
        $attribute = Mockery::mock('WC_Product_Attribute');
        $attribute->allows('get_name')->andReturn('color');
        $attribute->allows('is_taxonomy')->andReturn(false);
        $attribute->allows('get_options')->andReturn(['Red', 'Blue']);

        $product = $this->makeWcProduct(['get_attributes' => [$attribute]]);
        Functions\when('get_the_terms')->justReturn(false);
        Functions\when('wc_attribute_label')->justReturn('Color');

        $data = (new ProductMapper)->toApiData($product);

        self::assertSame([
            ['name' => 'Color', 'value' => 'Red'],
            ['name' => 'Color', 'value' => 'Blue'],
        ], $data['attributes']);
    }

    public function test_taxonomy_attributes_use_product_terms(): void
    {
        $attribute = Mockery::mock('WC_Product_Attribute');
        $attribute->allows('get_name')->andReturn('pa_size');
        $attribute->allows('is_taxonomy')->andReturn(true);

        $product = $this->makeWcProduct(['get_attributes' => [$attribute]]);
        Functions\when('get_the_terms')->justReturn(false);
        Functions\when('wc_attribute_label')->justReturn('Size');
        Functions\when('wc_get_product_terms')->justReturn(['S', 'M']);

        $data = (new ProductMapper)->toApiData($product);

        self::assertSame([
            ['name' => 'Size', 'value' => 'S'],
            ['name' => 'Size', 'value' => 'M'],
        ], $data['attributes']);
    }

    public function test_variable_product_exposes_price_range(): void
    {
        // WC_Product_Variable extends WC_Product (see tests/bootstrap.php) → instanceof both.
        $product = Mockery::mock('WC_Product_Variable');
        $this->applyWcProductStubs($product, ['get_id' => 2, 'get_type' => 'variable']);
        $product->allows('get_variation_prices')->with(true)->andReturn([
            'price' => ['10' => 10.0, '11' => 25.0, '12' => 15.0],
        ]);

        Functions\when('get_the_terms')->justReturn(false);

        $data = (new ProductMapper)->toApiData($product);

        self::assertSame(10.0, $data['price_min']);
        self::assertSame(25.0, $data['price_max']);
    }

    public function test_grouped_product_includes_published_children(): void
    {
        $product = $this->makeWcProduct(['get_type' => 'grouped', 'get_children' => [101, 102]]);
        Functions\when('get_the_terms')->justReturn(false);

        $children = [101 => $this->mockChild(101, 'publish'), 102 => $this->mockChild(102, 'publish')];
        Functions\when('wc_get_product')->alias(fn ($id) => $children[$id] ?? false);

        $data = (new ProductMapper)->toApiData($product);

        self::assertCount(2, $data['children']);
        self::assertSame('101', $data['children'][0]['id']);
        self::assertSame('Child 101', $data['children'][0]['title']);
    }

    public function test_grouped_product_skips_unpublished_children(): void
    {
        $product = $this->makeWcProduct(['get_type' => 'grouped', 'get_children' => [201, 202]]);
        Functions\when('get_the_terms')->justReturn(false);

        $children = [201 => $this->mockChild(201, 'publish'), 202 => $this->mockChild(202, 'draft')];
        Functions\when('wc_get_product')->alias(fn ($id) => $children[$id] ?? false);

        $data = (new ProductMapper)->toApiData($product);

        self::assertCount(1, $data['children']);
        self::assertSame('201', $data['children'][0]['id']);
    }

    public function test_brand_from_taxonomy_takes_priority(): void
    {
        $product = $this->makeWcProduct();

        $brand = Mockery::mock('WP_Term');
        $brand->name = 'Adidas';

        Functions\when('get_the_terms')->alias(fn ($id, $taxonomy) => match ($taxonomy) {
            'product_brand' => [$brand],
            default => false,
        });

        $data = (new ProductMapper)->toApiData($product);

        self::assertSame('Adidas', $data['brand']);
    }

    public function test_brand_falls_back_to_attribute(): void
    {
        $product = $this->makeWcProduct(['__brand' => 'Nike']);
        Functions\when('get_the_terms')->justReturn(false);

        $data = (new ProductMapper)->toApiData($product);

        self::assertSame('Nike', $data['brand']);
    }

    private function mockChild(int $id, string $status): \WC_Product
    {
        $child = Mockery::mock('WC_Product');
        $child->allows('get_status')->andReturn($status);
        $child->allows('get_id')->andReturn($id);
        $child->allows('get_name')->andReturn("Child $id");
        $child->allows('get_price')->andReturn('9.99');
        $child->allows('get_sku')->andReturn("C$id");
        $child->allows('get_image_id')->andReturn(0);
        $child->allows('is_in_stock')->andReturn(true);

        return $child;
    }
}
