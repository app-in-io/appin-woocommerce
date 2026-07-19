# Changelog

All notable changes to this project will be documented in this file.

Format based on [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

## [0.9.0] - 2026-07-19 (re-cut)

> The `v0.9.0` tag was **re-cut again on 2026-07-19** to carry the WordPress.org review response
> (review `R appinio-search/appinio/18Jul26/T2 19Jul26/4.1`) into the tag — the release zip is
> built from the tag ref (#40). The reviewer's sole remaining issue was **bundled translation
> files**: the directory generates and delivers translations via translate.wordpress.org, so a
> hosted plugin must not ship its own. Both build paths drop the compile-and-bundle step, so the
> zip now carries only the `.pot` template. This **supersedes the 2026-07-18 re-cut's decision**
> to keep shipping `.mo` as a fallback. Details under **Changed** / **Removed**.
>
> The `v0.9.0` tag was **re-cut again on 2026-07-18** to carry the WordPress.org pre-review
> response (review `AUTOPREREVIEW …/18Jul26/T1`) into the tag — the release zip is built from the
> tag ref (#39). Two changes: the `Requires Plugins: woocommerce` header (WordPress checks
> WooCommerce is active before activation), and compiled translations (`languages/*.mo`) become
> build artifacts — no longer committed, compiled from `.po` during packaging so the bundled
> fallback can't drift from source. The shipped zip's `.mo` are byte-identical to the previously
> committed ones. Details under **Changed** / **Removed**. The remaining pre-review item (short
> slug `appinio-search`) is handled at upload, not in code.
>
> The `v0.9.0` tag was **re-cut again on 2026-07-17** to carry a pre-submission rebrand into the
> tag itself. The sibling **appin-chat** plugin was pended by the WordPress.org directory three
> times over "generic prefix": their tooling splits CamelCase on capitals, so `AppInIo` reads as
> `app_in_io`, whose first fragment is the flagged common word `app`. Before submitting this plugin
> we move onto a single unsplittable prefix — the PHP namespace `AppInIo` → `Appinio` (root segment
> only) and the display name "AppIn Search" → "Appinio Search" — mirroring appin-chat's fix. The
> slug and text domain (`appinio-search`) and every `appinio_*` option/hook were already compliant
> and are unchanged, so stored settings survive the upgrade untouched. Details under **Changed**.
>
> The `v0.9.0` tag was **re-cut again on 2026-07-14** to carry WordPress.org submission-readiness
> work into the tag itself — the WordPress.org SVN deploy is dispatched from a tag ref, so work
> living only on `main` would not reach it. This re-cut adds Plugin Check to CI (which immediately
> found 3 real errors, fixed below) and replaces both directory screenshots, which had gone stale.
>
> The `v0.9.0` tag was **re-cut again on 2026-07-13** to carry the `.distignore` fix below into the
> tag itself — the WordPress.org SVN deploy is dispatched from a tag ref, so a fix that only exists
> on `main` would not reach it. The distributed zip is byte-for-byte unaffected (it is built with
> `git archive`, which never contained `.git`); this re-cut exists solely so that the first SVN
> deploy cannot leak the repository.
>
> The `v0.9.0` tag was previously **re-cut on 2026-07-12** over the original 2026-07-07 release: the beta had
> not been distributed beyond the demo store, so the version number was reused rather than burned.
> Everything below shipped under the same `0.9.0`. **Breaking for anyone running the 2026-07-07
> build:** the WordPress.org review required a vendor prefix, so the plugin file, namespace, options
> and hooks were renamed (`appin-search.php` → `appinio-search.php`, `AppIn\WooCommerce` → `AppInIo`,
> `appinio_*` options/hooks) with no back-compat shim — WordPress sees a different plugin, and the
> old build's settings (API key, public key, widget config) are not carried over. Reinstall and
> re-enter the keys.

### Changed
- **Translations are no longer bundled in the distributed plugin** (#40). WordPress.org delivers
  them as language packs from translate.wordpress.org and the directory review rejects shipping
  `.po`/`.mo` in the zip, so both the `release.yml` zip and the `deploy-wordpress-org.yml` SVN
  deploy dropped their compile-and-bundle step and now carry only the `.pot` template.
  `.github/scripts/compile-translations.sh` is kept for the CI i18n smoke-test only.
- ~~**Compiled translations (`languages/*.mo`) are now build artifacts** (#39)~~ — **superseded by
  #40 above**: `.mo` are no longer bundled at all. The `.mo`-are-not-committed part still holds;
  the "shipped as a fallback" part does not. Original entry, for the record:
- **Compiled translations (`languages/*.mo`) are now build artifacts** (#39), no longer committed
  to the repo. They are compiled from the `.po` sources during packaging — in the GitHub release
  zip (`release.yml`) and the WordPress.org SVN deploy (`deploy-wordpress-org.yml`), via the shared
  `.github/scripts/compile-translations.sh` (`msgfmt -c` + `nullglob` guard) — so the shipped
  bundles are always in sync with source and can't drift. `.po` + `.pot` remain committed as source.
  Bundled `.mo` still ship as a fallback; community locales arrive via translate.wordpress.org
  language packs, which take priority over bundled files since WP 4.6.

### Removed
- **Compiled `.mo` from every distribution channel** (#40) — GitHub Release zip, R2 CDN zip and
  WP.org SVN. `/languages/*.mo` added to `.gitattributes` (`export-ignore`) and `.distignore` as a
  guard. Trade-off: direct installs from the R2 CDN zip get English until a translation is supplied
  — WordPress.org language packs auto-deliver only to installs from the directory.
- **`languages/*.mo` from version control** (#39) — no longer committed (they are also no longer
  packaged; see #40 above).

### Fixed
- **Unescaped exception message (3 × Plugin Check ERROR).** `ProductSync::handleFailure()` threw
  `RuntimeException` with `$action` / `$productId` / `$status` interpolated. WPCS treats an
  exception message as output (`WordPress.Security.EscapeOutput.ExceptionNotEscaped`); the message
  in fact goes to Action Scheduler's log, not to a browser, but Plugin Check reports it as an
  ERROR and it would have stalled the review. Now wrapped in `esc_html()`.
- **`$_POST` read without `wp_unslash()` in the registration flow.** `appinio_reg_consent` was
  compared raw, and `appinio_reg_code` was regex-stripped without unslashing first — while the
  email and name fields three lines away were doing it correctly. Both now go through
  `sanitize_text_field(wp_unslash(...))`.
- **Plugin name mismatch.** The `readme.txt` title (keyword-rich, deliberate) and the
  `Plugin Name:` header disagreed, which Plugin Check flags. The header now carries the full
  name, so the directory title and the plugin header agree without giving up the listing keywords.
- **`.distignore` would have published the entire git history to WordPress.org.** The file listed
  every dev path except `/.git`, on the assumption that it merely mirrors `.gitattributes` — where
  `/.git` is genuinely unnecessary, because `git archive` cannot emit it. But the presence of a
  `.distignore` *switches* 10up's `deploy.sh` off the git-archive path entirely:
  `.distignore` present → `rsync -rc --exclude-from=.distignore "$GITHUB_WORKSPACE/" trunk/`;
  absent → `git archive HEAD | tar x`. The rsync path copies the raw `actions/checkout` workspace,
  in which `.git` is an ordinary directory that nothing excludes. The first SVN deploy would
  therefore have committed the full repository — all history, all branches — into the WordPress.org
  trunk, and shipped it inside every zip downloaded from the directory. `/.git` is now the first
  entry. Not reachable before this fix only because `deploy-wordpress-org.yml` is `workflow_dispatch`-only
  pending directory approval. Same gap was found and fixed in the sibling appin-chat plugin
  (app-in-io/appin-chat#10), which is where this one was spotted.
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
- **`Requires Plugins: woocommerce` header** (#39) — WordPress verifies WooCommerce is active
  before activating the plugin (WP 6.5+; ignored on older cores). Addresses the WordPress.org
  auto-pre-review "Requires Plugins" note.
- **CI `i18n` job** (#39, `.github/workflows/test.yml`) — validates every `languages/*.po`
  (`msgfmt -c`), guards that no `.mo` is committed, and smoke-compiles each `.po` to prove the
  release build works.
- **WordPress Plugin Check in CI** — the same checks the WordPress.org directory runs on
  submission, against the `git archive` dist tree (the artifact users actually receive), not the
  raw checkout. The sibling appin-chat plugin was pended twice by a human reviewer for things a
  machine could have caught; this plugin is next in the submission queue and will meet the same
  reviewer. Its first run found the three exception/sanitization/name issues above.
- **Re-captured WordPress.org directory screenshots** (`.wordpress-org/screenshot-1.png`,
  `screenshot-2.png`) — the 2026-07-07 originals were stale: screenshot-2 showed the pre-#216
  three-row Sync Status panel (current one has four: Published / Synced (queued) / Indexed in
  search / Last sync), and screenshot-1's demo store (`woo.app-in.io`) had placeholder
  "DEMO"-watermarked product tiles instead of real photos. Both are real captures — screenshot-2
  against the local Docker WP stand, fully reconciled at 240/240/240. screenshot-1 shows the
  actual `<semantic-search>` widget modal open with live results (not the post-submit `?s=`
  results page — a first pass got that wrong), which is what `== Screenshots ==` #1 in
  `readme.txt` describes.
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
- **Rebrand to a single unsplittable prefix (WordPress.org, pre-submission)**: the PHP namespace
  root `AppInIo` → `Appinio` (autoloader, `composer.json` PSR-4 maps, bootstrap, every `src/` and
  `tests/` file), and the display name "AppIn Search" → "Appinio Search" (plugin header, admin
  strings, `readme.txt`/`README.md`, all `languages/` catalogs recompiled). Mirrors appin-chat's
  rename: the directory's tooling splits `AppInIo` into `app_in_io` and flags the common word
  `app`; `Appinio` cannot be split. The slug and text domain `appinio-search`, the
  `woocommerce_page_appinio-search` enqueue guard, the `appinio-search-widget` handle, the
  `appinio_search` settings group, and every `appinio_*` option/hook are already compliant and are
  **unchanged** — stored settings (API key, public key, widget config) survive the upgrade.
- **Justified `phpcs:ignore` for four false positives**, each with the reason inline: the
  `error_log()` calls are already gated behind `WP_DEBUG` and are the only diagnostic channel a
  store owner has; `IndexState`'s direct `COUNT` *is* cached, just in a transient rather than
  `wp_cache_*`; the nonce *is* verified, inside `authorize()`, which PHPCS cannot follow into; and
  WPML's `wpml_*` filters are hooks WPML owns and names — a plugin cannot prefix them with its own
  slug without them never firing.
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

## [0.9.0] - 2026-07-07 (superseded by the 2026-07-12 re-cut above)

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
