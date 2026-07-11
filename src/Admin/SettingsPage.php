<?php

declare(strict_types=1);

namespace AppInIo\Admin;

use AppInIo\Sync\IndexState;
use AppInIo\Sync\RemoteIndexState;

if (! defined('ABSPATH')) {
    exit;
}

final class SettingsPage
{
    public function __construct(
        private IndexState $indexState = new IndexState,
        private Registration $registration = new Registration,
        private ?RemoteIndexState $remoteIndexState = null,
    ) {}

    /**
     * Lazily resolve the remote index state. Deferred (not a constructor default) so that
     * constructing SettingsPage — e.g. to render a single field or sanitize an option —
     * does not build an API Client and read options; the real index counts are only needed
     * when the sync dashboard actually renders.
     */
    private function remoteState(): RemoteIndexState
    {
        return $this->remoteIndexState ??= new RemoteIndexState;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueScripts']);
        add_filter('plugin_action_links_' . plugin_basename(APPINIO_PLUGIN_FILE), [$this, 'addActionLinks']);
        $this->registration->register();
    }

    /**
     * Add a "Settings" shortcut to the plugin's row on the Plugins screen.
     *
     * @param  array<int|string, string>  $links
     * @return array<int|string, string>
     */
    public function addActionLinks(array $links): array
    {
        $settings = \sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=appinio-search')),
            esc_html__('Settings', 'appinio-search')
        );

        array_unshift($links, $settings);

        return $links;
    }

    public function enqueueScripts(string $hook): void
    {
        if ($hook !== 'woocommerce_page_appinio-search') {
            return;
        }

        wp_add_inline_script('jquery-core', $this->syncPollingScript());
    }

    private function syncPollingScript(): string
    {
        $nonce = wp_create_nonce('appinio_sync_status');
        $ajaxUrl = admin_url('admin-ajax.php');

        return "
        (function(\$) {
            var container = document.getElementById('appinio-sync-section');
            if (!container) return;

            function poll(cb) {
                \$.post('{$ajaxUrl}', {
                    action: 'appinio_sync_status',
                    _ajax_nonce: '{$nonce}'
                }, cb);
            }

            // --- Local progress ticker (unchanged): runs only while a bulk run is active. ---
            if (container.dataset.running) {
                var localPoll = setInterval(function() {
                    poll(function(resp) {
                        if (!resp.running) {
                            clearInterval(localPoll);
                            var synced = container.querySelector('.appinio-synced-count');
                            var lastSync = container.querySelector('.appinio-last-sync');
                            var actions = container.querySelector('.appinio-actions');
                            var progress = container.querySelector('.appinio-progress');

                            if (synced) synced.textContent = resp.synced;
                            // Only a sync writes a 'Last sync' timestamp — never after a delete.
                            if (lastSync && resp.operation === 'sync' && resp.last_sync) {
                                lastSync.closest('tr').style.display = '';
                                lastSync.textContent = resp.last_sync;
                            }
                            if (progress) progress.style.display = 'none';
                            if (actions) actions.style.display = '';
                            if (resp.delete_failed > 0) { location.reload(); }
                        } else {
                            var synced = container.querySelector('.appinio-synced-count');
                            if (synced) synced.textContent = resp.synced;
                        }
                    });
                }, 3000);
            }

            // --- Remote reconciliation ticker: runs even on an idle dashboard. ---
            var indexedCell = container.querySelector('.appinio-indexed-count');
            var driftBadge = container.querySelector('.appinio-index-drift');

            function renderRemote(resp) {
                // Older API without the reconciliation fields — leave the server-rendered UI as is.
                if (typeof resp.indexed === 'undefined' && typeof resp.pending === 'undefined') {
                    return;
                }

                var indexed = (resp.indexed === null || typeof resp.indexed === 'undefined') ? null : resp.indexed;
                var pending = (typeof resp.pending === 'number') ? resp.pending : 0;
                var queuedEl = container.querySelector('.appinio-synced-count');
                var queued = queuedEl ? (parseInt(queuedEl.textContent, 10) || 0) : 0;

                if (indexedCell) {
                    if (indexed === null) {
                        indexedCell.textContent = '—';
                    } else if (pending > 0) {
                        indexedCell.textContent = indexed + ' / ' + queued + ' — Indexing… (' + pending + ' pending)';
                    } else {
                        indexedCell.textContent = String(indexed);
                    }
                }

                if (driftBadge) {
                    // While jobs are still in flight (pending > 0) the index legitimately lags —
                    // that is not drift, so the badge stays hidden until the queue drains.
                    var drift = pending === 0 && (resp.index_status === 'failed' || (indexed !== null && indexed < queued));
                    if (drift) {
                        if (indexed !== null && indexed < queued) {
                            driftBadge.textContent = indexed + ' of ' + queued +
                                ' products are in the search index — some queued items haven\'t indexed yet or failed. Retry sync.';
                        } else {
                            driftBadge.textContent = 'The last indexing run reported a failure — retry sync to be safe.';
                        }
                        driftBadge.style.display = '';
                    } else {
                        driftBadge.style.display = 'none';
                    }
                }
            }

            var remotePoll = null;
            var remoteTicks = 0;
            // Safety cap (~30 min at 15s): an open dashboard whose backend never drains its
            // pending jobs (e.g. stuck queue) must not poll forever. Generous so real long
            // syncs still get live updates.
            var REMOTE_MAX_TICKS = 120;
            // One immediate fetch on load — renders the indexed cell + drift even when idle.
            poll(function(resp) {
                renderRemote(resp);
                var pending = (typeof resp.pending === 'number') ? resp.pending : 0;
                // Only keep polling if there is live work to converge on.
                if (resp.running === true || pending > 0) {
                    remotePoll = setInterval(function() {
                        remoteTicks++;
                        poll(function(r) {
                            renderRemote(r);
                            var p = (typeof r.pending === 'number') ? r.pending : 0;
                            // Self-stop once the queue is drained and no run is active, or when
                            // the safety cap is hit.
                            if ((!r.running && p === 0) || remoteTicks >= REMOTE_MAX_TICKS) {
                                clearInterval(remotePoll);
                            }
                        });
                    }, 15000);
                }
            });
        })(jQuery);
        ";
    }

    public function addMenu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('AppIn Search', 'appinio-search'),
            __('AppIn Search', 'appinio-search'),
            'manage_woocommerce',
            'appinio-search',
            [$this, 'render'],
        );
    }

    public function registerSettings(): void
    {
        register_setting('appinio_search', 'appinio_api_key', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);

        register_setting('appinio_search', 'appinio_auto_sync', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => true,
        ]);

        register_setting('appinio_search', 'appinio_public_key', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);

        register_setting('appinio_search', 'appinio_search_selector', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);

        register_setting('appinio_search', 'appinio_results_page', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => true,
        ]);

        register_setting('appinio_search', 'appinio_widget_theme', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitizeTheme'],
            'default' => 'light',
        ]);

        add_settings_section(
            'appinio_main',
            __('Connection', 'appinio-search'),
            fn () => printf(
                '<p>%s</p>',
                esc_html__('Connect your WooCommerce store to AppIn AI Search.', 'appinio-search')
            ),
            'appinio-search',
        );

        add_settings_field(
            'appinio_api_key',
            __('API Key', 'appinio-search'),
            [$this, 'renderApiKeyField'],
            'appinio-search',
            'appinio_main',
        );

        add_settings_field(
            'appinio_auto_sync',
            __('Auto Sync', 'appinio-search'),
            [$this, 'renderAutoSyncField'],
            'appinio-search',
            'appinio_main',
        );

        add_settings_section(
            'appinio_widget',
            __('Search Widget', 'appinio-search'),
            fn () => printf(
                '<p>%s</p>',
                esc_html__('Configure the live search widget that appears on your store.', 'appinio-search')
            ),
            'appinio-search',
        );

        add_settings_field(
            'appinio_public_key',
            __('Public Key', 'appinio-search'),
            [$this, 'renderPublicKeyField'],
            'appinio-search',
            'appinio_widget',
        );

        add_settings_field(
            'appinio_search_selector',
            __('Search Input Selector', 'appinio-search'),
            [$this, 'renderSearchSelectorField'],
            'appinio-search',
            'appinio_widget',
        );

        add_settings_field(
            'appinio_results_page',
            __('Search Results Page', 'appinio-search'),
            [$this, 'renderResultsPageField'],
            'appinio-search',
            'appinio_widget',
        );

        add_settings_field(
            'appinio_widget_theme',
            __('Widget Appearance', 'appinio-search'),
            [$this, 'renderThemeField'],
            'appinio-search',
            'appinio_widget',
        );
    }

    /**
     * Restrict the appearance option to the values the widget understands.
     *
     * WordPress passes the raw request value to a sanitize callback, which may be an
     * array (e.g. a manipulated `field[]=x` submission) — accept `mixed` and guard for
     * a string, otherwise strict_types would fatal on a non-string argument.
     */
    public function sanitizeTheme(mixed $value): string
    {
        return \is_string($value) && \in_array($value, ['light', 'dark', 'auto'], true) ? $value : 'light';
    }

    public function renderApiKeyField(): void
    {
        $value = get_option('appinio_api_key', '');
        printf(
            '<input type="password" name="appinio_api_key" value="%s" class="regular-text" placeholder="sk_live_..." />',
            esc_attr($value)
        );
        printf(
            '<p class="description">%s</p>',
            wp_kses_post(\sprintf(
                /* translators: %s: link to the AppIn dashboard */
                esc_html__('Your AppIn API key. Found in the %s under Sites > API Keys.', 'appinio-search'),
                $this->dashboardLink()
            ))
        );
    }

    public function renderAutoSyncField(): void
    {
        $checked = get_option('appinio_auto_sync', true);
        printf(
            '<label><input type="checkbox" name="appinio_auto_sync" value="1" %s /> %s</label>',
            checked($checked, true, false),
            esc_html__('Automatically sync products when created, updated, or deleted.', 'appinio-search')
        );
    }

    public function renderPublicKeyField(): void
    {
        $value = get_option('appinio_public_key', '');
        printf(
            '<input type="text" name="appinio_public_key" value="%s" class="regular-text" placeholder="pk_live_..." />',
            esc_attr($value)
        );
        printf(
            '<p class="description">%s</p>',
            wp_kses_post(\sprintf(
                /* translators: %s: link to the AppIn dashboard */
                esc_html__('Public key for the search widget. Safe to expose in browser. Found in the %s under Sites > API Keys.', 'appinio-search'),
                $this->dashboardLink()
            ))
        );
    }

    public function renderSearchSelectorField(): void
    {
        $value = get_option('appinio_search_selector', '');
        printf(
            '<input type="text" name="appinio_search_selector" value="%s" class="regular-text" placeholder="input[name=&quot;s&quot;]" />',
            esc_attr($value)
        );
        printf(
            '<p class="description">%s</p>',
            esc_html__('Custom CSS selector for the search input. Leave empty to use automatic detection.', 'appinio-search')
        );
    }

    public function renderResultsPageField(): void
    {
        $checked = get_option('appinio_results_page', true);
        printf(
            '<label><input type="checkbox" name="appinio_results_page" value="1" %s /> %s</label>',
            checked($checked, true, false),
            esc_html__('Power the WordPress search results page (/?s=) with AI. Products use AI search; other content stays native. Falls back to native search if AppIn is unavailable.', 'appinio-search')
        );
    }

    public function renderThemeField(): void
    {
        $value = get_option('appinio_widget_theme', 'light');
        $options = [
            'light' => __('Light', 'appinio-search'),
            'dark' => __('Dark', 'appinio-search'),
            'auto' => __('Auto (match store background)', 'appinio-search'),
        ];

        echo '<select name="appinio_widget_theme">';
        foreach ($options as $optionValue => $label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($optionValue),
                selected($value, $optionValue, false),
                esc_html($label)
            );
        }
        echo '</select>';
        printf(
            '<p class="description">%s</p>',
            esc_html__('Colour theme for the search widget. Auto follows your store\'s background (light or dark).', 'appinio-search')
        );
    }

    public function render(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html(get_admin_page_title()) . '</h1>';

        // Display-only feedback flag from the bulk re-entrancy guard (no state change).
        if (isset($_GET['appinio_busy'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            echo '<div class="notice notice-warning"><p>'
                . esc_html__('A bulk operation is already running — please wait for it to finish.', 'appinio-search')
                . '</p></div>';
        }

        // Success confirmation after in-plugin registration. Shown here (not in the
        // registration card) because once the key is saved the card no longer renders.
        if (isset($_GET['appinio_connected'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('Your store is connected! You can now sync your products.', 'appinio-search')
                . '</p></div>';
        }

        // Sync section
        $this->renderSyncSection();

        // Settings form
        echo '<form method="post" action="options.php">';
        settings_fields('appinio_search');
        do_settings_sections('appinio-search');
        submit_button();
        echo '</form>';

        echo '</div>';
    }

    /**
     * Anchor tag linking to the AppIn dashboard, reused across field descriptions.
     */
    private function dashboardLink(): string
    {
        return '<a href="' . esc_url('https://my.app-in.io') . '" target="_blank" rel="noopener">'
            . esc_html__('AppIn dashboard', 'appinio-search') . '</a>';
    }

    private function renderSyncSection(): void
    {
        $apiKey = get_option('appinio_api_key', '');
        if ($apiKey === '') {
            $this->registration->renderCard();

            return;
        }

        $lastSync = get_option('appinio_last_sync', '');
        $synced = $this->indexState->count();
        $total = (int) wp_count_posts('product')->publish;
        $isSyncing = (bool) get_option('appinio_bulk_sync_running', false);
        $isDeleting = $isSyncing && get_option('appinio_bulk_operation', 'sync') === 'delete';
        $deleteFailed = (int) get_option('appinio_last_delete_failed', 0);

        // Real index reconciliation: how many products actually landed in the search index
        // (from the backend, 30s-cached), versus $synced (what the store queued locally).
        $remote = $this->remoteState();
        $indexed = $remote->products();
        $hasDrift = $remote->drift($synced);

        echo '<div id="appinio-sync-section" class="card" style="max-width:600px;margin-bottom:20px;padding:12px 20px;"';
        if ($isSyncing) {
            echo ' data-running="1"';
        }
        echo '>';
        echo '<h2 style="margin-top:0;">' . esc_html__('Sync Status', 'appinio-search') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th>' . esc_html__('Published products', 'appinio-search') . '</th>';
        echo '<td><strong>' . esc_html((string) $total) . '</strong></td></tr>';

        echo '<tr><th>' . esc_html__('Synced (queued)', 'appinio-search') . '</th>';
        echo '<td><strong class="appinio-synced-count">' . esc_html((string) $synced) . '</strong></td></tr>';

        echo '<tr><th>' . esc_html__('Indexed in search', 'appinio-search') . '</th>';
        echo '<td><strong class="appinio-indexed-count">'
            . esc_html($indexed === null ? '—' : (string) $indexed)
            . '</strong></td></tr>';

        echo '<tr' . ($lastSync ? '' : ' style="display:none"') . '>';
        echo '<th>' . esc_html__('Last sync', 'appinio-search') . '</th>';
        echo '<td class="appinio-last-sync">' . esc_html($lastSync) . '</td></tr>';

        echo '</tbody></table>';

        // Drift warning: fewer products are actually indexed than were queued, or the last
        // index run failed. Always emitted (hidden when converged) so the JS poller can
        // toggle it live as pending jobs drain. Two copy variants: a counting "shortfall"
        // message when fewer are indexed than queued, and a plain "last run failed" message
        // for the failed-but-counts-match case (a counting message there would contradict
        // itself). The JS poller rebuilds this text live; this is the initial / no-JS render.
        if ($indexed !== null && $indexed < $synced) {
            $driftMessage = \sprintf(
                /* translators: 1: number of products in the search index, 2: number queued */
                _n(
                    '%1$d of %2$d product is in the search index — some queued items haven\'t indexed yet or failed. Retry sync.',
                    '%1$d of %2$d products are in the search index — some queued items haven\'t indexed yet or failed. Retry sync.',
                    $synced,
                    'appinio-search'
                ),
                $indexed,
                $synced
            );
        } else {
            $driftMessage = __('The last indexing run reported a failure — retry sync to be safe.', 'appinio-search');
        }

        echo '<p class="appinio-index-drift" style="color:#b32d2e;' . ($hasDrift ? '' : 'display:none') . '">'
            . esc_html($driftMessage) . '</p>';

        if ($deleteFailed > 0) {
            echo '<p class="appinio-delete-failed" style="color:#b32d2e;">' . esc_html(\sprintf(
                /* translators: %d: number of products */
                _n(
                    '%d product could not be removed from the index — check the debug log or retry.',
                    '%d products could not be removed from the index — check the debug log or retry.',
                    $deleteFailed,
                    'appinio-search'
                ),
                $deleteFailed
            )) . '</p>';
        }

        echo '<p class="appinio-progress"' . ($isSyncing ? '' : ' style="display:none"') . '>';
        echo '<span class="spinner is-active" style="float:none;margin:0 5px 0 0;"></span>';
        echo '<span class="appinio-progress-label">'
            . esc_html($isDeleting ? __('Delete in progress...', 'appinio-search') : __('Sync in progress...', 'appinio-search'))
            . '</span></p>';

        echo '<p class="appinio-actions"' . ($isSyncing ? ' style="display:none"' : '') . '>';
        printf(
            '<a href="%s" class="button button-primary">%s</a>',
            esc_url(wp_nonce_url(admin_url('admin-post.php?action=appinio_bulk_sync'), 'appinio_bulk_sync')),
            esc_html__('Sync All Products', 'appinio-search')
        );
        echo ' ';
        printf(
            '<a href="%s" class="button" onclick="return confirm(\'%s\');">%s</a>',
            esc_url(wp_nonce_url(admin_url('admin-post.php?action=appinio_bulk_delete'), 'appinio_bulk_delete')),
            esc_attr__('This will remove all products from AppIn index. Continue?', 'appinio-search'),
            esc_html__('Delete All from Index', 'appinio-search')
        );
        echo '</p>';

        echo '</div>';
    }
}
