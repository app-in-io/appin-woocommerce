<?php

declare(strict_types=1);

namespace AppIn\WooCommerce\Tests\Sync;

use AppIn\WooCommerce\Sync\ProductSync;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

class ProductSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // ProductSync::$processed is a per-process static de-dupe cache. Reset it so
        // syncProduct() tests are hermetic and order-independent (no reliance on unique ids).
        $processed = new \ReflectionProperty(ProductSync::class, 'processed');
        $processed->setValue(null, []);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_register_wires_all_hooks_when_auto_sync_enabled(): void
    {
        Functions\when('get_option')->justReturn(true); // appin_auto_sync

        $sync = new ProductSync;
        $sync->register();

        self::assertNotFalse(has_action('woocommerce_new_product', [$sync, 'scheduleSync']));
        self::assertNotFalse(has_action('woocommerce_update_product', [$sync, 'scheduleSync']));
        self::assertNotFalse(has_action('woocommerce_product_set_stock', [$sync, 'scheduleSync']));
        self::assertNotFalse(has_action('wp_trash_post', [$sync, 'scheduleDelete']));
        self::assertNotFalse(has_action('untrashed_post', [$sync, 'scheduleSync']));
        self::assertNotFalse(has_action('appin_sync_product', [$sync, 'syncProduct']));
        self::assertNotFalse(has_action('appin_delete_product', [$sync, 'deleteProduct']));
    }

    public function test_register_skips_hooks_when_auto_sync_disabled(): void
    {
        Functions\when('get_option')->justReturn(false);

        $sync = new ProductSync;
        $sync->register();

        self::assertFalse(has_action('woocommerce_new_product', [$sync, 'scheduleSync']));
        self::assertFalse(has_action('appin_sync_product', [$sync, 'syncProduct']));
    }

    public function test_schedule_sync_schedules_debounced_action_for_plain_id(): void
    {
        Functions\when('get_post_type')->justReturn('product');
        Functions\when('as_unschedule_all_actions')->justReturn(null);

        Functions\expect('as_schedule_single_action')
            ->once()
            ->with(Mockery::type('int'), 'appin_sync_product', [123], 'appin-search');

        (new ProductSync)->scheduleSync(123);

        self::assertTrue(true);
    }

    public function test_schedule_sync_normalizes_wc_product_to_its_id(): void
    {
        Functions\when('get_post_type')->justReturn('product');
        Functions\when('as_unschedule_all_actions')->justReturn(null);

        $product = Mockery::mock('WC_Product');
        $product->allows('get_id')->andReturn(321);

        Functions\expect('as_schedule_single_action')
            ->once()
            ->with(Mockery::type('int'), 'appin_sync_product', [321], 'appin-search');

        (new ProductSync)->scheduleSync($product);

        self::assertTrue(true);
    }

    public function test_schedule_sync_indexes_parent_for_a_variation(): void
    {
        // Variations are not indexed directly — schedule the parent product instead.
        Functions\when('get_post_type')->justReturn('product_variation');
        Functions\when('wp_get_post_parent_id')->justReturn(500);
        Functions\when('as_unschedule_all_actions')->justReturn(null);

        Functions\expect('as_schedule_single_action')
            ->once()
            ->with(Mockery::type('int'), 'appin_sync_product', [500], 'appin-search');

        (new ProductSync)->scheduleSync(777); // a variation id

        self::assertTrue(true);
    }

    public function test_schedule_sync_ignores_non_positive_id(): void
    {
        Functions\expect('as_schedule_single_action')->never();

        $product = Mockery::mock('WC_Product');
        $product->allows('get_id')->andReturn(0);

        (new ProductSync)->scheduleSync($product);

        self::assertTrue(true);
    }

    public function test_schedule_delete_ignores_non_product_posts(): void
    {
        Functions\when('get_post_type')->justReturn('page');
        Functions\expect('as_schedule_single_action')->never();

        (new ProductSync)->scheduleDelete(55);

        self::assertTrue(true);
    }

    public function test_schedule_delete_schedules_removal_for_a_product(): void
    {
        Functions\when('get_post_type')->justReturn('product');
        Functions\when('as_unschedule_all_actions')->justReturn(null);

        Functions\expect('as_schedule_single_action')
            ->once()
            ->with(Mockery::type('int'), 'appin_delete_product', [55], 'appin-search');

        (new ProductSync)->scheduleDelete(55);

        self::assertTrue(true);
    }

    public function test_sync_product_is_noop_when_product_missing(): void
    {
        Functions\when('wc_get_product')->justReturn(false);
        Functions\expect('wp_remote_request')->never();

        (new ProductSync)->syncProduct(9001); // unique id (static de-dupe cache)

        self::assertTrue(true);
    }

    public function test_sync_product_deletes_from_index_when_not_published(): void
    {
        // A no-longer-published product must be removed from the index.
        $product = Mockery::mock('WC_Product');
        $product->allows('get_status')->andReturn('draft');
        Functions\when('wc_get_product')->justReturn($product);

        Functions\when('get_option')->justReturn('sk_test_key');
        Functions\when('wp_json_encode')->alias(static fn ($d) => json_encode($d));
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');

        Functions\expect('wp_remote_request')
            ->once()
            ->with('https://api.app-in.io/v1/index/products', Mockery::on(function (array $args): bool {
                self::assertSame('DELETE', $args['method']);
                self::assertSame(['id' => '9002'], json_decode($args['body'], true));

                return true;
            }))
            ->andReturn('RESP');

        (new ProductSync)->syncProduct(9002); // unique id

        self::assertTrue(true);
    }
}
