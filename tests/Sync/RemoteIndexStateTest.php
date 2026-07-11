<?php

declare(strict_types=1);

namespace AppInIo\Tests\Sync;

use AppInIo\Sync\RemoteIndexState;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * RemoteIndexState wraps the real (final) Client, so — like the other Client-consuming
 * tests in this suite — these stub the WordPress HTTP layer rather than mock the Client.
 */
class RemoteIndexStateTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Client constructor reads the API key; default = cache miss. Tests that reach the
        // cache-write path stub set_transient themselves (so the failure test can assert it).
        Functions\when('get_option')->justReturn('sk_test_key');
        Functions\when('get_transient')->justReturn(false);
        Functions\when('is_wp_error')->justReturn(false);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * The frozen `GET /index/counts` success contract, with optional per-test overrides.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function okBody(array $overrides = []): array
    {
        return array_merge([
            'counts' => ['pages' => 0, 'products' => 1234, 'docs' => 0],
            'total' => 1234,
            'pending' => 3,
            'status' => 'completed',
            'last_indexed_at' => '2026-07-11T07:57:41+00:00',
            'error' => null,
        ], $overrides);
    }

    /**
     * Stub a successful backend response for the (single) getCounts call.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function stubOkBackend(array $overrides = []): void
    {
        Functions\when('set_transient')->justReturn(true);
        Functions\when('wp_remote_request')->justReturn('RESP');
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn((string) json_encode($this->okBody($overrides)));
    }

    public function test_maps_products_pending_status_and_timestamp_from_backend(): void
    {
        // Memoized within the instance → a single HTTP call feeds every accessor.
        Functions\when('set_transient')->justReturn(true);
        Functions\expect('wp_remote_request')->once()->andReturn('RESP');
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn((string) json_encode($this->okBody()));

        $state = new RemoteIndexState;

        self::assertTrue($state->available());
        self::assertSame(1234, $state->products());
        self::assertSame(3, $state->pending());
        self::assertSame('completed', $state->status());
        self::assertSame('2026-07-11T07:57:41+00:00', $state->lastIndexedAt());
    }

    public function test_cache_hit_serves_the_snapshot_without_any_api_call(): void
    {
        // A cached snapshot is served straight from the transient — no HTTP round-trip.
        Functions\when('get_transient')->justReturn([
            'available' => true,
            'products' => 500,
            'pending' => 0,
            'status' => 'completed',
            'last_indexed_at' => null,
        ]);
        Functions\expect('wp_remote_request')->never();

        $state = new RemoteIndexState;

        self::assertTrue($state->available());
        self::assertSame(500, $state->products());
        self::assertSame(0, $state->pending());
    }

    public function test_available_is_false_and_counts_null_when_the_backend_fails(): void
    {
        // getCounts retries transient 5xx (maxRetries=2) — shim the namespaced sleep.
        Functions\when('AppInIo\Api\sleep')->justReturn(0);
        Functions\when('wp_remote_request')->justReturn('RESP');
        Functions\when('wp_remote_retrieve_response_code')->justReturn(500);
        Functions\when('wp_remote_retrieve_header')->justReturn('');
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');

        // The failure is cached briefly (unavailable sentinel) so a poller can't hammer the API.
        Functions\expect('set_transient')->once()->with('appinio_remote_counts', ['available' => false], 30);

        $state = new RemoteIndexState;

        self::assertFalse($state->available());
        self::assertNull($state->products());
        self::assertNull($state->pending());
        self::assertNull($state->status());
    }

    public function test_cached_unavailable_sentinel_reports_unavailable(): void
    {
        // A cached outage sentinel must read back as "unavailable", never as 0 indexed.
        Functions\when('get_transient')->justReturn(['available' => false]);
        Functions\expect('wp_remote_request')->never();

        $state = new RemoteIndexState;

        self::assertFalse($state->available());
        self::assertNull($state->products());
    }

    public function test_drift_is_true_when_fewer_products_are_indexed_than_queued_and_settled(): void
    {
        // pending 0 → the queue is drained, so a shortfall is genuine drift.
        $this->stubOkBackend(['counts' => ['products' => 90], 'status' => 'completed', 'pending' => 0]);

        self::assertTrue((new RemoteIndexState)->drift(100));
    }

    public function test_drift_is_false_while_indexing_is_in_flight(): void
    {
        // Mid-sync the backend index legitimately lags the local queued count — not drift.
        $this->stubOkBackend(['counts' => ['products' => 40], 'status' => 'running', 'pending' => 60]);

        self::assertFalse((new RemoteIndexState)->drift(100));
    }

    public function test_drift_is_false_when_indexed_equals_queued(): void
    {
        $this->stubOkBackend(['counts' => ['products' => 100], 'status' => 'completed', 'pending' => 0]);

        self::assertFalse((new RemoteIndexState)->drift(100));
    }

    public function test_drift_is_true_when_last_index_failed_even_if_counts_match(): void
    {
        $this->stubOkBackend(['counts' => ['products' => 100], 'status' => 'failed', 'pending' => 0]);

        self::assertTrue((new RemoteIndexState)->drift(100));
    }

    public function test_drift_is_false_when_the_backend_is_unavailable(): void
    {
        Functions\when('set_transient')->justReturn(true);
        Functions\when('AppInIo\Api\sleep')->justReturn(0);
        Functions\when('wp_remote_request')->justReturn('RESP');
        Functions\when('wp_remote_retrieve_response_code')->justReturn(500);
        Functions\when('wp_remote_retrieve_header')->justReturn('');
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');

        // Unavailable → we can't tell whether anything drifted, so report none.
        self::assertFalse((new RemoteIndexState)->drift(100));
    }

    public function test_unreconciled_counts_are_treated_as_unavailable(): void
    {
        // An "ok" response whose live index read did not reconcile (embeddings unreachable):
        // the counts are stale DB values, so surface "unavailable" rather than a stale number.
        Functions\when('wp_remote_request')->justReturn('RESP');
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn(
            (string) json_encode($this->okBody(['reconciled' => false]))
        );
        Functions\expect('set_transient')->once()->with('appinio_remote_counts', ['available' => false], 30);

        $state = new RemoteIndexState;

        self::assertFalse($state->available());
        self::assertNull($state->products());
    }

    public function test_reconciled_true_counts_are_trusted(): void
    {
        Functions\when('set_transient')->justReturn(true);
        Functions\when('wp_remote_request')->justReturn('RESP');
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn(
            (string) json_encode($this->okBody(['reconciled' => true, 'counts' => ['products' => 77]]))
        );

        $state = new RemoteIndexState;

        self::assertTrue($state->available());
        self::assertSame(77, $state->products());
    }

    public function test_flush_deletes_the_cache_transient(): void
    {
        Functions\expect('delete_transient')->once()->with('appinio_remote_counts');

        (new RemoteIndexState)->flush();
    }
}
