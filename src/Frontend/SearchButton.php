<?php

declare(strict_types=1);

namespace Appinio\Frontend;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The "AI search" button: `<semantic-search-button>` from search.js (widget v0.13.0+).
 * A click dispatches `appin-search:open`, which opens the `<semantic-search>` modal that
 * SearchWidget renders in the footer. The store owner places it with the shortcode or
 * the `appinio-search/search-button` block; both go through render().
 */
final class SearchButton
{
    public const SHORTCODE = 'appinio_search_button';

    public const EDITOR_SCRIPT = 'appinio-search-button-editor';

    public const STYLE = 'appinio-search-button';

    /** Admin settings (Admin\ButtonSettings); they apply to every button on the store. */
    public const OPTION_LABEL = 'appinio_button_label';

    public const OPTION_COLOR = 'appinio_button_color';

    public const OPTION_TEXT_COLOR = 'appinio_button_text_color';

    public const OPTION_ICON = 'appinio_button_icon';

    /**
     * Every button option. uninstall.php lists them as literals (it runs without the
     * autoloader); ButtonSettingsTest checks that it lists all of these.
     *
     * @var list<string>
     */
    public const OPTIONS = [self::OPTION_LABEL, self::OPTION_COLOR, self::OPTION_TEXT_COLOR, self::OPTION_ICON];

    /**
     * Options translated through Polylang (register + pll__). Must stay in step with the
     * <admin-texts> keys in wpml-config.xml: WPML filters get_option() itself.
     * TranslatableOptionsTest compares the two.
     *
     * @var list<string>
     */
    public const TRANSLATABLE_OPTIONS = [self::OPTION_LABEL];

    /** Values the widget accepts for `variant`; anything else is left to its default. */
    private const VARIANTS = ['icon-text', 'icon'];

    /** Admin colour option → the widget's CSS custom property on the button. */
    private const COLOR_PROPERTIES = [
        self::OPTION_COLOR => '--appin-primary',
        self::OPTION_TEXT_COLOR => '--appin-on-primary',
    ];

    /**
     * @param  string  $pluginFile  The plugin's main file; the block lives next to it in blocks/.
     */
    public function __construct(
        private string $pluginFile = '',
    ) {}

    public function register(): void
    {
        add_shortcode(self::SHORTCODE, [$this, 'shortcode']);
        add_action('init', [$this, 'registerBlock']);
        add_action('init', [self::class, 'registerPolylangStrings']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueStyle']);
        add_action('enqueue_block_editor_assets', [$this, 'editorSettings']);
    }

    /**
     * Register the admin label with Polylang, so it shows up under String translations.
     * WPML needs no call: wpml-config.xml declares it.
     */
    public static function registerPolylangStrings(): void
    {
        if (! \function_exists('pll_register_string')) {
            return;
        }

        foreach (self::TRANSLATABLE_OPTIONS as $option) {
            $value = (string) get_option($option, '');

            if ($value === '') {
                continue;
            }

            pll_register_string($option, $value, 'Appinio Search');
        }
    }

    /**
     * Print the admin colours once, as the widget's custom properties on every button.
     * The rule targets only <semantic-search-button>, so the search modal keeps its colours.
     * Theme CSS can still override it.
     */
    public function enqueueStyle(): void
    {
        if (SearchWidget::searchKey() === '') {
            return;
        }

        $declarations = [];

        foreach (self::COLOR_PROPERTIES as $option => $property) {
            $color = $this->color($option);

            if ($color !== '') {
                $declarations[] = $property . ':' . $color;
            }
        }

        if ($declarations === []) {
            return;
        }

        // A handle without a file: it exists only to carry the inline rule.
        // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
        wp_register_style(self::STYLE, false);
        wp_enqueue_style(self::STYLE);
        wp_add_inline_style(self::STYLE, 'semantic-search-button{' . implode(';', $declarations) . '}');
    }

    /**
     * Register the dynamic block from blocks/search-button/block.json. The editor script is
     * plain JS on the wp.* globals (no build step); the front end is server-rendered by
     * renderBlock(), so the block saves no markup.
     */
    public function registerBlock(): void
    {
        $dir = \dirname($this->pluginFile) . '/blocks/search-button';
        $script = $dir . '/index.js';

        wp_register_script(
            self::EDITOR_SCRIPT,
            plugins_url('blocks/search-button/index.js', $this->pluginFile),
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n'],
            file_exists($script) ? (string) filemtime($script) : '1',
            true
        );
        wp_set_script_translations(self::EDITOR_SCRIPT, 'appinio-search');

        register_block_type($dir, ['render_callback' => [$this, 'renderBlock']]);
    }

    /**
     * Values the editor preview needs, printed before the editor script. Hooked to
     * enqueue_block_editor_assets so the front end never computes them.
     */
    public function editorSettings(): void
    {
        $settings = [
            'hasKey' => SearchWidget::searchKey() !== '',
            'settingsUrl' => admin_url('admin.php?page=appinio-search'),
            'label' => $this->adminLabel(),
            'color' => $this->color(self::OPTION_COLOR),
            'textColor' => $this->color(self::OPTION_TEXT_COLOR),
            'iconUrl' => $this->iconUrl(),
        ];

        wp_add_inline_script(
            self::EDITOR_SCRIPT,
            // JSON_HEX_TAG: a translated label with "<!--" or "<script" cannot confuse the
            // HTML parser of this inline <script>.
            'window.appinioSearchButton = ' . wp_json_encode($settings, JSON_HEX_TAG) . ';',
            'before'
        );
    }

    /**
     * Front-end render of the block: the shortcode markup inside the block wrapper, so the
     * block's "Additional CSS class" applies. Nothing at all without a public key.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function renderBlock(array $attributes): string
    {
        $button = $this->render((string) ($attributes['label'] ?? ''), (string) ($attributes['variant'] ?? ''));

        if ($button === '') {
            return '';
        }

        return \sprintf('<div %s>%s</div>', get_block_wrapper_attributes(), $button);
    }

    /**
     * `[appinio_search_button label="…" variant="icon|icon-text"]`.
     *
     * @param  array<string, string>|string  $atts  WordPress passes '' when the shortcode has no attributes.
     */
    public function shortcode(array|string $atts = []): string
    {
        $atts = shortcode_atts(['label' => '', 'variant' => ''], \is_array($atts) ? $atts : [], self::SHORTCODE);

        return $this->render((string) $atts['label'], (string) $atts['variant']);
    }

    /**
     * Button markup, or '' when there is no public key: search.js is not loaded then,
     * and a button that opens nothing must not appear.
     *
     * Label precedence: the button's own label → the admin label (translated) → no
     * attribute, so the widget shows "AI search" in the visitor's language.
     */
    public function render(string $label, string $variant): string
    {
        if (SearchWidget::searchKey() === '') {
            return '';
        }

        $attrs = '';
        $label = sanitize_text_field($label);

        if ($label === '') {
            $label = $this->adminLabel();
        }

        if ($label !== '') {
            $attrs .= \sprintf(' label="%s"', esc_attr($label));
        }

        if (\in_array($variant, self::VARIANTS, true)) {
            $attrs .= \sprintf(' variant="%s"', esc_attr($variant));
        }

        // The admin icon replaces the sparkle through the widget's `icon` slot (v0.14.0+);
        // an older widget ignores the slot and keeps the sparkle.
        $iconUrl = $this->iconUrl();
        $icon = $iconUrl === ''
            ? ''
            : \sprintf('<img slot="icon" src="%s" alt="" width="16" height="16">', esc_url($iconUrl));

        return wp_kses(
            \sprintf('<semantic-search-button%s>%s</semantic-search-button>', $attrs, $icon),
            [
                'semantic-search-button' => ['label' => true, 'variant' => true],
                'img' => ['slot' => true, 'src' => true, 'alt' => true, 'width' => true, 'height' => true],
            ]
        );
    }

    /**
     * The admin label in the visitor's language. WPML has already translated it inside
     * get_option() (wpml-config.xml); Polylang translates it here.
     */
    private function adminLabel(): string
    {
        $label = (string) get_option(self::OPTION_LABEL, '');

        if ($label !== '' && \function_exists('pll__')) {
            return (string) pll__($label);
        }

        return $label;
    }

    /**
     * An admin colour, validated again on output: the option is only sanitized when it is
     * saved through the settings form.
     */
    private function color(string $option): string
    {
        $value = (string) get_option($option, '');

        return $value === '' ? '' : (string) sanitize_hex_color($value);
    }

    /**
     * URL of the admin icon, or '' when none is set or the attachment no longer exists.
     */
    private function iconUrl(): string
    {
        $id = (int) get_option(self::OPTION_ICON, 0);

        if ($id <= 0) {
            return '';
        }

        $url = wp_get_attachment_image_url($id, 'thumbnail');

        return \is_string($url) ? $url : '';
    }
}
