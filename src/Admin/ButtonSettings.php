<?php

declare(strict_types=1);

namespace Appinio\Admin;

use Appinio\Frontend\SearchButton;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * "AI Search Button" section on the plugin settings page: label, colours and icon for
 * every `<semantic-search-button>` the store places (block or shortcode). Saved with the
 * rest of the page (option group `appinio_search`); SearchButton reads the options.
 */
final class ButtonSettings
{
    private const PAGE = 'appinio-search';

    private const GROUP = 'appinio_search';

    private const SECTION = 'appinio_button';

    private const SCREEN = 'woocommerce_page_appinio-search';

    private const SCRIPT = 'appinio-search-button-settings';

    /** The widget's defaults, shown by the colour picker's "Default" button. */
    private const DEFAULT_COLOR = '#37b7ff';

    private const DEFAULT_TEXT_COLOR = '#ffffff';

    /**
     * @param  string  $pluginFile  The plugin's main file; the settings script lives in assets/js/.
     */
    public function __construct(
        private string $pluginFile = '',
    ) {}

    public function register(): void
    {
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueScripts']);
    }

    public function registerSettings(): void
    {
        register_setting(self::GROUP, SearchButton::OPTION_LABEL, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);

        register_setting(self::GROUP, SearchButton::OPTION_COLOR, [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitizeColor'],
            'default' => '',
        ]);

        register_setting(self::GROUP, SearchButton::OPTION_TEXT_COLOR, [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitizeColor'],
            'default' => '',
        ]);

        register_setting(self::GROUP, SearchButton::OPTION_ICON, [
            'type' => 'integer',
            'sanitize_callback' => [$this, 'sanitizeIcon'],
            'default' => 0,
        ]);

        add_settings_section(
            self::SECTION,
            __('AI Search Button', 'appinio-search'),
            fn () => printf(
                '<p>%s</p>',
                esc_html__('A button that opens the AI search. Add it with the "AI search button" block or the [appinio_search_button] shortcode. These settings apply to every button.', 'appinio-search')
            ),
            self::PAGE,
        );

        add_settings_field(SearchButton::OPTION_LABEL, __('Button Label', 'appinio-search'), [$this, 'renderLabelField'], self::PAGE, self::SECTION);
        add_settings_field(SearchButton::OPTION_COLOR, __('Button Colour', 'appinio-search'), [$this, 'renderColorField'], self::PAGE, self::SECTION);
        add_settings_field(SearchButton::OPTION_TEXT_COLOR, __('Button Text Colour', 'appinio-search'), [$this, 'renderTextColorField'], self::PAGE, self::SECTION);
        add_settings_field(SearchButton::OPTION_ICON, __('Button Icon', 'appinio-search'), [$this, 'renderIconField'], self::PAGE, self::SECTION);
    }

    /**
     * A `#rrggbb` / `#rgb` colour, or '' (the widget default). WordPress passes the raw
     * request value, which may be an array — accept `mixed`.
     */
    public function sanitizeColor(mixed $value): string
    {
        if (! \is_string($value) || $value === '') {
            return '';
        }

        return (string) sanitize_hex_color($value);
    }

    /**
     * The ID of an image attachment, or 0 (the widget's sparkle icon).
     */
    public function sanitizeIcon(mixed $value): int
    {
        $id = absint($value);

        return $id > 0 && wp_attachment_is_image($id) ? $id : 0;
    }

    public function enqueueScripts(string $hook): void
    {
        if ($hook !== self::SCREEN) {
            return;
        }

        $script = \dirname($this->pluginFile) . '/assets/js/button-settings.js';

        wp_enqueue_style('wp-color-picker');
        wp_enqueue_media();
        wp_enqueue_script(
            self::SCRIPT,
            plugins_url('assets/js/button-settings.js', $this->pluginFile),
            ['jquery', 'wp-color-picker'],
            file_exists($script) ? (string) filemtime($script) : '1',
            true
        );
        wp_localize_script(self::SCRIPT, 'appinioButtonSettings', [
            'title' => __('Button icon', 'appinio-search'),
            'select' => __('Use this icon', 'appinio-search'),
        ]);
    }

    public function renderLabelField(): void
    {
        printf(
            '<input type="text" name="%s" value="%s" class="regular-text" placeholder="%s" />',
            esc_attr(SearchButton::OPTION_LABEL),
            esc_attr((string) get_option(SearchButton::OPTION_LABEL, '')),
            esc_attr__('AI search', 'appinio-search')
        );
        printf(
            '<p class="description">%s</p>',
            esc_html__('Text on the button. Leave empty to show "AI search" in the visitor\'s language. With Polylang or WPML, translate your text under their string translations.', 'appinio-search')
        );
    }

    public function renderColorField(): void
    {
        $this->renderColor(
            SearchButton::OPTION_COLOR,
            self::DEFAULT_COLOR,
            __('Button background. Leave empty for the default blue.', 'appinio-search')
        );
    }

    public function renderTextColorField(): void
    {
        $this->renderColor(
            SearchButton::OPTION_TEXT_COLOR,
            self::DEFAULT_TEXT_COLOR,
            __('Text and icon colour on the button. Leave empty for white.', 'appinio-search')
        );
    }

    public function renderIconField(): void
    {
        $id = (int) get_option(SearchButton::OPTION_ICON, 0);
        $url = $id > 0 ? wp_get_attachment_image_url($id, 'thumbnail') : false;
        $option = SearchButton::OPTION_ICON;

        printf('<input type="hidden" id="%1$s" name="%1$s" value="%2$s" />', esc_attr($option), $id > 0 ? esc_attr((string) $id) : '');
        printf('<div id="%s-preview" style="margin-bottom:8px;">', esc_attr($option));

        if (\is_string($url)) {
            printf('<img src="%s" alt="" width="32" height="32" />', esc_url($url));
        }

        echo '</div>';
        printf('<button type="button" class="button appinio-choose-icon">%s</button>', esc_html__('Choose icon', 'appinio-search'));
        printf(
            ' <button type="button" class="button appinio-remove-icon"%s>%s</button>',
            \is_string($url) ? '' : ' style="display:none"',
            esc_html__('Remove', 'appinio-search')
        );
        printf(
            '<p class="description">%s</p>',
            esc_html__('Replaces the sparkle icon. Use a small square image; it is shown at 16 × 16 pixels.', 'appinio-search')
        );
    }

    private function renderColor(string $option, string $default, string $description): void
    {
        printf(
            '<input type="text" name="%s" value="%s" class="appinio-color-field" data-default-color="%s" />',
            esc_attr($option),
            esc_attr((string) get_option($option, '')),
            esc_attr($default)
        );
        printf('<p class="description">%s</p>', esc_html($description));
    }
}
