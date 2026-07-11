# TCG Locker — Sandbox UAT Runbook

Manual verification for behaviour that cannot be unit-tested locally (WooCommerce
checkout, sessions, AJAX, tax). Run on a **staging** site pointed at the **sandbox** API.
Do **not** book shipments or run on production. Phase 6 extends this into the full release
runbook.

## Preconditions
- Plugin active; a shipping zone with the ERPNext Shipping method.
- WooCommerce → ERPNext Shipping → **TCG Locker (beta)**: Enable **on**; API Base URL =
  `https://sandbox.api-pudo.co.za/api/v1`; API Token set (sandbox); pricing mode = Live.
- At least one **warehouse** location with **"TCG Locker dispatch origin (L2L)"** ticked.
- A 15% shipping-taxable tax setup (to verify VAT reconciliation).

## Phase 3 — checkout selection & rates

1. **Selector appears, no fake rate.** Add an in-stock, locker-eligible product; go to
   checkout. Under the shipping methods you should see the **"TCG Locker"** row with
   **"Choose a TCG Locker for cheaper delivery"** and a search box. There is **no**
   zero-cost TCG Locker rate yet.
2. **Search.** Type a town/postcode/name → results list (name, address, box sizes, hours).
   No external map/CDN requests (check the network tab: only `admin-ajax.php`).
3. **Select → rate appears.** Click a locker → checkout recalculates → a **"TCG Locker
   Delivery — <locker>"** rate appears, id ending `_locker`, showing Locker + Box meta.
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

## Notes / assumptions
- VAT handling assumes shipping is taxable at 15% (the ex-VAT cost is handed to WooCommerce,
  which applies the single shipping tax). If shipping is non-taxable, set a shipping tax
  class so VAT is applied once; otherwise the amount shows ex-VAT.
- No shipment booking exists yet (Phase 4). Selecting a locker only quotes and persists the
  choice as rate/shipping-item meta.
