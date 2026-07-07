<?php

// Plugin constants defined at runtime in appin-search.php. Declared here so PHPStan
// resolves them when analysing src/ in isolation (without the plugin bootstrap).
\define('APPIN_API_URL', 'https://api.app-in.io/v1');
\define('APPIN_CDN_URL', 'https://cdn.app-in.io/v1/search.js');
\define('APPIN_VERSION', '0.0.0-dev');
\define('APPIN_PLUGIN_FILE', '');
\define('APPIN_PLUGIN_DIR', '');
\define('APPIN_PLUGIN_URL', '');
