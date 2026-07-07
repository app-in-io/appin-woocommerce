<?php

declare(strict_types=1);

namespace AppIn\WooCommerce\Tests\Api;

use AppIn\WooCommerce\Api\Client;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // API key read by the Client constructor.
        Functions\when('get_option')->justReturn('sk_test_key');
        // Encode bodies exactly like WordPress would.
        Functions\when('wp_json_encode')->alias(static fn ($data) => json_encode($data));
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_index_product_posts_json_with_platform_headers(): void
    {
        Functions\expect('wp_remote_request')
            ->once()
            ->with('https://api.app-in.io/v1/index/products', Mockery::on(function (array $args): bool {
                self::assertSame('POST', $args['method']);
                self::assertSame('application/json', $args['headers']['Content-Type']);
                self::assertSame('sk_test_key', $args['headers']['X-API-Key']);
                self::assertSame('woocommerce', $args['headers']['X-Platform']);
                self::assertSame(['id' => '1', 'title' => 'Hat'], json_decode($args['body'], true));
                self::assertSame(30, $args['timeout']);

                return true;
            }))
            ->andReturn('RESP');

        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(202);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"status":"queued"}');

        $result = (new Client)->indexProduct(['id' => '1', 'title' => 'Hat']);

        self::assertSame(
            ['ok' => true, 'status' => 202, 'body' => ['status' => 'queued']],
            $result
        );
    }

    public function test_index_product_batch_targets_batch_endpoint(): void
    {
        Functions\expect('wp_remote_request')
            ->once()
            ->with('https://api.app-in.io/v1/index/products/batch', Mockery::on(function (array $args): bool {
                self::assertSame('POST', $args['method']);
                self::assertSame(['items' => [['id' => '1'], ['id' => '2']]], json_decode($args['body'], true));

                return true;
            }))
            ->andReturn('RESP');

        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(202);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');

        $result = (new Client)->indexProductBatch([['id' => '1'], ['id' => '2']]);

        self::assertTrue($result['ok']);
        self::assertSame(202, $result['status']);
    }

    public function test_delete_product_sends_delete_with_id(): void
    {
        Functions\expect('wp_remote_request')
            ->once()
            ->with('https://api.app-in.io/v1/index/products', Mockery::on(function (array $args): bool {
                self::assertSame('DELETE', $args['method']);
                self::assertSame(['id' => '5'], json_decode($args['body'], true));

                return true;
            }))
            ->andReturn('RESP');

        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');

        $result = (new Client)->deleteProduct('5');

        self::assertTrue($result['ok']);
        self::assertSame(200, $result['status']);
    }

    public function test_wp_error_returns_status_zero_and_does_not_retry(): void
    {
        // A transport-level WP_Error must short-circuit immediately — no retry loop.
        $wpError = Mockery::mock('WP_Error');
        $wpError->allows('get_error_message')->andReturn('Connection refused');

        Functions\expect('wp_remote_request')->once()->andReturn($wpError);
        Functions\when('is_wp_error')->justReturn(true);

        $result = (new Client)->indexProduct(['id' => '1']);

        self::assertFalse($result['ok']);
        self::assertSame(0, $result['status']);
        self::assertSame('Connection refused', $result['body']['error']);
    }

    public function test_retries_once_on_429_then_succeeds(): void
    {
        // sleep() is called on the 429 back-off — shim the namespaced call so the test is instant.
        Functions\when('AppIn\WooCommerce\Api\sleep')->justReturn(0);

        Functions\expect('wp_remote_request')->twice()->andReturn('R429', 'R200');
        Functions\when('is_wp_error')->justReturn(false);
        Functions\expect('wp_remote_retrieve_response_code')->twice()->andReturn(429, 200);
        Functions\when('wp_remote_retrieve_header')->justReturn('1');
        Functions\when('wp_remote_retrieve_body')->justReturn('{"ok":true}');

        $result = (new Client)->indexProduct(['id' => '1']);

        self::assertTrue($result['ok']);
        self::assertSame(200, $result['status']);
    }

    public function test_exhausts_max_retries_on_persistent_429(): void
    {
        Functions\when('AppIn\WooCommerce\Api\sleep')->justReturn(0);

        // MAX_RETRIES = 3 → three attempts, all 429.
        Functions\expect('wp_remote_request')->times(3)->andReturn('R429');
        Functions\when('is_wp_error')->justReturn(false);
        Functions\expect('wp_remote_retrieve_response_code')->times(3)->andReturn(429);
        Functions\when('wp_remote_retrieve_header')->justReturn('');
        Functions\when('wp_remote_retrieve_body')->justReturn('{"error":"rate limited"}');

        $result = (new Client)->indexProduct(['id' => '1']);

        self::assertFalse($result['ok']);
        self::assertSame(429, $result['status']);
        self::assertSame('rate limited', $result['body']['error']);
    }

    public function test_5xx_is_not_retried(): void
    {
        // Documents current behavior: only 429 retries; a 5xx breaks the loop on the first attempt.
        Functions\expect('wp_remote_request')->once()->andReturn('R500');
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(500);
        Functions\when('wp_remote_retrieve_body')->justReturn('');

        $result = (new Client)->indexProduct(['id' => '1']);

        self::assertFalse($result['ok']);
        self::assertSame(500, $result['status']);
        self::assertSame([], $result['body']);
    }

    public function test_4xx_returns_ok_false_with_decoded_body(): void
    {
        Functions\expect('wp_remote_request')->once()->andReturn('R422');
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(422);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"message":"invalid payload"}');

        $result = (new Client)->indexProduct(['id' => '1']);

        self::assertFalse($result['ok']);
        self::assertSame(422, $result['status']);
        self::assertSame('invalid payload', $result['body']['message']);
    }
}
