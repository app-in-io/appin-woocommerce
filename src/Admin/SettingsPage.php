<?php

declare(strict_types=1);

namespace AppIn\WooCommerce\Admin;

final class SettingsPage
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
    }

    public function addMenu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('AppIn Search', 'appin-search'),
            __('AppIn Search', 'appin-search'),
            'manage_woocommerce',
            'appin-search',
            [$this, 'render'],
        );
    }

    public function registerSettings(): void
    {
        register_setting('appin_search', 'appin_api_key', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);

        register_setting('appin_search', 'appin_auto_sync', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => true,
        ]);

        add_settings_section(
            'appin_main',
            __('Connection', 'appin-search'),
            fn () => printf(
                '<p>%s</p>',
                esc_html__('Connect your WooCommerce store to AppIn AI Search.', 'appin-search')
            ),
            'appin-search',
        );

        add_settings_field(
            'appin_api_key',
            __('API Key', 'appin-search'),
            [$this, 'renderApiKeyField'],
            'appin-search',
            'appin_main',
        );

        add_settings_field(
            'appin_auto_sync',
            __('Auto Sync', 'appin-search'),
            [$this, 'renderAutoSyncField'],
            'appin-search',
            'appin_main',
        );
    }

    public function renderApiKeyField(): void
    {
        $value = get_option('appin_api_key', '');
        printf(
            '<input type="password" name="appin_api_key" value="%s" class="regular-text" placeholder="sk_live_..." />',
            esc_attr($value)
        );
        printf(
            '<p class="description">%s</p>',
            esc_html__('Your AppIn API key. Found in the AppIn dashboard under Sites > API Keys.', 'appin-search')
        );
    }

    public function renderAutoSyncField(): void
    {
        $checked = get_option('appin_auto_sync', true);
        printf(
            '<label><input type="checkbox" name="appin_auto_sync" value="1" %s /> %s</label>',
            checked($checked, true, false),
            esc_html__('Automatically sync products when created, updated, or deleted.', 'appin-search')
        );
    }

    public function render(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html(get_admin_page_title()) . '</h1>';

        // Sync section
        $this->renderSyncSection();

        // Settings form
        echo '<form method="post" action="options.php">';
        settings_fields('appin_search');
        do_settings_sections('appin-search');
        submit_button();
        echo '</form>';

        echo '</div>';
    }

    private function renderSyncSection(): void
    {
        $apiKey = get_option('appin_api_key', '');
        if ($apiKey === '') {
            return;
        }

        $lastSync = get_option('appin_last_sync', '');
        $synced = (int) get_option('appin_synced_count', 0);
        $total = (int) wp_count_posts('product')->publish;
        $isSyncing = get_option('appin_bulk_sync_running', false);

        echo '<div class="card" style="max-width:600px;margin-bottom:20px;padding:12px 20px;">';
        echo '<h2 style="margin-top:0;">' . esc_html__('Sync Status', 'appin-search') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th>' . esc_html__('Published products', 'appin-search') . '</th>';
        echo '<td><strong>' . esc_html((string) $total) . '</strong></td></tr>';

        echo '<tr><th>' . esc_html__('Synced', 'appin-search') . '</th>';
        echo '<td><strong>' . esc_html((string) $synced) . '</strong></td></tr>';

        if ($lastSync) {
            echo '<tr><th>' . esc_html__('Last sync', 'appin-search') . '</th>';
            echo '<td>' . esc_html($lastSync) . '</td></tr>';
        }

        echo '</tbody></table>';

        if ($isSyncing) {
            echo '<p><span class="spinner is-active" style="float:none;margin:0 5px 0 0;"></span>';
            echo esc_html__('Sync in progress...', 'appin-search') . '</p>';
        } else {
            echo '<p>';
            printf(
                '<a href="%s" class="button button-primary">%s</a>',
                esc_url(wp_nonce_url(admin_url('admin-post.php?action=appin_bulk_sync'), 'appin_bulk_sync')),
                esc_html__('Sync All Products', 'appin-search')
            );
            echo ' ';
            printf(
                '<a href="%s" class="button" onclick="return confirm(\'%s\');">%s</a>',
                esc_url(wp_nonce_url(admin_url('admin-post.php?action=appin_bulk_delete'), 'appin_bulk_delete')),
                esc_attr__('This will remove all products from AppIn index. Continue?', 'appin-search'),
                esc_html__('Delete All from Index', 'appin-search')
            );
            echo '</p>';
        }

        echo '</div>';
    }
}
