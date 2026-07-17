<?php

/**
 * Plugin Name:       Appinio Search – AI Semantic & Multilingual Product Search for WooCommerce
 * Plugin URI:        https://app-in.io/woocommerce
 * Description:       Sync WooCommerce products with Appinio AI Search. Real-time hooks + bulk sync.
 * Version:           0.9.0
 * Author:            appinio
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       appinio-search
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * WC requires at least: 8.0
 * WC tested up to:   10.6
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/autoload.php';

add_action('before_woocommerce_init', function (): void {
    if (class_exists(Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true,
        );
    }
});

add_action('plugins_loaded', function (): void {
    if (! class_exists('WooCommerce')) {
        add_action('admin_notices', function (): void {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('Appinio Search requires WooCommerce to be installed and active.', 'appinio-search');
            echo '</p></div>';
        });

        return;
    }

    Appinio\Plugin::instance()->boot(__FILE__);
});
