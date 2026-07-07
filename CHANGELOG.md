# Changelog

All notable changes to this project will be documented in this file.

Format based on [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

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
- **Internationalization**: `languages/appin-search.pot` plus translations for German (de_DE),
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
