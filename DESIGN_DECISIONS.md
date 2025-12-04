# InkSoft WooCommerce Sync Plugin - Data Design

## 1. API DATA AVAILABLE FROM InkSoft

### Per Product from GetProductBaseList:

```
BASIC INFO:
- ID: 1003990 (InkSoft Product ID)
- Name: "Port Authority Cyber Backpack"
- Sku: "BG200"
- Active: true/false
- ProductType: "Standard" | "SignBanner" etc
- Created: "2025-12-03T11:35:39.693Z"

MANUFACTURER:
- Manufacturer: "Port Authority"
- ManufacturerSku: "BG200"
- ManufacturerId: 1000004
- ManufacturerBrandImageUrl: ""

SUPPLIER:
- Supplier: "Sanmar"
- SupplierId: 1000020

PRICING:
- Styles[].Price: 36.92 (base price)
- SalePrice: null (if on sale)
- UnitPrice: null (alternate pricing)
- UnitCost: null (cost for markup calculation)

DECORATION OPTIONS:
- CanScreenPrint: true
- CanDigitalPrint: true
- CanEmbroider: false
- CanPrint: true

DESCRIPTIONS & KEYWORDS:
- LongDescription: null (no long description available)
- Keywords: null (no keywords)

IMAGES:
- Styles[].ImageFilePath_Front: "/images/products/756/products/BG200/Black_Red/front/500.png"
- Styles[].ThumbnailImageFilePath: "/images/products/756/products/BG200/Black_Red/front/150.png"
- Styles[].Sides[].ImageFilePath: "/images/products/756/products/BG200/Black_Red/front/500.png"

VARIANTS (Styles):
- Styles[].Name: "Black Red" (color/style option)
- Styles[].Price: 36.92 (price for this style)
- Styles[].HtmlColor1: "030000" (color code)
- Styles[].HtmlColor2: "9C0812" (secondary color)

SIZES, CATEGORIES, ETC:
- Categories: null (not available in GetProductBaseList)
- Sides[]: available sides (front, back, sleeve, etc)
- Personalizations: [] (customization options)

TAXES:
- TaxExempt: false

MISSING DATA:
- LongDescription: Always null
- Categories: Always null in list endpoint
- StoreIds: Always null (doesn't show which stores have it)
```

---

## 2. WHAT WE NEED TO STORE IN WOOCOMMERCE

### Main Product Meta Fields:

```php
// InkSoft Source Data
_inksoft_product_id       = 1003990                    // Primary reference
_inksoft_store_uri        = "Devo_Designs"             // Which store it's from
_inksoft_store_name       = "Devo Designs1"            // Human readable store
_inksoft_sku             = "BG200"                     // Original SKU
_inksoft_manufacturer    = "Port Authority"
_inksoft_supplier        = "Sanmar"
_inksoft_product_type    = "Standard"

// Sync Tracking
_inksoft_last_sync       = "2025-12-04 14:30:00"       // When last synced
_inksoft_sync_status     = "synced" / "error"          // Status of sync
_inksoft_api_created     = "2025-12-03T11:35:39.693Z"  // When created in InkSoft

// Capabilities
_inksoft_can_print       = ["screen_print", "digital_print"]  // What's possible
_inksoft_has_product_art = false

// Source URL
_inksoft_source_url      = "https://stores.inksoft.com/Devo_Designs/..."

// Original API Response (backup)
_inksoft_api_data        = json_encoded full response (for reference)
```

### WooCommerce Native Fields:

```php
$product->set_name('Port Authority Cyber Backpack');
$product->set_regular_price(36.92);
$product->set_sku('BG200');
$product->set_description('Manufacturer: Port Authority | Supplier: Sanmar');
$product->set_manage_stock(false);  // InkSoft doesn't provide inventory
$product->set_stock_status('instock');  // Since InkSoft shows it
```

---

## 3. HANDLING VARIANTS (Colors/Styles)

InkSoft has "Styles" which are variations. Each style has:
- Name: "Black Red" (variant name)
- Price: 36.92 (variant-specific price)
- ImageFilePath_Front (image for this style)

### Option A: Create WooCommerce Variations (RECOMMENDED)
```
Parent Product: "Port Authority Cyber Backpack"
├─ Variation: Black Red ($36.92) with image
├─ Variation: Navy Blue ($36.92) with image
└─ Variation: White ($36.92) with image

Variation meta:
_inksoft_style_id = 1006035
_inksoft_style_name = "Black Red"
_inksoft_color_code = "#030000" (HtmlColor1)
```

### Option B: Create Separate Products (Simple)
```
Product 1: "Port Authority Cyber Backpack - Black Red" SKU: BG200-BLACK-RED
Product 2: "Port Authority Cyber Backpack - Navy Blue" SKU: BG200-NAVY-BLUE
Product 3: "Port Authority Cyber Backpack - White" SKU: BG200-WHITE
```

**DECISION NEEDED:** Use variations or separate products?

---

## 4. HANDLING IMAGES

### Image Storage Strategy:

```php
// Download and store images locally
- Source: https://stores.inksoft.com{ImageFilePath}
- Download: Use WordPress media library
- Attachment Meta:
  _inksoft_image_source = original URL
  _inksoft_style_id = if from variant

// Example for each style:
$image_url = "https://stores.inksoft.com/images/products/756/products/BG200/Black_Red/front/500.png";
// Download and attach to product
// Set as product gallery image

// If variant:
// - Main image goes to variation
// - Also added to product gallery
```

### Image Handling:

```php
function download_inksoft_image($image_path, $product_id, $attachment_title) {
    $full_url = "https://stores.inksoft.com" . $image_path;
    
    // Use WordPress media_sideload_image or similar
    $attachment_id = media_sideload_image($full_url, $product_id, $attachment_title, 'id');
    
    // Store original URL in meta
    add_post_meta($attachment_id, '_inksoft_image_source', $full_url);
    
    return $attachment_id;
}
```

**ISSUES TO HANDLE:**
- Image already exists? Check by URL hash
- Broken image links? Retry with fallback
- Storage quota? Compress images?

---

## 5. HANDLING CATEGORIES

### Problem:
- GetProductBaseList returns `Categories: null` always
- Need GetProductCategories endpoint instead
- May need separate API call per store

### Solution:

```php
// Get categories per store
$categories_url = "https://stores.inksoft.com/{$store_uri}/Api2/GetProductCategories?Format=JSON";

// Returns:
{
  "ID": 12345,
  "Name": "T-Shirts",
  "ParentID": 0,
  "Active": true
}

// In WooCommerce:
// Option A: Replicate InkSoft category structure
//   - Create WooCommerce category "T-Shirts"
//   - Assign products to it
//   - Store: _inksoft_category_id = 12345

// Option B: Create per-store categories
//   - "Devo Designs - T-Shirts"
//   - "Devo Designs - Hoodies"
//   - "Test Store - Backpacks"

// Option C: Use product tags instead
//   - Tag: "T-Shirts"
//   - Tag: "Devo Designs" (store name)
```

**DECISION NEEDED:** How organize categories?

---

## 6. HANDLING 20% MARKUP STORES

Two stores have markups:
- Saint Paul: 20% markup
- Parts Life Inc: 20% markup

### Implementation:

```php
// Store configuration
$stores_config = [
    'saintpaul' => [
        'name' => 'Saint Paul',
        'markup_percent' => 20,
        'category' => 'Saint Paul Store'
    ],
    'partslife' => [
        'name' => 'Parts Life Inc',
        'markup_percent' => 20,
        'category' => 'Parts Life Store'
    ]
];

// When syncing product with markup:
$base_price = 36.92;
$markup_percent = 20;
$final_price = $base_price * (1 + $markup_percent / 100);  // 44.30

// Store in product meta
_inksoft_base_price = 36.92
_inksoft_markup_percent = 20
_inksoft_final_price = 44.30

// But what about WooCommerce price?
// Option A: Set WooCommerce price to FINAL (with markup)
// Option B: Set WooCommerce price to BASE, use margin in product
// Option C: Create separate products per store

// If syncing same product from multiple stores:
// "Port Authority Backpack - Saint Paul" (44.30 with markup)
// "Port Authority Backpack - Devo Designs" (36.92 no markup)
```

**DECISION NEEDED:** How handle markup pricing?

---

## 7. DUPLICATE PRODUCTS (Same product in multiple stores)

Same InkSoft product might exist in multiple stores:
- Store A: 225 products
- Store B: 100 products
- **Overlap: Some products in both?**

### Solutions:

**Option A: Skip duplicates (by SKU)**
```php
// If SKU "BG200" already exists in WooCommerce, skip
// Mark as already synced
```

**Option B: Create per-store variants**
```php
// Same product, synced separately
// Product 1: "Port Authority - Devo Designs" (36.92)
// Product 2: "Port Authority - Saint Paul" (44.30) [with markup]
// 
// Mark with store names/tags so you know the source
```

**Option C: Update existing, add store meta**
```php
// If SKU exists:
// - Update price to highest/lowest/average
// - Add store to _inksoft_stores meta array
// - Track which stores sell this
```

**DECISION NEEDED:** How handle products in multiple stores?

---

## 8. DELETE PRODUCTS THAT NO LONGER EXIST

### When syncing:
- InkSoft shows 225 products today
- Tomorrow only 220 (5 deleted)

### Options:

**Option A: Auto-delete WooCommerce products**
```php
// If product no longer in InkSoft -> delete WooCommerce product
// RISKY: User might have sold them
```

**Option B: Archive/Disable**
```php
// Set post_status = 'draft'
// Or set _inksoft_sync_status = 'archived'
// Keep in database but hidden
```

**Option C: Mark as out of stock**
```php
// Set status to 'outofstock'
// Keep visible but can't buy
```

**DECISION NEEDED:** Delete or archive missing products?

---

## 9. SYNC TRACKING & LOGGING

### Database logging:

```sql
CREATE TABLE wp_inksoft_sync_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    store_uri VARCHAR(100),
    sync_date DATETIME,
    products_synced INT,
    products_created INT,
    products_updated INT,
    products_failed INT,
    images_downloaded INT,
    sync_status VARCHAR(50),
    error_message TEXT,
    execution_time INT  -- seconds
);
```

### Per-product tracking:

```php
_inksoft_last_sync = "2025-12-04 14:30:00"
_inksoft_sync_status = "synced" | "error" | "skipped"
_inksoft_sync_error = "Failed to download image" (if error)
```

---

## 10. API CALLS NEEDED

```php
// Per store sync:
1. GetProductCategories       // Get store's categories
2. GetProductBaseList         // Get products (with pagination)
   - Loop: Page 0, 1, 2... until TotalResults reached
3. For each style image:
   - Download image to WordPress media library

// Total API calls per store:
- 1 call for categories
- N calls for products (1 per 100 products)
- M calls for images (1 per image download)

// For 507 total products across 7 stores:
- 7 category calls
- ~6 product list calls (507/100 ≈ 5-6)
- ~100+ image calls (multiple per product)
```

---

## SUMMARY OF DECISIONS NEEDED:

1. **Variants:** Create WooCommerce variations or separate products?
2. **Categories:** Replicate structure, per-store, or use tags?
3. **Markups:** Apply to price or separate products per store?
4. **Duplicates:** Skip by SKU or create per-store versions?
5. **Delete:** Auto-delete missing products or archive?
6. **Images:** Download and store locally? Compression?
7. **Schedule:** Daily sync? Manual only? What time?
8. **Fallback:** If product fails to sync, retry later?
9. **Logging:** Keep detailed sync logs? How long?
10. **Conflicts:** If price changes in InkSoft, update WooCommerce?

---

## MINIMAL VIABLE PRODUCT (Start with this):

```php
// Simplest implementation:
1. Create simple WooCommerce product per InkSoft product
2. No variations (skip styles, use first style price)
3. Use first image only (no multi-image gallery)
4. Categories: flat structure or use tags
5. Price: Include markup if store has it
6. Duplicates: Skip if SKU exists
7. No deletion: Archive missing products
8. Manual sync: User clicks "Sync Now"
9. Basic logging: Last sync time per store
10. Images: Download to media library, with deduplication
```

This gives you 500+ products in WooCommerce without complex logic.
Once working, add advanced features like variations, multi-image, etc.

