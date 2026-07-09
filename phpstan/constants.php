<?php

// Plugin constants defined at runtime in appinio-search.php. Declared here so PHPStan
// resolves them when analysing src/ in isolation (without the plugin bootstrap).
\define('APPINIO_API_URL', 'https://api.app-in.io/v1');
\define('APPINIO_CDN_URL', 'https://cdn.app-in.io/v1/search.js');
\define('APPINIO_VERSION', '0.0.0-dev');
\define('APPINIO_PLUGIN_FILE', '');
\define('APPINIO_PLUGIN_DIR', '');
\define('APPINIO_PLUGIN_URL', '');
