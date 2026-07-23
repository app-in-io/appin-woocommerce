<?php

declare(strict_types=1);

namespace Appinio\Tests\Api;

use Appinio\Api\ConnectionSignal;
use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class ConnectionSignalTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Client::apiUrl() resolves through the appinio_api_url filter (returns the default).
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('home_url')->justReturn('https://store.test');
        Functions\when('get_bloginfo')->justReturn('6.5');
        Functions\when('wp_json_encode')->alias(static fn ($data) => json_encode($data));
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"status":"granted"}');
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_register_hooks_both_option_actions(): void
    {
        Actions\expectAdded('add_option_appinio_api_key')->once();
        Actions\expectAdded('update_option_appinio_api_key')->once();

        (new ConnectionSignal)->register();
    }

    public function test_it_announces_the_connection_when_a_key_is_saved(): void
    {
        Functions\when('get_option')->justReturn('sk_live_abc123');

        Functions\expect('wp_remote_request')
            ->once()
            ->with(
                Mockery::on(static fn (string $url): bool => str_contains($url, '/plugin/connected')),
                Mockery::type('array'),
            )
            ->andReturn(['response' => ['code' => 200]]);

        (new ConnectionSignal)->onUpdate('', 'sk_live_abc123');
    }

    public function test_it_does_not_announce_when_the_key_is_cleared(): void
    {
        Functions\when('get_option')->justReturn('');
        Functions\expect('wp_remote_request')->never();

        (new ConnectionSignal)->onUpdate('sk_live_old', '');
    }
}
