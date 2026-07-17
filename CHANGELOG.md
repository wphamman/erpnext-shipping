# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

## [1.15.0] - 2026-07-17

### Added
- **"Parcel In Locker" customer email.** When TCG reports a locker parcel has landed in the customer's destination locker, they get a one-time email naming the locker and stating the collection deadline. Toggleable/customisable in WooCommerce → Settings → Emails like every other fulfillment email.
  - Fires on the provider's `in-locker` event via a new `es_tcg_locker_arrived` action, **not** on a WC status change: `in-locker` maps to `completed`, but so do the earlier transit states, and advancement is forward-only — so the order is already `completed` on arrival and never transitions.
  - Gated on the shipment's **current** status being `in-locker`. Every delivered parcel keeps its `in-locker` event forever, so keying on history would mail "your parcel is waiting" to customers who collected days ago.
  - The deadline is anchored to the provider's own arrival event, never to poll time (the poll runs every 15 minutes, so a poll-derived deadline would always sit later than the real cutoff). Provider datetimes are naive **SAST** — verified against four live shipments — and are parsed in the provider's timezone, not the site's, so a store running WordPress on UTC still reads arrivals correctly.
  - Never restates the collection PIN — the provider's own message carries it.
  - Suppressed rather than sent if the collection window has already lapsed; a "collect by yesterday" mail is worse than silence.
- **Configurable collection window.** A new *Collection Window (hours)* setting (ERPNext Shipping → TCG Locker, default **36**). The same number is shown at checkout and used to compute the email's deadline, so the two can never disagree. Snapshotted onto the order at checkout (`_es_tcg_locker_collection_hours`), so retuning the setting later cannot move a deadline an existing order was sold on.
- **Checkout collection notice.** The selected-locker control now states the collection window and that we will email on arrival.
- **Re-book a dead locker booking.** When TCG reports a booked shipment as `cancelled` / `cancel-booking-expired`, the order panel now says so plainly and offers *Clear dead booking & allow re-book*. Clearing is refused unless TCG **confirms the shipment is dead on a live call** — a cached poll result is never enough, and any API error fails closed.
  - Previously the booking meta was immutable once booked: `handle_clear()` hard-refused whenever a shipment id existed, so a booking that died at TCG could not be re-booked from Woo. The only workaround was hand-adding a tracking number, which left the poll chasing the dead waybill forever and the order permanently reporting a cancelled booking. Observed on three live retail orders (#30314/#30318/#30321).
  - Clearing resets the arrival-notice state, so a re-booked parcel can notify on its own arrival.

### Changed
- `ES_TCG_Locker_Rate::read_order_snapshot()` is now the single implementation of the locker quote-snapshot read; `ES_TCG_Locker_Admin::read_order_locker_meta()` delegates to it. The admin class is only loaded behind `is_admin()`, but the tracking poll (WP-Cron) and the arrival email need the same read outside any admin request.

## [1.14.3] - 2026-07-14

### Added
- **Configurable TCG Locker multi-item fill factor.** A new *Multi-item Fill Factor* setting (ERPNext Shipping → TCG Locker, default **0.70**) controls the usable fraction of a locker box the packer assumes for orders of 2+ items, so real-world packing air-gaps can be tuned without a release. Lower = more conservative (fewer multi-item orders offered a locker). Single-item orders are unaffected (a lone item that fits dimensionally still fits). Invalid values fall back to 0.70. Previously hard-coded at 0.80.

## [1.14.2] - 2026-07-14

### Added
- **The order “ERPNext” box shows a live ERP-sync indicator.** Alongside the Sales Order name, the order-edit panel now displays whether the order actually exists in ERPNext right now (Synced / Drift / Missing / Unreachable), using the same authoritative check as the orders-list ERP Sync column. A stale “sync failed” note left by an earlier Force-Sync can no longer mislead staff about the true state.

### Fixed
- **Force ERPNext Sync no longer records a bare failure when a Sales Order is actually present.** On a fast sync error (e.g. Fusion’s concurrent-modification `TimestampMismatchError`), the plugin now confirms — with a short, worker-budget-safe request — whether a Sales Order for the order exists before writing the note. A present order is flagged for verification (“…verify it reflects the latest order changes”), **not** claimed as an outright success (existence does not prove the change landed) and not shown as a hard failure. The timeout path keeps its existing “sent — verify” wording untouched, and completing a Force-Sync clears the cached ERP-sync indicator so the order screen re-checks ERPNext immediately.

## [1.14.1] - 2026-07-11

### Fixed
- **Door shipping prices no longer receive VAT twice.** VAT-inclusive Courier Guy and MDS quotes are split through WooCommerce's active shipping tax rates into net shipping plus explicit tax, preserving the quoted gross while keeping `shipping_total` and `shipping_tax` distinct for ERPNext sync. Merchant-entered fallback and own-vehicle prices follow WooCommerce's store-wide “prices entered with tax” mode, preserving gross retail settings and ex-VAT wholesale settings. TCG Locker remains on its existing VAT-reconciled path.
- **Courier Guy booking and document calls now use the current Portal v2 API.** Shipment creation, waybill and sticker requests moved from the retired `api.shiplogic.com` host to `api.portal.thecourierguy.co.za/v2`; signed PDFs are restricted to the hosts allowlisted by The Courier Guy's current official WooCommerce plugin.
- **Booking spend locks are genuinely database-atomic.** Door and Locker booking now acquire with one `INSERT IGNORE` against the unique option-name index instead of WordPress `add_option()`'s duplicate-key update path; release is an ownership-checked conditional delete. Concurrent tabs/retries cannot both enter the irreversible provider call.
- **The fulfilment poll mutex is atomic and long-lived enough for a full batch.** Cron and Diagnostics runs share an immutable one-hour option lease with safe atomic stale takeover, preventing overlapping carrier polling. Custom tracking entries and newly-booked waybills awaiting carrier indexing no longer create false watchdog failures.
- **Door booking re-quotes at the final spend boundary.** The exact service and cents must still match the operator-confirmed quote immediately before shipment creation; any drift requires a new visible confirmation.
- Missing/deleted cart products now fail explicitly to the configured fallback instead of throwing during live quoting, enabled-carrier changes invalidate the relevant quote-cache namespace, and Locker rates use the same active Woo tax split so a store without a shipping-tax rate cannot be undercharged.

## [1.14.0] - 2026-07-11

### Added
- **Unified carrier booking in the Woo order sidebar.** The existing *Shipment Tracking* box is now *Carrier Fulfilment*: it identifies the carrier/service the customer selected, shows customer charge versus provider quote, performs a read-only live cost check, and only then exposes an explicit **Confirm & book** action. TCG Locker reuses its existing hardened controls inside the same box rather than rendering a second competing panel.
- **Operator carrier override before booking.** Staff may retain the customer's door carrier or quote the other configured carrier. Overrides stay within the customer's Economy/Standard/Express tier, preserve the original checkout choice for audit, and bind booking, tracking and PDFs to the carrier actually confirmed.
- **Manual booking for The Courier Guy door delivery and MDS Collivery.** New orders persist a hidden, exact booking snapshot (settings instance, dispatch location, provider service identifier, provider/customer prices and parcel geometry). Booking is available only for a paid, single-origin domestic order with complete addresses/contacts and resolvable products. Legacy/split orders fail safely to the existing portal + Add Tracking workflow.
- **Two-stage spend confirmation.** A read-only fresh quote creates a five-minute, server-stored confirmation bound to the current origin, destination, contact, parcel geometry, carrier and service. The booking POST refuses expired confirmations or any intervening order change.
- **Duplicate and uncertain-outcome protection.** Door booking uses a per-order atomic option mutex, a durable `booking` state saved before the provider call, a permanent shipment-id guard, and an `ambiguous` state for transport/timeout/5xx/unparseable success and HTTP 408/409/429. There is no automatic retry; operator recovery requires checking the carrier portal first.
- **Pre-shipment tracking for booked door orders.** The cron reserves capacity for booked Courier Guy/MDS orders still in Processing/On Hold, allowing the first real carrier event to advance them to Shipped without incorrectly treating waybill creation itself as dispatch.
- **Carrier-native documents.** The Courier Guy waybill/sticker PDFs are fetched through its signed ShipLogic document endpoints; MDS waybill/label PDFs use Collivery's native document API. Credentials/signed URLs remain server-side, returned bytes must start with `%PDF-`, downloads force `application/pdf` + `nosniff`, and Courier Guy signed URLs are host-allowlisted.
- **Dispatch contact settings.** Name, email and phone are available alongside Company Name; phone is required before an automatic door booking can be made.

### Changed
- Door-rate cache keys moved to `es_ship_v3_*` so orders placed immediately after upgrade cannot reuse a quote that lacks the numeric booking service identifier or delivery tier required by the safe override flow.
- New door orders hide the internal `_es_carrier_*` snapshot rows from the order-item display while retaining them for booking/audit.

## [1.13.2] - 2026-07-11

### Changed
- **Add Tracking now defaults to the carrier the customer actually selected.** The order-edit provider dropdown resolves the purchased shipping line: TCG Locker from its dedicated method/snapshot, MDS Collivery or The Courier Guy from the persisted winning `Carrier` rate metadata, and an existing saved tracking provider takes precedence. Generic tier labels never invent a carrier.

### Fixed
- **Internal Locker quote metadata flooded the admin order line.** The `_es_tcg_locker_*` booking and profitability snapshot remains persisted for code/reporting but is now registered with WooCommerce's hidden order-item metadata filter, leaving only the useful customer-facing Locker and Box rows visible.

## [1.13.1] - 2026-07-11

### Fixed
- **Selecting a locker could never reveal its priced shipping rate.** WooCommerce's shipping-package cache does not include the TCG locker session selection, so recalculation reused the pre-selection door rates indefinitely. Locker select/remove now explicitly invalidates each package cache before recalculating; a newly available `_locker` rate is automatically selected as the real WooCommerce shipping method and updates the order total.
- **TCG Locker looked like an unrelated checkout field instead of a delivery choice.** The locker chooser now appears on the classic cart—before the largest funnel drop-off—as well as checkout. Before selection it explains that choosing a locker reveals the exact price; afterwards the real priced Locker radio appears among the other shipping methods, is selected automatically, and carries the change/remove controls beneath it.

### Changed
- **Verified production API base:** `https://api-pudo.co.za/api/v1`. Authenticated read-only production checks returned the live locker catalogue and shipments; an L2L quote-only request returned the five live XS–XL services. No shipment was created during verification.

## [1.13.0] - 2026-07-11

### Added
- **TCG Locker (PUDO) locker-to-locker delivery.** A first-class L2L delivery service alongside the door carriers, built on the published TCG Locker sandbox API contract. Default-disabled; enable per store under WooCommerce → ERPNext Shipping → *TCG Locker*.
  - **Checkout locker selector** — dependency-free, accessible search/select of a destination locker in the classic-checkout review table (nonce-protected AJAX; selection lives in the session and is always re-validated server-side; no external map/CDN calls). Selecting recalculates shipping and adds a `_locker` rate.
  - **Conservative parcel packer** — proves spatial *coexistence* of all cart items in a real locker box (extreme-point placement + overlap test, six orientations) and picks the smallest fitting box from the services the API actually returns. Never books a box the order can't physically fill.
  - **VAT-reconciled pricing** — live / fixed / per-service free-threshold modes; the VAT-inclusive provider rate is split so WooCommerce applies exactly one 15% shipping tax (no double tax). Fails closed on a malformed or missing provider price — never a free or negative locker rate.
  - **Manual, idempotent admin booking** — a "Book TCG Locker Shipment" order-panel action guarded by a capability check, a per-order nonce, an atomic ownership-token mutex with an immutable lease, a durable duplicate/in-progress guard, full pre-book re-validation (paid, destination locker, dispatch origin, current-order-still-fits-the-box), and a fresh cache-bypassing re-quote that refuses any price/box/revision drift from checkout. Never auto-books. An inconclusive attempt (transport/timeout/5xx/unparseable-2xx, or HTTP 408/409/429) becomes an *ambiguous* state that blocks re-booking until an operator reconciles against the TCG portal.
  - **Authenticated label proxy** — waybill/sticker PDFs are streamed server-side (the api_key never reaches the browser or logs); only a genuine PDF is served, under a fixed `application/pdf` attachment.
  - **Authenticated forward-only tracking** — the fulfilment poller polls booked locker shipments via the plugin's own Bearer client and maps statuses conservatively: accepted-handoff/transit → *Shipped*, `customer-collected`/`delivered` → *Delivered*, everything else (exceptions, cancellations, unknown) records only. Booked locker orders are polled even while still pre-shipment (a reserved batch slice prevents a conventional-order backlog from starving them).
- **Split fulfilment never offers TCG Locker**; only warehouse locations flagged as a TCG Locker dispatch origin can dispatch a locker shipment (collection points never do).

### Security
- **API credentials are now write-only in both settings surfaces.** Stored ERPNext keys/secrets, carrier tokens, and the Google Distance Matrix key are no longer rendered into admin-page HTML. Blank submissions preserve the existing value instead of clearing it.
- **Tracking REST API keys now inherit their owner's order capabilities.** A valid WooCommerce key and permission level are no longer sufficient on their own; the owning user must also be permitted to read or edit shop orders.

## [1.12.14] - 2026-07-03

### Fixed
- **Deplete/restore left stock quantity and stock status inconsistent.** Both paths wrote stock via `wc_update_product_stock()` on one product instance, then saved the status on a second, stale instance — observed as depleted products showing qty 0 with status still "instock". Quantity and status are now set on a single object with a single save.

## [1.12.13] - 2026-07-03

### Added
- **Watchdog: stale "Pending Payment" alert.** Orders sitting in pending payment longer than a threshold (default 2 days) now appear in the daily digest — previously an unpaid order could hold an ERP-side reservation for weeks with no alert, because pending was deliberately outside the watched fulfillment statuses.
- **Watchdog: additional alert recipients.** New "Additional alert recipients" setting (comma-separated emails); the digest goes to the site admin email plus these addresses.

### Fixed
- **WooCommerce no longer auto-restores stock on order cancel / payment failure when ERPNext is configured.** WC's cancel path added the order's units back to Woo stock even though ERPNext (the stock source of truth) had merely released a reservation — the units never existed as sellable stock, and the phantom quantity survived until the next full sync (observed: a cancelled 3-unit order put 3 units "in stock" on a product with zero saleable stock). `woocommerce_can_restore_order_stock` is now filtered off when any shipping-method instance has an ERPNext URL configured. Manual "restock items" on refunds is a separate path and still works. Escape hatch: `add_filter( 'es_erp_owns_stock', '__return_false' )`.

## [1.12.12] - 2026-07-03

### Fixed
- **v1.12.11 regression: cross-warehouse over-reservation resurrected stock.** Depletion was decided on the sum of per-location values *after* clamping negatives to zero, so an item over-reserved at one warehouse (reservation larger than that warehouse's own stock) still counted the other warehouses' units as available — the restore path then flipped the product back in-stock right after woocommerce_fusion had correctly pushed a negative global net. Depletion (and the restore quantity) now use the GLOBAL raw net across all locations, matching Fusion exactly; per-location values are clamped only afterwards, for the SLW display and pickup gate.

## [1.12.11] - 2026-07-03

### Changed
- **Stock sync now honours ERPNext reservations (net availability = actual − reserved).** The sync previously read Bin `actual_qty` only, so stock reserved by submitted Sales Orders still showed as available for web sale, pickup, and dispatch planning — and the restore path could flip a product back in-stock right after woocommerce_fusion (running with `subtract_reserved_stock`) had correctly marked it unavailable, leaving the two writers fighting over any item with large open-SO reservations. Per-location quantities are now `actual_qty - reserved_qty` (over-reserved locations clamp to zero), items with no net availability anywhere follow the depleted path (core stock zeroed + out of stock), and the SLW location meta, pickup feasibility gate, and fulfillment planner all see net availability.

## [1.12.10] - 2026-07-03

### Fixed
- **Force ERPNext Sync and "open in ERPNext" links used the wrong Sales Order name on non-retail sites.** `derive_so_name()` hardcoded the `WEB1-` naming-series prefix, but woocommerce_fusion uses a different series per WooCommerce Server (e.g. `WEB3-` for a second site). On such sites every Force ERPNext Sync asked Fusion to sync a non-existent Sales Order (HTTP 404 `DoesNotExistError` reported as a sync failure on the order) and the meta-box ERPNext link pointed at a non-existent document — even though the real Sales Order synced fine under its own prefix. The prefix is now a shipping-method setting (**ERP Sales Order prefix**, default `WEB1-`), resolved from instance settings like the other ERP options.

## [1.12.9] - 2026-06-17

### Fixed
- **"Force ERPNext Sync" button always reported "Request failed" on slow syncs.** The button called `run_sales_order_sync` synchronously over admin-ajax; on a multi-item order the ERP↔Woo round-trip runs past the host's 30s PHP `max_execution_time`, so LiteSpeed killed the PHP process before it could return JSON. The browser got a 5xx HTML page → the JS catch-all showed the generic "Request failed", even though ERPNext had received the request and finished the sync anyway (the order appeared in ERP regardless). The force-sync now runs as an **async Action Scheduler job** (WP-Cron single-event fallback): the button enqueues the work and returns immediately ("Sync queued…"), the worker runs the sync off the HTTP request and records the outcome as an **order note** + `_es_last_force_sync` meta. A WP-side timeout is reported as "request sent — verify in ERPNext" rather than a hard failure, since the ERP sync typically completes. Both the meta-box button (AJAX) and the order-list row action share the new enqueue path. The async callback is registered outside the `is_admin()` gate so the cron/loopback queue runner can find it. Per-order lock TTL raised 30s→300s to span the queued run. The worker's ERP-client timeout is kept **under** the host's 30s PHP hard cap (25s) so `wp_remote_post` returns a graceful timeout — releasing the lock and writing the note — before PHP could kill the process mid-call (`set_time_limit(0)` is attempted but is a no-op on hosts that disable it). The enqueue path checks `as_enqueue_async_action()`'s return and releases the lock + reports failure if scheduling fails, so a scheduling error can't leave a "queued" message with a wedged lock and no job.

## [1.12.8] - 2026-06-10

### Fixed
- **"Delivery Quote Required" rate silently removed when free shipping was present.** The smart free-shipping logic hides the cheapest carrier rate, excluding rates ending `_locker` and `_bulk_delivery` — but the manual-quote rate id ends `_bulk_delivery_quote` (matched neither) and costs 0, so it was always removed as "cheapest". Customers over the quote-distance band with a free-shipping-qualifying cart could check out with free courier shipping instead of a delivery quote. All `_bulk_delivery*` rates are now excluded from the cheapest-rate cleanup.
- **Empty/partial carrier results were cached for the full TTL.** A carrier timeout or the 20s time-budget break cached an empty or one-carrier rate set, pinning "no rates" (or undercharged split rates) for every customer sharing the cache key for 15 minutes. Only complete, non-empty result sets are cached now.
- **Fractional quantities truncated throughout.** Per-kg products (sold in 0.01 steps) were `intval()`ed in the parcel estimator (0.25 kg billed as a full unit), the ERP stock sync (0.9 stock stored as 0), and the pickup feasibility gate (false "not available for pickup" blocks). All three now handle float quantities; the pickup check uses an epsilon to avoid float-noise blocks.

- **Own-vehicle delivery priced from the wrong warehouse.** For chooseable/split fulfillment plans the distance was computed from the *first configured* location — a customer near Warehouse B could be priced (or pushed into the manual-quote band) from Warehouse A's distance. The distance now uses the nearest candidate location in the plan.
- **Split orders could present a partial total as the full charge.** A dispatch location skipped entirely by the 20s time budget was missing from the rate map, so split combination summed only the quoted legs. Skipped locations now count as "no rates", which suppresses the combined split rate (fallback applies) instead of undercharging.
- **Heavy-class free-shipping exclusion failed open.** The exclusion list was only read from an ES rate present in the package; when carriers returned nothing (and no fallback rate was configured), WC's Free Shipping survived on carts containing excluded shipping classes. Settings are now read from the configured instance directly when no ES rate is present.
- **Watchdog stale-order alerts and pickup reminders could never fire.** Staleness was keyed on `date_modified`, which the 15-minute tracking poll bumps on every run. Orders now stamp `_es_status_changed_at` on every status transition and the watchdog/reminders key on that (legacy orders fall back to `date_modified`). Reminder 2 timing no longer restarts when reminder 1 saves the order.
- **Stale quick-action links could drag orders out of final statuses.** Status quick actions (nonces valid ~24h in list-page URLs) now refuse to act on orders in `delivered`/`pickup`/`refunded`/`cancelled`/`failed` — deliberate changes still work from the order edit screen.
- **Pickup-location guard now covers ALL status-change paths.** Bulk actions and the order-edit dropdown bypassed the quick-action check, putting orders into pickup statuses with no location — the pickup email then silently bailed and the customer was never notified. A `woocommerce_order_status_changed` safety net (runs before email hooks) reverts such changes with an explanatory order note.
- **Stock sync can no longer run concurrently.** Cron, manual "Sync Now", and AJAX syncs could overlap (a slow ERP response can outlast the 15-min interval), racing the previous-stock diff into spurious SLW deplete/restore writes. `sync()` now takes a 10-minute self-expiring mutex.

### Security
- **Warehouse Staff can no longer use the WooCommerce REST API.** The role carries `manage_woocommerce` (required for WC admin screens), and menu/page restrictions don't apply to REST — so warehouse logins implicitly had the full `wc/v3` surface (products, coupons, settings, customer data). A `woocommerce_rest_check_permissions` filter now denies REST for warehouse users; the plugin's own tracking REST endpoints (used by integrations) accept WC API keys and are unaffected.

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
