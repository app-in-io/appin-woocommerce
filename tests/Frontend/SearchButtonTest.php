<?php

declare(strict_types=1);

namespace Appinio\Tests\Frontend;

use Appinio\Frontend\SearchButton;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class SearchButtonTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('wp_kses')->returnArg();
        Functions\when('esc_attr')->alias(static fn ($text) => htmlspecialchars((string) $text, ENT_QUOTES));
        Functions\when('sanitize_text_field')->alias(static fn ($text) => trim(strip_tags((string) $text)));

        // Once a test mocks pll__, function_exists('pll__') stays true for the whole run;
        // default it to "Polylang returns the string unchanged".
        Functions\when('pll__')->returnArg();

        // WordPress shortcode_atts(): keep only the known keys, defaults for missing ones.
        Functions\when('shortcode_atts')->alias(static function (array $pairs, $atts): array {
            $atts = (array) $atts;
            $out = [];

            foreach ($pairs as $name => $default) {
                $out[$name] = \array_key_exists($name, $atts) ? $atts[$name] : $default;
            }

            return $out;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $options  Extra options (button settings).
     */
    private function withPublicKey(string $key, array $options = []): void
    {
        Functions\when('get_option')->alias(static fn ($option, $default = '') => match (true) {
            $option === 'appinio_public_key' => $key,
            \array_key_exists($option, $options) => $options[$option],
            default => $default,
        });
    }

    public function test_admin_label_is_used_when_the_button_has_none(): void
    {
        $this->withPublicKey('pk_live_abc123', ['appinio_button_label' => 'Zoek met AI']);

        self::assertStringContainsString('label="Zoek met AI"', (new SearchButton)->shortcode(''));
    }

    public function test_own_label_wins_over_the_admin_label(): void
    {
        $this->withPublicKey('pk_live_abc123', ['appinio_button_label' => 'Zoek met AI']);

        $output = (new SearchButton)->shortcode(['label' => 'Zoek']);

        self::assertStringContainsString('label="Zoek"', $output);
        self::assertStringNotContainsString('Zoek met AI', $output);
    }

    public function test_admin_label_goes_through_polylang(): void
    {
        $this->withPublicKey('pk_live_abc123', ['appinio_button_label' => 'Search with AI']);
        Functions\when('pll__')->alias(static fn ($string) => $string === 'Search with AI' ? 'Zoek met AI' : $string);

        self::assertStringContainsString('label="Zoek met AI"', (new SearchButton)->shortcode(''));
    }

    public function test_polylang_registration_of_the_admin_label(): void
    {
        $this->withPublicKey('pk_live_abc123', ['appinio_button_label' => 'Search with AI']);

        Functions\expect('pll_register_string')
            ->once()
            ->with('appinio_button_label', 'Search with AI', 'Appinio Search');

        SearchButton::registerPolylangStrings();

        self::assertTrue(true);
    }

    public function test_polylang_registration_skips_an_empty_label(): void
    {
        $this->withPublicKey('pk_live_abc123');

        Functions\when('pll_register_string')->alias(static function (): void {
            self::fail('An empty label must not be registered.');
        });

        SearchButton::registerPolylangStrings();

        self::assertTrue(true);
    }

    public function test_admin_icon_is_slotted_into_the_button(): void
    {
        $this->withPublicKey('pk_live_abc123', ['appinio_button_icon' => 12]);
        Functions\when('esc_url')->returnArg();
        Functions\when('wp_get_attachment_image_url')->alias(
            static fn ($id, $size) => $id === 12 && $size === 'thumbnail' ? 'https://store.test/icon.png' : false
        );

        self::assertSame(
            '<semantic-search-button><img slot="icon" src="https://store.test/icon.png" alt="" width="16" height="16"></semantic-search-button>',
            (new SearchButton)->shortcode('')
        );
    }

    public function test_deleted_icon_attachment_falls_back_to_the_sparkle(): void
    {
        $this->withPublicKey('pk_live_abc123', ['appinio_button_icon' => 12]);
        Functions\when('wp_get_attachment_image_url')->justReturn(false);

        self::assertSame('<semantic-search-button></semantic-search-button>', (new SearchButton)->shortcode(''));
    }

    public function test_colours_are_printed_once_as_custom_properties(): void
    {
        $this->withPublicKey('pk_live_abc123', [
            'appinio_button_color' => '#7c3aed',
            'appinio_button_text_color' => '#111111',
        ]);
        Functions\when('sanitize_hex_color')->returnArg();

        Functions\expect('wp_register_style')->once()->with('appinio-search-button', false);
        Functions\expect('wp_enqueue_style')->once()->with('appinio-search-button');
        Functions\expect('wp_add_inline_style')
            ->once()
            ->with('appinio-search-button', 'semantic-search-button{--appin-primary:#7c3aed;--appin-on-primary:#111111}');

        (new SearchButton)->enqueueStyle();

        self::assertTrue(true);
    }

    public function test_button_colour_alone_sets_only_its_property(): void
    {
        $this->withPublicKey('pk_live_abc123', ['appinio_button_color' => '#7c3aed']);
        Functions\when('sanitize_hex_color')->returnArg();
        Functions\when('wp_register_style')->justReturn(true);
        Functions\when('wp_enqueue_style')->justReturn(null);

        Functions\expect('wp_add_inline_style')
            ->once()
            ->with('appinio-search-button', 'semantic-search-button{--appin-primary:#7c3aed}');

        (new SearchButton)->enqueueStyle();

        self::assertTrue(true);
    }

    public function test_invalid_stored_colour_is_dropped_on_output(): void
    {
        $this->withPublicKey('pk_live_abc123', ['appinio_button_color' => 'red;}body{display:none']);
        Functions\when('sanitize_hex_color')->justReturn(null);

        Functions\expect('wp_add_inline_style')->never();

        (new SearchButton)->enqueueStyle();

        self::assertTrue(true);
    }

    public function test_text_colour_alone_sets_only_its_property(): void
    {
        $this->withPublicKey('pk_live_abc123', ['appinio_button_text_color' => '#111111']);
        Functions\when('sanitize_hex_color')->returnArg();
        Functions\when('wp_register_style')->justReturn(true);
        Functions\when('wp_enqueue_style')->justReturn(null);

        Functions\expect('wp_add_inline_style')
            ->once()
            ->with('appinio-search-button', 'semantic-search-button{--appin-on-primary:#111111}');

        (new SearchButton)->enqueueStyle();

        self::assertTrue(true);
    }

    public function test_no_colours_print_no_style(): void
    {
        $this->withPublicKey('pk_live_abc123');
        Functions\when('sanitize_hex_color')->returnArg();

        Functions\expect('wp_register_style')->never();
        Functions\expect('wp_add_inline_style')->never();

        (new SearchButton)->enqueueStyle();

        self::assertTrue(true);
    }

    public function test_no_public_key_prints_no_style(): void
    {
        $this->withPublicKey('', ['appinio_button_color' => '#7c3aed']);

        Functions\expect('wp_add_inline_style')->never();

        (new SearchButton)->enqueueStyle();

        self::assertTrue(true);
    }

    public function test_register_adds_the_shortcode_and_the_block_hooks(): void
    {
        $button = new SearchButton;

        Functions\expect('add_shortcode')
            ->once()
            ->with('appinio_search_button', [$button, 'shortcode']);

        $button->register();

        self::assertNotFalse(has_action('init', [$button, 'registerBlock']));
        self::assertNotFalse(has_action('init', [SearchButton::class, 'registerPolylangStrings']));
        self::assertNotFalse(has_action('wp_enqueue_scripts', [$button, 'enqueueStyle']));
        self::assertNotFalse(has_action('enqueue_block_editor_assets', [$button, 'editorSettings']));
    }

    public function test_register_block_uses_block_json_and_the_render_callback(): void
    {
        $button = new SearchButton('/plugin/appinio-search.php');

        Functions\when('plugins_url')->alias(static fn ($path, $file) => 'https://store.test/wp-content/plugins/appinio-search/' . $path);
        Functions\expect('wp_register_script')
            ->once()
            ->with(
                'appinio-search-button-editor',
                'https://store.test/wp-content/plugins/appinio-search/blocks/search-button/index.js',
                ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n'],
                \Mockery::type('string'),
                true
            );
        Functions\expect('wp_set_script_translations')
            ->once()
            ->with('appinio-search-button-editor', 'appinio-search');
        Functions\expect('register_block_type')
            ->once()
            ->with('/plugin/blocks/search-button', ['render_callback' => [$button, 'renderBlock']]);

        $button->registerBlock();

        self::assertTrue(true);
    }

    public function test_render_block_wraps_the_same_button_markup(): void
    {
        $this->withPublicKey('pk_live_abc123');
        Functions\when('get_block_wrapper_attributes')->justReturn('class="wp-block-appinio-search-search-button"');

        $button = new SearchButton;

        self::assertSame(
            '<div class="wp-block-appinio-search-search-button">'
                . $button->shortcode(['label' => 'Zoek', 'variant' => 'icon'])
                . '</div>',
            $button->renderBlock(['label' => 'Zoek', 'variant' => 'icon'])
        );
    }

    public function test_render_block_renders_nothing_without_public_key(): void
    {
        $this->withPublicKey('');

        self::assertSame('', (new SearchButton)->renderBlock(['label' => 'Zoek']));
    }

    public function test_editor_settings_carry_the_key_state_and_the_admin_settings(): void
    {
        $this->withPublicKey('', [
            'appinio_button_label' => 'Zoek met AI',
            'appinio_button_color' => '#7c3aed',
            'appinio_button_text_color' => '#111111',
            'appinio_button_icon' => 12,
        ]);
        Functions\when('admin_url')->alias(static fn ($path) => 'https://store.test/wp-admin/' . $path);
        Functions\when('wp_json_encode')->alias(static fn ($data, $flags = 0) => json_encode($data, $flags | JSON_UNESCAPED_SLASHES));
        Functions\when('sanitize_hex_color')->returnArg();
        Functions\when('wp_get_attachment_image_url')->justReturn('https://store.test/icon.png');

        Functions\expect('wp_add_inline_script')
            ->once()
            ->with(
                'appinio-search-button-editor',
                \Mockery::on(static fn (string $js) => str_contains($js, 'window.appinioSearchButton =')
                    && str_contains($js, '"hasKey":false')
                    && str_contains($js, 'admin.php?page=appinio-search')
                    && str_contains($js, '"label":"Zoek met AI"')
                    && str_contains($js, '"color":"#7c3aed"')
                    && str_contains($js, '"textColor":"#111111"')
                    && str_contains($js, '"iconUrl":"https://store.test/icon.png"')),
                'before'
            );

        (new SearchButton)->editorSettings();

        self::assertTrue(true);
    }

    public function test_shortcode_without_attributes_renders_a_bare_button(): void
    {
        $this->withPublicKey('pk_live_abc123');

        // WordPress passes an empty string when the shortcode has no attributes.
        $output = (new SearchButton)->shortcode('');

        self::assertSame('<semantic-search-button></semantic-search-button>', $output);
    }

    public function test_shortcode_passes_label_and_variant(): void
    {
        $this->withPublicKey('pk_live_abc123');

        $output = (new SearchButton)->shortcode(['label' => 'Zoek', 'variant' => 'icon']);

        self::assertSame('<semantic-search-button label="Zoek" variant="icon"></semantic-search-button>', $output);
    }

    public function test_label_is_sanitized_and_escaped(): void
    {
        $this->withPublicKey('pk_live_abc123');

        $output = (new SearchButton)->shortcode(['label' => 'Zoek "AI" <script>x</script>']);

        self::assertStringContainsString('label="Zoek &quot;AI&quot; x"', $output);
        self::assertStringNotContainsString('<script>', $output);
    }

    public function test_unknown_variant_is_dropped(): void
    {
        $this->withPublicKey('pk_live_abc123');

        $output = (new SearchButton)->shortcode(['variant' => 'foo']);

        self::assertSame('<semantic-search-button></semantic-search-button>', $output);
    }

    public function test_icon_text_variant_is_kept(): void
    {
        $this->withPublicKey('pk_live_abc123');

        $output = (new SearchButton)->shortcode(['variant' => 'icon-text']);

        self::assertStringContainsString('variant="icon-text"', $output);
    }

    public function test_shortcode_renders_nothing_without_public_key(): void
    {
        $this->withPublicKey('');

        self::assertSame('', (new SearchButton)->shortcode(['label' => 'Zoek']));
    }
}
