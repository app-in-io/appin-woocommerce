<?php

declare(strict_types=1);

namespace Appinio;

use Appinio\Admin\SettingsPage;
use Appinio\Api\ConnectionSignal;
use Appinio\Frontend\SearchResults;
use Appinio\Frontend\SearchWidget;
use Appinio\Sync\BulkSync;
use Appinio\Sync\ProductSync;

if (! defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    private static ?self $instance = null;

    private string $file = '';

    private function __construct() {}

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    public function boot(string $file): void
    {
        $this->file = $file;

        $apiKey = get_option('appinio_api_key', '');

        (new SettingsPage)->register();
        (new SearchWidget)->register();

        // Registered before the no-key early return: its whole job is to fire when the
        // key is saved for the first time, i.e. exactly when $apiKey is still empty here.
        (new ConnectionSignal)->register();

        if ($apiKey === '') {
            return;
        }

        (new ProductSync)->register();
        (new BulkSync)->register();
        (new SearchResults)->register();
    }

    /**
     * Absolute path to the plugin's main file, recorded on boot(). Replaces the former
     * plugin-file constant so no global define is needed.
     */
    public function file(): string
    {
        return $this->file;
    }

    /**
     * The plugin's basename (e.g. `appinio-search/appinio-search.php`) — used to build
     * the `plugin_action_links_{$basename}` hook on the Plugins screen.
     */
    public function basename(): string
    {
        return plugin_basename($this->file);
    }
}
