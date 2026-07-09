# Changelog

All notable changes to this project will be documented in this file.

Format based on [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

### Added
- **AI search on the results page**: the WordPress/WooCommerce search results page (`/?s=`) is now
  powered by AppIn instead of native keyword search — fixing typos and semantic queries for every
  entry path (sidebar form, bookmarks, back button, mobile), not just the dropdown widget. Products
  are matched by AI; posts/pages keep native keyword matching and are merged into one result set, so
  stores with a blog don't lose non-product search. Product searches and product-category archives
  stay product-scoped so WooCommerce catalog visibility, price/attribute layered-nav filters and the
  category tax query keep intersecting natively; a search within a product category also passes
  `category_id` to the search API. Runs on a short timeout with graceful fallback to native search if
  the API is unavailable. New **Search Results Page** setting (on by default) under Search Widget.

### Changed
- **Unique prefix (WordPress.org requirement)**: renamed the plugin's namespace
  (`AppIn\WooCommerce` → `AppInIo`), constants (`APPIN_*` → `APPINIO_*`), option keys and hooks
  (`appin_*` → `appinio_*`) to a distinct 4+ character prefix. The plugin slug and text domain
  (`appinio-search`) are unchanged.
- readme.txt: WP.org listing SEO/GEO pass — keyworded title, optimized tags, multilingual
  differentiator surfaced, GEO fact block, comparison-intent FAQ.

### Fixed
- **Search-excluded products no longer indexed**: products with catalog visibility "Catalog only" or
  "Hidden" are skipped on sync (and deindexed on their next update), so they don't surface in AppIn
  search — on the results page or the dropdown widget. Only `visible`/`search`-visibility products are
  indexed. (#13)
- **Sync reliability** (api#215, plugin side): network/transport errors are now retried (previously
  only 429/5xx were); a persistently failing real-time sync self-heals via Action Scheduler with
  exponential backoff (5 attempts), then throws so the action is marked **failed** (visible in
  Tools → Scheduled Actions) instead of silently completing; **hard-deleted** products
  (`before_delete_post` — `EMPTY_TRASH_DAYS=0`, REST, WP-CLI) are now removed from the index.
- **Sync Status accuracy** (#9, #10, #11): the "Synced" count is now derived from a per-product
  `_appinio_indexed` meta flag (idempotent set/remove) instead of a hand-maintained tally, so it no
  longer goes stale on draft/trash/delete deindex or over-counts on re-index. Double-clicking "Sync All
  Products" no longer spawns a second concurrent batch chain (a heartbeated re-entrancy guard, so a
  crashed run can't wedge future runs). The Delete flow now
  shows a distinct "Delete in progress" label, no longer displays a stale "Last sync" timestamp after a
  delete-only run, and surfaces per-item delete failures.

## [0.9.0] - 2026-07-07

First public beta — distributed to early stores for feedback; prepared for the WordPress.org plugin directory.

### Added
- WooCommerce product sync — real-time hooks (create, update, stock change, trash, restore) with
  Action Scheduler debounce, plus one-click Bulk Sync / Delete All in background batches.
- `ProductMapper`: full WC_Product → API payload mapping (title, description, price, sale/compare-at,
  currency, image, stock, SKU, categories, `category_id`, tags, rating, review count, brand,
  attributes, variable price range, grouped children).
- **HPOS compatibility**: declare `custom_order_tables` compatibility via `FeaturesUtil`.
- **Search widget**: `SearchWidget` — enqueues `search.js` from CDN, renders the `<semantic-search>`
  element, category-aware, custom input selector; Public Key + Search Input Selector settings.
- **Onboarding**: getting-started empty state with a signup CTA and clickable AppIn dashboard links
  in the settings page.
- API client for index/delete/batch operations with `429` + `5xx` retry and back-off.
- `APPIN_CDN_URL` constant overridable via `wp-config.php` for dev/staging.
- **WordPress.org readiness**: `readme.txt` (with an External Services disclosure), `uninstall.php`
  (option + Action Scheduler cleanup), `LICENSE` (GPL-2.0), `Text Domain` / `Domain Path` /
  `License URI` headers, and direct-file-access guards in every source file.
- **Internationalization**: `languages/appinio-search.pot` plus translations for German (de_DE),
  Dutch (nl_NL), Ukrainian (uk), and Estonian (et).
- **Tests**: PHPUnit 11 + Brain Monkey — 54 tests across Client, ProductSync, BulkSync,
  ProductMapper, SearchWidget, and SettingsPage.
- **Static analysis in CI**: `laravel/pint` + `phpstan` level 5 with WordPress/WooCommerce stubs.
- **CI/CD**: GitHub Actions — test on PR (PHP 8.1–8.4), `git archive` release zip on tag, and a
  manual-dispatch WordPress.org SVN deploy workflow.

### Changed
- Removed the duplicate `Author URI` from the plugin header (WordPress.org requirement); author set
  to `appinio`.
- **WordPress.org Plugin Check compliance**: direct-access guards use `defined('ABSPATH')` (the
  leading-backslash form was not recognised); dynamic output escaped via `wp_kses`/`wp_kses_post`;
  `wp_die()` messages escaped with `esc_html__`; the sync-status inline script no longer uses a
  heredoc; removed `load_plugin_textdomain` (WordPress.org auto-loads translations); aligned the
  plugin name between the header and `readme.txt`; added `.distignore` for the SVN deploy. Pint
  `native_function_invocation` now excludes `defined` so the guard stays Plugin-Check-friendly.
- Gated `error_log()` calls behind `WP_DEBUG`.
- `Api/Client` no longer sleeps after the final retry attempt (avoids needless blocking).
- `BulkSync::ajaxSyncStatus` now enforces the `manage_woocommerce` capability.
- `BulkSync` made testable via a `protected redirect()` seam.

### Fixed
- **PHP 8.1/8.2 compatibility**: removed a typed class constant (`const int MAX_RETRIES`, a PHP 8.3+
  feature) that would fatal on the declared minimum.
- Retry transient `5xx` server errors (502/503/504) during sync, not only `429`.
- `ProductMapper` type/lint fixes: cast attachment IDs to `int` before `wp_get_attachment_url()`,
  and dropped a dead `is_bool()` clause from the output filter.
