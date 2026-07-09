<?php

declare(strict_types=1);

namespace AppInIo\Sync;

use AppInIo\Api\Client;
use AppInIo\Mapper\ProductMapper;

if (! defined('ABSPATH')) {
    exit;
}

class BulkSync
{
    private const BATCH_SIZE = 20;

    // A run whose heartbeat is older than this is treated as crashed, not in-progress.
    private const STALE_AFTER = 300;

    public function __construct(
        private RetryPolicy $retryPolicy = new RetryPolicy,
        private IndexState $indexState = new IndexState,
    ) {}

    public function register(): void
    {
        add_action('admin_post_appinio_bulk_sync', [$this, 'handleBulkSync']);
        add_action('admin_post_appinio_bulk_delete', [$this, 'handleBulkDelete']);
        add_action('appinio_bulk_sync_batch', [$this, 'processBatch']);
        add_action('appinio_bulk_delete_batch', [$this, 'processDeleteBatch']);
        add_action('wp_ajax_appinio_sync_status', [$this, 'ajaxSyncStatus']);
    }

    /**
     * AJAX endpoint: return sync status as JSON.
     */
    public function ajaxSyncStatus(): void
    {
        check_ajax_referer('appinio_sync_status');

        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error('', 403);
        }

        wp_send_json([
            'running' => (bool) get_option('appinio_bulk_sync_running', false),
            'operation' => (string) get_option('appinio_bulk_operation', 'sync'),
            'synced' => $this->indexState->count(),
            'last_sync' => get_option('appinio_last_sync', ''),
            'delete_failed' => (int) get_option('appinio_last_delete_failed', 0),
        ]);
    }

    /**
     * Handle "Sync All Products" button click.
     */
    public function handleBulkSync(): void
    {
        check_admin_referer('appinio_bulk_sync');

        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized.', 'appinio-search'));
        }

        // Re-entrancy guard: a run already in progress must not spawn a second chain.
        if ($this->isBulkRunning()) {
            $this->redirect(admin_url('admin.php?page=appinio-search&appinio_busy=1'));

            return;
        }

        $this->startBulk('sync', 'appinio_bulk_sync_batch');
        $this->redirect(admin_url('admin.php?page=appinio-search'));
    }

    /**
     * Handle "Delete All from Index" button click.
     */
    public function handleBulkDelete(): void
    {
        check_admin_referer('appinio_bulk_delete');

        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized.', 'appinio-search'));
        }

        if ($this->isBulkRunning()) {
            $this->redirect(admin_url('admin.php?page=appinio-search&appinio_busy=1'));

            return;
        }

        $this->startBulk('delete', 'appinio_bulk_delete_batch');
        $this->redirect(admin_url('admin.php?page=appinio-search'));
    }

    /**
     * Whether a bulk run is genuinely in progress. The running flag is heartbeated on
     * every batch, so a flag left set by a crashed worker (PHP fatal, OOM, timeout, or a
     * dropped Action Scheduler job) goes stale and no longer wedges future runs.
     */
    private function isBulkRunning(): bool
    {
        if (! get_option('appinio_bulk_sync_running', false)) {
            return false;
        }

        return (time() - (int) get_option('appinio_bulk_heartbeat', 0)) < self::STALE_AFTER;
    }

    /**
     * Mark a bulk run in progress and schedule its first batch, cancelling any stray
     * pending batches from a previous run first (defensive against orphans).
     */
    private function startBulk(string $operation, string $hook): void
    {
        if (\function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions($hook, [], 'appinio-search');
        }

        update_option('appinio_bulk_sync_running', true, false);
        update_option('appinio_bulk_operation', $operation, false);
        update_option('appinio_bulk_heartbeat', time(), false);
        update_option('appinio_last_delete_failed', 0, false); // clear any stale delete-failure notice

        as_schedule_single_action(time(), $hook, [1], 'appinio-search');
    }

    /**
     * Redirect and halt after an admin-post action. Extracted (and protected, non-final)
     * so the handlers above can be unit-tested — tests override this to avoid exit().
     */
    protected function redirect(string $url): void
    {
        wp_safe_redirect($url);
        exit;
    }

    /**
     * Process a batch of products via batch endpoint. Schedules next batch if more remain.
     */
    public function processBatch(int $page): void
    {
        update_option('appinio_bulk_heartbeat', time(), false);

        $products = wc_get_products([
            'status' => 'publish',
            'limit' => self::BATCH_SIZE,
            'page' => $page,
            'return' => 'objects',
            'type' => ['simple', 'variable', 'external', 'grouped'],
        ]);

        if (empty($products)) {
            $this->finishSync();

            return;
        }

        $mapper = new ProductMapper;
        $client = new Client;

        $items = [];
        $syncedIds = [];
        foreach ($products as $product) {
            // Skip search-excluded products (catalog-visibility "catalog"/"hidden").
            if (! \in_array($product->get_catalog_visibility(), ['visible', 'search'], true)) {
                continue;
            }

            $items[] = $mapper->toApiData($product);
            $syncedIds[] = (int) $product->get_id();
        }

        // A batch may contain only non-searchable products — nothing to send.
        if ($items !== []) {
            $result = $client->indexProductBatch($items);

            if ($result['ok']) {
                $this->retryPolicy->clear('appinio_bulk_sync_batch', [$page]);
                foreach ($syncedIds as $syncedId) {
                    $this->indexState->markIndexed($syncedId);
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(\sprintf(
                        '[AppIn Search] Bulk sync batch failed (page %d): HTTP %d — %s',
                        $page,
                        $result['status'],
                        $result['body']['error'] ?? $result['body']['message'] ?? 'unknown'
                    ));
                }

                // Transient → reschedule this same page with backoff and stop; the retry
                // re-runs processBatch($page). Permanent/Exhausted → fall through and
                // advance the chain so bulk still completes (finishSync runs, no stuck flag).
                if ($this->retryPolicy->attempt('appinio_bulk_sync_batch', [$page], $result['status']) === RetryOutcome::Rescheduled) {
                    return;
                }
            }
        }

        // Schedule next batch
        if (\count($products) === self::BATCH_SIZE) {
            as_schedule_single_action(time(), 'appinio_bulk_sync_batch', [$page + 1], 'appinio-search');
        } else {
            $this->finishSync();
        }
    }

    /**
     * Delete products from index in batches.
     */
    public function processDeleteBatch(int $page): void
    {
        update_option('appinio_bulk_heartbeat', time(), false);

        $products = wc_get_products([
            'limit' => self::BATCH_SIZE,
            'page' => $page,
            'return' => 'ids',
        ]);

        if (empty($products)) {
            $this->finishDelete();

            return;
        }

        $client = new Client;
        $failed = 0;

        foreach ($products as $productId) {
            $result = $client->deleteProduct((string) $productId);

            if ($result['ok'] || $result['status'] === 404) {
                $this->indexState->markDeindexed((int) $productId);
            } else {
                $failed++;
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(\sprintf(
                        '[AppIn Search] Bulk delete failed for #%d: HTTP %d',
                        $productId,
                        $result['status']
                    ));
                }
            }
        }

        if ($failed > 0) {
            $prior = (int) get_option('appinio_last_delete_failed', 0);
            update_option('appinio_last_delete_failed', $prior + $failed, false);
        }

        if (\count($products) === self::BATCH_SIZE) {
            as_schedule_single_action(time(), 'appinio_bulk_delete_batch', [$page + 1], 'appinio-search');
        } else {
            $this->finishDelete();
        }
    }

    private function finishSync(): void
    {
        update_option('appinio_bulk_sync_running', false, false);
        delete_option('appinio_bulk_heartbeat');
        update_option('appinio_last_sync', current_time('mysql'), false);
        // Keep appinio_bulk_operation as the last op so the widget's terminal poll (which
        // fires after this) still labels the run correctly; startBulk overwrites it next.
    }

    private function finishDelete(): void
    {
        update_option('appinio_bulk_sync_running', false, false);
        delete_option('appinio_bulk_heartbeat');
        // The "Synced" count derives from the per-product flag (removed per item above),
        // so there is nothing to reset here — and "Last sync" is a sync-only timestamp.
    }
}
