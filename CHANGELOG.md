# Changelog

All notable changes to this project will be documented in this file.

## [1.1.0] - 2026-02-26

### Added
- **Smart free shipping filter** (`woocommerce_package_rates`): removes free shipping for split shipments to protect margins, hides cheapest carrier rate when free shipping is available so customers see free + premium options.
- **Split shipment notice**: customers are informed when free shipping isn't available due to multi-warehouse fulfillment.
- **Cart location auto-update**: changing the SLW warehouse dropdown on the cart page automatically recalculates shipping rates.
- **Continue Shopping referrer**: "Continue Shopping" button returns customers to their last browsed shop/category/product page instead of the default shop page.
- **Self-healing cron**: re-schedules stock sync on admin page load if the WP-Cron event went missing.
- **Future locker compatibility**: rate filter skips `_locker` suffix rates, ready for TCG Locker integration.

### Changed
- **Free shipping no longer returns early** in `calculate_shipping()`: carrier rates are always fetched alongside free shipping, giving customers the choice of premium (faster) shipping even when free is available. The `woocommerce_package_rates` filter handles which rates to display.

## [1.0.2] - 2025-02-15

### Fixed
- **WP-Cron stock sync**: Removed singleton pattern dependency in WP-Cron callback. Now queries `wp_options` directly to find all plugin instances, ensuring reliable auto-sync every 15 minutes regardless of hook execution context.
- **Rate cache collision**: Fixed cache key building to include both city AND postcode instead of postcode only. Prevents different cities with the same postcode from sharing cached rates (e.g., Johannesburg 2000 vs Pretoria 0002 both sharing "2000" postcode).
- **Error logging**: Added ERROR/WARNING prefixes to WP-Cron error logs for easier filtering. Success operations are silent to avoid log spam.

### Technical Details
- WP-Cron callback pattern documented in global CLAUDE.md for reference
- Cache key now uses: `md5( origin_code|origin_city _ dest_code|dest_city _ parcel_hash )`
- All instances are processed in a single cron run with proper error handling per instance

## [1.0.0] - 2025-02-11

Initial release. Forked from CactusCraft Multi-Carrier Shipping and generalized for any WooCommerce + ERPNext setup.

### Added
- N-location support: configure unlimited dispatch locations via admin UI
- Dynamic fulfillment routing: single, cheapest-of-many, or split-shipment
- The Courier Guy (Ship Logic) carrier integration
- MDS Collivery carrier integration
- ERPNext Bin API stock sync with WP Cron (15-min interval)
- Stock Locations for WooCommerce (SLW) integration (optional)
- Tiered rate display: Economy, Standard, Express
- Configurable markup (percentage or flat), free shipping threshold, flat rate fallback
- Transient-based rate caching (15-min TTL, location-aware keys)
- Admin settings page with dynamic location card UI
- Debug logging to WooCommerce logs

### Changed (from CactusCraft source)
- Renamed all prefixes: CC_ to ES_, cactuscraft to erpnext
- Replaced hardcoded 2-location (CPT/PTA) model with dynamic N-location system
- Removed all site-specific addresses, warehouse names, term IDs, and credentials
- Location data stored in dedicated WP option (`es_shipping_locations`) instead of WC instance fields
- Fulfillment plan structure generalized: `chooseable`/`single`/`split` with location IDs
- Cache keys now include location ID for correctness
- Company name passed to Ship Logic API from settings instead of hardcoded value
