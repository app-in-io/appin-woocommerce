<?php

declare(strict_types=1);

namespace Appinio\Tests\Frontend;

use Appinio\Frontend\SearchWidget;
use Appinio\I18n\LanguageResolver;
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

        // show-all-url is built from get_search_link() (permalink-aware) + esc_url().
        // Stub the search URL so the {query} placeholder round-trips through str_replace.
        Functions\when('esc_url')->returnArg();
        Functions\when('get_search_link')->alias(static fn ($query) => 'https://store.test/?s=' . $query);

        // Auto theme prints an inline luminance script via wp_print_inline_script_tag.
        Functions\when('wp_print_inline_script_tag')->alias(static function ($javascript): void {
            echo $javascript;
        });

        // enqueueAssets() resolves the CDN URL through the `appinio_cdn_url` filter.
        // Default: pass the value through unchanged (no override registered).
        Functions\when('apply_filters')->returnArg(2);
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
            'appinio_public_key' => 'pk_live_test123',
            default => $default,
        });

        Functions\expect('wp_enqueue_script')
            ->once()
            ->with(
                'appinio-search-widget',
                'https://cdn.app-in.io/v1/search.js',
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
            'appinio_public_key' => 'pk_live_abc123',
            'appinio_search_selector' => '',
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

    public function test_render_element_includes_lang_on_multilingual_store(): void
    {
        Functions\when('get_option')->alias(fn ($key, $default = '') => match ($key) {
            'appinio_public_key' => 'pk_live_abc123',
            'appinio_search_selector' => '',
            default => $default,
        });

        Functions\when('esc_attr')->returnArg();
        Functions\when('is_product_category')->justReturn(false);

        $widget = new SearchWidget(new class extends LanguageResolver
        {
            public function currentLanguage(): ?string
            {
                return 'de';
            }
        });

        ob_start();
        $widget->renderElement();
        $output = ob_get_clean();

        self::assertStringContainsString('lang="de"', $output);
    }

    public function test_render_element_omits_lang_on_single_language_store(): void
    {
        Functions\when('get_option')->alias(fn ($key, $default = '') => match ($key) {
            'appinio_public_key' => 'pk_live_abc123',
            'appinio_search_selector' => '',
            default => $default,
        });

        Functions\when('esc_attr')->returnArg();
        Functions\when('is_product_category')->justReturn(false);

        $widget = new SearchWidget;

        ob_start();
        $widget->renderElement();
        $output = ob_get_clean();

        self::assertStringNotContainsString('lang=', $output);
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
            'appinio_public_key' => 'pk_live_abc123',
            'appinio_search_selector' => '',
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
            'appinio_public_key' => 'pk_live_abc123',
            'appinio_search_selector' => '.my-search-input',
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

    public function test_render_element_passes_canonical_show_all_url(): void
    {
        Functions\when('get_option')->alias(fn ($key, $default = '') => match ($key) {
            'appinio_public_key' => 'pk_live_abc123',
            'appinio_search_selector' => '',
            default => $default,
        });

        Functions\when('esc_attr')->returnArg();
        Functions\when('is_product_category')->justReturn(false);

        $widget = new SearchWidget;

        ob_start();
        $widget->renderElement();
        $output = ob_get_clean();

        // Permalink-aware URL from get_search_link(), with the widget's {query} placeholder
        // preserved (esc_url must not have encoded the braces).
        self::assertStringContainsString('show-all-url="https://store.test/?s={query}"', $output);
    }

    public function test_render_element_show_all_url_falls_back_when_token_mangled(): void
    {
        Functions\when('get_option')->alias(fn ($key, $default = '') => match ($key) {
            'appinio_public_key' => 'pk_live_abc123',
            'appinio_search_selector' => '',
            default => $default,
        });

        Functions\when('esc_attr')->returnArg();
        Functions\when('is_product_category')->justReturn(false);
        // A filter slug-sanitized the token out of the URL — it can no longer be replaced.
        Functions\when('get_search_link')->justReturn('https://store.test/search/appinio-q/');
        Functions\when('home_url')->alias(static fn ($path = '') => 'https://store.test/' . ltrim((string) $path, '/'));

        $widget = new SearchWidget;

        ob_start();
        $widget->renderElement();
        $output = ob_get_clean();

        // Falls back to the always-valid plain ?s= form so {query} is never lost.
        self::assertStringContainsString('show-all-url="https://store.test/?s={query}"', $output);
    }

    public function test_render_element_dark_theme_sets_attribute(): void
    {
        Functions\when('get_option')->alias(fn ($key, $default = '') => match ($key) {
            'appinio_public_key' => 'pk_live_abc123',
            'appinio_search_selector' => '',
            'appinio_widget_theme' => 'dark',
            default => $default,
        });

        Functions\when('esc_attr')->returnArg();
        Functions\when('is_product_category')->justReturn(false);

        $widget = new SearchWidget;

        ob_start();
        $widget->renderElement();
        $output = ob_get_clean();

        self::assertStringContainsString('theme="dark"', $output);
        self::assertStringNotContainsString("setAttribute('theme'", $output);
    }

    public function test_render_element_light_theme_omits_attribute_and_script(): void
    {
        // No stored theme → defaults to light → no theme attribute, no inline script.
        Functions\when('get_option')->alias(fn ($key, $default = '') => match ($key) {
            'appinio_public_key' => 'pk_live_abc123',
            'appinio_search_selector' => '',
            default => $default,
        });

        Functions\when('esc_attr')->returnArg();
        Functions\when('is_product_category')->justReturn(false);

        $widget = new SearchWidget;

        ob_start();
        $widget->renderElement();
        $output = ob_get_clean();

        self::assertStringNotContainsString('theme=', $output);
        self::assertStringNotContainsString("setAttribute('theme'", $output);
    }

    public function test_render_element_auto_theme_prints_luminance_script(): void
    {
        Functions\when('get_option')->alias(fn ($key, $default = '') => match ($key) {
            'appinio_public_key' => 'pk_live_abc123',
            'appinio_search_selector' => '',
            'appinio_widget_theme' => 'auto',
            default => $default,
        });

        Functions\when('esc_attr')->returnArg();
        Functions\when('is_product_category')->justReturn(false);

        $widget = new SearchWidget;

        ob_start();
        $widget->renderElement();
        $output = ob_get_clean();

        // Auto resolves client-side: no server-rendered theme attribute, but the
        // luminance script that flips the element to dark is emitted.
        self::assertStringNotContainsString('theme="dark"', $output);
        self::assertStringContainsString("setAttribute('theme', 'dark')", $output);
    }

    public function test_cdn_url_returns_hardcoded_default_with_filter_passthrough(): void
    {
        // The production default is baked into SearchWidget::DEFAULT_CDN_URL; the filter
        // passes it through unchanged → the canonical prod script URL.
        self::assertSame('https://cdn.app-in.io/v1/search.js', SearchWidget::cdnUrl());
    }

    public function test_cdn_url_filter_override_wins_over_default(): void
    {
        // The dev harness registers `appinio_cdn_url` to target the local Vite dev server.
        // The filter receives the hardcoded default and its return value takes precedence.
        Functions\when('apply_filters')->alias(function (string $hook, string $value): string {
            self::assertSame('appinio_cdn_url', $hook);
            self::assertSame('https://cdn.app-in.io/v1/search.js', $value);

            return 'http://localhost:5174/src/search/loader.ts';
        });

        self::assertSame('http://localhost:5174/src/search/loader.ts', SearchWidget::cdnUrl());
    }
}
