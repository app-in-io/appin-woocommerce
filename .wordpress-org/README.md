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
| `screenshot-1.png` | 2880×1640 | ✅ done | **Real capture** — AI search widget on the WooCommerce storefront returning semantic results for "shoes" (matches `== Screenshots ==` #1) |
| `screenshot-2.png` | 2160×1470 | ✅ done | **Real capture** — WooCommerce → AppIn Search admin: settings + Sync Status (matches #2) |

Screenshots are **real captures** of the running plugin (brand rule: no fake screenshots),
taken against the plugin's own Docker env with the full search backend (embeddings + Qdrant)
running and the demo shop's products indexed. screenshot-1 was captured with Playwright driving
the storefront search; screenshot-2 with headless Chrome on the settings page.
Optional: `icon.svg` (vector icon, WordPress.org supports it).
