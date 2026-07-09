<?php

declare(strict_types=1);

namespace AppInIo\Tests\Sync;

use AppInIo\Sync\BulkSync;
use AppInIo\Tests\Concerns\MocksWooCommerceProduct;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

class BulkSyncTest extends TestCase
{
    use MocksWooCommerceProduct;

    /** @var array<string, mixed> Records every update_option() write so finish transitions can be asserted. */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->options = [];
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_register_wires_all_hooks(): void
    {
        $bulk = new BulkSync;
        $bulk->register();

        self::assertNotFalse(has_action('admin_post_appinio_bulk_sync', [$bulk, 'handleBulkSync']));
        self::assertNotFalse(has_action('admin_post_appinio_bulk_delete', [$bulk, 'handleBulkDelete']));
        self::assertNotFalse(has_action('appinio_bulk_sync_batch', [$bulk, 'processBatch']));
        self::assertNotFalse(has_action('appinio_bulk_delete_batch', [$bulk, 'processDeleteBatch']));
        self::assertNotFalse(has_action('wp_ajax_appinio_sync_status', [$bulk, 'ajaxSyncStatus']));
    }

    public function test_handle_bulk_sync_starts_first_batch_and_redirects(): void
    {
        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('admin_url')->alias(static fn ($p) => "https://wp.test/wp-admin/$p");
        $this->captureOptions();

        Functions\expect('as_schedule_single_action')
            ->once()
            ->with(Mockery::type('int'), 'appinio_bulk_sync_batch', [1], 'appinio-search');

        // Partial mock so the real handler runs but redirect() does not exit().
        $bulk = Mockery::mock(BulkSync::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $bulk->expects('redirect')->once()->with('https://wp.test/wp-admin/admin.php?page=appinio-search&syncing=1');

        $bulk->handleBulkSync();

        self::assertTrue($this->options['appinio_bulk_sync_running']);
        self::assertSame(0, $this->options['appinio_synced_count']);
    }

    public function test_handle_bulk_delete_starts_first_batch_and_redirects(): void
    {
        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('admin_url')->alias(static fn ($p) => "https://wp.test/wp-admin/$p");
        $this->captureOptions();

        Functions\expect('as_schedule_single_action')
            ->once()
            ->with(Mockery::type('int'), 'appinio_bulk_delete_batch', [1], 'appinio-search');

        $bulk = Mockery::mock(BulkSync::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $bulk->expects('redirect')->once()->with('https://wp.test/wp-admin/admin.php?page=appinio-search&deleting=1');

        $bulk->handleBulkDelete();

        self::assertTrue($this->options['appinio_bulk_sync_running']);
    }

    public function test_process_batch_indexes_and_finishes_when_page_not_full(): void
    {
        $this->stubClientHttp(202);
        Functions\when('current_time')->justReturn('2026-07-06 10:00:00');

        // 2 products (< BATCH_SIZE) → last page.
        Functions\when('wc_get_products')->justReturn([
            $this->makeWcProduct(['get_id' => 1]),
            $this->makeWcProduct(['get_id' => 2]),
        ]);

        Functions\expect('wp_remote_request')
            ->once()
            ->with('https://api.app-in.io/v1/index/products/batch', Mockery::on(function (array $args): bool {
                $body = json_decode($args['body'], true);
                self::assertCount(2, $body['items']);

                return true;
            }))
            ->andReturn('RESP');

        // Final page → no next batch scheduled.
        Functions\expect('as_schedule_single_action')->never();

        (new BulkSync)->processBatch(1);

        // finishSync() ran: running flag cleared, last_sync stamped, count incremented by the 2 items.
        self::assertFalse($this->options['appinio_bulk_sync_running']);
        self::assertSame('2026-07-06 10:00:00', $this->options['appinio_last_sync']);
        self::assertSame(2, $this->options['appinio_synced_count']);
    }

    public function test_process_batch_schedules_next_page_when_full(): void
    {
        $this->stubClientHttp(202);

        // Exactly BATCH_SIZE (20) products → another page must follow, no finish.
        $products = array_map(fn (int $i) => $this->makeWcProduct(['get_id' => $i]), range(1, 20));
        Functions\when('wc_get_products')->justReturn($products);

        Functions\expect('wp_remote_request')
            ->once()
            ->with('https://api.app-in.io/v1/index/products/batch', Mockery::on(function (array $args): bool {
                self::assertCount(20, json_decode($args['body'], true)['items']);

                return true;
            }))
            ->andReturn('RESP');

        Functions\expect('as_schedule_single_action')
            ->once()
            ->with(Mockery::type('int'), 'appinio_bulk_sync_batch', [2], 'appinio-search');

        (new BulkSync)->processBatch(1);

        self::assertSame(20, $this->options['appinio_synced_count']);
        // Not the final page → finishSync() must NOT run.
        self::assertArrayNotHasKey('appinio_bulk_sync_running', $this->options);
    }

    public function test_process_batch_finishes_immediately_when_empty(): void
    {
        $this->stubClientHttp(202);
        Functions\when('current_time')->justReturn('2026-07-06 10:00:00');
        Functions\when('wc_get_products')->justReturn([]);

        Functions\expect('wp_remote_request')->never();
        Functions\expect('as_schedule_single_action')->never();

        (new BulkSync)->processBatch(1);

        self::assertFalse($this->options['appinio_bulk_sync_running']);
        self::assertSame('2026-07-06 10:00:00', $this->options['appinio_last_sync']);
    }

    public function test_process_delete_batch_deletes_each_id_and_finishes(): void
    {
        $this->stubClientHttp(200);

        // 3 ids (< BATCH_SIZE) → final page.
        Functions\when('wc_get_products')->justReturn([10, 20, 30]);

        // One DELETE per id.
        Functions\expect('wp_remote_request')
            ->times(3)
            ->with('https://api.app-in.io/v1/index/products', Mockery::on(function (array $args): bool {
                self::assertSame('DELETE', $args['method']);

                return true;
            }))
            ->andReturn('RESP');

        Functions\expect('as_schedule_single_action')->never();

        (new BulkSync)->processDeleteBatch(1);

        // finishDelete() ran: running flag cleared, synced count reset.
        self::assertFalse($this->options['appinio_bulk_sync_running']);
        self::assertSame(0, $this->options['appinio_synced_count']);
    }

    public function test_process_delete_batch_schedules_next_page_when_full(): void
    {
        $this->stubClientHttp(200);

        Functions\when('wc_get_products')->justReturn(range(1, 20)); // 20 ids
        Functions\when('wp_remote_request')->justReturn('RESP');

        Functions\expect('as_schedule_single_action')
            ->once()
            ->with(Mockery::type('int'), 'appinio_bulk_delete_batch', [2], 'appinio-search');

        (new BulkSync)->processDeleteBatch(1);

        // Not the final page → finishDelete() must NOT run.
        self::assertArrayNotHasKey('appinio_bulk_sync_running', $this->options);
    }

    public function test_process_delete_batch_finishes_immediately_when_empty(): void
    {
        $this->stubClientHttp(200);
        Functions\when('wc_get_products')->justReturn([]);

        Functions\expect('wp_remote_request')->never();
        Functions\expect('as_schedule_single_action')->never();

        (new BulkSync)->processDeleteBatch(1);

        self::assertFalse($this->options['appinio_bulk_sync_running']);
        self::assertSame(0, $this->options['appinio_synced_count']);
    }

    /**
     * Stub everything the inline `new Client` needs, and record option writes into $this->options.
     */
    /**
     * Record every update_option() write into $this->options so finish/handler
     * transitions can be asserted.
     */
    private function captureOptions(): void
    {
        Functions\when('update_option')->alias(function ($key, $value) {
            $this->options[$key] = $value;

            return true;
        });
    }

    private function stubClientHttp(int $status): void
    {
        Functions\when('get_option')->alias(fn ($key, $default = '') => match ($key) {
            'appinio_api_key' => 'sk_test_key',
            'appinio_synced_count' => $this->options['appinio_synced_count'] ?? 0,
            default => $default,
        });
        $this->captureOptions();
        Functions\when('wp_json_encode')->alias(static fn ($d) => json_encode($d));
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn($status);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');
        // ProductMapper reads category/tag/brand terms while building each batch item.
        Functions\when('get_the_terms')->justReturn(false);
    }
}
