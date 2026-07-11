<?php

// PHPStan bootstrap for plugin-level constants defined at runtime in appinio-search.php,
// declared here so PHPStan resolves them when analysing src/ in isolation. The plugin
// currently defines no such constants — the file/basename now come from Plugin::file() /
// Plugin::basename(), and the API/CDN URLs resolve through WordPress filters. Kept as the
// declared bootstrap seam for any future runtime constant.
