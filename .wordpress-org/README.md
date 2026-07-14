# WordPress.org directory assets

Drop the plugin-directory images here. They are uploaded to the WordPress.org
SVN `assets/` folder by `.github/workflows/deploy-wordpress-org.yml` and are
**excluded from the distributed plugin zip** (see `.gitattributes`).

| File | Dimensions | Status | Purpose |
|---|---|---|---|
| `icon-128x128.png` | 128×128 | ✅ done | Directory icon (brand mark, shared with AppIn Chat) |
| `icon-256x256.png` | 256×256 | ✅ done | Directory icon retina |
| `banner-772x250.png` | 772×250 | ✅ done | Plugin page banner (designed in `design/design.pen`) |
| `banner-1544x500.png` | 1544×500 | ✅ done | Plugin page banner retina |
| `screenshot-1.png` | 2880×2106 | ✅ done (2026-07-14) | **Real capture** — `woo.app-in.io` demo storefront, query "lightweight shelter for two people" returning correctly-ranked tent results with real product photos (matches `== Screenshots ==` #1) |
| `screenshot-2.png` | 2880×2160 | ✅ done (2026-07-14) | **Real capture** — WooCommerce → AppIn Search admin on the local dev stand: current Sync Status panel (Published / Synced (queued) / Indexed in search / Last sync), fully reconciled 240/240/240 (matches #2) |

Screenshots are **real captures** of the running plugin (brand rule: no fake screenshots).
Re-captured 2026-07-14 — the 2026-07-07 originals were stale: screenshot-2 showed the pre-#216
three-row Sync Status panel, and screenshot-1's demo store had placeholder "DEMO"-watermarked
product tiles instead of real photos (see `adr-wp-search-admin-ux-theme-editor.md` for the admin
rebuild this will need re-shooting again for). screenshot-1 was captured with Playwright against
the live `woo.app-in.io` demo store; screenshot-2 with Playwright against the local Docker WP
stand (`woo.app-in.local`) logged in as a local dev admin — production wp-admin is never touched.
Optional: `icon.svg` (vector icon, WordPress.org supports it).
