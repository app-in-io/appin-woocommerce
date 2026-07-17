<?php

declare(strict_types=1);

namespace Appinio\Tests\Sync;

use Appinio\Sync\ProductSync;
use Appinio\Tests\Concerns\MocksWooCommerceProduct;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

class ProductSyncTest extends TestCase
{
    use MocksWooCommerceProduct;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // ProductSync::$processed is a per-process static de-dupe cache. Reset it so
        // syncProduct() tests are hermetic and order-independent (no reliance on unique ids).
        $processed = new \ReflectionProperty(ProductSync::class, 'processed');
        $processed->setValue(null, []);

        // handleFailure() wraps the exception message in esc_html() — WPCS treats an exception
        // message as output, and Plugin Check reports the unescaped form as an ERROR.
        Functions\when('esc_html')->returnArg();
        // RetryPolicy transient helpers — default no-op so success paths don't fatal.
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        // IndexState deindex helper — default no-op (the index success test sets its own
        // expectation on update_post_meta, so that one is deliberately not stubbed here).
        Functions\when('delete_post_meta')->justReturn(true);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_register_wires_all_hooks_when_auto_sync_enabled(): void
    {
        Functions\when('get_option')->justReturn(true); // appinio_auto_sync

        $sync = new ProductSync;
        $sync->register();

        self::assertNotFalse(has_action('woocommerce_new_product', [$sync, 'scheduleSync']));
        self::assertNotFalse(has_action('woocommerce_update_product', [$sync, 'scheduleSync']));
        self::assertNotFalse(has_action('woocommerce_product_set_stock', [$sync, 'scheduleSync']));
        self::assertNotFalse(has_action('wp_trash_post', [$sync, 'scheduleDelete']));
        self::assertNotFalse(has_action('before_delete_post', [$sync, 'scheduleDelete'])); // hard delete
        self::assertNotFalse(has_action('untrashed_post', [$sync, 'scheduleSync']));
        self::assertNotFalse(has_action('appinio_sync_product', [$sync, 'syncProduct']));
        self::assertNotFalse(has_action('appinio_delete_product', [$sync, 'deleteProduct']));
    }

    public function test_register_skips_hooks_when_auto_sync_disabled(): void
    {
        Functions\when('get_option')->justReturn(false);

        $sync = new ProductSync;
        $sync->register();

        self::assertFalse(has_action('woocommerce_new_product', [$sync, 'scheduleSync']));
        self::assertFalse(has_action('appinio_sync_product', [$sync, 'syncProduct']));
    }

    public function test_schedule_sync_schedules_debounced_action_for_plain_id(): void
    {
        Functions\when('get_post_type')->justReturn('product');
        Functions\when('as_unschedule_all_actions')->justReturn(null);

        Functions\expect('as_schedule_single_action')
            ->once()
            ->with(Mockery::type('int'), 'appinio_sync_product', [123], 'appinio-search');

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
            ->with(Mockery::type('int'), 'appinio_sync_product', [321], 'appinio-search');

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
            ->with(Mockery::type('int'), 'appinio_sync_product', [500], 'appinio-search');

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
            ->with(Mockery::type('int'), 'appinio_delete_product', [55], 'appinio-search');

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

    public function test_sync_product_deletes_from_index_when_not_searchable(): void
    {
        // Published but catalog-visibility "hidden" (or "catalog") is excluded from
        // search — it must be removed from the index, not indexed.
        $product = Mockery::mock('WC_Product');
        $product->allows('get_status')->andReturn('publish');
        $product->allows('get_catalog_visibility')->andReturn('hidden');
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
                self::assertSame(['id' => '9003'], json_decode($args['body'], true));

                return true;
            }))
            ->andReturn('RESP');

        (new ProductSync)->syncProduct(9003); // unique id

        self::assertTrue(true);
    }

    public function test_sync_product_marks_indexed_on_success(): void
    {
        $product = $this->makeWcProduct(['get_id' => 42]);
        Functions\when('wc_get_product')->justReturn($product);
        $this->stubIndexHttp(200); // success

        Functions\expect('update_post_meta')->once()->with(42, '_appinio_indexed', 1);

        (new ProductSync)->syncProduct(42);

        self::assertTrue(true);
    }

    public function test_sync_product_reschedules_on_transient_failure(): void
    {
        $product = $this->makeWcProduct(['get_id' => 42]);
        Functions\when('wc_get_product')->justReturn($product);
        $this->stubIndexHttp(503); // transient server error
        Functions\when('get_transient')->justReturn(false); // first failure

        // RetryPolicy reschedules the same action with backoff; no exception.
        Functions\expect('as_schedule_single_action')
            ->once()
            ->with(Mockery::type('int'), 'appinio_sync_product', [42], 'appinio-search');

        (new ProductSync)->syncProduct(42);

        self::assertTrue(true);
    }

    public function test_sync_product_throws_when_retries_exhausted(): void
    {
        $product = $this->makeWcProduct(['get_id' => 42]);
        Functions\when('wc_get_product')->justReturn($product);
        $this->stubIndexHttp(503);
        Functions\when('get_transient')->justReturn(4); // next attempt = 5 = cap → Exhausted
        Functions\expect('as_schedule_single_action')->never();

        // Exhausted → throw so Action Scheduler marks the action failed (visible).
        $this->expectException(\RuntimeException::class);

        (new ProductSync)->syncProduct(42);
    }

    public function test_sync_product_does_not_throw_on_permanent_failure(): void
    {
        $product = $this->makeWcProduct(['get_id' => 42]);
        Functions\when('wc_get_product')->justReturn($product);
        $this->stubIndexHttp(422); // client validation error → permanent
        Functions\expect('as_schedule_single_action')->never();

        (new ProductSync)->syncProduct(42); // no exception, no reschedule

        self::assertTrue(true);
    }

    public function test_delete_product_treats_404_as_success(): void
    {
        $this->stubDeleteHttp(404); // already gone from the index
        Functions\expect('as_schedule_single_action')->never();

        (new ProductSync)->deleteProduct(55); // no throw, no reschedule

        self::assertTrue(true);
    }

    public function test_delete_product_reschedules_on_transient_failure(): void
    {
        $this->stubDeleteHttp(500);
        Functions\when('get_transient')->justReturn(false);

        Functions\expect('as_schedule_single_action')
            ->once()
            ->with(Mockery::type('int'), 'appinio_delete_product', [55], 'appinio-search');

        (new ProductSync)->deleteProduct(55);

        self::assertTrue(true);
    }

    /**
     * Drive the full index path (mapper + Client) to a given HTTP status.
     */
    private function stubIndexHttp(int $status): void
    {
        Functions\when('get_option')->justReturn('sk_test_key');
        Functions\when('wp_json_encode')->alias(static fn ($d) => json_encode($d));
        Functions\when('get_the_terms')->justReturn(false);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_request')->justReturn('RESP');
        Functions\when('wp_remote_retrieve_response_code')->justReturn($status);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');
        Functions\when('wp_remote_retrieve_header')->justReturn('');
        Functions\when('Appinio\Api\sleep')->justReturn(0);
    }

    /**
     * Drive the delete path (Client only, no mapper) to a given HTTP status.
     */
    private function stubDeleteHttp(int $status): void
    {
        Functions\when('get_option')->justReturn('sk_test_key');
        Functions\when('wp_json_encode')->alias(static fn ($d) => json_encode($d));
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_request')->justReturn('RESP');
        Functions\when('wp_remote_retrieve_response_code')->justReturn($status);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');
        Functions\when('wp_remote_retrieve_header')->justReturn('');
        Functions\when('Appinio\Api\sleep')->justReturn(0);
    }
}
