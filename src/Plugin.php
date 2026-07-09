<?php

declare(strict_types=1);

namespace AppInIo;

use AppInIo\Admin\SettingsPage;
use AppInIo\Frontend\SearchResults;
use AppInIo\Frontend\SearchWidget;
use AppInIo\Sync\BulkSync;
use AppInIo\Sync\ProductSync;

if (! defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    private static ?self $instance = null;

    private function __construct() {}

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    public function boot(): void
    {
        $apiKey = get_option('appinio_api_key', '');

        (new SettingsPage)->register();
        (new SearchWidget)->register();

        if ($apiKey === '') {
            return;
        }

        (new ProductSync)->register();
        (new BulkSync)->register();
        (new SearchResults)->register();
    }
}
