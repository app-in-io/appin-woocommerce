<?php

declare(strict_types=1);

namespace Appinio\Tests\Sync;

use Appinio\I18n\LanguageResolver;
use Appinio\Sync\BulkSync;
use Appinio\Tests\Concerns\MocksWooCommerceProduct;
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

        // RetryPolicy transient helpers — default no-op so success paths don't fatal.
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        // IndexState deindex helper — default no-op (index tests set their own
        // update_post_meta expectations, so that one is not stubbed here).
        Functions\when('delete_post_meta')->justReturn(true);
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
        self::assertNotFalse(has_filter('heartbeat_received', [$bulk, 'heartbeatReceived']));
    }

    public function test_heartbeat_received_attaches_reconciliation_when_opted_in(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_option')->alias(fn ($key, $default = false) => match ($key) {
            'appinio_bulk_sync_running' => true,
            'appinio_bulk_operation' => 'sync',
            'appinio_last_sync' => '2026-07-06 10:00:00',
            'appinio_api_key' => 'sk_test_key',
            default => $default,
        });
        // IndexState count (queued) served from cache; remote counts fetched fresh over HTTP.
        Functions\when('get_transient')->alias(fn ($key) => $key === 'appinio_indexed_count' ? 7 : false);

        // Real Client (final) — stub the HTTP layer for the getCounts call.
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_request')->justReturn('RESP');
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn((string) json_encode([
            'counts' => ['products' => 1234],
            'pending' => 3,
            'status' => 'running',
            'reconciled' => true,
            'last_indexed_at' => null,
        ]));

        // Client opted in on our screen via heartbeat-send.
        $response = (new BulkSync)->heartbeatReceived([], ['appinio_sync_status' => true]);

        self::assertArrayHasKey('appinio_sync_status', $response);
        $s = $response['appinio_sync_status'];
        self::assertSame(7, $s['synced']);       // local queued count
        self::assertSame(1234, $s['indexed']);   // real index count
        self::assertSame(3, $s['pending']);      // jobs still in flight
        self::assertSame('running', $s['index_status']);
    }

    public function test_heartbeat_received_is_a_noop_without_opt_in(): void
    {
        // No `appinio_sync_status` in the heartbeat data → unrelated admin screen, leave untouched
        // (and never pay the reconciliation cost).
        $response = (new BulkSync)->heartbeatReceived(['existing' => 1], []);

        self::assertSame(['existing' => 1], $response);
        self::assertArrayNotHasKey('appinio_sync_status', $response);
    }

    public function test_handle_bulk_sync_starts_first_batch_and_redirects(): void
    {
        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('admin_url')->alias(static fn ($p) => "https://wp.test/wp-admin/$p");
        Functions\when('as_unschedule_all_actions')->justReturn(0);
        $this->captureOptions();

        Functions\expect('as_schedule_single_action')
            ->once()
            ->with(Mockery::type('int'), 'appinio_bulk_sync_batch', [1], 'appinio-search');

        // Partial mock so the real handler runs but redirect() does not exit().
        $bulk = Mockery::mock(BulkSync::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $bulk->expects('redirect')->once()->with('https://wp.test/wp-admin/admin.php?page=appinio-search');

        $bulk->handleBulkSync();

        self::assertTrue($this->options['appinio_bulk_sync_running']);
        self::assertSame('sync', $this->options['appinio_bulk_operation']);
    }

    public function test_handle_bulk_sync_is_blocked_while_a_run_is_in_progress(): void
    {
        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('admin_url')->alias(static fn ($p) => "https://wp.test/wp-admin/$p");
        $this->captureOptions();
        $this->options['appinio_bulk_sync_running'] = true; // already running
        $this->options['appinio_bulk_heartbeat'] = time(); // fresh heartbeat → genuinely running

        // Re-entrancy guard: no second chain scheduled.
        Functions\expect('as_schedule_single_action')->never();

        $bulk = Mockery::mock(BulkSync::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $bulk->expects('redirect')->once()->with('https://wp.test/wp-admin/admin.php?page=appinio-search&appinio_busy=1');

        $bulk->handleBulkSync();

        self::assertTrue(true);
    }

    public function test_handle_bulk_sync_recovers_from_a_stale_run(): void
    {
        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('admin_url')->alias(static fn ($p) => "https://wp.test/wp-admin/$p");
        Functions\when('as_unschedule_all_actions')->justReturn(0);
        $this->captureOptions();
        $this->options['appinio_bulk_sync_running'] = true;
        $this->options['appinio_bulk_heartbeat'] = time() - 3600; // stale → crashed run

        // A stale run must not wedge future syncs — a fresh chain starts.
        Functions\expect('as_schedule_single_action')
            ->once()
            ->with(Mockery::type('int'), 'appinio_bulk_sync_batch', [1], 'appinio-search');

        $bulk = Mockery::mock(BulkSync::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $bulk->expects('redirect')->once()->with('https://wp.test/wp-admin/admin.php?page=appinio-search');

        $bulk->handleBulkSync();

        self::assertTrue($this->options['appinio_bulk_sync_running']);
    }

    public function test_handle_bulk_delete_starts_first_batch_and_redirects(): void
    {
        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('admin_url')->alias(static fn ($p) => "https://wp.test/wp-admin/$p");
        Functions\when('as_unschedule_all_actions')->justReturn(0);
        $this->captureOptions();

        Functions\expect('as_schedule_single_action')
            ->once()
            ->with(Mockery::type('int'), 'appinio_bulk_delete_batch', [1], 'appinio-search');

        $bulk = Mockery::mock(BulkSync::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $bulk->expects('redirect')->once()->with('https://wp.test/wp-admin/admin.php?page=appinio-search');

        $bulk->handleBulkDelete();

        self::assertTrue($this->options['appinio_bulk_sync_running']);
        self::assertSame('delete', $this->options['appinio_bulk_operation']);
        self::assertSame(0, $this->options['appinio_last_delete_failed']); // reset at start
    }

    public function test_process_batch_indexes_and_finishes_when_page_not_full(): void
    {
        $this->stubClientHttp(202);
        Functions\when('current_time')->justReturn('2026-07-06 10:00:00');

        // Each synced product is flagged indexed.
        Functions\expect('update_post_meta')->twice()->with(Mockery::type('int'), '_appinio_indexed', 1);

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

        // finishSync() ran: running flag cleared, last_sync stamped.
        self::assertFalse($this->options['appinio_bulk_sync_running']);
        self::assertSame('2026-07-06 10:00:00', $this->options['appinio_last_sync']);
    }

    public function test_process_batch_schedules_next_page_when_full(): void
    {
        $this->stubClientHttp(202);
        Functions\when('update_post_meta')->justReturn(true); // markIndexed (not asserted here)

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

        // Not the final page → finishSync() must NOT run.
        self::assertArrayNotHasKey('appinio_bulk_sync_running', $this->options);
    }

    public function test_process_batch_skips_non_searchable_products(): void
    {
        $this->stubClientHttp(202);
        Functions\when('current_time')->justReturn('2026-07-06 10:00:00');
        // Only the 2 searchable products are flagged indexed (hidden/catalog are not).
        Functions\expect('update_post_meta')->twice()->with(Mockery::type('int'), '_appinio_indexed', 1);

        // Searchable (visible/search) indexed; both non-searchable states (hidden AND
        // catalog-only) excluded.
        $products = [
            $this->makeWcProduct(['get_id' => 1]), // visible (trait default)
            $this->makeWcProduct(['get_id' => 2, 'get_catalog_visibility' => 'hidden']),
            $this->makeWcProduct(['get_id' => 3, 'get_catalog_visibility' => 'search']),
            $this->makeWcProduct(['get_id' => 4, 'get_catalog_visibility' => 'catalog']),
        ];
        Functions\when('wc_get_products')->justReturn($products);

        Functions\expect('wp_remote_request')
            ->once()
            ->with('https://api.app-in.io/v1/index/products/batch', Mockery::on(function (array $args): bool {
                $ids = array_column(json_decode($args['body'], true)['items'], 'id');
                self::assertSame(['1', '3'], $ids); // hidden #2 and catalog #4 excluded, order preserved

                return true;
            }))
            ->andReturn('RESP');

        (new BulkSync)->processBatch(1);

        self::assertTrue(true); // batch body assertion above proves only searchable were sent
    }

    public function test_process_batch_reschedules_same_page_on_transient_failure(): void
    {
        $this->stubClientHttp(503); // transient server error
        Functions\when('wp_remote_request')->justReturn('RESP');
        Functions\when('wp_remote_retrieve_header')->justReturn('');
        Functions\when('Appinio\Api\sleep')->justReturn(0);
        Functions\when('get_transient')->justReturn(false); // first failure

        $products = array_map(fn (int $i) => $this->makeWcProduct(['get_id' => $i]), range(1, 20));
        Functions\when('wc_get_products')->justReturn($products);

        // Retry THIS page (5) with backoff, do NOT advance to page 6.
        Functions\expect('as_schedule_single_action')
            ->once()
            ->with(Mockery::type('int'), 'appinio_bulk_sync_batch', [5], 'appinio-search');

        (new BulkSync)->processBatch(5);

        // Batch failed → running flag not cleared (no finishSync), retry pending.
        self::assertArrayNotHasKey('appinio_bulk_sync_running', $this->options);
    }

    public function test_process_batch_advances_chain_when_retries_exhausted(): void
    {
        $this->stubClientHttp(503);
        Functions\when('wp_remote_request')->justReturn('RESP');
        Functions\when('wp_remote_retrieve_header')->justReturn('');
        Functions\when('Appinio\Api\sleep')->justReturn(0);
        Functions\when('get_transient')->justReturn(4); // next = 5 = cap → Exhausted

        $products = array_map(fn (int $i) => $this->makeWcProduct(['get_id' => $i]), range(1, 20));
        Functions\when('wc_get_products')->justReturn($products);

        // Give up on this page but advance the chain (page 4) so bulk still completes.
        Functions\expect('as_schedule_single_action')
            ->once()
            ->with(Mockery::type('int'), 'appinio_bulk_sync_batch', [4], 'appinio-search');

        (new BulkSync)->processBatch(3);

        self::assertTrue(true); // as_schedule([4]) expectation above proves the chain advanced
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

        // finishDelete() ran: running flag cleared.
        self::assertFalse($this->options['appinio_bulk_sync_running']);
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
    }

    public function test_finish_delete_flushes_the_remote_counts_cache(): void
    {
        // After a delete run the indexed count drops — the 30s remote cache must be busted
        // (symmetric with finishSync) so the dashboard doesn't show a stale too-high number.
        $this->stubClientHttp(200);
        Functions\when('wc_get_products')->justReturn([]); // empty → finishDelete

        $flushed = [];
        Functions\when('delete_transient')->alias(function ($key) use (&$flushed): bool {
            $flushed[] = $key;

            return true;
        });

        (new BulkSync)->processDeleteBatch(1);

        self::assertContains('appinio_remote_counts', $flushed);
    }

    public function test_process_delete_batch_records_a_failure(): void
    {
        $this->stubClientHttp(500); // persistent server error → delete fails
        Functions\when('wc_get_products')->justReturn([10]);
        Functions\when('wp_remote_request')->justReturn('RESP');
        Functions\when('wp_remote_retrieve_header')->justReturn('');
        Functions\when('Appinio\Api\sleep')->justReturn(0);

        Functions\expect('delete_post_meta')->never(); // a failed delete must not deindex locally
        Functions\expect('as_schedule_single_action')->never();

        (new BulkSync)->processDeleteBatch(1);

        self::assertSame(1, $this->options['appinio_last_delete_failed']);
    }

    public function test_process_batch_widens_query_to_all_languages(): void
    {
        $this->stubClientHttp(202);
        Functions\when('current_time')->justReturn('2026-07-06 10:00:00');

        // Resolver that widens the query (Polylang-style) → the batch query must carry
        // `lang => ''`, otherwise only the default language would ever be indexed.
        $lang = new class extends LanguageResolver
        {
            public function allLanguagesQueryArgs(): array
            {
                return ['lang' => ''];
            }
        };

        Functions\expect('wc_get_products')
            ->once()
            ->with(Mockery::on(function (array $args): bool {
                self::assertSame('', $args['lang']);
                self::assertSame('publish', $args['status']);

                return true;
            }))
            ->andReturn([]); // empty → finishSync, no HTTP, no next batch

        Functions\expect('as_schedule_single_action')->never();

        (new BulkSync(lang: $lang))->processBatch(1);

        self::assertFalse($this->options['appinio_bulk_sync_running']);
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
        Functions\when('get_option')->alias(fn ($key, $default = false) => $this->options[$key] ?? $default);
        Functions\when('update_option')->alias(function ($key, $value) {
            $this->options[$key] = $value;

            return true;
        });
        Functions\when('delete_option')->alias(function ($key) {
            unset($this->options[$key]);

            return true;
        });
    }

    private function stubClientHttp(int $status): void
    {
        $this->options['appinio_api_key'] = 'sk_test_key'; // read by the inline new Client
        $this->captureOptions();
        Functions\when('wp_json_encode')->alias(static fn ($d) => json_encode($d));
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn($status);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');
        // ProductMapper reads category/tag/brand terms while building each batch item.
        Functions\when('get_the_terms')->justReturn(false);
    }
}
