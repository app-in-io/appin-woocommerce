# appin-search

WordPress plugin: sync WooCommerce products with AppIn AI Search. Real-time hooks + bulk sync + search widget. PHP 8.1+ | PSR-4 | PHPUnit 11 + Brain Monkey.

> **Layout:** This plugin lives at `api/wordpress-plugin/appin-search/` as a subdirectory of the main API project. It has its own `.git`, CI/CD, and GitHub repo (`app-in-io/appin-woocommerce`). The parent API repo ignores this directory via `.gitignore`.

## CRITICAL RULES

**DO NOT** commit or push without explicit permission from the user.

**DO NOT** push directly to `main`. All work goes through feature branches → PR → merge.

**ALWAYS** update `CHANGELOG.md` when making changes.

**This plugin MUST work without Composer autoload in production** — the manual `autoload.php` handles PSR-4 loading. Composer is only for dev dependencies.

**This plugin sends `X-Platform: woocommerce` header** — the API uses this to select the WooCommerce platform driver for validation.

## Purpose

WordPress/WooCommerce plugin that:
1. Syncs products to AppIn API (`POST /v1/index/products`) on create/update/delete
2. Provides bulk sync and bulk delete via admin settings page
3. Embeds the `<semantic-search>` Web Component from CDN for frontend search
4. All API calls go through `Api/Client.php` with `X-API-Key` + `X-Platform: woocommerce` headers

## Structure

```
appin-search.php                ← Bootstrap: constants, WC check, Plugin::boot()
autoload.php                    ← Manual PSR-4 autoloader (no Composer in prod)
src/
  Plugin.php                    ← Singleton, boot: settings + widget + sync
  Api/Client.php                ← HTTP client (wp_remote_request)
  Mapper/ProductMapper.php      ← WC_Product → API payload (17+ fields)
  Sync/ProductSync.php          ← Real-time hooks + Action Scheduler debounce (5s)
  Sync/BulkSync.php             ← Background batch sync/delete (20/batch)
  Admin/SettingsPage.php        ← WP admin settings + sync dashboard
  Frontend/SearchWidget.php     ← Enqueues search.js from CDN, renders <semantic-search>
tests/
  bootstrap.php                 ← Brain Monkey setup
  Mapper/ProductMapperTest.php  ← 3 tests (category mapping)
  Frontend/SearchWidgetTest.php ← 7 tests (hooks, assets, rendering)
```

## Running (Docker)

Own `compose.yml` — isolated from the main API project. Requires `search-network` to reach the API.

```bash
docker network create search-network       # once, shared with api
docker compose up -d                       # wordpress + mariadb
docker compose run --rm wp-cli wp theme install storefront --activate
docker compose run --rm wp-cli wp plugin install woocommerce --activate
docker compose run --rm wp-cli wp plugin activate appin-search
```

Or from the root repo: `make up-wp && make wp-setup`.

WordPress available at `woo.app-in.local` (OrbStack). Plugin auto-mounted at `wp-content/plugins/appin-search`.

First start auto-loads `seed.sql` into MariaDB (WP tables + WooCommerce + plugin settings). To reset: `docker compose down -v && docker compose up -d && make wp-setup`.

## Commands

```bash
composer install                       # install dev dependencies
vendor/bin/phpunit                     # run all tests
vendor/bin/phpunit --filter=ClassName  # run specific test
```

## CI

- **Test workflow**: PHP 8.2-8.4 matrix on PRs
- **Release workflow**: tag push → inject version → create zip → upload artifact → Slack notify

## Key Decisions

- **No Composer autoload in production**: manual `autoload.php` maps `AppIn\WooCommerce\` → `src/`
- **Singleton pattern** for `Plugin` (WordPress convention)
- **Action Scheduler** for debounced sync (5-second coalesce) — WooCommerce saves products multiple times per edit
- **Variable products**: index parent only with min/max variation prices + aggregated attributes
- **Status guard**: only `publish` products indexed; draft/private/trash auto-removed from index
- `APPIN_API_URL` and `APPIN_CDN_URL` constants overridable in `wp-config.php` for dev/staging

## API Contract

This plugin is an API client for the AppIn API:
- `POST /v1/index/products` — index a product (with `X-Platform: woocommerce`)
- `DELETE /v1/index/products` — remove a product
- API Key: `sk_live_...` format, stored in `wp_options` as `appin_api_key`
- Platform: `woocommerce` (selects `WooCommerceProductData` DTO on the API side)

Field mapping is defined in `Mapper/ProductMapper.php` and must match the WooCommerce platform's expected fields in the API.

## Coding Style

- PHP 8.1+ features: constructor promotion, named arguments, match, readonly
- `declare(strict_types=1)` in every file
- WordPress coding standards for hooks/filters (snake_case function names in callbacks)
- Namespace: `AppIn\WooCommerce`
- Brain Monkey for mocking WordPress functions in tests
- Requires: WordPress 6.0+, WooCommerce 8.0+
