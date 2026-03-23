# Changelog

All notable changes to this project will be documented in this file.

## [1.6.1] - 2026-03-23

### Fixed
- Legacy provider slugs (`collivery`, `the-courier-guy-sa`) from AST Pro now render with correct display names and clickable tracking links in emails.

## [1.6.0] - 2026-03-23

### Added
- **Tracking info in emails**: Shipment tracking section injected into Shipped, Partially Shipped, Delivered, and Invoice customer emails. Shows carrier name, clickable tracking number (links to carrier tracking page), and ship date. Both HTML and plain text formats.

## [1.5.3] - 2026-03-23

### Fixed
- PHP syntax error in meta box — missing `?>` close tag before template block caused fatal on activation.
- Ampersand in modal button text ("Add & Ship") rendered as `&amp;` — changed to "Add + Ship".

## [1.5.2] - 2026-03-23

### Fixed
- PHP fatal error on plugin activation — meta box template had `<?php` inside PHP code block.

## [1.5.1] - 2026-03-23

### Fixed
- Pickup status button URL in meta box was broken — nonce and new_status were concatenated instead of separate parameters.
- Customer message field now renders in the Ready for Pickup email (HTML and plain text).

### Changed
- Updated CLAUDE.md, README.md with full fulfillment module documentation.

## [1.5.0] - 2026-03-23

### Added
- **Fulfillment module**: replaces AST Pro, TrackShip, and Zorem Local Pickup Pro with built-in tracking, courier polling, and pickup management.
- **Custom order statuses**: Partially Shipped, Delivered, Processing LP, Ready For Pickup, Picked Up. Registered with identical slugs for safe migration from old plugins.
- **Two-mode operation**: Migration mode (statuses only, safe alongside old plugins) and Active mode (full fulfillment).
- **AST-compatible REST API**: tracking endpoints under both `wc/v3` and `wc-shipment-tracking/v3` namespaces for woocommerce_fusion compatibility. Includes GET/POST/DELETE for tracking items and GET for providers list.
- **Courier tracking cron**: polls TCG (ShipLogic API v2) and MDS (Collivery API v3) every 15 minutes. Forward-only status updates, min-status across parcels for multi-parcel orders.
- **Order list columns**: Shipping Method, Shipment Tracking (carrier + waybill + date), Shipment Status (live courier status with colored dots).
- **Flow-aware action buttons**: Processing → Mark as Shipped + Add Tracking; Processing LP → Ready for Pickup; Ready for Pickup → Picked Up. WC core "Complete" action removed.
- **Quick tracking modal**: inline popup on order list with carrier dropdown + tracking number. Includes shipping note field and waybill validation with carrier mismatch warning.
- **Filter dropdowns**: filter orders by shipping provider and by shipment status.
- **Meta box flow separation**: delivery orders show tracking UI; pickup orders show pickup location + status action buttons.
- **Checkout pickup location selector**: dropdown appears when customer selects Local Pickup, shows address and customer message, validates selection, saves to order meta, auto-sets Processing LP status.
- **Collection point location type**: pickup-only locations without ERPNext warehouse mapping. Excluded from rate calculations and stock sync.
- **Customer message field**: per-location message shown at checkout and in Ready for Pickup email.
- **Fulfillment email classes**: Processing LP, Ready For Pickup, Picked Up, Partially Shipped, Delivered.
- **Pickup location resolver**: checks plugin meta first, falls back to Zorem Local Pickup Pro meta for in-flight orders, migrates on first access.

### Fixed
- Pickup status changes blocked server-side when no pickup location is set (prevents silent email failure).
- Empty tracking meta cleaned up on last entry deletion (fixes NOT EXISTS filters and cron polling).

## [1.1.0] - 2026-02-26

### Added
- **Smart free shipping filter** (`woocommerce_package_rates`): removes free shipping for split shipments to protect margins, hides cheapest carrier rate when free shipping is available so customers see free + premium options.
- **Free Shipping Source setting**: choose between WC's native Free Shipping zone method (recommended for CommerceKit/theme integration) or the plugin's built-in threshold. Defaults to "WC method".
- **No Free Shipping Classes**: comma-separated shipping class slugs (e.g. "heavy") — orders containing items in these classes don't get free shipping, with a customer-facing notice.
- **Split shipment notice**: customers are informed when free shipping isn't available due to multi-warehouse fulfillment.
- **Cart location auto-update**: changing the SLW warehouse dropdown on the cart page automatically recalculates shipping rates.
- **Continue Shopping referrer**: "Continue Shopping" button returns customers to their last browsed shop/category/product page instead of the default shop page.
- **Self-healing cron**: re-schedules stock sync on admin page load if the WP-Cron event went missing.
- **Future locker compatibility**: rate filter skips `_locker` suffix rates, ready for TCG Locker integration.

### Changed
- **Free shipping in `calculate_shipping()`**: when source is "plugin", carrier rates are still fetched alongside free shipping so the `woocommerce_package_rates` filter can offer premium options. When source is "WC method", the plugin only calculates carrier rates.

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
