<?php

/**
 * Plugin Name: AppIn Search
 * Plugin URI:  https://app-in.io
 * Description: Sync WooCommerce products with AppIn AI Search. Real-time hooks + bulk sync.
 * Version:     1.0.0
 * Author:      AppIn
 * Author URI:  https://app-in.io
 * License:     GPL-2.0-or-later
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * WC tested up to: 10.6
 */

declare(strict_types=1);

if (! \defined('ABSPATH')) {
    exit;
}

if (! \defined('APPIN_API_URL')) {
    \define('APPIN_API_URL', 'https://api.app-in.io/v1');
}

\define('APPIN_PLUGIN_FILE', __FILE__);
\define('APPIN_PLUGIN_DIR', plugin_dir_path(__FILE__));
\define('APPIN_PLUGIN_URL', plugin_dir_url(__FILE__));
\define('APPIN_VERSION', '1.0.0');

require_once __DIR__ . '/autoload.php';

add_action('plugins_loaded', function (): void {
    if (! class_exists('WooCommerce')) {
        add_action('admin_notices', function (): void {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('AppIn Search requires WooCommerce to be installed and active.', 'appin-search');
            echo '</p></div>';
        });

        return;
    }

    AppIn\WooCommerce\Plugin::instance()->boot();
});
