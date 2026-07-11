<?php

declare(strict_types=1);

namespace AppInIo\Tests;

use AppInIo\Plugin;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class PluginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Plugin is a singleton holding the plugin-file path across boots. Reset the shared
        // instance so each test starts from a clean slate (no state leaking between tests).
        $this->resetSingleton();

        // boot() reads the API key and short-circuits when it's empty (no real-time sync
        // registration), keeping this test focused on the file/basename wiring.
        Functions\when('get_option')->justReturn('');
    }

    protected function tearDown(): void
    {
        $this->resetSingleton();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function resetSingleton(): void
    {
        (new ReflectionClass(Plugin::class))->setStaticPropertyValue('instance', null);
    }

    public function test_boot_records_the_plugin_file(): void
    {
        // plugin_basename is invoked while SettingsPage registers during boot().
        Functions\when('plugin_basename')->returnArg();

        Plugin::instance()->boot('/wp-content/plugins/appinio-search/appinio-search.php');

        self::assertSame(
            '/wp-content/plugins/appinio-search/appinio-search.php',
            Plugin::instance()->file()
        );
    }

    public function test_basename_delegates_to_plugin_basename_of_the_recorded_file(): void
    {
        $received = null;
        Functions\when('plugin_basename')->alias(static function ($file) use (&$received): string {
            $received = $file;

            return 'appinio-search/appinio-search.php';
        });

        Plugin::instance()->boot('/wp-content/plugins/appinio-search/appinio-search.php');

        self::assertSame('appinio-search/appinio-search.php', Plugin::instance()->basename());
        self::assertSame('/wp-content/plugins/appinio-search/appinio-search.php', $received);
    }
}
