# AppIn Search for WooCommerce

Sync WooCommerce products with [AppIn AI Search](https://app-in.io) automatically. Real-time hooks on create/update/delete + bulk sync.

## Requirements

- WordPress 6.0+
- WooCommerce 8.0+
- PHP 8.1+
- AppIn API key ([get one here](https://my.app-in.io))

## Installation

1. Download the plugin zip or clone this repo into `wp-content/plugins/appinio-search/`
2. Activate in WordPress Admin > Plugins
3. Go to WooCommerce > AppIn Search
4. Enter your API key and save

## Features

### Real-Time Sync

Products are automatically synced when:
- Created or updated
- Trashed or restored
- Stock changes

Hooks are debounced via Action Scheduler (5-second coalesce) to avoid duplicate API calls from WooCommerce's multiple-save architecture.

### Bulk Sync

- **Sync All Products** — indexes all published products in background batches
- **Delete All from Index** — removes everything from AppIn index

### Field Mapping

| WooCommerce | AppIn |
|---|---|
| Product name | `title` |
| Description + short description | `content` |
| Price (effective) | `price` |
| Regular price (when on sale) | `compare_at_price` |
| Store currency | `currency` |
| Featured image | `image_url` |
| Stock status | `in_stock` |
| SKU | `sku` |
| Categories (deepest) | `category` |
| Tags | `tags` |
| Average rating | `rating` |
| Review count | `reviews_count` |
| Product attributes | `attributes` |
| `pa_brand` taxonomy or "Brand" attribute | `brand` |

### Variable Products

Parent product is indexed with:
- **Price** = minimum variation price
- **In stock** = true if any variation is in stock
- **Attributes** = all options from all variations

Variations are not indexed separately.

### Status Guard

Only `publish` products are indexed. Draft, pending, or private products are automatically removed from the index.

## Configuration

### Settings (WooCommerce > AppIn Search)

- **API Key** — your `sk_live_...` key from AppIn dashboard
- **Auto Sync** — toggle real-time hooks on/off

### Local Development

Override the API URL in `wp-config.php`:

```php
define('APPINIO_API_URL', 'http://api.search.local/v1');
```

## Architecture

```
appinio-search.php          Bootstrap, WooCommerce dependency check
autoload.php              PSR-4 autoloader (no Composer required)
src/
  Plugin.php              Main class, singleton, boot sequence
  Api/Client.php          HTTP client (wp_remote_request)
  Mapper/ProductMapper.php WC_Product → API payload
  Sync/ProductSync.php    Real-time hooks + Action Scheduler debounce
  Sync/BulkSync.php       Background bulk sync/delete
  Admin/SettingsPage.php  WP admin settings + sync dashboard
  Frontend/SearchWidget.php   Search dropdown widget (<semantic-search>)
  Frontend/SearchResults.php  AI-powered /?s= results page
```

Namespace: `AppInIo`

## License

GPL-2.0-or-later
