## Buyer Homepage Feature Scope (Phase 1)

### 1) Recommended for you
- **What appears**: up to 6 active/in-stock products ranked by recent order activity and freshness.
- **When shown**: on home load after section fetch finishes.
- **Tap behavior**: tapping a card opens add-to-cart flow via product tile tap.
- **States**
  - Loading: spinner
  - Empty: `No recommendations yet.`
  - Error: `Could not load recommendations. Pull to refresh or tap Retry.`

### 2) Nearby stores
- **What appears**: up to 6 sellers ordered by nearest distance from buyer location/default address.
- **When shown**: after nearby section fetch succeeds with coordinates.
- **Tap behavior**: opens `BuyerStorePage` using a sample product from that seller.
- **States**
  - Loading: spinner
  - Empty: `No nearby stores found yet.`
  - Error: `Could not load nearby stores.`

### 3) Flash deals
- **What appears**: up to 6 active deals from `homepage_flash_deals` table; falls back to generated deals if table has no rows.
- **When shown**: after flash section fetch.
- **Tap behavior**: opens add-to-cart flow for deal product.
- **States**
  - Loading: spinner
  - Empty: `No flash deals yet.`
  - Error: `Could not load flash deals.`

## Backend/Data Requirements
- New endpoint: `api/buyer/homepage.php` with `section` query (`recommended|nearby|flash|all`).
- Optional new table: `homepage_flash_deals` for dynamic deal management.
- Nearby section needs valid seller and buyer coordinates; falls back to empty list when not available.

## Non-destructive Safety
- Existing `products/list.php` and current home sections remain untouched.
- New homepage logic only adds endpoint/table and app-side section rendering.
