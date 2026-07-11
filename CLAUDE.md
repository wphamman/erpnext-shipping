# ERPNext Shipping for WooCommerce

## Project Overview
WooCommerce shipping plugin with real-time multi-carrier rates, ERPNext stock-based warehouse routing, and integrated order fulfillment (tracking, courier polling, pickup management).

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
  "type": "warehouse",
  "street": "",
  "suburb": "",
  "city": "",
  "province": "",
  "postcode": "",
  "country": "ZA",
  "erp_warehouses": ["Warehouse Name - Company"],
  "slw_term_id": null,
  "pickup_enabled": true,
  "customer_message": "Allow 3 business days for delivery to this location"
}
```

**Location types:**
- `warehouse` — ships orders, needs ERPNext warehouse mapping, optionally available for pickup
- `collection_point` — pickup only, no warehouse mapping needed, always pickup-enabled, excluded from rate calculations and stock sync. Optional `serviced_by` (array of warehouse location IDs) declares which warehouse(s) supply this point; used by the checkout pickup feasibility gate. If empty, the point offers no pickup until configured.

Locations are referenced by `id` throughout the codebase (not by hardcoded keys like `cpt`/`pta`).

### Fulfillment Module
The fulfillment module replaces AST Pro, TrackShip, and Zorem Local Pickup Pro. It has two modes controlled by the `es_fulfillment_mode` WP option:

- **`migration`** (default) — Only registers custom order statuses. Safe to run alongside the old plugins.
- **`active`** — Full fulfillment: tracking, emails, courier polling, admin UI, checkout pickup selector.

**Custom order statuses (registered in both modes):**
| Status Slug | Label | Flow |
|---|---|---|
| `partially-shipped` | Partially Shipped | Delivery |
| `delivered` | Delivered | Delivery |
| `processing-lp` | Processing LP | Pickup |
| `ready-pickup` | Ready For Pickup | Pickup |
| `pickup` | Picked Up | Pickup |

WC core `completed` is relabeled to "Shipped" in the status dropdown.

**Tracking data format (AST-compatible):**
- Meta key: `_wc_shipment_tracking_items`
- REST endpoints registered under both `wc/v3` and `wc-shipment-tracking/v3` namespaces
- Includes GET/POST/DELETE for tracking items + GET for providers list
- Permission checks support both cookie auth and WC API key auth (Basic Auth)

### Key Design Decisions
- Locations are fully dynamic (add/remove/edit N locations via admin UI)
- Two location types: warehouse (ships + optional pickup) and collection_point (pickup only)
- ERPNext warehouse mapping is per-location (N warehouses per location)
- SLW integration is optional — plugin works without it
- Carrier modules self-register via filter (`es_shipping_carriers`)
- All site-specific data lives in settings, never in code
- No hardcoded addresses, warehouse names, term IDs, or instance IDs
- Migration-safe: identical status slugs prevent mass email on plugin switchover
- REST routes under both `wc/v3` and `wc-shipment-tracking/v3` for woocommerce_fusion compatibility

### Files
```
erpnext-shipping/
├── erpnext-shipping.php                    # Plugin bootstrap, cron schedule, fulfillment init
├── includes/
│   ├── class-es-shipping-method.php        # WC_Shipping_Method (rate calculation)
│   ├── class-es-admin-page.php             # Admin settings page + location UI
│   ├── class-es-stock-sync.php             # ERPNext Bin sync + fulfillment planning
│   ├── class-es-parcel-estimator.php       # Cart → parcel dimensions/weight
│   ├── class-es-rate-cache.php             # Transient-based rate caching
│   ├── class-es-carrier-base.php           # Abstract carrier interface
│   ├── class-es-carrier-shiplogic.php      # The Courier Guy API
│   ├── class-es-carrier-collivery.php      # MDS Collivery (API v3)
│   ├── class-es-carrier-booking.php        # Pure door-booking snapshot/state/confirmation contract
│   ├── class-es-door-booking-client.php    # Injectable TCG/MDS create + native PDF clients
│   ├── class-es-carrier-booking-admin.php  # Unified order UI, two-stage booking, mutex + PDF proxy
│   ├── class-es-fulfillment-statuses.php   # Custom order statuses + pickup resolver
│   ├── class-es-fulfillment-tracking.php   # Tracking meta + AST-compatible REST API
│   ├── class-es-fulfillment-admin.php      # Order list columns, actions, meta box, modal
│   ├── class-es-fulfillment-cron.php       # Courier polling cron (TCG + MDS + TCG Locker)
│   ├── class-es-fulfillment-checkout.php   # Checkout pickup location selector
│   ├── class-es-tcg-locker-client.php      # TCG Locker (PUDO) API client (injectable HTTP seam)
│   ├── class-es-tcg-locker-packer.php      # Locker parcel packer (spatial coexistence)
│   ├── class-es-tcg-locker-rate.php        # Pure L2L pricing / dispatch-origin / rate meta
│   ├── class-es-tcg-locker-booking.php     # Pure booking guard / drift / result classification
│   ├── class-es-tcg-locker-tracking.php    # Pure forward-only tracking status map
│   ├── class-es-tcg-locker-checkout.php    # Classic-checkout locker search/select
│   ├── class-es-tcg-locker-admin.php       # Locker order panel: manual booking + label proxy
│   ├── class-es-email-partially-shipped.php
│   ├── class-es-email-order-delivered.php
│   ├── class-es-email-processing-lp.php
│   ├── class-es-email-ready-pickup.php
│   ├── class-es-email-picked-up.php
│   ├── class-es-email-pickup-reminder.php  # Automated pickup collection reminders
│   └── class-es-fulfillment-watchdog.php   # Daily stale order alerts + pickup reminders
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
- Fulfillment module loads on `init` (not `woocommerce_shipping_init`) so statuses register early
- Email classes load via `woocommerce_email_classes` filter (active mode only)
- Checkout pickup class registers AJAX handlers for both logged-in and guest users
- Quick tracking modal uses a generic nonce (not per-order) since it operates from the order list
- Waybill validation: TCG = alphanumeric, Collivery = 7-digit numeric
- Pickup status changes are blocked server-side if no pickup location is set
- Email tracking: hooks `woocommerce_email_order_details` to inject tracking into WC core emails (Shipped, Invoice). Our own emails (Partially Shipped, Delivered) render tracking inline via templates to avoid duplication.
- Email templates: all 6 fulfillment emails use `wc_get_template_html()` with files in `templates/emails/`. Override via `yourtheme/woocommerce/emails/`. Subject and heading customizable in WC Settings > Emails.
- Legacy provider aliases: `collivery` and `the-courier-guy-sa` map to the same tracking URLs as their modern equivalents
- Pickup flow: Processing LP → Dispatched to Pickup → Ready for Pickup → Picked Up (4 steps)
- Watchdog: daily cron (`es_fulfillment_watchdog`) checks for stale orders and sends pickup reminders. Settings stored in `es_watchdog_settings` WP option. Tracks consecutive API failures via `_es_poll_fail_count` order meta. Pickup reminder tracking via `_es_pickup_reminder_sent` order meta (0=none, 1=first, 2=second).
- **TCG Locker (PUDO) L2L**: default-disabled; resolves settings from the FIRST ES instance with `tcg_locker_enabled=yes` (global, via `es_tcg_locker_settings()` / `es_tcg_locker_client()`). Contract authority = the published sandbox Postman collection (`docs/tcg-locker-architecture.md`); sandbox base `https://sandbox.api-pudo.co.za/api/v1`, Bearer auth; label URLs sit OUTSIDE `/api/v1` (origin = base minus `/api/v1`). **L2L only** (collection is `type:locker`, never a source terminal). Pure/testable logic (`rate`, `packer`, `booking`, `tracking`) has a no-WP harness — run `php tests/run.php`. Checkout selector on `woocommerce_review_order_after_shipping`; rate id ends `_locker`; quote snapshot (`_es_tcg_locker_*`) persists onto the order shipping item via WC's rate `meta_data` copy. **Booking is manual, admin-only, idempotent, and never automatic** — capability + per-order nonce + atomic ownership-token mutex (immutable lease) + durable duplicate/in-progress guard + fresh cache-bypass drift refusal + ambiguous-timeout state. Booking outputs: order meta `_es_tcg_locker_booking_status` (`none|booking|booked|ambiguous|error`), `_es_tcg_locker_shipment_id`, `_es_tcg_locker_tracking_ref`, `_es_tcg_locker_booked_ts`. Tracking poll is forward-only (§12 map), authenticated, using the plugin's own token (NOT the Courier-Guy portal token). Rollback = set `tcg_locker_enabled=no`. This is a SINGLE gate: `es_tcg_locker_client()` returns null before reading the token, so disabling turns off new quotes, the Book action AND authenticated tracking at once — keeping the token does NOT keep polling alive. Booked orders still get *selected* by the poll (keyed on `booking_status=booked`/shipment_id, not the flag), so a disabled client false-fails those polls into the watchdog until they're terminal. **Preferred: disable only once all booked locker orders are terminal (Delivered)** — then it's clean; otherwise track in-flight shipments via the TCG portal and expect watchdog noise. Credentials persist and can't be cleared from the UI (blank submit preserves them). Full removal = deactivate the plugin (default-disabled). No data destroyed. (A "stop sales but keep tracking" mode would need a separate offer-at-checkout flag — a feature change, not a rollback.) Full design + status vocabulary in `docs/tcg-locker-architecture.md`; sandbox test steps in `docs/tcg-locker-uat.md`.
- **Door carrier booking (v1.14)**: new single-origin TCG/MDS rates persist an `_es_carrier_*` order-shipping-item snapshot including the exact provider booking service. The unified *Carrier Fulfilment* sidebar runs a read-only live-cost check, persists a five-minute confirmation bound to current order facts, then requires a second explicit booking action. Booking uses a durable in-progress state + atomic option mutex; ambiguous responses never auto-retry. MDS creates with `auto_accept=true` only after the confirmation. Carrier-native PDFs are server-proxied and magic-checked. Legacy and split orders remain portal/manual-tracking only. Staging runbook: `docs/carrier-booking-uat.md`.

## Security Checklist
- [ ] No hardcoded credentials or API keys
- [ ] No site-specific URLs, addresses, or warehouse names
- [ ] No hardcoded term IDs or instance IDs
- [ ] All admin forms use nonces and capability checks
- [ ] All user input is sanitized, all output is escaped
- [ ] API credentials stored in WC settings (database), never in code
- [ ] REST API permission checks support both cookie and WC API key auth
- [ ] Collection points enforce pickup_enabled=true server-side
