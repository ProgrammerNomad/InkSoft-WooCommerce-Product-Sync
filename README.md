# InkSoft → WooCommerce Product Sync Plugin

Automatically sync products from multiple InkSoft stores to WooCommerce with category mapping, image management, price markups, and chunked AJAX-based processing to avoid timeouts.

## Features

✅ **Multi-store support** — Sync products from multiple InkSoft stores simultaneously  
✅ **Chunked AJAX sync** — Process large catalogs in pages without timing out  
✅ **Price markup** — Apply configurable percentage markup to InkSoft base prices  
✅ **Category sync** — Auto-create and link product categories  
✅ **Image management** — Download and cache images; replace existing images on sync  
✅ **SKU-based deduplication** — Update existing products by SKU; no duplicates  
✅ **Missing product deletion** — Automatically delete products that are no longer in InkSoft  
✅ **Daily cron scheduling** — Automatic daily syncs with manual override  
✅ **Admin progress logging** — Real-time sync progress displayed in WordPress admin  

## Installation

1. Download or clone this plugin into `wp-content/plugins/inksoft-woocommerce-sync/`
2. Activate the plugin in WordPress Admin → Plugins
3. Go to **InkSoft Sync** menu (top-level admin page)
4. Enter your InkSoft API key and store URIs
5. Configure sync options (markup, deletion policy, image replacement)
6. Click **Start Sync (AJAX)** to begin or let daily cron run automatically

## Configuration

### API Credentials
- **API Key**: Get from your InkSoft account → API Integrations  
- **Store URIs**: Comma-separated list (e.g., `Devo_Designs,devodesigns,test_store_7150`)

### Sync Options
- **Markup (%)**: Percentage to add to InkSoft base price (e.g., 10 for 10% markup)
- **Page Size**: Number of products per page during sync (default: 100)
- **Enable Variants**: Create WooCommerce variations from InkSoft styles (currently maps first style only)
- **Delete Missing**: Remove products from WooCommerce if they're no longer in InkSoft
- **Replace Images**: Download and overwrite product images on each sync

## How It Works

### Sync Flow
1. **Fetch Products**: API calls `GetProductBaseList` with pagination
2. **Create/Update**: Products matched by SKU; if SKU exists, update; else create new
3. **Categories**: Auto-create categories and link to products
4. **Images**: Download from InkSoft and set as featured image
5. **Delete Missing**: (Optional) Remove products not found in current InkSoft sync
6. **Logging**: Each chunk logged and displayed in admin sync UI

### Pagination
- Uses `Pagination.TotalResults` from API response to determine pages
- Chunks processed one page at a time via AJAX to avoid server timeouts
- Manual or automatic daily cron scheduling available

## Plugin Structure

```
inksoft-woocommerce-sync/
├── inksoft-woocommerce-sync.php    # Main plugin bootstrap
├── admin/
│   └── class-admin.php             # Admin settings page & UI
├── includes/
│   ├── class-inksoft-api.php       # InkSoft API wrapper
│   ├── class-sync-manager.php      # Sync logic & product handling
│   └── class-sync-ajax.php         # AJAX endpoints
├── assets/
│   └── admin.js                    # Admin JS for AJAX orchestration
└── archive/
    └── tests/                      # Archived test scripts (reference)
```

## Requirements

- WordPress 5.0+
- WooCommerce 3.0+
- PHP 7.4+
- Active InkSoft API key

## API Endpoints Used

- `GetStoreData` — Fetch store metadata
- `GetProductBaseList` — Paginated product list
- `GetProductCategories` — Product categories (future)

## Manual AJAX Sync

1. Go to **InkSoft Sync** admin page
2. Verify API key and store URIs are saved
3. Click **Start Sync (AJAX)**
4. Watch the log box for progress
5. When complete, products are created/updated in WooCommerce

## Cron Scheduling

- **Activation**: Daily cron hook registered (`inksoft_woo_sync_daily`)
- **Deactivation**: Cron hook cleared
- **Manual**: Use AJAX button in admin or call `do_action( 'inksoft_woo_sync_daily' )`

## Troubleshooting

**"WooCommerce is not active"** — Ensure WooCommerce is installed and activated  
**"No stores configured"** — Add store URIs to plugin settings  
**"API connection failed"** — Verify API key and store URIs are correct  
**Timeout during sync** — Use AJAX method (chunked processing) instead of direct cron

## Future Enhancements

- Full WooCommerce variations support (style → variation mapping)
- Hierarchical category support
- Background job queue (Action Scheduler)
- Persistent sync history & detailed logs
- Bulk operations (skip/exclude stores)

## License

GPL v2 or later

InkSoft WooCommerce Product Sync
