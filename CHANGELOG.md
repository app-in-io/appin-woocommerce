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
- **Sync-engine test coverage** (#221): `Api/Client` (headers/method/body, 429 retry + back-off,
  no-retry on WP_Error/5xx, `MAX_RETRIES` exhaustion), `Sync/ProductSync` (hook registration,
  Action Scheduler debounce, variation→parent, status-guard delete), `Sync/BulkSync` (batch
  pagination, delete batches, finish transitions), and expanded `Mapper/ProductMapper` (price,
  stock, on-sale/compare-at, rating, sku, tags, attributes, variable price range, grouped
  children, brand). Suite grew from 10 to 47 tests. Documented gaps: `Api/Client` does not retry
  on 5xx (only 429); `BulkSync::handleBulkSync/handleBulkDelete` `exit` after redirect are not
  unit-tested (would need a small refactor).
- **Static analysis in CI** (#221): `laravel/pint` (code style) + `phpstan/phpstan` level 5 with
  WordPress/WooCommerce stubs (`szepeviktor/phpstan-wordpress`, `php-stubs/woocommerce-stubs`).
  `composer format` / `lint` / `analyse` scripts; `pint --test` + `phpstan` added to the CI
  workflow. Three pre-existing `src/Mapper/ProductMapper.php` findings captured in
  `phpstan-baseline.neon` as debt (not fixed here — tests/tooling-only change).
- **CI/CD**: GitHub Actions — test on PR (PHP 8.1–8.4), release zip on tag

### Fixed
- **PHP 8.1/8.2 compatibility** (#221): `Api/Client` declared a typed class constant
  (`const int MAX_RETRIES`, a PHP 8.3+ feature) that would fatal on the declared minimum
  PHP 8.1/8.2; removed the constant's type. Surfaced by the new `ClientTest` running on the
  8.2 CI matrix leg.

## [v1.0.0] - 2026-04-02

### Added
- Initial release
- WooCommerce product sync (real-time hooks + bulk sync)
- `ProductMapper`: full WC_Product → API payload mapping
- Settings page: API key, auto sync toggle, sync status
- API client for index/delete operations
