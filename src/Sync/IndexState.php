<?php

declare(strict_types=1);

namespace AppInIo\Sync;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Source of truth for the "Synced" count: a per-product `_appinio_indexed` post-meta
 * flag, set when a product is indexed and removed when it's deindexed. The displayed
 * count is derived (a COUNT of the flag), cached briefly — so it can never drift from
 * the real index the way a hand-maintained running total does. Both writes are
 * idempotent: setting the flag twice is a no-op (no over-count on re-index), deleting an
 * absent flag is a no-op (no under-count on a never-indexed delete).
 */
final class IndexState
{
    private const META_KEY = '_appinio_indexed';

    private const COUNT_CACHE = 'appinio_indexed_count';

    public function markIndexed(int $productId): void
    {
        update_post_meta($productId, self::META_KEY, 1);
        delete_transient(self::COUNT_CACHE);
    }

    public function markDeindexed(int $productId): void
    {
        delete_post_meta($productId, self::META_KEY);
        delete_transient(self::COUNT_CACHE);
    }

    /**
     * Number of currently-indexed products, cached for 60s.
     */
    public function count(): int
    {
        $cached = get_transient(self::COUNT_CACHE);

        if ($cached !== false) {
            return (int) $cached;
        }

        global $wpdb;

        // No core function counts posts by a meta key; the key is indexed, so a direct
        // COUNT is cheap, and the transient above keeps it off the hot path.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $count = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_KEY)
        );

        set_transient(self::COUNT_CACHE, $count, 60);

        return $count;
    }
}
