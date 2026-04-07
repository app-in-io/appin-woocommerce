<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Define WordPress constants used by the plugin
if (! defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (! defined('APPIN_API_URL')) {
    define('APPIN_API_URL', 'https://api.app-in.io/v1');
}

if (! defined('APPIN_CDN_URL')) {
    define('APPIN_CDN_URL', 'https://cdn.app-in.io/v1/search.js');
}

if (! defined('APPIN_PLUGIN_FILE')) {
    define('APPIN_PLUGIN_FILE', dirname(__DIR__) . '/appin-search.php');
}

if (! defined('APPIN_PLUGIN_DIR')) {
    define('APPIN_PLUGIN_DIR', dirname(__DIR__) . '/');
}

if (! defined('APPIN_PLUGIN_URL')) {
    define('APPIN_PLUGIN_URL', 'https://example.com/wp-content/plugins/appin-search/');
}

if (! defined('APPIN_VERSION')) {
    define('APPIN_VERSION', '1.0.0-test');
}
