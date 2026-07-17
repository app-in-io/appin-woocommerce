<?php

declare(strict_types=1);

namespace Appinio\Tests\Sync;

use Appinio\Sync\RetryOutcome;
use Appinio\Sync\RetryPolicy;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class RetryPolicyTest extends TestCase
{
    use MockeryPHPUnitIntegration;

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

    public function test_permanent_status_clears_and_does_not_reschedule(): void
    {
        Functions\expect('delete_transient')->once();
        Functions\expect('as_schedule_single_action')->never();

        $outcome = (new RetryPolicy)->attempt('appinio_sync_product', [7], 422);

        self::assertSame(RetryOutcome::Permanent, $outcome);
    }

    public function test_transient_status_reschedules_same_args_with_backoff(): void
    {
        Functions\when('get_transient')->justReturn(false); // first failure → next attempt = 1
        Functions\expect('set_transient')->once()->with(Mockery::type('string'), 1, DAY_IN_SECONDS);
        Functions\expect('as_schedule_single_action')
            ->once()
            ->with(Mockery::type('int'), 'appinio_sync_product', [7], 'appinio-search');

        $outcome = (new RetryPolicy)->attempt('appinio_sync_product', [7], 503);

        self::assertSame(RetryOutcome::Rescheduled, $outcome);
    }

    public function test_exhausted_after_attempt_cap(): void
    {
        Functions\when('get_transient')->justReturn(4); // next = 5 = MAX_ATTEMPTS
        Functions\expect('delete_transient')->once();
        Functions\expect('as_schedule_single_action')->never();

        $outcome = (new RetryPolicy)->attempt('appinio_sync_product', [7], 0); // network error

        self::assertSame(RetryOutcome::Exhausted, $outcome);
    }

    public function test_clear_deletes_the_counter(): void
    {
        Functions\expect('delete_transient')->once();

        (new RetryPolicy)->clear('appinio_sync_product', [7]);
    }

    // Note: the "Action Scheduler unavailable → Permanent (give up, don't throw)" guard
    // in attempt() is not unit-tested here — Brain Monkey defines a mocked function
    // process-wide, so function_exists('as_schedule_single_action') can't be forced false
    // once any other test has stubbed it. The branch is a simple defensive guard.
}
