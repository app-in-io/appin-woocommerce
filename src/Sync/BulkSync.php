<?php

declare(strict_types=1);

namespace AppIn\WooCommerce\Sync;

use AppIn\WooCommerce\Api\Client;
use AppIn\WooCommerce\Mapper\ProductMapper;

final class BulkSync
{
    private const BATCH_SIZE = 20;

    public function register(): void
    {
        add_action('admin_post_appin_bulk_sync', [$this, 'handleBulkSync']);
        add_action('admin_post_appin_bulk_delete', [$this, 'handleBulkDelete']);
        add_action('appin_bulk_sync_batch', [$this, 'processBatch']);
        add_action('appin_bulk_delete_batch', [$this, 'processDeleteBatch']);
    }

    /**
     * Handle "Sync All Products" button click.
     */
    public function handleBulkSync(): void
    {
        check_admin_referer('appin_bulk_sync');

        if (! current_user_can('manage_woocommerce')) {
            wp_die(__('Unauthorized.', 'appin-search'));
        }

        update_option('appin_bulk_sync_running', true, false);
        update_option('appin_synced_count', 0, false);

        // Schedule first batch
        as_schedule_single_action(time(), 'appin_bulk_sync_batch', [1], 'appin-search');

        wp_safe_redirect(admin_url('admin.php?page=appin-search&syncing=1'));
        exit;
    }

    /**
     * Handle "Delete All from Index" button click.
     */
    public function handleBulkDelete(): void
    {
        check_admin_referer('appin_bulk_delete');

        if (! current_user_can('manage_woocommerce')) {
            wp_die(__('Unauthorized.', 'appin-search'));
        }

        update_option('appin_bulk_sync_running', true, false);

        as_schedule_single_action(time(), 'appin_bulk_delete_batch', [1], 'appin-search');

        wp_safe_redirect(admin_url('admin.php?page=appin-search&deleting=1'));
        exit;
    }

    /**
     * Process a batch of products. Schedules next batch if more remain.
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

        foreach ($products as $product) {
            $data = $mapper->toApiData($product);
            $result = $client->indexProduct($data);

            if ($result['ok']) {
                $count = (int) get_option('appin_synced_count', 0);
                update_option('appin_synced_count', $count + 1, false);
            } else {
                error_log(\sprintf(
                    '[AppIn Search] Bulk sync failed for product #%d: HTTP %d',
                    $product->get_id(),
                    $result['status']
                ));
            }
        }

        // Schedule next batch
        if (\count($products) === self::BATCH_SIZE) {
            as_schedule_single_action(time(), 'appin_bulk_sync_batch', [$page + 1], 'appin-search');
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
            as_schedule_single_action(time(), 'appin_bulk_delete_batch', [$page + 1], 'appin-search');
        } else {
            $this->finishDelete();
        }
    }

    private function finishSync(): void
    {
        update_option('appin_bulk_sync_running', false, false);
        update_option('appin_last_sync', current_time('mysql'), false);
    }

    private function finishDelete(): void
    {
        update_option('appin_bulk_sync_running', false, false);
        update_option('appin_synced_count', 0, false);
    }
}
