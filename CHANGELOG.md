# Changelog

All notable changes to this project will be documented in this file.

## [1.12.7] - 2026-06-04

### Fixed
- **WP-Cron stock sync silently dead since v1.12.0.** The scheduled stock sync loaded `ES_Stock_Sync` directly (WP-Cron does not fire `woocommerce_shipping_init`) but not its `ES_ERPNext_Client` dependency, so every cron run fatalled with `Class "ES_ERPNext_Client" not found` — an `Error`, not caught by the cron loop's `catch (Exception)`. Stock levels only updated when an admin manually triggered a sync. `ES_Stock_Sync` now loads the client itself, so cron, AJAX, and any other caller work regardless of context.
- **Admin: "Serviced by" checkboxes rendered as full-width bars** in the dispatch-location editor (the card's `input { width:100% }` rule bled onto the checkboxes). Pinned them to `width:auto` with a flex label layout.

## [1.12.6] - 2026-06-04

### Added
- **Pickup feasibility gate (multi-branch carts).** Local Pickup now only offers pickup locations whose supplying warehouse(s) hold the *entire* cart. If a cart spans branches that no single pickup point can supply, the pickup option shows a prominent notice and checkout is blocked for pickup — preventing free cross-branch stock transfers. Delivery is unaffected (it still quotes split shipments).
- **"Serviced by warehouse(s)" mapping on Collection Points.** Each collection point can declare which warehouse location(s) supply it (e.g. a satellite pickup point served from a central warehouse). Feasibility is checked against those warehouses' per-location stock (`_stock_at_<term>`). Items with no per-location stock data (fees/services) are ignored. **Collection points with no `serviced_by` configured do not offer pickup until mapped.**

## [1.12.5] - 2026-05-29

### Added
- **HPOS compatibility declaration.** Declares `custom_order_tables` compatibility via `before_woocommerce_init` / `FeaturesUtil::declare_compatibility()`. The plugin already used HPOS-safe order APIs; this stops WooCommerce 10.x flagging it as "incompatible" on Settings → Advanced → Features and keeps High-Performance Order Storage enabled. Cart/Checkout Blocks compatibility is intentionally **not** declared — the pickup-location selector uses classic checkout hooks and would not render in the Checkout block.
- **Plugin header compatibility tags**: `Requires at least: 6.0`, `Tested up to: 7.0`, `Requires PHP: 8.0`, `WC requires at least: 8.0`, `WC tested up to: 10.8` — removes "Untested with your version" warnings on WP 7.0 / WC 10.8.

## [1.12.4] - 2026-05-16

### Added
- **Manual quote distance bands** for Own Vehicle Delivery. In banded mode, use `quote` as the delivery fee (for example `100+ = quote`) to show a dedicated quote-required checkout rate instead of hiding the method.
- **Quote Rate Label** setting for the manual quote band label shown at checkout.

## [1.12.3] - 2026-05-16

### Added
- **Banded own-vehicle delivery pricing**. Own Vehicle Delivery can now use distance bands with a fixed delivery fee and per-band free-delivery threshold, e.g. `0-50 = 350 | 5000`.
- **Distance basis setting** for own-vehicle pricing. Keep one-way distance behaviour for existing setups, or price/match bands on round-trip distance with a configurable multiplier.

## [1.12.2] - 2026-05-16

### Added
- **Own Vehicle Delivery rate** for wholesale/bulk orders. Default off per shipping-method instance, so retail sites keep existing courier behaviour unless enabled.
- Distance-based pricing at a configurable Rand/km rate, with optional free-delivery threshold, maximum distance, postcode/city distance rules, and optional Google Distance Matrix lookup with 12-hour caching.

## [1.12.1] - 2026-05-15

### Fixed
- **Cron lock leak on exception.** `ES_Fulfillment_Cron::poll()` now wraps its body in `try/finally`, so the `es_poll_lock` transient is released even if `wc_get_orders()` or a per-order poll throws. Previously, an unhandled exception would block the next cron cycle and the Diagnostics "Run now" button for 60s.
- **Diagnostics "Force stock sync" was a no-op.** Was firing the wrong action name (`es_shipping_stock_sync_cron`); the registered hook is `es_shipping_stock_sync`. Now triggers correctly.
- **Collivery quote log gap.** Town-resolution failures returned before `ES_Quote_Log::record()` was called, so the Diagnostics rate-quote panel hid those attempts. Now records the failure with the unresolved city names in the error column.
- **Row actions did nothing useful.** "Re-poll Courier" and "Force ERPNext Sync" row actions on the order list previously redirected the user to the meta box without performing the action (because the row-action API can't carry POST nonces). Now they target a real `admin-post.php` endpoint with per-action nonces, perform the work server-side, and surface a result via admin notice on return.

## [1.12.0] - 2026-05-15

### Added
- **"Open in ERPNext" button** on the order edit screen. Renders inside a new "ERPNext" meta box (HPOS + legacy CPT). URL pattern: `{erp_url}/app/sales-order/WEB1-{padded order id}`. Only shown when ERPNext URL is configured.
- **"Re-poll Courier" action.** Triggers an immediate cron-poll-equivalent for one order via AJAX. Available on the order edit screen (meta-box button) and as a bulk action on the order list (cap: 25 orders/submit, 200ms sleep between polls to stay below carrier API rate ceilings).
- **"Force ERPNext Sync" action.** Calls fusion's `run_sales_order_sync` RPC for one order. Per-order transient lock (30s TTL) prevents concurrent duplicate sync attempts.
- **ERP Sync indicator column** on the WooCommerce order list (HPOS + legacy CPT). Shows ✓ Synced / ⚠ Drift / ✗ Missing / — Unknown per order. Batched ERPNext API call per page render, results cached per-order in 5-min transients. Falls back silently if ERPNext is unreachable.
- **Diagnostics tab** (`?page=es-shipping&tab=diagnostics`). Five panels:
  - **Connectivity**: ERPNext + carrier last-success timestamps, "Test" button
  - **Recent Rate Quotes**: last 20 quotes with latency and result
  - **Courier Polling**: last cycle summary (orders processed, errors, per-provider success/failure counts) + "Run poll now" button
  - **Recent ERPNext Sync Errors**: last 20 fusion-related entries from ERPNext Error Log, each linking to the full entry
  - **Cache & Maintenance**: clear rate-quote log, flush sync-state transients, force stock sync
- `ES_ERPNext_Client` helper class — shared HTTP wrapper around the ERPNext REST API, reads creds from existing `erp_url`/`erp_api_key`/`erp_api_secret` shipping-method options.
- `ES_Quote_Log` — fixed-size circular buffer (50 entries) of carrier rate quotes for the Diagnostics tab.
- Cron poll summary captured in `es_last_poll_summary` option at the end of every `ES_Fulfillment_Cron::poll()` run.
- Cron mutex via `es_poll_lock` transient (60s TTL) — prevents the WP-cron run and the Diagnostics "Run poll now" button from colliding.

### Changed
- `ES_Stock_Sync` refactored to use the new shared `ES_ERPNext_Client`. Behaviour unchanged (same endpoint, same 30s timeout, same data shape).
- `ES_Fulfillment_Cron::poll()` per-order body extracted into `poll_single_order()` so the manual Re-poll action and the cron path share one implementation.
- Carrier classes (`ES_Carrier_ShipLogic`, `ES_Carrier_Collivery`) record each rate-quote attempt into `ES_Quote_Log` (timestamp, carrier slug, masked destination postcode, parcel count, response time, success/error).

## [1.11.7] - 2026-05-15

### Changed
- `normalize_provider()` now treats `-`, `_`, and whitespace as equivalent separators when matching display names and aliases. Defensive coverage for input variants like `courier_guy`, `the_courier_guy`, `mds_collivery` that fall outside the canonical slug + explicit alias list. The fast-path exact-slug check is unchanged, so canonical values still self-match without going through the fuzzy comparison.

## [1.11.6] - 2026-05-15

### Fixed
- **Tracking provider normalization.** Provider values stored from woocommerce_fusion (which echoes back display names like `"The Courier Guy"` rather than slugs) now route correctly through courier polling and tracking-URL lookup. Previously silent: any tracking added via Fusion's "Edit Shipment Trackings" dialog would store the display name verbatim, causing cron polling to skip the order and tracking links to render empty.
- **Order-list provider filter** is now alias-aware. Legacy stored values (`collivery`, `the-courier-guy-sa`, mixed-case display names) appear in the filtered list when the corresponding provider is selected.

### Changed
- Single ingestion chokepoint: `ES_Fulfillment_Tracking::create_tracking_item()` normalizes the provider value once, so REST, admin AJAX, and any future caller all store canonical slugs.
- `$providers` array restructured: legacy slugs (`collivery`, `the-courier-guy-sa`) moved from top-level keys to per-provider `aliases` lists. AST-Pro REST response format unchanged for third-party compatibility.

## [1.11.5] - 2026-05-12

### Changed
- **Courier Guy API base URL**: updated from `api.shiplogic.com` to `api.portal.thecourierguy.co.za` per carrier deprecation notice (old URL sunsets 2026-05-20). Affects rate quotes and tracking poll endpoints.
- **Rebranded "Ship Logic" → "The Courier Guy"** in all user-facing strings (admin settings labels, descriptions) and code comments. Internal class names retained for compatibility. Option keys (`tcg_*`) unchanged.

## [1.11.1] - 2026-03-29

### Fixed
- **High: Warehouse role over-privileged.** Removed `manage_woocommerce` capability from warehouse staff. Uses custom `es_fulfillment_actions` capability instead. Packing slip and status update handlers accept either capability. Warehouse staff restricted to orders page only (not all admin.php pages). Role restrictions now run in both migration and active mode.
- **Medium: Legacy provider aliases not polled by cron.** Orders with `collivery` or `the-courier-guy-sa` tracking entries (from AST Pro migration) now correctly trigger courier API polling.
- **Medium: Assignment accepts any user ID.** AJAX handler now validates assignee against allowed roles (administrator, shop_manager, warehouse_staff). Crafted requests with subscriber/customer IDs are rejected.

## [1.11.0] - 2026-03-29

### Added
- **Order Assignment**: assign orders to staff members from the order detail page. "Assigned to" dropdown with all admin/shop manager/warehouse staff users. Email notification sent to assignee with order details and customer note. "Assigned to" column on order list. Filter by assignee dropdown.
- **Warehouse Staff role**: custom WordPress role for warehouse/fulfillment staff. Can view orders, print packing slips, and use fulfillment action buttons. Cannot edit products, settings, coupons, plugins, or manage users. Admin menu restricted to WooCommerce Orders only. Redirected to orders page on login. Admin bar hidden on frontend.

## [1.10.5] - 2026-03-26

### Added
- **Product addon fields on packing slip** — custom product options (e.g. "Milled - Standard Crush", "Mix grains: No") now display below each line item. Only shows when addons exist.

## [1.10.4] - 2026-03-25

### Fixed
- **Critical: Delivery orders incorrectly entering pickup flow.** Session-leaked pickup location was saved to order meta even when customer chose a delivery shipping method. `maybe_set_processing_lp` now only triggers when the order's shipping method is `local_pickup` — never from pickup meta alone. `save_pickup_location` clears session and skips saving when shipping method is not local pickup.

## [1.10.3] - 2026-03-25

### Added
- **Customer Note column** on order list — shows truncated note (50 chars) with full text on hover. Placed after Shipping Method column.
- **Print Packing Slip button on order detail page** — full-width button in the Shipment Tracking meta box, opens print page in new tab.

## [1.10.2] - 2026-03-25

### Fixed
- **High: Stock sync restore path** — products previously marked out-of-stock by the sync now have their WC core stock quantity and status restored when ERPNext stock comes back.
- **High: Pickup location validation** — checkout, AJAX session save, and admin-side pickup location save now validate the location ID against configured pickup-enabled locations. Invalid IDs are rejected.
- **Medium: SKU-less products** — products without SKUs no longer collapse into the same empty-key stock lookup. Each gets a unique `_pid_{ID}` fallback key for fulfillment routing.
- **Medium: Multi-instance admin page** — settings page now supports multiple WooCommerce shipping zone instances. If more than one instance exists, an instance selector appears at the top of the page.
- **Low: Watchdog cron on mode switch** — switching between Migration and Active mode in admin now immediately schedules/clears the daily watchdog cron alongside the 15-minute polling cron.

## [1.10.1] - 2026-03-25

### Added
- **Pickup location on order detail**: shows pickup location name + address below the shipping section for pickup orders.
- **Pickup location on order list**: tracking column displays the pickup location for pickup orders (instead of "–").
- **Print Packing Slip**: new quick action button on order list opens a clean, print-friendly packing slip with customer notes, SKUs, quantities, weights. Shows pickup location for pickup orders or shipping address for delivery orders. Auto-triggers print dialog.

## [1.10.0] - 2026-03-24

### Added
- **Order Watchdog**: daily cron monitors for stuck orders across all non-terminal statuses. Sends admin digest email listing all stuck orders with configurable thresholds per status.
- **Customer Pickup Reminders**: automated emails at configurable intervals (default 3 and 10 days) after an order enters Ready for Pickup. Uses WooCommerce email template system (customizable via WooCommerce Settings > Emails > Pickup Reminder).
- **Courier API failure tracking**: consecutive poll failures per order are tracked via `_es_poll_fail_count` meta. Watchdog alerts admin when threshold is exceeded. Counter resets on successful poll.
- **Alerts settings section**: new admin UI section with per-status enable/disable toggles and configurable day thresholds.
- New WC email class: `ES_Email_Pickup_Reminder` with HTML and plain text templates (theme-overridable).

### Changed
- Cron polling now records API failures and successes for watchdog integration.

## [1.9.1] - 2026-03-23

### Fixed
- Partially Shipped and Delivered emails no longer show tracking info twice (template + hook duplication removed).

## [1.9.0] - 2026-03-23

### Changed
- **Email template refactor**: all 6 fulfillment emails now use WooCommerce's `wc_get_template_html()` template system. Templates live in `templates/emails/` (HTML) and `templates/emails/plain/` (plain text). Users can override them by copying to `yourtheme/woocommerce/emails/`. Subject lines and headings are customizable via WooCommerce Settings > Emails.

## [1.8.2] - 2026-03-23

### Fixed
- Clearing the pickup dropdown back to blank now clears the WC session value (previously the old selection persisted silently).
- Cart to checkout pickup location persistence — AJAX save now works on cart page (was only working on checkout due to missing JS variable).
- Admin location cards show the pickup address customers will see, with a warning for multi-warehouse locations.

## [1.8.0] - 2026-03-23

### Added
- **Dispatched to Pickup status**: new step in the pickup flow between Processing LP and Ready for Pickup. Includes email notification with pickup location details and customer message.
- **Pickup flow**: Processing LP → Dispatched to Pickup → Ready for Pickup → Picked Up.

## [1.7.1] - 2026-03-23

### Fixed
- Courier status on My Account order view moved from per-shipment table rows to a single line below the table (status is order-level, not per-shipment).

## [1.7.0] - 2026-03-23

### Added
- **My Account tracking**: Shipment Tracking table on customer order detail page (carrier, clickable tracking number, ship date, status). Tracking column on orders list with carrier + waybill link.

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
