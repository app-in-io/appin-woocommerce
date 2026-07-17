<?php

declare(strict_types=1);

namespace Appinio\Sync;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Result of RetryPolicy::attempt() — how the caller should react to a failed sync.
 */
enum RetryOutcome
{
    /** A retry was scheduled with backoff; the caller should stop and wait for it. */
    case Rescheduled;

    /** Non-retryable (4xx client error); the caller should give up quietly. */
    case Permanent;

    /** Retryable, but the attempt cap was reached; the caller should surface a failure. */
    case Exhausted;
}
