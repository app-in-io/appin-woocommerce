<?php

declare(strict_types=1);

require_once \dirname(__DIR__) . '/vendor/autoload.php';

// Define WordPress constants used by the plugin
if (! defined('ABSPATH')) {
    \define('ABSPATH', '/tmp/wordpress/');
}

if (! defined('APPINIO_PLUGIN_FILE')) {
    \define('APPINIO_PLUGIN_FILE', \dirname(__DIR__) . '/appinio-search.php');
}

if (! defined('DAY_IN_SECONDS')) {
    \define('DAY_IN_SECONDS', 86400);
}

// Minimal WooCommerce type hierarchy so Mockery mocks satisfy the mapper's
// `WC_Product` type-hint and its `instanceof WC_Product_Variable` branch.
// Empty interfaces — Mockery still stubs any method dynamically.
if (! interface_exists('WC_Product')) {
    interface WC_Product {}
}

if (! interface_exists('WC_Product_Variable')) {
    interface WC_Product_Variable extends WC_Product {}
}

// Minimal WP_Query so Mockery mocks satisfy the SearchResults `\WP_Query` type-hint.
if (! class_exists('WP_Query')) {
    class WP_Query
    {
        /** @var list<int> */
        public array $posts = [];

        public function get(string $key, mixed $default = ''): mixed
        {
            return $default;
        }

        public function set(string $key, mixed $value): void {}

        public function is_main_query(): bool
        {
            return false;
        }

        public function is_search(): bool
        {
            return false;
        }
    }
}
