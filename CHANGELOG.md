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
- **CI/CD**: GitHub Actions — test on PR (PHP 8.1–8.4), release zip on tag

## [v1.0.0] - 2026-04-02

### Added
- Initial release
- WooCommerce product sync (real-time hooks + bulk sync)
- `ProductMapper`: full WC_Product → API payload mapping
- Settings page: API key, auto sync toggle, sync status
- API client for index/delete operations
