# appinio-search

WordPress plugin: sync WooCommerce products with Appinio AI Search. Real-time hooks + bulk sync + search widget. PHP 8.1+ | PSR-4 | PHPUnit 11 + Brain Monkey.

> **Layout:** This plugin lives at `api/wordpress-plugin/appin-search/` as a subdirectory of the main API project (host checkout dir unchanged; the distributed / wp.org slug is `appinio-search`). It has its own `.git`, CI/CD, and GitHub repo (`app-in-io/appin-woocommerce`). The parent API repo ignores this directory via `.gitignore`.

## CRITICAL RULES

**DO NOT** commit or push without explicit permission from the user.

**DO NOT** push directly to `main`. All work goes through feature branches → PR → merge.

**ALWAYS** update `CHANGELOG.md` when making changes.

**This plugin MUST work without Composer autoload in production** — the manual `autoload.php` handles PSR-4 loading. Composer is only for dev dependencies.

**This plugin sends `X-Platform: woocommerce` header** — the API uses this to select the WooCommerce platform driver for validation.

## Purpose

WordPress/WooCommerce plugin that:
1. Syncs products to Appinio API (`POST /v1/index/products`) on create/update/delete
2. Provides bulk sync and bulk delete via admin settings page
3. Embeds the `<semantic-search>` Web Component from CDN for frontend search
4. All API calls go through `Api/Client.php` with `X-API-Key` + `X-Platform: woocommerce` headers

## Structure

```
appinio-search.php                ← Bootstrap: constants, WC check, Plugin::boot()
autoload.php                    ← Manual PSR-4 autoloader (no Composer in prod)
src/
  Plugin.php                    ← Singleton, boot: settings + widget + sync + results page
  Api/Client.php                ← HTTP client (wp_remote_request); index/delete + searchProducts()
  Mapper/ProductMapper.php      ← WC_Product → API payload (17+ fields)
  Sync/ProductSync.php          ← Real-time hooks + Action Scheduler debounce (5s)
  Sync/BulkSync.php             ← Background batch sync/delete (20/batch)
  Admin/SettingsPage.php        ← WP admin settings + sync dashboard
  Frontend/SearchWidget.php     ← Enqueues search.js from CDN, renders <semantic-search>
  Frontend/SearchResults.php    ← Powers /?s= results page with AI (pre_get_posts + post__in merge)
tests/
  bootstrap.php                 ← Brain Monkey setup + WC_Product / WP_Query stubs
  Mapper/ProductMapperTest.php  ← category mapping
  Frontend/SearchWidgetTest.php ← widget hooks, assets, rendering
  Frontend/SearchResultsTest.php← results-page takeover guards + merge + nullify
```

## Running (Docker)

The local WordPress dev harness is **not part of this plugin** — it lives in the
monorepo root (`compose.wordpress.yml` + `docker/wordpress/seed.sql`) and mounts
both `appin-search` and `appin-chat` into one WordPress + WooCommerce install.
Requires `search-network` to reach the API. Run from the monorepo root:

```bash
make up-wp        # docker compose -f compose.wordpress.yml up -d (wordpress + mariadb)
make wp-setup     # install Storefront + WooCommerce, activate the plugin
```

WordPress available at `woo.app-in.local` (OrbStack). This plugin auto-mounted at
`wp-content/plugins/appinio-search`; appin-chat at `wp-content/plugins/appin-chat`.

First start auto-loads `docker/wordpress/seed.sql` into MariaDB (WP tables +
WooCommerce + plugin settings). To reset:
`docker compose -f compose.wordpress.yml down -v && make up-wp && make wp-setup`.

Demo catalog: activate the **appin-demo-seeder** plugin
(`wordpress-plugin/appin-demo-seeder`) and seed from wp-admin (Tools → Appinio Demo
Seeder) — it replaces the old `seed-products.php` WP-CLI seeder.

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

- **No Composer autoload in production**: manual `autoload.php` maps `Appinio\` → `src/`
- **Singleton pattern** for `Plugin` (WordPress convention)
- **Unique prefix** (WordPress.org requirement): namespace `Appinio`, constants `APPINIO_*`, options/hooks `appinio_*`. Slug + text domain stay `appinio-search`.
- **Action Scheduler** for debounced sync (5-second coalesce) — WooCommerce saves products multiple times per edit
- **Variable products**: index parent only with min/max variation prices + aggregated attributes
- **Status guard**: only `publish` products indexed; draft/private/trash auto-removed from index
- **Results-page takeover**: standard `pre_get_posts` + `post__in` + `posts_search`-nullify; AI products merged with native non-product matches; graceful native fallback on API failure; gated by `appinio_results_page` (on by default)
- API base URL resolves through the `appinio_api_url` filter — the sole override seam (production default hardcoded in `Api\Client`). The dev harness overrides it via `WP_ENVIRONMENT_TYPE` + a monorepo mu-plugin; no `APPIN*_API_URL` constant is honored. The widget CDN script URL resolves the same way through the `appinio_cdn_url` filter (production default hardcoded in `Frontend\SearchWidget`); no `APPINIO_CDN_URL` constant is honored.

## API Contract

This plugin is an API client for the Appinio API:
- `POST /v1/index/products` — index a product (with `X-Platform: woocommerce`)
- `DELETE /v1/index/products` — remove a product
- `POST /v1/search/products` — results-page takeover (`searchProducts()`, short timeout, no retries)
- `GET /v1/index/counts` — index reconciliation (`Api\Client::getCounts()`): live Qdrant counts + `pending` in-flight jobs + `reconciled` freshness flag + last index status. Consumed by `Sync\RemoteIndexState` (30s transient cache) to power the "Indexed in search" dashboard row + drift badge. Secret key only.
- API Key: `sk_live_...` format, stored in `wp_options` as `appinio_api_key`
- Platform: `woocommerce` (selects `WooCommerceProductData` DTO on the API side)

Field mapping is defined in `Mapper/ProductMapper.php` and must match the WooCommerce platform's expected fields in the API.

## Translations

Hybrid model — **we maintain our own locales, community contributes the rest**:

- **`.po` + `.pot` are source (committed); `.mo` are build artifacts (gitignored).** The compiled
  `.mo` are generated from `.po` during packaging — in the release zip (`release.yml`) and the
  WordPress.org SVN deploy (`deploy-wordpress-org.yml`) — so the shipped bundles never drift from
  source. Bundled `.mo` ship as a **fallback**; since WP 4.6 translate.wordpress.org **language
  packs take priority** over bundled files, so a community pack overrides ours per-locale — no conflict.
- Text domain **must** equal the slug (`appinio-search`) for both bundled loading and language
  packs. No `load_plugin_textdomain()` call — WordPress JIT-loads matching `.mo` (compliant because
  `Requires at least ≥ 4.6`).
- CI (`i18n` job) validates `.po` (`msgfmt -c`), guards that no `.mo` is committed, and smoke-compiles
  each `.po`. When strings change: edit the `.po`; refresh the template with
  `wp i18n make-pot . languages/appinio-search.pot`. For a **local** test build, compile with
  `msgfmt -o languages/<f>.mo languages/<f>.po` (the `.mo` stay untracked — do not commit them).
- **Post-approval runbook** (submitting our locales to the community system — no CI/API path exists
  for this, it is manual): on translate.wordpress.org, open the plugin's translation project →
  "Import Translations" → upload each `.po`; request **PTE** status for the plugin so imports land
  as *Current*; once a locale reaches ≥90% approved, WordPress.org generates and auto-delivers the
  language pack.

## Coding Style

- PHP 8.1+ features: constructor promotion, named arguments, match, readonly
- `declare(strict_types=1)` in every file
- WordPress coding standards for hooks/filters (snake_case function names in callbacks)
- Namespace: `Appinio`
- Brain Monkey for mocking WordPress functions in tests
- Requires: WordPress 6.0+, WooCommerce 8.0+

## Knowledge graph (graphify)

This repo has its own knowledge graph in `graphify-out/` (gitignored, rebuilt locally).

- Codebase questions: run `graphify query "<question>"` **before** grepping — it returns a scoped
  subgraph instead of raw text. Also: `graphify path "<A>" "<B>"`, `graphify explain "<concept>"`,
  `graphify affected "<symbol>"`.
- Cross-repo questions (this repo ↔ api ↔ embeddings ↔ widget): use the merged graph —
  `graphify query "<question>" --graph <api-repo>/graphify-out/merged-graph.json`, or
  `make graph-query q="..."` from the api repo. This repo's tag in the merged graph is `wordpress-search`.
- **Freshness is automatic**: a `graphify watch` daemon rebuilds the graph ~3s after any file save,
  git hooks rebuild it on commit/checkout, and an hourly job refreshes the docs + semantic layer.
  Never hand-rebuild; if in doubt run `make graph-status` in the api repo.
- Never commit `graphify-out/` — it is derived output plus an LLM cache.
