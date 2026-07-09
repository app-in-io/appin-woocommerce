<?php

declare(strict_types=1);

namespace AppInIo\Sync;

use AppInIo\Api\Client;
use AppInIo\Mapper\ProductMapper;

if (! defined('ABSPATH')) {
    exit;
}

final class ProductSync
{
    /** @var array<int, true> Prevent duplicate processing within same request */
    private static array $processed = [];

    public function __construct(private RetryPolicy $retryPolicy = new RetryPolicy) {}

    public function register(): void
    {
        if (! get_option('appinio_auto_sync', true)) {
            return;
        }

        // Product create/update — debounced via Action Scheduler
        add_action('woocommerce_new_product', [$this, 'scheduleSync']);
        add_action('woocommerce_update_product', [$this, 'scheduleSync']);
        add_action('woocommerce_product_set_stock', [$this, 'scheduleSync']);

        // Product delete — trash, and hard delete (EMPTY_TRASH_DAYS=0, REST, WP-CLI)
        add_action('wp_trash_post', [$this, 'scheduleDelete']);
        add_action('before_delete_post', [$this, 'scheduleDelete']);

        // Product restore from trash
        add_action('untrashed_post', [$this, 'scheduleSync']);

        // Deferred execution via Action Scheduler
        add_action('appinio_sync_product', [$this, 'syncProduct']);
        add_action('appinio_delete_product', [$this, 'deleteProduct']);
    }

    /**
     * Schedule a product sync with 5-second debounce.
     * Cancels existing scheduled action to coalesce rapid-fire hooks.
     */
    public function scheduleSync(int|\WC_Product $productOrId): void
    {
        $productId = $productOrId instanceof \WC_Product
            ? $productOrId->get_id()
            : $productOrId;

        if ($productId <= 0) {
            return;
        }

        // Skip variations — we index the parent product only
        if (get_post_type($productId) === 'product_variation') {
            $productId = wp_get_post_parent_id($productId) ?: $productId;
        }

        $this->cancelPending($productId);

        if (\function_exists('as_schedule_single_action')) {
            as_schedule_single_action(
                time() + 5,
                'appinio_sync_product',
                [$productId],
                'appinio-search'
            );
        } else {
            // Fallback if Action Scheduler not available
            $this->syncProduct($productId);
        }
    }

    /**
     * Schedule a product deletion.
     */
    public function scheduleDelete(int $postId): void
    {
        if (get_post_type($postId) !== 'product') {
            return;
        }

        $this->cancelPending($postId);

        if (\function_exists('as_schedule_single_action')) {
            as_schedule_single_action(
                time() + 2,
                'appinio_delete_product',
                [$postId],
                'appinio-search'
            );
        } else {
            $this->deleteProduct($postId);
        }
    }

    /**
     * Execute product sync — called by Action Scheduler.
     */
    public function syncProduct(int $productId): void
    {
        if (isset(self::$processed[$productId])) {
            return;
        }
        self::$processed[$productId] = true;

        $product = wc_get_product($productId);
        if (! $product) {
            return;
        }

        // Only sync published products. If no longer published, delete from index.
        if ($product->get_status() !== 'publish') {
            $this->deleteProduct($productId);

            return;
        }

        // Only sync searchable products. Catalog-visibility "catalog"/"hidden" is
        // excluded from search, so it must not live in the search index — deindex.
        if (! \in_array($product->get_catalog_visibility(), ['visible', 'search'], true)) {
            $this->deleteProduct($productId);

            return;
        }

        $mapper = new ProductMapper;
        $data = $mapper->toApiData($product);

        $client = new Client;
        $result = $client->indexProduct($data);

        if ($result['ok']) {
            $this->retryPolicy->clear('appinio_sync_product', [$productId]);
            $this->updateSyncedCount();

            return;
        }

        $this->logError($productId, 'index', $result);
        $this->handleFailure('appinio_sync_product', $productId, 'index', $result['status']);
    }

    /**
     * Execute product deletion — called by Action Scheduler.
     */
    public function deleteProduct(int $productId): void
    {
        $client = new Client;
        $result = $client->deleteProduct((string) $productId);

        // A 404 means it's already gone from the index — treat as success.
        if ($result['ok'] || $result['status'] === 404) {
            $this->retryPolicy->clear('appinio_delete_product', [$productId]);

            return;
        }

        $this->logError($productId, 'delete', $result);
        $this->handleFailure('appinio_delete_product', $productId, 'delete', $result['status']);
    }

    /**
     * Self-heal a failed sync: transient failures reschedule with backoff; when the
     * retry cap is hit, throw so Action Scheduler marks the action failed (visible in
     * Tools → Scheduled Actions). Permanent (4xx) failures are already logged — drop.
     */
    private function handleFailure(string $hook, int $productId, string $action, int $status): void
    {
        if ($this->retryPolicy->attempt($hook, [$productId], $status) === RetryOutcome::Exhausted) {
            throw new \RuntimeException(\sprintf(
                'AppIn %s failed for product #%d after retries (HTTP %d)',
                $action,
                $productId,
                $status
            ));
        }
    }

    private function cancelPending(int $productId): void
    {
        if (\function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('appinio_sync_product', [$productId], 'appinio-search');
            as_unschedule_all_actions('appinio_delete_product', [$productId], 'appinio-search');
        }

        // A fresh edit/delete resets the retry budget for this product.
        $this->retryPolicy->clear('appinio_sync_product', [$productId]);
        $this->retryPolicy->clear('appinio_delete_product', [$productId]);
    }

    private function updateSyncedCount(): void
    {
        $count = (int) get_option('appinio_synced_count', 0);
        update_option('appinio_synced_count', $count + 1, false);
    }

    /**
     * @param  array{ok: bool, status: int, body: array<string, mixed>}  $result
     */
    private function logError(int $productId, string $action, array $result): void
    {
        if (! defined('WP_DEBUG') || ! WP_DEBUG) {
            return;
        }

        error_log(\sprintf(
            '[AppIn Search] Failed to %s product #%d: HTTP %d — %s',
            $action,
            $productId,
            $result['status'],
            $result['body']['error'] ?? 'Unknown error'
        ));
    }
}
