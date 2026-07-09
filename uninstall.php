<?php

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$appinio_search_options = [
    'appinio_api_key',
    'appinio_auto_sync',
    'appinio_public_key',
    'appinio_search_selector',
    'appinio_results_page',
    'appinio_last_sync',
    'appinio_synced_count',
    'appinio_bulk_sync_running',
];

$appinio_search_hooks = [
    'appinio_sync_product',
    'appinio_delete_product',
    'appinio_bulk_sync_batch',
    'appinio_bulk_delete_batch',
];

// Options are stored per-site via update_option and Action Scheduler jobs live in
// per-site tables, so on multisite we must purge each blog individually.
$appinio_search_purge = static function () use ($appinio_search_options, $appinio_search_hooks): void {
    foreach ($appinio_search_options as $appinio_search_option) {
        delete_option($appinio_search_option);
    }

    if (\function_exists('as_unschedule_all_actions')) {
        foreach ($appinio_search_hooks as $appinio_search_hook) {
            as_unschedule_all_actions($appinio_search_hook, [], 'appinio-search');
        }
    }
};

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $appinio_search_site_id) {
        switch_to_blog((int) $appinio_search_site_id);
        $appinio_search_purge();
        restore_current_blog();
    }
} else {
    $appinio_search_purge();
}
