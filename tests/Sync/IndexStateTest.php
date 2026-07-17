<?php

declare(strict_types=1);

namespace Appinio\Tests\Sync;

use Appinio\Sync\IndexState;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class IndexStateTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_mark_indexed_sets_the_flag_and_busts_the_cache(): void
    {
        Functions\expect('update_post_meta')->once()->with(7, '_appinio_indexed', 1);
        Functions\expect('delete_transient')->once()->with('appinio_indexed_count');

        (new IndexState)->markIndexed(7);
    }

    public function test_mark_deindexed_removes_the_flag_and_busts_the_cache(): void
    {
        Functions\expect('delete_post_meta')->once()->with(7, '_appinio_indexed');
        Functions\expect('delete_transient')->once()->with('appinio_indexed_count');

        (new IndexState)->markDeindexed(7);
    }

    public function test_count_returns_the_cached_value_without_querying(): void
    {
        Functions\when('get_transient')->justReturn(42);

        self::assertSame(42, (new IndexState)->count());
    }

    public function test_count_queries_and_caches_on_a_cache_miss(): void
    {
        Functions\when('get_transient')->justReturn(false);
        Functions\expect('set_transient')->once()->with('appinio_indexed_count', 8, 60);

        $wpdb = Mockery::mock();
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->allows('prepare')->andReturn('SQL');
        $wpdb->allows('get_var')->with('SQL')->andReturn('8');
        $GLOBALS['wpdb'] = $wpdb;

        self::assertSame(8, (new IndexState)->count());
    }
}
