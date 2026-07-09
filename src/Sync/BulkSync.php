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
            'synced' => (int) get_option('appinio_synced_count', 0),
            'last_sync' => get_option('appinio_last_sync', ''),
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

        update_option('appinio_bulk_sync_running', true, false);
        update_option('appinio_synced_count', 0, false);

        // Schedule first batch
        as_schedule_single_action(time(), 'appinio_bulk_sync_batch', [1], 'appinio-search');

        $this->redirect(admin_url('admin.php?page=appinio-search&syncing=1'));
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

        update_option('appinio_bulk_sync_running', true, false);

        as_schedule_single_action(time(), 'appinio_bulk_delete_batch', [1], 'appinio-search');

        $this->redirect(admin_url('admin.php?page=appinio-search&deleting=1'));
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
        foreach ($products as $product) {
            // Skip search-excluded products (catalog-visibility "catalog"/"hidden").
            if (! \in_array($product->get_catalog_visibility(), ['visible', 'search'], true)) {
                continue;
            }

            $items[] = $mapper->toApiData($product);
        }

        // A batch may contain only non-searchable products — nothing to send.
        if ($items !== []) {
            $result = $client->indexProductBatch($items);

            if ($result['ok']) {
                $count = (int) get_option('appinio_synced_count', 0);
                update_option('appinio_synced_count', $count + \count($items), false);
            } elseif (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(\sprintf(
                    '[AppIn Search] Bulk sync batch failed (page %d): HTTP %d — %s',
                    $page,
                    $result['status'],
                    $result['body']['error'] ?? $result['body']['message'] ?? 'unknown'
                ));
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

        foreach ($products as $productId) {
            $client->deleteProduct((string) $productId);
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
        update_option('appinio_last_sync', current_time('mysql'), false);
    }

    private function finishDelete(): void
    {
        update_option('appinio_bulk_sync_running', false, false);
        update_option('appinio_synced_count', 0, false);
    }
}
