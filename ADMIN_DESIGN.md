# InkSoft WooCommerce Sync - Admin Settings Design

## Current Findings
- **Store Access:** Each store has a unique URI (Devo_Designs, devodesigns, JKRenewables, etc.)
- **API Key:** Single API key works for all stores
- **Product Discovery:** No API endpoint that lists all stores automatically
- **Total Products:** 507 across 7 stores found via manual testing

---

## Option 1: MANUAL STORE CONFIGURATION (Simplest)
User manually enters store URIs in admin

### Admin Settings Page
```
InkSoft WooCommerce Sync Settings
════════════════════════════════════

API Configuration
┌─ API Key: [53cb30be-e0b3-4888-bbd1-213216c31b21________] 
│  (Get from: stores.inksoft.com/YourStore/api-integrations)
└─ [Test Connection] Button

Store List Configuration
┌─ ☑ Enable Auto-Discovery (try to find stores via API)
│  [Refresh Store List] Button
│
└─ Manual Store Entry:
   │ Store 1: [Devo_Designs___________]  ✓ 224 products
   │ Store 2: [devodesigns__________]   ✓ 99 products
   │ Store 3: [JKRenewables________]    ✓ 23 products
   │ [+ Add More Store]
   │
   └─ Selected Stores to Sync:
      ☑ Devo Designs1 (224 products)
      ☑ Devo Designs (99 products)
      ☑ JK Renewables (23 products)
      ☐ McOmber McOmber & Luber (94 products)
      ☐ Saint Paul (43 products)
      ☐ Parts Life Inc. (1 product)
      ☐ Test Store (23 products)

Sync Options
┌─ Product Data to Sync:
│  ☑ Product Name
│  ☑ Product Description
│  ☑ Product Price
│  ☑ Product Images
│  ☑ Product Categories
│  ☑ Product SKU
│  ☑ Product Attributes
│
├─ Store Organization:
│  ◉ Create WooCommerce category per store
│  ○ Add store name as product tag
│  ○ Store only as product meta
│
├─ Duplicate Handling:
│  ○ Use SKU to detect duplicates
│  ○ Use Product Name to detect duplicates
│  ◉ Sync all (allow duplicates)
│
└─ Sync Schedule:
   ◉ Daily at: [02:00 AM _________]
   ○ Manual only (use [Sync Now] button)

[Save Settings] [Sync Now] [Test Connection]
```

---

## Option 2: SEMI-AUTOMATIC (Better UX)
Admin enters one store URI, plugin discovers sub-stores

**Problem:** InkSoft API doesn't have an endpoint to list sub-stores
**Solution:** Keep Option 1, but provide helpful pre-filled list

---

## API Endpoints Needed

### 1. **GetStoreData** (Used for verification)
```
GET /Api2/GetStoreData?Format=JSON
Headers: x-api-key: YOUR_KEY
Response:
{
  "Data": {
    "StoreId": 456162,
    "Name": "Devo Designs1",
    "StoreUri": "Devo_Designs",
    "IsMainStore": true,
    "PublisherId": 19910,
    ...
  }
}
```
**Purpose:** Verify store exists and get metadata

---

### 2. **GetProductBaseList** (Main product fetching)
```
GET /Api2/GetProductBaseList?Format=JSON&Page=0&PageSize=100
Headers: x-api-key: YOUR_KEY
Response:
{
  "Data": [
    {
      "ID": 1003990,
      "Name": "Port Authority Cyber Backpack",
      "Sku": "ABC123",
      "Active": true,
      "Manufacturer": "Port Authority",
      "ManufacturerSku": "BPK25",
      "Supplier": "JDS Industries",
      "SupplierId": 1000029,
      "Categories": null,
      "LongDescription": "Description here",
      "Images": [...],
      "Styles": [...],
      "Sides": [...]
    },
    ...
  ],
  "Pagination": {
    "TotalResults": 224,
    "IncludedResults": 100,
    "Index": 0
  }
}
```
**Purpose:** Get all products from a store with pagination

---

### 3. **GetProductDetails** (Do we need this?)
```
GET /Api2/GetProduct?ID=1003990&Format=JSON
Headers: x-api-key: YOUR_KEY
```
**Questions:**
- Does GetProductBaseList return all details we need?
- Or do we need GetProduct endpoint for full details?
- Do we need variant/size/color information?

---

### 4. **GetProductCategories** (For category sync)
```
GET /Api2/GetProductCategories?Format=JSON
Headers: x-api-key: YOUR_KEY
Response:
{
  "Data": [
    {
      "ID": 12345,
      "Name": "T-Shirts",
      "ParentID": 0,
      "Active": true
    },
    ...
  ]
}
```
**Purpose:** Get category structure for organizing WooCommerce products

---

## What WooCommerce Product Should Store

```php
// Meta fields for each synced product
_inksoft_product_id       → 1003990
_inksoft_store_uri        → "Devo_Designs"
_inksoft_product_name     → "Port Authority Cyber Backpack"
_inksoft_sku             → "ABC123"
_inksoft_manufacturer    → "Port Authority"
_inksoft_supplier        → "JDS Industries"
_inksoft_last_sync       → "2025-12-04 14:30:00"
_inksoft_sync_status     → "synced" / "error"
```

---

## Database Table for Store Configuration

```sql
CREATE TABLE wp_inksoft_stores (
  id INT AUTO_INCREMENT PRIMARY KEY,
  store_uri VARCHAR(100) NOT NULL UNIQUE,
  store_name VARCHAR(255),
  product_count INT,
  enabled TINYINT(1) DEFAULT 1,
  last_sync DATETIME,
  sync_status VARCHAR(50),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Example data:
INSERT INTO wp_inksoft_stores VALUES
(1, 'Devo_Designs', 'Devo Designs1', 224, 1, '2025-12-04 14:30:00', 'synced', NOW()),
(2, 'devodesigns', 'Devo Designs', 99, 1, '2025-12-04 14:30:00', 'synced', NOW()),
(3, 'JKRenewables', 'JK Renewables', 23, 1, NULL, 'pending', NOW());
```

---

## Sync Flow

```
1. User clicks [Sync Now] or cron runs daily
2. For each enabled store:
   a. Call GetStoreData to verify access
   b. Call GetProductCategories to get categories
   c. Create/update WooCommerce categories
   d. Call GetProductBaseList with pagination:
      - Page 0: Get first 100 products + TotalResults
      - Calculate total pages needed
      - Fetch remaining pages
      - For each product:
        * Check if exists in WooCommerce (by SKU or _inksoft_product_id)
        * Create new or update existing
        * Set images, categories, metadata
        * Handle duplicates per settings
   e. Update last_sync timestamp
3. Display sync results (X products created, Y updated, Z errors)
```

---

## Questions for User Confirmation

1. **Store Discovery:**
   - Option A: Manual entry (user enters URI for each store) ← SIMPLEST
   - Option B: Try auto-discovery, fallback to manual
   - Option C: Something else?

2. **API Endpoints:**
   - Do we need GetProductDetails endpoint? Or is GetProductBaseList enough?
   - Do we need product images in full resolution?
   - Do we need variant/style/color information?

3. **Product Matching:**
   - Match by SKU (if duplicate SKU found in WooCommerce)?
   - Match by InkSoft Product ID?
   - Allow products to be added from multiple stores (duplicates)?

4. **Category Handling:**
   - Create category per store (e.g., "Devo Designs1 → T-Shirts")?
   - Create flat category structure?
   - Use store name as tag instead?

5. **Sync Scope:**
   - Sync all 507 products every day?
   - Or only new/modified products?
   - How to detect if product changed on InkSoft side?

