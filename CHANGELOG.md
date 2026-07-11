# Changelog

All notable changes to this project will be documented in this file.

Format based on [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

### Fixed
- **HTML entities in product text decoded before indexing**: WordPress stores taxonomy term names
  HTML-entity-encoded (e.g. `Drinkware &amp; Bottles`), so category, tag, brand, attribute and
  title/content text carried the escaped literal into the search index and the widget rendered
  `&amp;` to shoppers. These fields are now decoded before indexing, so names like "Drinkware &
  Bottles" display correctly in the search widget. Product descriptions are cleaned in the correct
  order — strip the real HTML markup first, then decode entities, then remove any tag-like sequences
  the decode revealed — so entity-encoded markup (e.g. `&lt;div&gt;`) is stripped out of the indexed
  text while a legitimate encoded comparison operator (e.g. `Rated to &lt; -5°C`) survives intact
  instead of being eaten as an unclosed tag.
- **Sync Status dashboard never updated live**: the polling script was printed inline after
  `jquery-core` in the page `<head>`, so it ran before the dashboard existed in the DOM and bailed
  immediately — no live updates ever fired. Reworked onto the WordPress **Heartbeat API** (the admin
  heartbeat already ticks on this screen): the server attaches the sync/reconciliation data to the
  heartbeat response and the dashboard updates on `heartbeat-tick`. The beat speeds up to ~5s while a
  sync is in flight and eases back when it settles. Removes the bespoke `setInterval` poller and its
  custom admin-ajax action entirely.

### Added
- **Sync reconciliation & live indexing progress**: the Sync Status dashboard now shows two
  distinct numbers — **Synced (queued)** (what the plugin has sent for indexing) and **Indexed
  in search** (how many products actually landed in the AI search index, read live from the
  backend). Once indexing settles, if fewer products are indexed than were queued — or the last
  index run failed — a drift warning appears prompting a retry, so async indexing failures are no
  longer invisible. While a sync is still in flight the warning is suppressed (the index legitimately
  lags the queue), and the "Indexed in search" figure updates live showing how many index jobs remain
  ("Indexing… (N pending)"), converging on its own once the queue drains. The real counts are cached
  for 30 seconds, so live polling never hammers the API, and the backend count shows a dash ("—")
  rather than a misleading number whenever the search service is unavailable or its counts are stale.
- **Multilingual support (WPML / Polylang)**: products are now tagged with their language,
  and search is scoped to the visitor's language, so a multilingual store no longer returns
  mixed-language duplicates of the same product. Bulk "Sync All" and "Delete All" now cover
  **every** language (previously only the site's default language was indexed); the search
  results page (`/?s=`) and the search widget both pass the visitor's current language. Single-
  language stores are unaffected — no language is sent and behaviour is unchanged. Language is
  detected automatically from WPML or Polylang; no configuration needed.
- **readme "Terms and privacy" now links the full legal set**: the WooCommerce Service Terms
  (product schedule) at `https://app-in.io/woocommerce/eula` and the GDPR Data Processing
  Agreement at `https://app-in.io/dpa`, alongside the existing Terms of Service and Privacy
  Policy links.
- **Settings shortcut on the Plugins screen**: the plugin's row now shows a **Settings** link
  (next to Deactivate) that opens the AppIn Search settings page directly.
- **In-plugin self-serve registration**: create your AppIn account and get your keys straight
  from the plugin — no dashboard round-trip. Enter your email and store name, receive a
  6-digit code by email, enter it, and the secret + public keys are provisioned and saved
  automatically. The code entry supports one-tap autofill (`autocomplete="one-time-code"`),
  the resend button has a cooldown countdown that survives a page reload, and a "Change email
  / start over" link lets you edit details. Email/store-name are sent to AppIn; the store URL
  and admin language are derived server-side. Pasting an existing API key still works.
- **Required policy consent at registration**: the "Connect your store" form now has a required
  checkbox — you must agree to the Terms of Service, Privacy Policy, Data Processing Agreement and
  WooCommerce Service Terms (linked inline) before a code is sent. Enforced in the browser
  (HTML5 `required`) and server-side in the request-OTP handler.
- **Widget Appearance setting** (Light / Dark / Auto) under Search Widget: the search widget now
  honors an explicit theme. **Auto** matches the store's background (measured client-side) — there is
  no standard WordPress light/dark flag, so choosing the theme is the plugin's job, not the widget's.
- **Explicit "Show all results" URL**: the plugin now passes a permalink-aware, canonical search URL
  (built via `get_search_link()`, works with both plain `?s=` and pretty `/search/term/` permalinks)
  to the widget instead of relying on its generic default. Keeps the widget's "Show all results" link
  consistent with the AI-powered results-page takeover.
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
- **API base URL resolves through the `appinio_api_url` filter**: the plugin now passes a
  hardcoded production default (baked into `Api\Client`) through a WordPress filter, making that
  hook the **sole** override seam (the dev harness registers it to target local infrastructure per
  environment). The `APPINIO_API_URL` constant has been **removed** — it is no longer defined or
  honored anywhere.
- **Widget CDN URL resolves through the `appinio_cdn_url` filter**: the search widget script URL now
  passes a hardcoded production default (baked into `Frontend\SearchWidget`) through a WordPress
  filter — the same override seam as the API URL, used by the dev harness to target the local Vite
  dev server. The `APPINIO_CDN_URL` constant has been **removed**. Also removed three dead constants
  that had no consumers: `APPINIO_VERSION` (the plugin header + `readme.txt` carry the version, and
  the enqueue intentionally passes `null` to avoid a `?ver` query), `APPINIO_PLUGIN_DIR` and
  `APPINIO_PLUGIN_URL`. The last plugin `define()`, `APPINIO_PLUGIN_FILE`, is also **removed** — the
  plugin file path and basename now come from `Plugin::file()` / `Plugin::basename()` (recorded when
  `boot()` runs), leaving the plugin with no global constants of its own.
- **Unique prefix (WordPress.org requirement)**: renamed the plugin's namespace
  (`AppIn\WooCommerce` → `AppInIo`), constants (`APPIN_*` → `APPINIO_*`), option keys and hooks
  (`appin_*` → `appinio_*`) to a distinct 4+ character prefix. The plugin slug and text domain
  (`appinio-search`) are unchanged.
- readme.txt: WP.org listing SEO/GEO pass — keyworded title, optimized tags, multilingual
  differentiator surfaced, GEO fact block, comparison-intent FAQ.
- **"Visit plugin site" link** (Plugins screen) now points to the WooCommerce landing page
  (`https://app-in.io/woocommerce`) instead of the generic product site.

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
