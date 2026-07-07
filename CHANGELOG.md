# Changelog

All notable changes to this project will be documented in this file.

Format based on [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

### Added
- **HPOS compatibility**: declare `custom_order_tables` compatibility via `FeaturesUtil` — removes WooCommerce "Incompatible" warning
- **Search widget**: `SearchWidget` class — enqueues `search.js` from CDN, renders `<semantic-search>` element
- **Category ID filtering**: `ProductMapper` sends `category_id` (WP term ID) alongside category name
- **Settings**: Public Key and Search Input Selector fields in new "Search Widget" section
- **`APPIN_CDN_URL` constant**: overridable via `wp-config.php` for dev/staging
- **Tests**: PHPUnit 11 + Brain Monkey — SearchWidget (7 tests) + ProductMapper (3 tests)
- **Sync-engine test coverage** (#221): `Api/Client` (headers/method/body, 429 + 5xx retry with
  back-off, no-retry on WP_Error, `MAX_RETRIES` exhaustion), `Sync/ProductSync` (hook registration,
  Action Scheduler debounce, variation→parent, status-guard delete), `Sync/BulkSync` (batch
  pagination, delete batches, finish transitions, admin-post handlers), and expanded
  `Mapper/ProductMapper` (price, stock, on-sale/compare-at, rating, sku, tags, attributes,
  variable price range, grouped children, brand). Suite grew from 10 to 50 tests.
- **Static analysis in CI** (#221): `laravel/pint` (code style) + `phpstan/phpstan` level 5 with
  WordPress/WooCommerce stubs (`szepeviktor/phpstan-wordpress`, `php-stubs/woocommerce-stubs`).
  `composer format` / `lint` / `analyse` scripts; `pint --test` + `phpstan` added to the CI
  workflow. `src/` passes level 5 with no baseline.
- **CI/CD**: GitHub Actions — test on PR (PHP 8.1–8.4), release zip on tag

### Fixed
- **PHP 8.1/8.2 compatibility** (#221): `Api/Client` declared a typed class constant
  (`const int MAX_RETRIES`, a PHP 8.3+ feature) that would fatal on the declared minimum
  PHP 8.1/8.2; removed the constant's type. Surfaced by the new `ClientTest` running on the
  8.2 CI matrix leg.
- **Retry transient server errors** (#221): `Api/Client` now retries `5xx` responses (with the
  same back-off), not only `429` — a transient `502/503/504` during a product sync previously
  failed immediately with no retry.
- **`ProductMapper` type/lint fixes** (#221): cast attachment IDs to `int` before
  `wp_get_attachment_url()`, and dropped a dead `is_bool()` clause from the output `array_filter`
  (booleans are already retained by the strict comparisons) — clears all PHPStan level-5 findings.

### Changed
- **`BulkSync` made testable** (#221): extracted the post-action `wp_safe_redirect(); exit;` into
  a `protected redirect()` seam (class no longer `final`) so `handleBulkSync`/`handleBulkDelete`
  are now unit-tested.

## [v1.0.0] - 2026-04-02

### Added
- Initial release
- WooCommerce product sync (real-time hooks + bulk sync)
- `ProductMapper`: full WC_Product → API payload mapping
- Settings page: API key, auto sync toggle, sync status
- API client for index/delete operations
