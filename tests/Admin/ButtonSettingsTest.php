<?php

declare(strict_types=1);

namespace Appinio\Tests\Admin;

use Appinio\Admin\ButtonSettings;
use Appinio\Frontend\SearchButton;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class ButtonSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_register_hooks_settings_and_assets(): void
    {
        $settings = new ButtonSettings;
        $settings->register();

        self::assertNotFalse(has_action('admin_init', [$settings, 'registerSettings']));
        self::assertNotFalse(has_action('admin_enqueue_scripts', [$settings, 'enqueueScripts']));
    }

    public function test_register_settings_adds_the_four_options_to_the_settings_group(): void
    {
        $registered = [];
        Functions\when('register_setting')->alias(static function ($group, $option) use (&$registered): void {
            $registered[$option] = $group;
        });
        Functions\when('add_settings_section')->justReturn(null);
        Functions\when('add_settings_field')->justReturn(null);
        Functions\when('__')->returnArg();

        (new ButtonSettings)->registerSettings();

        self::assertSame(
            array_fill_keys(SearchButton::OPTIONS, 'appinio_search'),
            $registered
        );
    }

    public function test_sanitize_color_keeps_a_hex_colour(): void
    {
        Functions\when('sanitize_hex_color')->alias(
            static fn ($color) => preg_match('/^#[0-9a-f]{3,6}$/i', $color) ? $color : null
        );

        $settings = new ButtonSettings;

        self::assertSame('#7c3aed', $settings->sanitizeColor('#7c3aed'));
        self::assertSame('', $settings->sanitizeColor('red;}body{'));
        self::assertSame('', $settings->sanitizeColor(''));
        self::assertSame('', $settings->sanitizeColor(['#7c3aed']));
    }

    public function test_sanitize_icon_accepts_only_an_image_attachment(): void
    {
        Functions\when('absint')->alias(static fn ($value) => abs((int) $value));
        Functions\when('wp_attachment_is_image')->alias(static fn ($id) => $id === 12);

        $settings = new ButtonSettings;

        self::assertSame(12, $settings->sanitizeIcon('12'));
        self::assertSame(0, $settings->sanitizeIcon('13'));
        self::assertSame(0, $settings->sanitizeIcon(''));
    }

    public function test_enqueue_scripts_loads_the_picker_and_media_on_the_settings_screen(): void
    {
        Functions\when('plugins_url')->alias(static fn ($path) => 'https://store.test/wp-content/plugins/appinio-search/' . $path);
        Functions\when('__')->returnArg();
        Functions\expect('wp_enqueue_style')->once()->with('wp-color-picker');
        Functions\expect('wp_enqueue_media')->once();
        Functions\expect('wp_enqueue_script')
            ->once()
            ->with(
                'appinio-search-button-settings',
                'https://store.test/wp-content/plugins/appinio-search/assets/js/button-settings.js',
                ['jquery', 'wp-color-picker'],
                \Mockery::type('string'),
                true
            );
        Functions\expect('wp_localize_script')->once();

        (new ButtonSettings('/plugin/appinio-search.php'))->enqueueScripts('woocommerce_page_appinio-search');

        self::assertTrue(true);
    }

    public function test_enqueue_scripts_is_a_noop_off_the_settings_screen(): void
    {
        Functions\expect('wp_enqueue_media')->never();
        Functions\expect('wp_enqueue_script')->never();

        (new ButtonSettings)->enqueueScripts('edit.php');

        self::assertTrue(true);
    }

    public function test_uninstall_deletes_every_button_option(): void
    {
        $uninstall = (string) file_get_contents(\dirname(__DIR__, 2) . '/uninstall.php');

        foreach (SearchButton::OPTIONS as $option) {
            self::assertStringContainsString("'{$option}'", $uninstall, "{$option} would survive an uninstall.");
        }
    }
}
