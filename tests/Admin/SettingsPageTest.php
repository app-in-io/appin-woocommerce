<?php

declare(strict_types=1);

namespace Appinio\Tests\Admin;

use Appinio\Admin\Registration;
use Appinio\Admin\SettingsPage;
use Appinio\Sync\IndexState;
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
        // The drift warning uses _n() — return the matching form so sprintf can run.
        Functions\when('_n')->alias(static fn ($single, $plural, $number) => $number === 1 ? $single : $plural);
    }

    /**
     * Render the full settings page with a connected store, a given local "queued" count
     * and a given cached remote-counts snapshot (the value RemoteIndexState reads from its
     * transient). Returns the rendered HTML.
     *
     * @param  array<string, mixed>  $remote
     */
    private function renderDashboard(int $queued, array $remote): string
    {
        Functions\when('get_option')->alias(fn ($key, $default = '') => match ($key) {
            'appinio_api_key' => 'sk_live_x',
            'appinio_bulk_sync_running' => false,
            'appinio_last_sync' => '',
            default => $default,
        });
        // IndexState reads 'appinio_indexed_count'; RemoteIndexState reads 'appinio_remote_counts'.
        Functions\when('get_transient')->alias(fn ($key) => $key === 'appinio_remote_counts' ? $remote : $queued);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_admin_page_title')->justReturn('Appinio Search');
        Functions\when('wp_count_posts')->justReturn((object) ['publish' => 20]);
        Functions\when('wp_nonce_url')->returnArg();
        Functions\when('admin_url')->returnArg();
        Functions\when('settings_fields')->justReturn(null);
        Functions\when('do_settings_sections')->justReturn(null);
        Functions\when('submit_button')->justReturn(null);

        ob_start();
        (new SettingsPage)->render();

        return (string) ob_get_clean();
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
        self::assertStringContainsString('Appinio dashboard', $output);
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
        Functions\when('get_admin_page_title')->justReturn('Appinio Search');
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
        // IndexState count (cache hit = 5) + a cached remote snapshot so no HTTP is made.
        Functions\when('get_transient')->alias(fn ($key) => $key === 'appinio_remote_counts'
            ? ['available' => true, 'products' => 5, 'pending' => 0, 'status' => 'completed', 'last_indexed_at' => null]
            : 5);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_admin_page_title')->justReturn('Appinio Search');
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
        // IndexState count (cache hit = 3) + a cached remote snapshot so no HTTP is made.
        Functions\when('get_transient')->alias(fn ($key) => $key === 'appinio_remote_counts'
            ? ['available' => true, 'products' => 3, 'pending' => 0, 'status' => 'completed', 'last_indexed_at' => null]
            : 3);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_admin_page_title')->justReturn('Appinio Search');
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

    public function test_indexed_in_search_row_shows_backend_count_and_hides_drift_when_converged(): void
    {
        $output = $this->renderDashboard(10, [
            'available' => true, 'products' => 10, 'pending' => 0, 'status' => 'completed', 'last_indexed_at' => null,
        ]);

        self::assertStringContainsString('Indexed in search', $output);
        self::assertStringContainsString('<strong class="appinio-indexed-count">10</strong>', $output);
        // indexed == queued and status completed → drift badge present but hidden.
        self::assertStringContainsString('appinio-index-drift" style="color:#b32d2e;display:none"', $output);
    }

    public function test_indexed_row_shows_em_dash_when_backend_unavailable(): void
    {
        $output = $this->renderDashboard(10, ['available' => false]);

        self::assertStringContainsString('<strong class="appinio-indexed-count">—</strong>', $output);
        // Unavailable → we can't tell, so no drift warning.
        self::assertStringContainsString('appinio-index-drift" style="color:#b32d2e;display:none"', $output);
    }

    public function test_drift_badge_visible_when_indexed_below_queued_and_settled(): void
    {
        $output = $this->renderDashboard(10, [
            'available' => true, 'products' => 4, 'pending' => 0, 'status' => 'completed', 'last_indexed_at' => null,
        ]);

        // Fewer indexed than queued, queue drained → the badge is shown with a counting message.
        self::assertStringContainsString('appinio-index-drift" style="color:#b32d2e;">', $output);
        self::assertStringNotContainsString('appinio-index-drift" style="color:#b32d2e;display:none"', $output);
        self::assertStringContainsString('4 of 10 products are in the search index', $output);
    }

    public function test_no_drift_badge_while_indexing_is_in_flight(): void
    {
        $output = $this->renderDashboard(10, [
            'available' => true, 'products' => 4, 'pending' => 6, 'status' => 'running', 'last_indexed_at' => null,
        ]);

        // pending > 0 → the lag is expected, so the badge must stay hidden (no false alarm).
        self::assertStringContainsString('appinio-index-drift" style="color:#b32d2e;display:none"', $output);
    }

    public function test_drift_badge_shows_non_counting_copy_when_last_index_failed(): void
    {
        $output = $this->renderDashboard(10, [
            'available' => true, 'products' => 10, 'pending' => 0, 'status' => 'failed', 'last_indexed_at' => null,
        ]);

        // Counts match but the last run failed → badge shown with the plain failure copy,
        // NOT the self-contradictory "10 of 10 … haven't indexed yet" counting message.
        self::assertStringContainsString('appinio-index-drift" style="color:#b32d2e;">', $output);
        self::assertStringContainsString('The last indexing run reported a failure', $output);
        self::assertStringNotContainsString('10 of 10 products are in the search index', $output);
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

    public function test_action_links_prepend_settings_shortcut(): void
    {
        Functions\when('admin_url')->alias(
            static fn ($path = '') => 'https://shop.test/wp-admin/' . ltrim((string) $path, '/')
        );

        $links = (new SettingsPage)->addActionLinks(['deactivate' => '<a>Deactivate</a>']);

        // Settings is prepended (index 0) with a link to the plugin's settings page,
        // and the existing action links are preserved.
        self::assertStringContainsString('page=appinio-search', $links[0]);
        self::assertStringContainsString('Settings', $links[0]);
        self::assertSame('<a>Deactivate</a>', $links['deactivate']);
    }

    public function test_enqueue_scripts_rides_the_heartbeat_api_on_the_settings_screen(): void
    {
        $handle = null;
        $script = null;
        Functions\when('wp_enqueue_script')->justReturn(true);
        Functions\expect('wp_add_inline_script')->once()->andReturnUsing(
            function ($h, $s) use (&$handle, &$script): bool {
                $handle = $h;
                $script = $s;

                return true;
            }
        );

        (new SettingsPage)->enqueueScripts('woocommerce_page_appinio-search');

        // Must attach to the heartbeat script (runs after wp.heartbeat, DOM-safe), NOT jquery-core
        // in the header where the sync section does not exist yet.
        self::assertSame('heartbeat', $handle);
        self::assertStringContainsString('heartbeat-tick', $script);
        self::assertStringContainsString('heartbeat-send', $script);
        self::assertStringNotContainsString('setInterval', $script);
    }

    public function test_enqueue_scripts_is_a_noop_off_the_settings_screen(): void
    {
        $called = false;
        Functions\when('wp_enqueue_script')->alias(function () use (&$called) {
            $called = true;

            return true;
        });
        Functions\when('wp_add_inline_script')->alias(function () use (&$called) {
            $called = true;

            return true;
        });

        (new SettingsPage)->enqueueScripts('edit.php');

        self::assertFalse($called, 'enqueueScripts must not enqueue anything off the settings screen');
    }
}
