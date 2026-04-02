<?php

declare(strict_types=1);

namespace AppIn\WooCommerce;

use AppIn\WooCommerce\Admin\SettingsPage;
use AppIn\WooCommerce\Sync\BulkSync;
use AppIn\WooCommerce\Sync\ProductSync;

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
        $apiKey = get_option('appin_api_key', '');

        (new SettingsPage)->register();

        if ($apiKey === '') {
            return;
        }

        (new ProductSync)->register();
        (new BulkSync)->register();
    }
}
