# ERPNext Shipping for WooCommerce

## Project Overview
Open-source WooCommerce shipping plugin that provides real-time multi-carrier shipping rates with ERPNext stock-based warehouse routing.

**Plugin Slug**: `erpnext-shipping`
**Text Domain**: `erpnext-shipping`
**Class Prefix**: `ES_` (ERPNext Shipping)
**Function Prefix**: `es_`
**Constant Prefix**: `ES_SHIPPING_`
**WC Method ID**: `erpnext_shipping`
**Admin Page Slug**: `es-shipping`
**Option Key Pattern**: `woocommerce_erpnext_shipping_{instance_id}_settings`

## Origin
Forked from CactusCraft Multi-Carrier Shipping (private, production plugin for cactuscraft.co.za). All site-specific data has been removed and replaced with configurable settings.

## Architecture

### N-Location Model
Locations are stored as a JSON array in the WP option `es_shipping_locations`. Each location is an object:
```json
{
  "id": "loc_1",
  "name": "Warehouse A",
  "address": {
    "street": "",
    "suburb": "",
    "city": "",
    "province": "",
    "postcode": ""
  },
  "erp_warehouses": ["Warehouse Name - Company"],
  "slw_term_id": null
}
```

Locations are referenced by `id` throughout the codebase (not by hardcoded keys like `cpt`/`pta`).

### Key Design Decisions
- Locations are fully dynamic (add/remove/edit N locations via admin UI)
- ERPNext warehouse mapping is per-location (N warehouses per location)
- SLW integration is optional — plugin works without it
- Carrier modules self-register via filter (`es_shipping_carriers`)
- All site-specific data lives in settings, never in code
- No hardcoded addresses, warehouse names, term IDs, or instance IDs

### Carriers
Carriers are in `includes/carriers/` and register via the `es_shipping_carriers` filter:
- Ship Logic (The Courier Guy) — South Africa
- MDS Collivery — South Africa
- (Extensible — third-party plugins can add carriers)

### Files
```
erpnext-shipping/
├── erpnext-shipping.php              # Plugin bootstrap
├── includes/
│   ├── class-es-shipping-method.php  # WC_Shipping_Method (main logic)
│   ├── class-es-admin-page.php       # Admin settings page
│   ├── class-es-stock-sync.php       # ERPNext Bin sync + SLW bridge
│   ├── class-es-parcel-estimator.php # Cart → parcel dimensions
│   ├── class-es-rate-cache.php       # Transient-based rate caching
│   ├── class-es-carrier-base.php     # Abstract carrier interface
│   ├── class-es-carrier-shiplogic.php # Ship Logic carrier
│   └── class-es-carrier-collivery.php # Collivery carrier
├── README.md
├── LICENSE
├── CHANGELOG.md
└── CLAUDE.md
```

### Dependencies
- **Required**: WooCommerce 8.0+, WordPress 6.0+, PHP 8.0+, ERPNext (any version with Bin API)
- **Optional**: Stock Locations for WooCommerce (SLW) — enables per-location stock display and customer location selection

## Development Notes
- This is a WooCommerce Shipping Method plugin — it extends `WC_Shipping_Method`
- Settings are stored in the WC shipping method instance options
- Location data is stored separately in `es_shipping_locations` (WP option) because WC settings don't support repeatable field groups
- ERPNext stock is synced via WP Cron (configurable interval, default 15 min) and stored in `es_shipping_warehouse_stock` option
- Rate caching uses WC transients with 15-min TTL, keyed by destination + cart hash
- Carrier API timeouts are 5s each; overall time budget is 20s for rate calculation
- Debug logging writes to WooCommerce logs (`erpnext-shipping-*`)

## Security Checklist
- [ ] No hardcoded credentials or API keys
- [ ] No site-specific URLs, addresses, or warehouse names
- [ ] No hardcoded term IDs or instance IDs
- [ ] All admin forms use nonces and capability checks
- [ ] All user input is sanitized, all output is escaped
- [ ] API credentials stored in WC settings (database), never in code
