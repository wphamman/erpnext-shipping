# Carrier Booking v1.14 — Staging UAT

Production access during development was read-only. Run all create/book tests on staging only, using carrier test/sandbox credentials where available. A booking can incur a carrier charge; do not click the final confirmation against production credentials unless the test shipment is authorised.

## Setup

1. Install the v1.14 release candidate on staging. Do not deploy it to live during this UAT.
2. Keep Fulfilment Mode set to **Active**.
3. Under General, set Company Name plus Dispatch Contact Name, Email and Phone.
4. Ensure both warehouse locations have complete street, suburb, city, province, postcode and country values.
5. Configure the staging/test Courier Guy and MDS tokens. TCG Locker retains its existing configuration.

## Checkout snapshot

For each door carrier, place and pay a new staging order that selects that carrier as the winning Economy/Standard/Express tier. Existing orders cannot test automatic booking because they deliberately lack the v1.14 snapshot.

Expected on the Woo order screen:

- One **Carrier Fulfilment** sidebar box, not a separate TCG Locker booking box.
- Carrier and exact service match the purchased shipping line.
- Customer-paid shipping and checkout provider quote are shown.
- Internal `_es_carrier_*` metadata is not displayed in the shipping line.

Also confirm a split order and an older door order do not offer an unsafe booking button; manual Add Tracking remains available.

## VAT-inclusive checkout regression

With the store's normal 15% standard VAT rate and shipping taxable, obtain a live door quote without booking it.

1. Record the carrier's VAT-inclusive quote and apply the configured markup/rounding exactly once.
2. Confirm the shipping radio and order total show that gross customer price, not that price plus another 15%. For example, a rounded R100 charge must display as R100, not R115.
3. Confirm the cart/order records the amount as net shipping plus one VAT component whose sum equals the displayed gross.
4. Confirm a zero-rated/non-taxable shipping context preserves the same gross price with no shipping tax.
5. Confirm a configured flat-rate fallback and own-vehicle fee follow WooCommerce's **prices entered with tax** mode: the configured amount is gross on an inclusive retail store and ex-VAT on an exclusive wholesale store.
6. Confirm TCG Locker still displays its existing VAT-reconciled total unchanged.

For ERPNext Fusion, keep shipping on the normal taxable path: Woo's `shipping_total` is net and `shipping_tax` is the VAT amount. Do not make shipping zero-rated to compensate for a double-tax display.

## Read-only cost check

1. Click **Check live booking cost**.
2. Confirm the returned carrier/service and live provider cost are plausible.
3. Confirm the UI states whether the customer charge covers the current provider cost.
4. Do not click **Confirm & book** yet. Verify no shipment exists in the carrier portal.
5. Change the order address or an item, then try the old confirmation: booking must refuse and require another cost check.
6. Wait more than five minutes: the confirmation must expire.
7. If the exact service price changes between checking and booking, the final action must refuse before creating anything and require a new visible confirmation.

## Authorised booking test

Only with an authorised staging/test carrier account:

1. Run the live cost check again.
2. Click **Confirm & book**, acknowledge the spend warning, and do not close the page while it runs.
3. Confirm exactly one shipment/waybill appears in the relevant carrier portal.
4. Confirm the order records booked state, shipment id, tracking item and order note.
5. Double-click/reload/retry: no second booking may be created.
6. Confirm normal tracking polling can use the newly-added tracking item.

For an intentionally interrupted/timeout test, the order must enter **Booking outcome needs review**. It may only be cleared after checking the carrier portal and confirming no shipment exists.

## Carrier override

1. Place a new paid, single-origin order using a Courier Guy door rate.
2. In *Carrier Fulfilment*, confirm the summary still identifies Courier Guy as the customer choice.
3. Change **Book with** to MDS Collivery and click **Check live booking cost**. This step must not create a shipment.
4. Confirm the green quote names MDS, identifies the override, and returns an MDS service in the same Economy/Standard/Express tier as the customer's service.
5. Click **Confirm & book** and verify the browser warning names MDS and the live price before accepting it.
6. After booking, confirm the panel and order note show MDS while retaining the original Courier Guy checkout summary; tracking and both PDF buttons must use the MDS shipment.
7. Repeat in the opposite direction on an order placed with MDS. If the alternate carrier has no service in the same tier, the check must refuse rather than substitute another tier.

## Native documents

After a successful booking:

- **Waybill PDF** downloads a file beginning `%PDF-`, branded/formatted by the selected carrier.
- **Parcel label** downloads the carrier-native label/sticker PDF.
- The browser URL contains only the WordPress AJAX proxy; it must not contain the Courier Guy Bearer token, MDS `api_token`, or an S3/signed document URL.
- A non-PDF provider response must produce an error, never render as same-origin HTML.

## TCG Locker regression

Open a newly booked/unbooked locker order. The existing hardened locker summary, Book action, ambiguous recovery and Waybill/Sticker actions must appear inside **Carrier Fulfilment** and behave as in `docs/tcg-locker-uat.md`.

## Release gate

- `git diff --check`
- PHP lint over every plugin PHP file
- `php tests/run.php`
- New door order snapshot confirmed in installed WooCommerce
- One authorised Courier Guy staging booking + native documents
- One authorised MDS staging booking + native documents
- TCG Locker order-screen regression
- No production deployment until the operator signs off
