<?php

declare(strict_types=1);

namespace AppInIo\Sync;

use AppInIo\Api\Client;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Turns a transient sync failure into a scheduled retry with exponential backoff, so
 * Action Scheduler self-heals after a transient API/network outage instead of silently
 * diverging. The attempt count lives in a transient keyed by hook + args, so the
 * rescheduled action keeps identical args (the debounce/cancel logic is unaffected).
 */
final class RetryPolicy
{
    private const MAX_ATTEMPTS = 5;

    private const GROUP = 'appinio-search';

    /**
     * Decide what to do with a failed request status.
     *
     * @param  array<int, mixed>  $args  the Action Scheduler args to reschedule with
     */
    public function attempt(string $hook, array $args, int $status): RetryOutcome
    {
        $key = $this->key($hook, $args);

        if (! Client::isRetryable($status)) {
            delete_transient($key);

            return RetryOutcome::Permanent;
        }

        // Without Action Scheduler the job ran synchronously inside the request; we can't
        // reschedule, so give up quietly rather than throw and abort a product save.
        if (! \function_exists('as_schedule_single_action')) {
            delete_transient($key);

            return RetryOutcome::Permanent;
        }

        $next = (int) get_transient($key) + 1;

        if ($next >= self::MAX_ATTEMPTS) {
            delete_transient($key);

            return RetryOutcome::Exhausted;
        }

        set_transient($key, $next, DAY_IN_SECONDS);
        as_schedule_single_action(time() + $this->backoff($next), $hook, $args, self::GROUP);

        return RetryOutcome::Rescheduled;
    }

    /**
     * Clear the retry counter after a success (or a permanent give-up).
     *
     * @param  array<int, mixed>  $args
     */
    public function clear(string $hook, array $args): void
    {
        delete_transient($this->key($hook, $args));
    }

    /**
     * Exponential backoff in seconds, capped at one hour.
     */
    private function backoff(int $attempt): int
    {
        return (int) min(300 * (2 ** ($attempt - 1)), 3600);
    }

    /**
     * @param  array<int, mixed>  $args
     */
    private function key(string $hook, array $args): string
    {
        return 'appinio_retry_' . md5($hook . '|' . implode('_', $args));
    }
}
