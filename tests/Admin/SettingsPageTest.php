<?php

declare(strict_types=1);

namespace AppInIo\Tests\Admin;

use AppInIo\Admin\Registration;
use AppInIo\Admin\SettingsPage;
use AppInIo\Sync\IndexState;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SettingsPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Escaping / i18n helpers: pass the text through so we can assert on it.
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_attr__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_url')->returnArg();
        Functions\when('__')->returnArg();
        // Field descriptions wrap the link markup in wp_kses_post — pass it through.
        Functions\when('wp_kses_post')->returnArg();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_api_key_field_links_to_dashboard(): void
    {
        Functions\when('get_option')->justReturn('');

        ob_start();
        (new SettingsPage)->renderApiKeyField();
        $output = ob_get_clean();

        self::assertStringContainsString('href="https://my.app-in.io"', $output);
        self::assertStringContainsString('AppIn dashboard', $output);
    }

    public function test_public_key_field_links_to_dashboard(): void
    {
        Functions\when('get_option')->justReturn('');

        ob_start();
        (new SettingsPage)->renderPublicKeyField();
        $output = ob_get_clean();

        self::assertStringContainsString('href="https://my.app-in.io"', $output);
    }

    public function test_registration_card_shown_when_no_api_key(): void
    {
        Functions\when('get_option')->justReturn('');
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_admin_page_title')->justReturn('AppIn Search');
        Functions\when('settings_fields')->justReturn(null);
        Functions\when('do_settings_sections')->justReturn(null);
        Functions\when('submit_button')->justReturn(null);

        // With no API key the sync section hands off to the self-serve registration card.
        $registration = new class extends Registration
        {
            public function renderCard(): void
            {
                echo '<!--registration-card-->';
            }
        };

        ob_start();
        (new SettingsPage(new IndexState, $registration))->render();
        $output = ob_get_clean();

        self::assertStringContainsString('registration-card', $output);
        self::assertStringNotContainsString('Sync Status', $output);
    }

    public function test_sync_status_shown_when_api_key_set(): void
    {
        Functions\when('get_option')->alias(fn ($key, $default = '') => match ($key) {
            'appinio_api_key' => 'sk_live_x',
            'appinio_bulk_sync_running' => false,
            'appinio_last_sync' => '',
            default => $default,
        });
        Functions\when('get_transient')->justReturn(5); // IndexState count (cache hit)
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_admin_page_title')->justReturn('AppIn Search');
        Functions\when('wp_count_posts')->justReturn((object) ['publish' => 10]);
        Functions\when('wp_nonce_url')->returnArg();
        Functions\when('admin_url')->returnArg();
        Functions\when('settings_fields')->justReturn(null);
        Functions\when('do_settings_sections')->justReturn(null);
        Functions\when('submit_button')->justReturn(null);

        ob_start();
        (new SettingsPage)->render();
        $output = ob_get_clean();

        self::assertStringContainsString('Sync Status', $output);
        self::assertStringContainsString('Sync in progress', $output); // sync label
        self::assertStringNotContainsString('Getting started', $output);
    }

    public function test_delete_operation_shows_delete_label(): void
    {
        Functions\when('get_option')->alias(fn ($key, $default = '') => match ($key) {
            'appinio_api_key' => 'sk_live_x',
            'appinio_bulk_sync_running' => true,
            'appinio_bulk_operation' => 'delete',
            default => $default,
        });
        Functions\when('get_transient')->justReturn(3);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_admin_page_title')->justReturn('AppIn Search');
        Functions\when('wp_count_posts')->justReturn((object) ['publish' => 10]);
        Functions\when('wp_nonce_url')->returnArg();
        Functions\when('admin_url')->returnArg();
        Functions\when('settings_fields')->justReturn(null);
        Functions\when('do_settings_sections')->justReturn(null);
        Functions\when('submit_button')->justReturn(null);

        ob_start();
        (new SettingsPage)->render();
        $output = ob_get_clean();

        self::assertStringContainsString('Delete in progress', $output);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function themeProvider(): array
    {
        return [
            'light stays light' => ['light', 'light'],
            'dark stays dark' => ['dark', 'dark'],
            'auto stays auto' => ['auto', 'auto'],
            'unknown falls back' => ['neon', 'light'],
            'empty falls back' => ['', 'light'],
            // WordPress hands the raw request value to sanitize callbacks — a non-string
            // must not fatal under strict_types, it must fall back to light.
            'array falls back' => [['dark'], 'light'],
            'null falls back' => [null, 'light'],
        ];
    }

    #[DataProvider('themeProvider')]
    public function test_sanitize_theme_restricts_to_known_values(mixed $input, string $expected): void
    {
        self::assertSame($expected, (new SettingsPage)->sanitizeTheme($input));
    }

    public function test_theme_field_renders_options_with_current_selected(): void
    {
        Functions\when('get_option')->justReturn('dark');
        Functions\when('selected')->alias(
            static fn ($value, $current, $echo = true): string => (string) $value === (string) $current ? 'selected' : ''
        );

        ob_start();
        (new SettingsPage)->renderThemeField();
        $output = ob_get_clean();

        self::assertStringContainsString('name="appinio_widget_theme"', $output);
        self::assertStringContainsString('value="light"', $output);
        self::assertStringContainsString('value="dark"', $output);
        self::assertStringContainsString('value="auto"', $output);
        // The stored value is preselected.
        self::assertStringContainsString('value="dark" selected', $output);
    }
}
