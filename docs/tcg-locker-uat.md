# TCG Locker — Sandbox UAT Runbook

Manual verification for behaviour that cannot be unit-tested locally (WooCommerce
checkout, sessions, AJAX, tax, order booking, cron). Run on a **staging** site pointed at
the **sandbox** API (`https://sandbox.api-pudo.co.za/api/v1`). Booking a shipment against
the sandbox is expected during UAT; **never run this against production or the production
API**, and never book a real (non-sandbox) shipment. This is the full release runbook for
the feature.

## Preconditions
- Plugin active; a shipping zone with the ERPNext Shipping method.
- WooCommerce → ERPNext Shipping → **TCG Locker (beta)**: Enable **on**; API Base URL =
  `https://sandbox.api-pudo.co.za/api/v1`; API Token set (sandbox); pricing mode = Live.
- At least one **warehouse** location with **"TCG Locker dispatch origin (L2L)"** ticked.
- A 15% shipping-taxable tax setup (to verify VAT reconciliation).

## Phase 3 — cart/checkout selection & rates

1. **Cart selector appears, no fake rate.** Add an in-stock, locker-eligible product; go to
   the classic cart. Under the shipping methods you should see **"TCG Locker delivery"**
   with **"Choose a locker to see the exact price"** and a search box. There is no
   zero-cost placeholder rate.
2. **Search.** Type a town/postcode/name → results list (name, address, box sizes, hours).
   No external map/CDN requests (check the network tab: only `admin-ajax.php`).
3. **Select → priced radio appears and is chosen.** Click a locker → cart recalculates → a
   **"TCG Locker Delivery — <locker>"** rate appears among the normal shipping radios,
   id ending `_locker`, with its price visible and the radio selected automatically. The
   total must update. Proceed to checkout: the same locker/rate stays selected and
   change/remove controls render beneath the rate.
4. **VAT reconciliation (15% store).** The rate's displayed total (incl. tax) equals the
   sandbox provider `rate` (VAT-inclusive) within rounding; exactly one tax line; no double
   tax. Cross-check against `_es_tcg_locker_provider_rate` on the order later.
5. **Change / Remove.** "Change locker" re-opens search; "Remove" clears it and the
   `_locker` rate disappears on recalculation.
6. **Fixed & Free modes.** Set pricing mode = Fixed (e.g. R69) → customer sees R69 incl.
   Set a TCG free threshold below the cart total → charge becomes R0 (provider cost still
   recorded on the order).
7. **Block on missing locker.** Select the TCG Locker rate, then Remove the locker (or tamper
   the session), and try to place the order → blocked with an actionable notice.
8. **Graceful API failure.** Temporarily set an invalid token → the TCG Locker rate does not
   appear (or disappears), and **all other shipping rates remain**; no fatal, no silent
   fallback labelled as TCG Locker.

## Required regression checks

- **Local Pickup** unchanged (still offered/selectable as before).
- **TCG (Courier Guy) + MDS Collivery** door rates still appear as before.
- **Free doorstep shipping** logic unchanged.
- **`_locker` not stripped when free shipping present**: with a qualifying free-shipping
  cart AND a selected locker, both the Free rate and the `_locker` rate show (the
  cheapest-carrier-hide logic already excludes `_locker`).
- **Heavy-class kit + locker**: a product in the door `heavy-items` class (but NOT in
  *TCG-Locker* excluded classes) still gets a locker rate when packed weight/dimensions fit.
- **Split fulfilment never offers TCG Locker**: force a 2-warehouse split (SLW per-item
  locations) → no `_locker` rate.
- **Changing SLW location requotes origin**: switch the cart's stock location → the plan
  re-resolves; if it becomes split, the locker rate disappears; if a different single
  origin, the rate re-quotes (destination-only, so price unchanged).
- **Stale/tampered locker code**: editing the session/hidden value to a bogus code →
  server-side validation rejects it (no rate; order blocked).

## Phase 4 — booking, admin panel, labels

Place a **sandbox** locker order (complete checkout with a selected locker) so an order
carries the `_es_tcg_locker_*` shipping-item snapshot. Open the order edit screen.

1. **Panel appears only on locker orders.** A **"TCG Locker Shipment"** meta box shows the
   destination locker, service, box, customer charge, provider (incl VAT) and quote time,
   with a **"Book TCG Locker Shipment"** button. Non-locker orders show no such box.
2. **Capability + nonce.** Confirm a user without the booking capability cannot book (the
   button/AJAX is refused). The action carries a per-order nonce.
3. **Book once (sandbox).** Click *Book* → success → the panel shows the shipment id +
   tracking reference, and **Waybill**/**Sticker** buttons. Order meta:
   `_es_tcg_locker_booking_status = booked`, `_es_tcg_locker_shipment_id`,
   `_es_tcg_locker_tracking_ref`, `_es_tcg_locker_booked_ts`; a private order note records it;
   exactly **one** AST tracking item (provider *TCG Locker*) is appended.
4. **Idempotent — no double-book.** Reload and try again → refused ("already has a booked
   shipment"). Rapid double-click does not create two shipments (mutex).
5. **Drift refusal.** Before booking a fresh order, change the sandbox rate/box for that
   destination (or wait for the quote to change) → *Book* refuses with a drift reason and
   creates nothing; re-quote (re-select the locker at checkout) to proceed.
6. **Pre-book validation.** An unpaid order, an order whose locker is now invalid, a
   dispatch origin no longer flagged, or an order edited to no longer fit the box → *Book*
   is refused with the specific reason; nothing is created.
7. **Ambiguous state (no auto-retry).** Simulate an inconclusive result (e.g. point the
   client at an endpoint that times out / returns 5xx for `/shipments`) → status becomes
   `ambiguous`, the panel shows the "outcome unknown" warning, and re-booking is blocked.
   The only path forward is **"I checked the portal — clear & allow re-book"**, which is
   refused while a booking is still in flight and only clears a stale/finished attempt.
8. **Label proxy.** Click **Waybill**/**Sticker** → a PDF downloads. Confirm the URL is
   `admin-ajax.php?action=es_tcg_locker_label…` (nonce-protected) and **never** exposes the
   `api_key`; a non-PDF provider response is refused (does not render as HTML).

## Phase 5 — tracking poll (forward-only)

With a booked sandbox order (status `booked`, a real tracking reference):

1. **Poll runs.** Trigger the fulfilment poll (Diagnostics "Run now", or wait for the
   15-min cron). The booked locker order is polled even while still **processing** (it is
   selected by the meta-qualified query, reserved a batch slot so a backlog can't starve it).
2. **Forward-only advancement.** As the sandbox status progresses: an accepted-handoff /
   in-transit / `in-locker` status advances the order to **Shipped** (`completed`);
   `customer-collected` / `delivered` advances to **Delivered**. Verify it **never regresses**
   and does not re-fire once already at that status.
3. **Record-only statuses.** An exception/cancellation/unknown status is recorded in
   `_es_courier_status` (shown as *TCG Locker: …*) but does **not** change WC status.
4. **No duplicate tracking items.** Repeated polls never append a second tracking item.
5. **No-waybill fallback.** A booked order with an empty tracking reference records
   `tcg-locker:no-tracking-ref` and is **not** polled by shipment id (no false failures).
6. **Locker-only store.** With BOTH legacy carrier tokens blank but the locker client
   configured, the poll still runs for locker orders (it is not skipped).

## Production activation checklist

Do these deliberately, in order, on the live site:

- [ ] Confirm sandbox UAT above passed on staging.
- [ ] Set the **production** API base `https://api-pudo.co.za/api/v1` + live token in
      WooCommerce → ERPNext Shipping → *TCG Locker*. This base was verified on 2026-07-11
      with authenticated read-only locker/shipment calls and a quote-only L2L `/rates` call.
      Token fields are write-only.
- [ ] Flag the correct **warehouse** location(s) as TCG Locker dispatch origins.
- [ ] Confirm shipping is taxable at 15% (or set a shipping tax class) so VAT reconciles.
- [ ] Enable **TCG Locker** (`tcg_locker_enabled = yes`) on the intended ES instance.
- [ ] Smoke test: one real cart → locker rate quotes; do **not** book until a real order.
- [ ] First real order: book once, print the waybill, confirm the tracking item + poll.
- [ ] Watch `erpnext-shipping-tcg-locker` logs for the first day.

## Rollback

Setting **TCG Locker → Enable = off** (`tcg_locker_enabled = no`) is the primary rollback.
`tcg_locker_enabled` is a **single gate**: the client factory (`es_tcg_locker_client()`) returns
`null` when it is not `yes`, *before* it even looks at the stored token — so disabling turns off
**everything the client does at once**: new quotes, the Book action, AND authenticated tracking.
Be precise about the consequences:

- **Stops NEW sales cleanly.** The checkout selector and the `_locker` rate self-gate on the
  client, so no new locker quotes appear and no new orders can select a locker. Instant and
  non-destructive.
- **Book action becomes inert.** The *TCG Locker Shipment* meta box still shows on orders that
  carry the persisted `_es_tcg_locker_*` shipping-item snapshot (the box registers on the
  snapshot, not the enable flag), but clicking **Book** now fails "TCG Locker is not
  configured" — so no new bookings can be made.
- **Authenticated tracking STOPS too — and cannot be kept alive by keeping the token.** Because
  the client is gated off by the enable flag before the token is read, retaining the token does
  NOT keep polling working. Booked locker orders are still *selected* by the fulfilment poll
  (they match on `_es_tcg_locker_shipment_id` / `booking_status = booked`, not the enable flag),
  so while cron keeps running for the legacy carriers each such order hits the unconfigured-
  client failure path and **accrues watchdog poll-failure alerts** until it reaches a terminal
  WC status. Nothing regresses and no shipment is created — but in-flight shipments must then be
  **tracked manually via the TCG portal**.
- **Preferred rollback (no watchdog noise):** disable only once **no locker orders are still in
  flight** — i.e. every booked locker order has reached a terminal status (Delivered), at which
  point it has already dropped out of the poll. Then `Enable = off` is fully clean.
- **Credentials persist and cannot be cleared from the UI.** Blank credential submissions
  intentionally preserve the stored token (write-only fields), so disabling leaves the API
  base/token in the database. There is no admin control to erase them; removal requires a direct
  option edit outside the settings screen.
- **Full removal:** the feature is default-disabled, so deactivating the plugin removes it
  entirely (checkout, panel, and poll). No auto-booking and no new scheduled jobs are added
  beyond the existing 15-min fulfilment poll.

> Product note: because a single flag governs both offering AND tracking, there is currently no
> way to "stop new locker sales but keep tracking in-flight shipments". If that becomes a real
> operational need, it wants a separate offer-at-checkout gate (client/tracking on `enabled`, new
> quotes on a second flag) — a deliberate feature change, not a rollback tweak.

## Profitability reporting notes

Both sides of the economics are captured, so margin is auditable without a live API call — but
mind **where** each value lives:

- **On the order's SHIPPING LINE ITEM** (the checkout quote, copied there from the `_locker`
  rate's meta; read via `$order->get_items('shipping')` → `$item->get_meta(...)`, **not** as
  order-level meta):
  - `_es_tcg_locker_customer_charge` — what the customer paid (incl VAT).
  - `_es_tcg_locker_provider_rate` / `_es_tcg_locker_provider_rate_ex_vat` — the provider's
    quoted cost (incl / ex VAT).
  - `_es_tcg_locker_pricing_mode` — `live` | `fixed` | `free` (a `free` order still records the
    provider cost, so free-shipping bleed is measurable).
  - These are the **checkout-time quote** — booking re-validates against a fresh quote (and
    refuses on drift) but does **not** re-stamp these values, so they equal what was charged.
- **At the ORDER level** (written by the booking action via `update_meta_data`): the outcome
  keys `_es_tcg_locker_booking_status` (`booked` etc.), `_es_tcg_locker_shipment_id`,
  `_es_tcg_locker_tracking_ref`, `_es_tcg_locker_booked_ts`.

Margin per order = shipping-item `customer_charge` − `provider_rate`. Under **free** mode it is
negative by the provider cost; under **fixed** it is `fixed − provider_rate`. To size the
channel's GP, find booked orders by the **order-level** `_es_tcg_locker_booking_status = booked`,
then read each order's **shipping-item** charge/provider-rate meta.

## Notes / assumptions
- VAT handling assumes shipping is taxable at 15% (the ex-VAT cost is handed to WooCommerce,
  which applies the single shipping tax). If shipping is non-taxable, set a shipping tax
  class so VAT is applied once; otherwise the amount shows ex-VAT.
- Some provider field names (contact keys, tracking status field) are confirmed only against
  the sandbox contract — re-verify against production responses before wide rollout.
