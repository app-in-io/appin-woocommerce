<?php

declare(strict_types=1);

namespace AppIn\WooCommerce\Tests\Frontend;

use AppIn\WooCommerce\Frontend\SearchWidget;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class SearchWidgetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // renderElement() wraps the element in wp_kses — pass the markup through.
        Functions\when('wp_kses')->returnArg();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_register_hooks_actions(): void
    {
        $widget = new SearchWidget;
        $widget->register();

        self::assertNotFalse(has_action('wp_enqueue_scripts', [$widget, 'enqueueAssets']));
        self::assertNotFalse(has_action('wp_footer', [$widget, 'renderElement']));
    }

    public function test_enqueue_assets_when_public_key_set(): void
    {
        Functions\when('get_option')->alias(fn ($key, $default = '') => match ($key) {
            'appin_public_key' => 'pk_live_test123',
            default => $default,
        });

        Functions\expect('wp_enqueue_script')
            ->once()
            ->with(
                'appin-search-widget',
                APPIN_CDN_URL,
                [],
                null,
                ['strategy' => 'defer', 'in_footer' => true]
            );

        $widget = new SearchWidget;
        $widget->enqueueAssets();

        // Brain Monkey expectations are verified on tearDown
        self::assertTrue(true);
    }

    public function test_enqueue_assets_skipped_when_no_public_key(): void
    {
        Functions\when('get_option')->justReturn('');

        Functions\expect('wp_enqueue_script')->never();

        $widget = new SearchWidget;
        $widget->enqueueAssets();

        self::assertTrue(true);
    }

    public function test_render_element_with_public_key(): void
    {
        Functions\when('get_option')->alias(fn ($key, $default = '') => match ($key) {
            'appin_public_key' => 'pk_live_abc123',
            'appin_search_selector' => '',
            default => $default,
        });

        Functions\when('esc_attr')->returnArg();
        Functions\when('is_product_category')->justReturn(false);

        $widget = new SearchWidget;

        ob_start();
        $widget->renderElement();
        $output = ob_get_clean();

        self::assertStringContainsString('<semantic-search', $output);
        self::assertStringContainsString('api-key="pk_live_abc123"', $output);
        self::assertStringContainsString('platform="woocommerce"', $output);
        self::assertStringNotContainsString('category-id', $output);
        self::assertStringNotContainsString('input-selector', $output);
    }

    public function test_render_element_skipped_when_no_key(): void
    {
        Functions\when('get_option')->justReturn('');

        $widget = new SearchWidget;

        ob_start();
        $widget->renderElement();
        $output = ob_get_clean();

        self::assertEmpty($output);
    }

    public function test_render_element_with_category_page(): void
    {
        Functions\when('get_option')->alias(fn ($key, $default = '') => match ($key) {
            'appin_public_key' => 'pk_live_abc123',
            'appin_search_selector' => '',
            default => $default,
        });

        Functions\when('esc_attr')->returnArg();
        Functions\when('is_product_category')->justReturn(true);
        Functions\when('get_queried_object_id')->justReturn(42);

        $widget = new SearchWidget;

        ob_start();
        $widget->renderElement();
        $output = ob_get_clean();

        self::assertStringContainsString('category-id="42"', $output);
    }

    public function test_render_element_with_custom_selector(): void
    {
        Functions\when('get_option')->alias(fn ($key, $default = '') => match ($key) {
            'appin_public_key' => 'pk_live_abc123',
            'appin_search_selector' => '.my-search-input',
            default => $default,
        });

        Functions\when('esc_attr')->returnArg();
        Functions\when('is_product_category')->justReturn(false);

        $widget = new SearchWidget;

        ob_start();
        $widget->renderElement();
        $output = ob_get_clean();

        self::assertStringContainsString('input-selector=".my-search-input"', $output);
    }
}
