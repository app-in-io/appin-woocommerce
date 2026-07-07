<?php

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$appin_search_options = [
    'appin_api_key',
    'appin_auto_sync',
    'appin_public_key',
    'appin_search_selector',
    'appin_last_sync',
    'appin_synced_count',
    'appin_bulk_sync_running',
];

// Options are stored per-site via update_option and Action Scheduler jobs live in
// per-site tables, so on multisite we must purge each blog individually.
$appin_search_purge = static function () use ($appin_search_options): void {
    foreach ($appin_search_options as $appin_search_option) {
        delete_option($appin_search_option);
    }

    if (\function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('appin_sync_product', [], 'appin-search');
        as_unschedule_all_actions('appin_delete_product', [], 'appin-search');
        as_unschedule_all_actions('appin_bulk_sync_batch', [], 'appin-search');
        as_unschedule_all_actions('appin_bulk_delete_batch', [], 'appin-search');
    }
};

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $appin_search_site_id) {
        switch_to_blog((int) $appin_search_site_id);
        $appin_search_purge();
        restore_current_blog();
    }
} else {
    $appin_search_purge();
}
