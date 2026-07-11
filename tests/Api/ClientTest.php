<?php

declare(strict_types=1);

namespace AppInIo\Tests\Api;

use AppInIo\Api\Client;
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

    public function test_get_counts_sends_get_without_body_and_parses_contract(): void
    {
        Functions\expect('wp_remote_request')
            ->once()
            ->with('https://api.app-in.io/v1/index/counts', Mockery::on(function (array $args): bool {
                self::assertSame('GET', $args['method']);
                self::assertSame('sk_test_key', $args['headers']['X-API-Key']);
                self::assertSame('woocommerce', $args['headers']['X-Platform']);
                // A GET must carry no request body — some servers/WAFs reject one.
                self::assertArrayNotHasKey('body', $args);
                self::assertSame(10, $args['timeout']);

                return true;
            }))
            ->andReturn('RESP');

        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn(
            '{"counts":{"pages":0,"products":1234,"docs":0},"total":1234,"pending":3,'
            . '"status":"completed","last_indexed_at":"2026-07-11T07:57:41+00:00","error":null}'
        );

        $result = (new Client)->getCounts();

        self::assertTrue($result['ok']);
        self::assertSame(200, $result['status']);
        self::assertSame(1234, $result['body']['counts']['products']);
        self::assertSame(3, $result['body']['pending']);
        self::assertSame('completed', $result['body']['status']);
    }

    public function test_network_error_retries_then_returns_status_zero(): void
    {
        // A transport-level WP_Error is transient — retry up to MAX_RETRIES, then give up
        // with status 0. (sleep() is shimmed so the back-off doesn't block the test.)
        Functions\when('AppInIo\Api\sleep')->justReturn(0);

        $wpError = Mockery::mock('WP_Error');
        $wpError->allows('get_error_message')->andReturn('Connection refused');

        Functions\expect('wp_remote_request')->times(3)->andReturn($wpError);
        Functions\when('is_wp_error')->justReturn(true);

        $result = (new Client)->indexProduct(['id' => '1']);

        self::assertFalse($result['ok']);
        self::assertSame(0, $result['status']);
        self::assertSame('Connection refused', $result['body']['error']);
    }

    public function test_is_retryable_classifies_transient_vs_permanent(): void
    {
        // Transient: network (0), rate limit (429), server errors (5xx).
        self::assertTrue(Client::isRetryable(0));
        self::assertTrue(Client::isRetryable(429));
        self::assertTrue(Client::isRetryable(503));
        // Permanent: success + 4xx client errors.
        self::assertFalse(Client::isRetryable(200));
        self::assertFalse(Client::isRetryable(422));
        self::assertFalse(Client::isRetryable(404));
    }

    public function test_retries_once_on_429_then_succeeds(): void
    {
        // sleep() is called on the 429 back-off — shim the namespaced call so the test is instant.
        Functions\when('AppInIo\Api\sleep')->justReturn(0);

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
        Functions\when('AppInIo\Api\sleep')->justReturn(0);

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

    public function test_retries_on_5xx_then_succeeds(): void
    {
        // Transient server errors are retryable, just like 429.
        Functions\when('AppInIo\Api\sleep')->justReturn(0);

        Functions\expect('wp_remote_request')->twice()->andReturn('R503', 'R200');
        Functions\when('is_wp_error')->justReturn(false);
        Functions\expect('wp_remote_retrieve_response_code')->twice()->andReturn(503, 200);
        Functions\when('wp_remote_retrieve_header')->justReturn('');
        Functions\when('wp_remote_retrieve_body')->justReturn('{"ok":true}');

        $result = (new Client)->indexProduct(['id' => '1']);

        self::assertTrue($result['ok']);
        self::assertSame(200, $result['status']);
    }

    public function test_exhausts_max_retries_on_persistent_5xx(): void
    {
        Functions\when('AppInIo\Api\sleep')->justReturn(0);

        Functions\expect('wp_remote_request')->times(3)->andReturn('R500');
        Functions\when('is_wp_error')->justReturn(false);
        Functions\expect('wp_remote_retrieve_response_code')->times(3)->andReturn(500);
        Functions\when('wp_remote_retrieve_header')->justReturn('');
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

    public function test_search_products_returns_ids_with_short_timeout(): void
    {
        Functions\expect('wp_remote_request')
            ->once()
            ->with('https://api.app-in.io/v1/search/products', Mockery::on(function (array $args): bool {
                self::assertSame('POST', $args['method']);
                self::assertSame(['query' => 'boots', 'limit' => 100], json_decode($args['body'], true));
                // User-facing render path: short timeout, no retry loop.
                self::assertSame(5, $args['timeout']);

                return true;
            }))
            ->andReturn('RESP');

        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"results":[{"id":"5"},{"id":"3"},{"id":"9"}]}');

        $ids = (new Client)->searchProducts('boots');

        self::assertSame([5, 3, 9], $ids);
    }

    public function test_search_products_includes_lang_and_category(): void
    {
        Functions\expect('wp_remote_request')
            ->once()
            ->with('https://api.app-in.io/v1/search/products', Mockery::on(function (array $args): bool {
                self::assertSame(
                    ['query' => 'jacket', 'limit' => 20, 'lang' => 'de', 'category_id' => 7],
                    json_decode($args['body'], true)
                );

                return true;
            }))
            ->andReturn('RESP');

        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"results":[]}');

        (new Client)->searchProducts('jacket', 20, 'de', 7);
    }

    public function test_search_products_returns_empty_list_on_zero_results(): void
    {
        Functions\expect('wp_remote_request')->once()->andReturn('RESP');
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"results":[]}');

        // Success with no matches is [] (distinct from a failure).
        self::assertSame([], (new Client)->searchProducts('zzz'));
    }

    public function test_search_products_returns_null_on_failure_without_retry(): void
    {
        // Single attempt only (maxRetries = 1) even on a normally-retryable 500.
        Functions\expect('wp_remote_request')->once()->andReturn('R500');
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(500);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');

        self::assertNull((new Client)->searchProducts('boots'));
    }

    public function test_request_otp_posts_registration_payload_with_platform_header(): void
    {
        Functions\expect('wp_remote_request')
            ->once()
            ->with('https://api.app-in.io/v1/register/request-otp', Mockery::on(function (array $args): bool {
                self::assertSame('POST', $args['method']);
                self::assertSame('woocommerce', $args['headers']['X-Platform']);
                self::assertSame(
                    ['email' => 'o@shop.com', 'store_url' => 'https://shop.com', 'name' => 'Shop', 'locale' => 'de_DE'],
                    json_decode($args['body'], true)
                );
                self::assertSame(15, $args['timeout']);

                return true;
            }))
            ->andReturn('RESP');

        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(202);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"status":"otp_sent","expires_in":600}');

        $result = (new Client)->requestOtp('o@shop.com', 'https://shop.com', 'Shop', 'de_DE');

        self::assertTrue($result['ok']);
        self::assertSame(202, $result['status']);
    }

    public function test_request_otp_does_not_retry_on_429(): void
    {
        // maxRetries=1: a 429 cooldown must surface, never trigger a second OTP email.
        Functions\expect('wp_remote_request')->once()->andReturn('R429');
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(429);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"retry_after":60}');

        $result = (new Client)->requestOtp('o@shop.com', 'https://shop.com', 'Shop', 'en_US');

        self::assertFalse($result['ok']);
        self::assertSame(429, $result['status']);
        self::assertSame(60, $result['body']['retry_after']);
    }

    public function test_request_otp_omits_api_key_header_on_fresh_install(): void
    {
        // No key yet → the keyless registration call must not send an empty X-API-Key.
        Functions\when('get_option')->justReturn('');

        Functions\expect('wp_remote_request')
            ->once()
            ->with(Mockery::any(), Mockery::on(function (array $args): bool {
                self::assertArrayNotHasKey('X-API-Key', $args['headers']);
                self::assertSame('woocommerce', $args['headers']['X-Platform']);

                return true;
            }))
            ->andReturn('RESP');

        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(202);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');

        (new Client)->requestOtp('o@shop.com', 'https://shop.com', 'Shop', 'en_US');
    }

    public function test_verify_registration_posts_code_and_returns_keys(): void
    {
        Functions\expect('wp_remote_request')
            ->once()
            ->with('https://api.app-in.io/v1/register/verify', Mockery::on(function (array $args): bool {
                self::assertSame('POST', $args['method']);
                self::assertSame('woocommerce', $args['headers']['X-Platform']);
                self::assertSame(
                    ['email' => 'o@shop.com', 'store_url' => 'https://shop.com', 'code' => '123456'],
                    json_decode($args['body'], true)
                );

                return true;
            }))
            ->andReturn('RESP');

        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(201);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"api_key":"sk_live_x","public_key":"pk_live_y"}');

        $result = (new Client)->verifyRegistration('o@shop.com', 'https://shop.com', '123456');

        self::assertTrue($result['ok']);
        self::assertSame(201, $result['status']);
        self::assertSame('sk_live_x', $result['body']['api_key']);
        self::assertSame('pk_live_y', $result['body']['public_key']);
    }
}
