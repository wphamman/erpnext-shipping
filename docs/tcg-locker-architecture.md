# TCG Locker Integration — Architecture & Contract Lock (Phase 0)

**Feature branch:** `feature/pudo-locker` (worktree `.worktrees/pudo-locker`)
**Base:** `feature/fulfillment-module` @ `e2a6298` (v1.12.14)
**Status:** Phase 0 — documentation only. **No PHP is implemented in this phase.**

---

## 0. Authority & supersession notice

**This document supersedes every earlier PUDO reference.** Specifically it supersedes:

- the stale `https://api-pudo.co.za/` documentation page used as a primary contract;
- the `POST /api/v1/locker-rates-new` endpoint;
- the February-2025 PUDO WooCommerce reference plugin contract
  (`pudo-shipping-for-woocommerce.zip`);
- any production-endpoint or AWS `execute-api` hostname assumptions;
- source-locker (specific collection `terminal_id`) configuration on dispatch locations;
- Door-to-Locker (D2L) as a first-release mode.

**Primary authority for this integration is the published Postman collection:**

> **TCG LOCKER SANDBOX API** — https://api-docs.tcglocker.co.za/ (published 2026-01-07)

The response examples inside that collection carry older dates; they are authoritative for
**shape and semantics** but **not** for current pricing, SLAs, dates, or commercial terms.
Where an exact JSON field name is not yet verified against an authenticated sandbox
response, it is flagged **(to confirm)** in this document. Those are resolved in Phase 1
against fixtures captured from an explicitly-authorised read-only sandbox call — not invented.

### Branding & naming (authoritative)

| Concern | Value |
|---|---|
| Customer/admin label | **TCG Locker** |
| Class prefix | `ES_TCG_Locker_` |
| Settings key prefix | `tcg_locker_` |
| Order-meta key prefix | `_es_tcg_locker_` |
| Tracking provider slug | `tcg-locker` |
| Shipping rate ID suffix | `_locker` (already reserved — see §7) |

The word "PUDO" appears in new code **only** where an actual external API field, legacy
response key, URL path, or service code requires it (e.g. the sandbox origin hostname
`sandbox.api-pudo.co.za`). It is not used for our own classes, settings, meta, or labels.

> **Filename note:** Phase 0's deliverable was originally listed as
> `docs/pudo-locker-architecture.md`. Per the branding correction above (avoid new "PUDO"
> naming), this file is named `docs/tcg-locker-architecture.md`. This is a deliberate,
> flagged deviation, not an oversight.

---

## 1. Scope

### 1.1 In scope — first release (MVP)

**Locker-to-Locker (L2L) only.** Warehouse staff deposit the packed order at a TCG locker;
TCG delivers it to the customer's chosen destination locker. The current L2L contract
identifies the collection side as `type: locker` — **not** a specific source terminal.

The ERPNext fulfilment origin still matters operationally and is persisted on the order:
it identifies which warehouse packs and deposits, drives staff workflow, and gates
split-order eligibility. It is **not** sent as a `terminal_id` in the L2L request.

### 1.2 Deferred (not in first release)

- **Door-to-Locker (D2L)** — deferred until real production-account pricing is inspected,
  collection behaviour + SLA are confirmed, and the L2L pilot yields operational data.
- Preferred drop-off/source locker as *operational guidance* (not part of the L2L API request).

### 1.3 Explicitly out of scope (all releases covered here)

Door-to-Door; Locker-to-Door; returns; billing/invoice API; automatic booking at
checkout/payment/status-change; Checkout Blocks compatibility; map-based locker selection;
PostNet integration; production deployment.

### 1.4 Preserved behaviour (must not regress)

Existing TCG (Courier Guy / ShipLogic) and MDS Collivery **door** rates; ERPNext
stock-based dispatch routing; SLW location selection; split-shipment protection; Local
Pickup; free-shipping rules; fulfilment statuses & tracking; HPOS compatibility;
classic-checkout compatibility; current mobile + desktop checkout behaviour.

---

## 2. API contract (current TCG Locker)

### 2.1 Origins (configurable; sandbox by default)

| Role | Origin | Notes |
|---|---|---|
| **Sandbox origin** (default) | `https://sandbox.api-pudo.co.za` | Documented sandbox host. |
| Sandbox API base | `https://sandbox.api-pudo.co.za/api/v1` | `/api/v1` for data/rates/shipments/tracking. |
| Production origin | `https://api-tcg.co.za` **(unproven)** | Observed on `customer.tcglocker.co.za` frontend only. **Do not treat as proven.** Verify with TCG or an authorised read-only `GET /api/v1/lockers-data` before any production work. |
| Production API base | `https://api-tcg.co.za/api/v1` **(unproven)** | Same caveat. |

**One setting, deterministic derivation (no contradiction).** There is a **single** stored
setting, `tcg_locker_api_url`, holding the **API base** (e.g.
`https://sandbox.api-pudo.co.za/api/v1`). It is validated on save to contain a `/api/v1`
segment. The **origin** is derived deterministically from it by stripping the trailing
`/api/v1[/]` (equivalently `scheme://host[:port]`):

```
api_base = rtrim(tcg_locker_api_url, '/')                       # …/api/v1
origin   = preg_replace('#/api/v1/?$#', '', api_base)           # scheme://host
```

- `/api/v1/*` endpoints (E1–E10) → composed from `api_base`.
- `/generate/*` label endpoints (E11–E12) sit **outside** `/api/v1` → composed from the
  derived `origin`.

There is no second "origin" setting to fall out of sync; §6.1 stores only `tcg_locker_api_url`.

### 2.2 Authentication

```
Authorization: Bearer <API key>
Accept: application/json
Content-Type: application/json
```

The token is never exposed or logged (see §11). The waybill/sticker endpoints place the
key in a **query parameter** — those URLs are composed server-side only, never logged,
never returned to the browser, and always proxied through a capability-and-nonce-protected
admin request.

### 2.3 Endpoint table

| # | Method & path | Purpose | Phase |
|---|---|---|---|
| E1 | `POST /api/v1/rates/opt-in` | Account rate opt-in (one-time / capability check). | 1 |
| E2 | `POST /api/v1/rates` | Quote available L2L service levels for a destination locker. | 1, 3 |
| E3 | `GET  /api/v1/lockers-data` | Full locker dataset (cached; drives search). | 1, 3 |
| E4 | `GET  /api/v1/locker-rates` | Reference locker rate card (sizes/prices). | 1 |
| E5 | `POST /api/v1/shipments` | Create a shipment (irreversible; admin-only). | 4 |
| E6 | `PUT  /api/v1/shipments/{shipment_id}` | Update a shipment. | 4 (guarded) |
| E7 | `GET  /api/v1/shipments?<filter/range/sort>` | List shipments (diagnostics). | 5 |
| E8 | `GET  /api/v1/tracking/shipments?waybill={ref}` | Track by waybill (Bearer auth). | 5 |
| E9 | `GET  /api/v1/tracking/shipments?id={shipment_id}&include_parcels=false` | Track by shipment id. | 5 |
| E10 | `GET  /api/v1/shipments/pod/images?shipment_id={id}` | Proof-of-delivery images. | 5 (optional) |
| E11 | `GET  /generate/waybill/{shipment_id}?api_key={key}` | Waybill PDF (origin-relative; key in query). | 4 (proxied) |
| E12 | `GET  /generate/sticker/{shipment_id}?api_key={key}` | Sticker PDF (origin-relative; key in query). | 4 (proxied) |

**Forbidden endpoints/hosts:** `POST /api/v1/locker-rates-new`; the old AWS `execute-api`
host; any unauthenticated/public tracking endpoint; the old `api-pudo.co.za` docs page as a
contract source.

### 2.4 Request / response shapes

Field names below marked **(to confirm)** are derived from the published collection and the
integration checklist; they are verified against a sandbox fixture in Phase 1 before any
code depends on them. Names **not** marked are fixed by the corrected contract in the brief.

#### E2 — `POST /api/v1/rates` (L2L quote)

Request (current L2L contract — collection is `type: locker`, not a terminal):

```json
{
  "collection_address": { "type": "locker" },
  "delivery_address":   { "terminal_id": "CG929" }
}
```

- `delivery_address.terminal_id` — the customer's server-validated destination locker code.
- **No** `collection_address.terminal_id` is sent (no invented source terminal).
- **No** `parcels[]` array is sent in L2L quote/shipment unless an authenticated sandbox
  result proves it is accepted and required — the L2L contract selects capacity via the
  service-level code, not parcel dimensions.

Response shape — established from the published collection's L2L example (it mirrors the
existing ShipLogic door-rate shape this plugin already parses in
`ES_Carrier_ShipLogic::get_rates()`, `$r['service_level']['code']` + `$r['rate']`). The
response is `{ "rates": [ { … } ] }`; **only** the service levels that destination/account
returns are present. Per rate item:

```json
{
  "rates": [
    {
      "service_level": {
        "code": "L2LM - ECO",
        "name": "...",
        "box_type": "M",
        "box_type_name": "...",
        "dimensions": { "length": 60, "width": 41, "height": 19, "weight": 10 }
      },
      "rate": 0.00,
      "rate_excluding_vat": 0.00,
      "rate_revision_id": "..."
    }
  ]
}
```

Fields the client reads and persists per selected service:

| JSON path | Meaning |
|---|---|
| `rates[].service_level.code` | e.g. `L2LM - ECO`. Persisted verbatim → `_es_tcg_locker_service_code`. |
| `rates[].service_level.name` | Human name → `_es_tcg_locker_service_name`. |
| `rates[].service_level.box_type` | Size class (XS…XL) → `_es_tcg_locker_box_code`. |
| `rates[].service_level.box_type_name` | Size display name → `_es_tcg_locker_box_name`. |
| `rates[].service_level.dimensions` | Box dims + max weight; used for the packer fit-check against the **returned** box → `_es_tcg_locker_box_dims` / `_es_tcg_locker_box_max_weight`. |
| `rates[].rate` | Customer-facing total **inclusive of VAT** (top-level on the rate item, **not** nested). |
| `rates[].rate_excluding_vat` | Net (ex-VAT), top-level on the rate item. |
| `rates[].rate_revision_id` | Quote fingerprint, top-level on the rate item; persisted, re-checked at booking. |

**(to confirm) in Phase 1** against a sandbox fixture: the exact key names *inside*
`service_level.dimensions` (length/width/height/weight vs. l/w/h/max_weight) and
`box_type_name`. The nesting structure above (service_level object; `rate` /
`rate_excluding_vat` / `rate_revision_id` directly on the rate item) is **locked** from the
published collection, not deferred.

Known service-level codes (examples; not every destination returns every size):
`L2LXS - ECO`, `L2LS - ECO`, `L2LM - ECO`, `L2LL - ECO`, `L2LXL - ECO`.

#### E3 — `GET /api/v1/lockers-data`

Full national locker list. Each locker (fields **to confirm** in Phase 1) minimally yields:
terminal/locker code, name, address, town/city, postcode, latitude, longitude, type,
opening hours, supported box sizes. Cached 24h; last-known-good retained (§8).

#### E5 — `POST /api/v1/shipments` (L2L booking)

```json
{
  "collection_address": { "type": "locker" },
  "special_instructions_collection": "None",
  "collection_contact": { "name": "...", "email": "...", "mobile_number": "..." },
  "delivery_address":   { "terminal_id": "CG929" },
  "delivery_contact":   { "name": "...", "email": "...", "mobile_number": "..." },
  "service_level_code": "L2LL - ECO"
}
```

- `service_level_code` — the exact persisted code from the accepted quote.
- `delivery_address.terminal_id` — the persisted, re-validated destination locker.
- Contacts are populated at **booking time** only (name/email/mobile), from HPOS-safe order
  APIs. A stable customer reference derived from site + order id is included where the
  contract supports it **(field to confirm)**.
- Response yields a shipment id + tracking/waybill reference **(field names to confirm)**,
  persisted per §10.

#### E8/E9 — tracking

`GET /api/v1/tracking/shipments?waybill={ref}` (or `?id={shipment_id}&include_parcels=false`)
with **Bearer** auth. Returns the current status string (enumerated in §12) plus history
**(shape to confirm)**.

---

## 3. Where TCG Locker plugs into the current code (seam map)

Verified against v1.12.14 (`e2a6298`). File:line references are current.

### 3.1 Rate production & the `_locker` suffix — already reserved

`ES_Shipping_Method::calculate_shipping()` builds WC rates via `$this->add_rate([...])`
with IDs `"{$this->id}_{$tier}"`, `_free`, `_fallback`, `_bulk_delivery`,
`_bulk_delivery_quote` (`includes/class-es-shipping-method.php` ~L526–L864).

The site free-shipping filter `woocommerce_package_rates`
(`erpnext-shipping.php:180`) **already excludes any rate whose id ends in `_locker`**
from the "hide cheapest carrier rate when free shipping is present" logic
(`erpnext-shipping.php:333`, `substr($rate_id,-7) !== '_locker'`). **The locker rate must
therefore end in `_locker`** to be protected — this is a pre-existing contract, honoured,
not invented. TCG Locker's rate id will be `"{$this->id}_locker"`.

The locker rate is produced by a dedicated `maybe_add_locker_rate()` path inside
`calculate_shipping()` (Phase 3), gated on `tcg_locker_enabled === 'yes'` **and** a
server-validated destination locker in session. It is **not** a `get_carriers()` door
carrier and does **not** emit tiered economy/standard/express rates.

### 3.2 Carrier instantiation (actual seam — CLAUDE.md drift)

`ES_Shipping_Method::get_carriers()` (~L403) **hardcodes** `ES_Carrier_ShipLogic` and
`ES_Carrier_Collivery`, each gated on `{tcg,mds}_enabled` + token. CLAUDE.md's claim that
"carrier modules self-register via filter (`es_shipping_carriers`)" is **stale — no such
filter exists.** TCG Locker follows the actual hardcoded-instantiation convention but as a
separate locker path, not a `ES_Carrier_Base` door carrier.

### 3.3 Fulfilment plan types & split protection

`calculate_shipping()` resolves a plan of `type` ∈ {`single`, `chooseable`, `split`}
(ERPNext stock routing, ~L579/L617/L714–L720). `split` plans set `_es_is_split=1` rate meta;
the free-shipping filter strips free shipping for splits (`erpnext-shipping.php:264`).
**TCG Locker is offered for `single` and `chooseable` plans only; `split` plans never offer
a locker rate** (requirement 6). For `chooseable`, the cheapest valid origin is selected and
the chosen origin id is persisted on the order/shipping item.

### 3.4 Checkout selector seam (analogue: pickup selector)

The pickup selector supplies the reusable seam conventions
(`includes/class-es-fulfillment-checkout.php`):

- validate on `woocommerce_checkout_process` (L16);
- persist on `woocommerce_checkout_create_order` (L19, HPOS-safe order object);
- selection stored in `WC()->session` (`es_pickup_location_id`, L57/L411);
- nonce `es_checkout_pickup` (L127/L403); AJAX `es_save_checkout_pickup` registered for
  **both** `wp_ajax_` and `wp_ajax_nopriv_` (L450–L451).

**Render seam — corrected.** The pickup selector renders on `woocommerce_after_shipping_rate`
(L13), which only fires **beneath an existing rate**. The locker selector must appear
**before** any `_locker` rate exists (requirement 3), so it **cannot** use that hook as its
entry point. The locker selector's primary placement is the classic-checkout review-table
action **`woocommerce_review_order_after_shipping`**, which fires inside
`checkout/review-order.php` immediately after the shipping rows on every checkout AJAX
refresh, regardless of whether any locker rate is present. That control renders either:

- the **"Choose a TCG Locker for cheaper delivery"** prompt (no locker selected yet), or
- the selected-locker summary + a **"Change locker"** control (locker selected).

**Recalculation.** After the select/clear AJAX persists the choice to session, the plugin JS
triggers WooCommerce's standard classic-checkout recalculation with
`jQuery(document.body).trigger('update_checkout')`, which re-runs `calculate_shipping()` and
re-renders the review table (and thus the selector) via the same hook. Optionally, once a
`_locker` rate exists, a compact "Change locker" affordance may **also** render beneath it via
`woocommerce_after_shipping_rate` on the `_locker` rate id — but that is secondary; the
`woocommerce_review_order_after_shipping` control is the authoritative, always-present entry
point. (Blocks checkout is out of scope — this uses classic-checkout hooks only.)

TCG Locker session/nonce/AJAX: session `es_tcg_locker_selection`, nonce `es_checkout_locker`,
AJAX actions `es_tcg_locker_search` + `es_tcg_locker_select` (priv + nopriv).

### 3.5 Tracking provider registry & polling seam

`ES_Fulfillment_Tracking::$providers` (`includes/class-es-fulfillment-tracking.php:17`) is a
slug→{name,url,aliases} registry with `normalize_provider()` alias resolution. Polling is
dispatched in `ES_Fulfillment_Cron::poll_single_order()`
(`includes/class-es-fulfillment-cron.php:159`) by a hardcoded
`if 'the-courier-guy' … elseif 'mds-collivery'` branch, with per-provider stats.

TCG Locker adds a canonical `tcg-locker` provider (Phase 5) plus a new polling branch
calling the **authenticated** `GET /api/v1/tracking/shipments` (Bearer) — **not** a public
URL template, and **not** the existing TCG Courier-Guy portal token (which cannot see locker
shipments). Tracking items are AST-compatible (`_wc_shipment_tracking_items`), appended once.

**Poll-selection gap — corrected (BLOCKER fix).** The existing poll **selection** query
(`ES_Fulfillment_Cron::poll()`, `class-es-fulfillment-cron.php:85`/`:104`) only fetches
orders whose status is `completed` or `partially-shipped`. A TCG Locker order is booked while
still in **`processing`** (paid, not yet shipped); under the current query it would **never**
be polled, so cron could never observe `customer-deposited`/`in-locker` and perform the first
forward transition to Shipped. Phase 5 therefore adds a **separate, meta-qualified selection
query** (unioned with the existing one, same anti-starvation + `BATCH_SIZE` handling):

```
status     = [ 'processing', 'on-hold', 'completed', 'partially-shipped' ]   # includes pre-shipment
meta_query = _es_tcg_locker_shipment_id EXISTS
             AND _es_tcg_locker_booking_status = 'booked'
```

This selects booked locker orders regardless of pre-shipment WC status, so cron can drive the
first transition (`processing` → `completed`/"Shipped") once TCG reports an accepted
handoff/transit state (§12). Selection stops naturally once the order reaches a terminal state
(`delivered`) — the mapping never regresses and unknown/terminal statuses do not re-trigger
transitions. The union must de-duplicate (a `completed` locker order matches both queries).
Non-locker orders are unaffected: the existing query still governs door-carrier tracking.

### 3.6 Admin action seam (capability + per-order nonce)

`includes/class-es-fulfillment-admin.php` establishes the conventions the booking action
mirrors: per-order admin nonces `check_admin_referer('es_status_'.$order_id)` (L484),
`wp_nonce_field('es_tracking_'.$order_id, ...)` (L943), AJAX
`check_ajax_referer('es_tracking_'.$order_id)` (L1258/L1304); capability via
`ES_Warehouse_Role::current_user_can_fulfill()` (warehouse actions) and
`current_user_can('manage_woocommerce')` (tracking edits). The "Book TCG Locker Shipment"
action (Phase 4) uses a per-order nonce `es_tcg_locker_book_{order_id}`, a booking-capable
capability, and a booking mutex (§10).

### 3.7 HPOS-safe meta

The plugin already uses the WC CRUD order API (`$order->get_meta()`,
`update_meta_data()`, `save()`) and declares `custom_order_tables` compatibility
(`erpnext-shipping.php:30–32`). All new TCG Locker meta uses the same order-object API —
**no direct post-meta access.**

### 3.8 Rate cache seam

`ES_Rate_Cache` builds transient keys `es_ship_<md5(origin|dest|parcels)>`, TTL 900
(`includes/class-es-rate-cache.php`). TCG Locker uses **separate** cache namespaces whose
key includes the environment/base URL (§9) so sandbox and production never collide.

---

## 4. Packing policy

### 4.1 Correction to the brief (estimator drift)

The brief states the existing parcel estimator "truncates fractional quantities with
`intval()`". **This is stale.** `ES_Parcel_Estimator::estimate()`
(`includes/class-es-parcel-estimator.php:35`) already uses
`max(0.01, floatval($item['qty']))` — the `intval()` truncation was fixed, with a comment
recording the prior bug. Loose per-kg malt quantities are **not** currently undercounted by
quantity.

The estimator is still unfit for **locker eligibility** for different reasons, which is why a
dedicated packer is required:

- it tracks only the **maximum single-item dimensions** — it never accumulates volume;
- it applies no box-fit check, no rotation, and no per-box weight/dimension ceiling;
- it exists to feed door-carrier parcel arrays and must **not** be changed by this feature.

### 4.2 Dedicated TCG Locker packer (`ES_TCG_Locker_Packer`, Phase 2)

A pure, testable class (no WP/WC runtime dependency). **The circular dependency between "the
packer needs boxes" and "boxes come from `/rates`" is resolved by splitting the packer into
two box-independent-then-box-aware methods**, so nothing calls `/rates` before the packer
and nothing packs before `/rates`:

1. **`compute_requirements(cart_lines) → requirements | ineligible`** — box-**independent**.
   Runs **before** `/rates`. Produces the packed-order profile: total weight (float),
   per-item rotated bounding dimensions, and **cumulative volume** (with the conservative
   fill factor applied). Returns a structured ineligibility reason immediately for
   box-independent failures (missing/non-positive weight, missing dimensions, an
   explicitly locker-ineligible product) — in which case `/rates` is **never** called.

2. **`fits_box(requirements, box) → bool`** and
   **`select_smallest(requirements, boxes[]) → box | no_box_fits`** — box-**aware**. Run
   **after** `/rates`, over the boxes extracted from the returned `service_level` objects.
   Verifies every item physically fits the candidate box (rotated), enforces the box's
   actual maximum weight and cumulative-volume ceiling, and picks the **smallest fitting**
   box among those the API actually returned.

**Locked executable order (no circularity):**

```
locker selected (server-validated)
  → packer.compute_requirements(cart)            # box-independent; may short-circuit ineligible
  → [optional] cheap static XS…XL pre-check       # skip /rates for obviously-too-big orders
  → POST /api/v1/rates (destination terminal)     # returns service levels + their boxes
  → boxes = extract service_level.{box_type,dimensions} from returned rates
  → packer.select_smallest(requirements, boxes)   # authoritative fit against RETURNED boxes
  → chosen service level → _locker rate
```

The optional static XS…XL pre-check uses the documented catalogue **only** to short-circuit
grossly ineligible orders (e.g. > 20 kg / longer than XL) before spending a `/rates` call; it
**never** overrides a live response — actual availability and selection come from the
**returned** services. If the packer needs M but only XS and XL come back, XL (next larger
fitting) is chosen; if nothing returned fits → `no_box_fits` → TCG Locker is **not** offered.

Requirements enforced across the two methods:

- preserve float quantities and float weights;
- reject missing / non-positive weight (never optimistic on missing data);
- allow rotation of item dimensions;
- verify **every** item physically fits the candidate box (rotated);
- account for **cumulative volume**, not just max item dimensions;
- apply a **conservative fill factor** (documented constant, < 1.0);
- enforce the box's **actual maximum weight**;
- choose the **smallest valid box** among those the API actually returned;
- return structured ineligibility reasons (`too_heavy`, `too_long`, `volume_overflow`,
  `missing_dimensions`, `missing_weight`, `no_box_fits`, `product_excluded`);
- honour an explicit product-level "ship separately / locker ineligible" override;
- handle single-SKU ingredient kits with accurate packed dimensions well.

---

## 5. Pricing & tax

- `rate` is **VAT-inclusive**; `rate_excluding_vat` is net. The store is 15% VAT-taxable.
- The WC displayed total must **reconcile to the provider total without double taxation**.
  The customer charge is presented so the order's shipping line + tax equals the provider
  `rate` (incl VAT). Explicit tests cover a 15% taxable store (Phase 2/3).

Pricing modes (`tcg_locker_pricing_mode`):

| Mode | Customer charge |
|---|---|
| `live` | Follows the provider `rate`. |
| `fixed` | `tcg_locker_fixed_customer_price`; merchant absorbs/retains the difference. |
| free threshold | Charge becomes 0 when cart qualifies under `tcg_locker_free_shipping_threshold` — a **TCG-Locker-specific** threshold, never the general doorstep free-shipping threshold. |

Both **provider cost** (`rate`, `rate_excluding_vat`) and **customer charge** are persisted
on the order for later profitability reporting (§10).

---

## 6. Settings & data model

### 6.1 Global settings (WC method instance options), all default-disabled/safe

| Key | Default | Notes |
|---|---|---|
| `tcg_locker_enabled` | `no` | Master switch. |
| `tcg_locker_api_url` | sandbox API base | **The only** URL setting. Holds the API base (`…/api/v1`); sandbox by default; validated to contain `/api/v1`. The origin for `/generate/*` labels is **derived deterministically** from it (strip trailing `/api/v1`), not stored separately (§2.1). |
| `tcg_locker_api_token` | *(empty)* | **Password field, masked; preserve-on-blank; never rendered back into page source (see §11.1).** |
| `tcg_locker_rate_label` | `TCG Locker Delivery` | Customer-facing rate label. |
| `tcg_locker_pricing_mode` | `live` | `live` \| `fixed`. |
| `tcg_locker_fixed_customer_price` | `0` | Used when `fixed`. |
| `tcg_locker_free_shipping_threshold` | `0` | TCG-Locker-specific; 0 = disabled. |
| `tcg_locker_excluded_shipping_classes` | *(empty)* | Comma-separated slugs. |
| `tcg_locker_rate_timeout` | internal sane default | Not necessarily user-facing. |

### 6.2 Per-location field (dispatch origin operational mode)

Each `warehouse` location gains:

| Key | Default | Notes |
|---|---|---|
| `tcg_locker_dispatch_enabled` | `false` | Whether this warehouse may pack/deposit L2L orders. |

**No source `terminal_id` field is added** — the L2L contract sends
`collection_address.type = locker`, not a specific collection terminal. `collection_point`
locations are **never** TCG Locker dispatch origins.

> The original brief's `pudo_source_mode` / `pudo_source_locker_code` per-location fields are
> **removed** by the L2L correction and are not implemented.

### 6.3 Order & shipping-item metadata (`_es_tcg_locker_*`, HPOS-safe)

Persisted via the order object CRUD API. Minimum set:

| Meta key | Contents |
|---|---|
| `_es_tcg_locker_dest_code` | Destination locker/terminal code. |
| `_es_tcg_locker_dest_name` | Destination locker name. |
| `_es_tcg_locker_dest_address` | Destination address. |
| `_es_tcg_locker_dest_lat` / `_es_tcg_locker_dest_lng` | Coordinates. |
| `_es_tcg_locker_dispatch_location_id` | ERPNext-selected origin warehouse (persisted even though not sent as a terminal). |
| `_es_tcg_locker_service_code` | Exact `service_level_code`. |
| `_es_tcg_locker_service_name` | Service display name. |
| `_es_tcg_locker_box_code` / `_es_tcg_locker_box_name` | Fitted box. |
| `_es_tcg_locker_box_dims` / `_es_tcg_locker_box_max_weight` | Packed box spec. |
| `_es_tcg_locker_packed_weight` | Packer's computed packed weight. |
| `_es_tcg_locker_provider_rate` | Provider `rate` (incl VAT). |
| `_es_tcg_locker_provider_rate_ex_vat` | `rate_excluding_vat`. |
| `_es_tcg_locker_customer_charge` | Amount charged to customer. |
| `_es_tcg_locker_rate_revision_id` | Quote fingerprint. |
| `_es_tcg_locker_quote_ts` | Quote timestamp. |
| `_es_tcg_locker_booking_status` | `none` \| `booking` \| `booked` \| `ambiguous` \| `error`. |
| `_es_tcg_locker_shipment_id` | Provider shipment id (duplicate-prevention key). |
| `_es_tcg_locker_tracking_ref` | Waybill / tracking reference. |
| `_es_tcg_locker_booked_ts` | Booking timestamp. |
| `_es_tcg_locker_last_error` | Sanitised, credential-free last booking error. |

Recipient/deposit PINs are **not** stored unless a later requirement proves it necessary.
Constants for these keys are defined once (Phase 1) and referenced everywhere.

---

## 7. Rate flow, rate id & free-shipping interaction

- Rate id: `"{$this->id}_locker"` (e.g. `erpnext_shipping_2_locker`).
- Protected by the pre-existing `_locker` exclusion in `woocommerce_package_rates`
  (§3.1) — a free doorstep rate does **not** strip the locker rate, and the locker rate does
  **not** get treated as the "cheapest carrier rate" to hide.
- Before a locker is selected, the checkout shows a **"Choose a TCG Locker for cheaper
  delivery"** control — **never** a fake zero-cost shipping rate. No provider call is made
  until a locker is chosen (requirement 3).
- If selection, validation, or quotation fails **after** the customer chose TCG Locker, the
  locker rate is **removed** and an actionable message is shown; other rates remain. There is
  **never** a silent fallback from TCG Locker to another service (requirement 7).

---

## 8. Locker cache policy

- `lockers-data` cached for **24h** under a namespaced key (§9).
- The **last known-good** list is retained separately and used for read-only fallback when a
  refresh fails.
- An empty or error response **never** overwrites good cached data and is **never** cached as
  success (requirement 8).
- Search returns a **limited** result set to the browser, never the full national dataset.

---

## 9. Cache & rate keys

| Cache | Key shape | TTL |
|---|---|---|
| Locker dataset | `es_tcg_locker_data_<md5(base_url)>` | 24h |
| Locker last-known-good | `es_tcg_locker_data_lkg_<md5(base_url)>` | none (persist) |
| L2L quote | `es_tcg_locker_rate_<md5(base_url \| dest_code \| box_code \| packed_fingerprint)>` | short (rate timeout window / minutes) |

Every key includes an environment discriminator (`base_url`) so sandbox and production data
never collide. Quote keys include the packed-order fingerprint and destination code so a
different cart or destination re-quotes.

---

## 10. Booking sequence & idempotency (Phase 4)

Booking is a **manual, admin-only, irreversible, potentially chargeable** action:

1. Capability check (booking-capable role) + per-order nonce `es_tcg_locker_book_{order_id}`.
2. Order must be paid/processable.
3. Acquire a **per-order booking mutex**; refuse concurrent booking.
4. If `_es_tcg_locker_shipment_id` already exists → refuse (never create a second shipment).
5. Re-validate order, destination locker, packed parcel, and origin.
6. **Fresh** `/rates` lookup; refuse if the rate has drifted beyond a configurable/sane
   tolerance from the persisted quote.
7. `POST /api/v1/shipments` with the exact persisted `service_level_code`, validated
   destination `terminal_id`, `collection_address.type = locker`, and contacts populated now.
8. On success: persist shipment id + tracking ref, set `booking_status = booked`, append one
   AST-compatible tracking item, write a concise credential-free order note.
9. On an **ambiguous timeout** (acceptance unknown): set `booking_status = ambiguous`, write a
   note, and **never auto-retry** — a human resolves it.
10. Waybill/sticker are fetched only via the authenticated admin proxy (§11.2).

---

## 11. Privacy & security

The API token must never appear in frontend HTML/JS, logs, order notes, REST/AJAX responses,
exception messages, or downloadable label URLs.

### 11.1 Token storage (deviation from existing pattern — flagged)

The existing password rows render the stored secret straight into the input `value`
attribute (`ES_Admin_Page::render_password_row()`, `class-es-admin-page.php:907–911`), i.e.
current tokens are present in page source, and the settings whitelist (L160) has no
preserve-on-blank. **TCG Locker will not copy this.** Its token field renders a masked
placeholder (never the stored value), and an empty submit **preserves** the existing token
rather than clearing it. (Retrofitting the existing rows is out of scope for this feature.)

### 11.2 Label proxy

Waybill/sticker URLs (E11/E12) carry the key in a query param. They are composed
server-side, never logged, never returned to the browser. Downloads go through a
capability-and-nonce-protected admin request that streams the PDF; the signed URL is never
exposed.

### 11.3 Server-side validation

The selected locker is **always** re-validated server-side against cached `lockers-data`
(and, at booking, freshly). Hidden checkout fields are never trusted (requirement 5). Stale
or tampered locker codes are rejected.

### 11.4 Consent / POPI

Contact details (name/email/mobile) are sent to TCG only at **booking time**, from
HPOS-safe order data, for the purpose of fulfilment.

---

## 12. Tracking status mapping (Codex must review before activation)

Tracking uses `GET /api/v1/tracking/shipments?waybill=...` with **Bearer** auth. Mapping is
**conservative and forward-only**. Unknown statuses are recorded but **never** advance WC
status.

**No WC advancement** (record only):
`quote-pending`, `payment-failed`, `submitted`, `deposit-pending`,
`collection-assigned`, `collection-unassigned`, `collection-rejected`,
`collection-exception`, `collection-failed`, `delivery-exception`,
`delivery-failed-attempt`, `returned-to-hub`, `cancelled`, `cancel-booking-expired`,
**and any unknown status.**

**Advance to WC `completed` ("Shipped")** — accepted handoff/transit:
`customer-deposited`, `courier-collected`, `collected`, `at-hub`, `in-transit`,
`at-destination-hub`, `delivery-assigned`, `out-for-delivery`, `in-locker`.

**Advance to WC `delivered`**:
`customer-collected`, `delivered`.

> Note the deliberate asymmetry Codex must confirm: **`in-locker` maps to Shipped, NOT
> delivered** (parcel is in the destination locker but not yet collected);
> **`customer-collected` maps to delivered.**

Full documented status vocabulary (for the formatter/labels): `quote-pending`,
`payment-failed`, `submitted`, `deposit-pending`, `customer-deposited`, `courier-collected`,
`collection-assigned`, `collection-exception`, `collected`, `at-hub`, `in-transit`,
`at-destination-hub`, `in-locker`, `customer-collected`, `delivered`, `cancelled`,
`cancel-booking-expired`.

---

## 13. Checkout sequence (Mermaid)

```mermaid
sequenceDiagram
    autonumber
    actor C as Customer (classic checkout)
    participant JS as Locker selector (plugin JS)
    participant AX as AJAX (nonce es_checkout_locker)
    participant CL as ES_TCG_Locker_Client
    participant PK as ES_TCG_Locker_Packer
    participant SM as ES_Shipping_Method
    participant WC as WooCommerce

    C->>JS: types search (name/town/postcode)
    JS->>AX: es_tcg_locker_search (limited results)
    AX->>CL: read cached lockers-data (24h; LKG fallback)
    CL-->>JS: matching lockers (name, addr, hours, sizes)
    C->>JS: selects destination locker
    JS->>AX: es_tcg_locker_select (server validates code)
    AX->>WC: store selection in session; update_checkout
    WC->>SM: calculate_shipping()
    SM->>SM: resolve ERPNext plan (single/chooseable/split)
    alt split plan
        SM-->>WC: no locker rate (split excluded)
    else single/chooseable
        SM->>PK: compute_requirements(cart)  [box-independent]
        PK-->>SM: packed weight + volume + rotated dims (or ineligible → no rate)
        SM->>CL: POST /api/v1/rates (collection type=locker, dest terminal_id)
        CL-->>SM: returned L2L service levels (each with service_level.box_type + dimensions)
        SM->>PK: select_smallest(requirements, returned boxes)
        PK-->>SM: smallest fitting service (or no_box_fits → no rate)
        SM->>SM: apply pricing mode (live/fixed/free)
        SM-->>WC: add_rate id "..._locker" (+ persist-able meta)
    end
    Note over WC: free-shipping filter keeps _locker (erpnext-shipping.php:333)
    C->>WC: places order
    WC->>SM: woocommerce_checkout_create_order → persist _es_tcg_locker_* + chosen origin
    Note over WC: no provider booking at checkout
```

---

## 14. Booking / waybill / tracking sequence (Mermaid)

```mermaid
sequenceDiagram
    autonumber
    actor A as Admin (booking-capable)
    participant OA as Order admin panel
    participant BK as Booking handler
    participant CL as ES_TCG_Locker_Client
    participant API as TCG Locker API
    participant TR as Tracking/cron

    A->>OA: opens paid order (locker selected)
    OA-->>A: shows dest locker, origin, box, provider quote, customer charge, state
    A->>BK: Book TCG Locker Shipment (nonce es_tcg_locker_book_{id})
    BK->>BK: cap check + paid guard + acquire mutex
    alt shipment_id already stored
        BK-->>A: refuse (no duplicate)
    else
        BK->>BK: revalidate order/locker/parcel/origin
        BK->>CL: fresh POST /api/v1/rates (drift check)
        alt rate drift > tolerance
            BK-->>A: refuse; keep state=none
        else within tolerance
            BK->>CL: POST /api/v1/shipments (exact service_code, contacts now)
            CL->>API: create shipment (Bearer)
            alt success
                API-->>BK: shipment_id + tracking_ref
                BK->>BK: persist meta; append 1 AST tracking item; order note
                BK-->>A: booked
            else ambiguous timeout
                BK->>BK: state=ambiguous; note; NO auto-retry
                BK-->>A: pending — resolve manually
            end
        end
    end
    A->>OA: download waybill/sticker
    OA->>CL: authenticated admin proxy (E11/E12; key server-side only)
    CL->>API: GET /generate/{waybill,sticker}/{id}?api_key=...
    API-->>A: streamed PDF (signed URL never exposed)
    TR->>CL: poll GET /api/v1/tracking/shipments?waybill= (Bearer)
    CL-->>TR: status → conservative forward-only WC mapping (§12)
```

---

## 15. Error & timeout policy

| Situation | Policy |
|---|---|
| Quote HTTP error / bad shape | No locker rate; other rates remain; nothing cached as success. |
| Empty `lockers-data` refresh | Keep last-known-good; do not overwrite; log (credential-free). |
| Customer chose locker but quote fails | Remove locker rate + actionable message; **no** silent fallback. |
| Booking ambiguous timeout | `booking_status=ambiguous`; **no** auto-retry; human resolves. |
| Duplicate booking attempt | Refuse if `shipment_id` exists; mutex blocks concurrency. |
| Any provider/network error | Sanitised, credential-free message in logs/notes/UI. |

Rate/opt-in/quote calls use `tcg_locker_rate_timeout`; the client never leaks the token in
`is_wp_error()` messages or HTTP bodies it logs. Quote failures are recorded in the existing
diagnostics quote log (`ES_Quote_Log`) under a `tcg-locker` slug (Phase 1/5), postcode masked.

---

## 16. Unresolved provider questions (resolve in Phase 1 against sandbox fixtures)

1. **Production origin** — is `https://api-tcg.co.za` correct? Verify with TCG or an
   authorised read-only `GET /api/v1/lockers-data`. Default stays sandbox until proven.
2. **Exact `/rates` response field names** — service name, box type, box dimensions, box max
   weight, `rate_revision_id`. Capture a sandbox fixture.
3. **`/shipments` response** — exact keys for shipment id and tracking/waybill reference.
4. **`lockers-data` record shape** — field names for code, coordinates, opening hours,
   supported sizes, type.
5. **Customer reference field** — does `POST /shipments` accept a merchant reference, and
   under what key?
6. **`rates/opt-in` semantics** — one-time account action vs. per-request precondition;
   response shape; error when not opted-in.
7. **Tracking response shape** — status key name; whether history array is included.
8. **Whether `/rates` ever requires `parcels[]`** — default is to send none for L2L; confirm.
9. **POD images** (E10) response shape — optional Phase 5.

None of these block Phase 0. Each has a safe default (sandbox, send-nothing-extra,
confirm-before-depend).

---

## 17. Test matrix

Repo-local harness (Phase 1+): smallest maintainable pure-PHP test runner for packer,
payload-builder, response-shape, pricing/tax, and status-mapping logic. No heavy production
test dependency. WordPress-runtime behaviour that cannot be automated locally is documented
as a sandbox UAT procedure (Phase 6).

### 17.1 Contract-safety tests (required by the brief)

- No call is ever made to `/locker-rates-new`.
- Sandbox URLs use `/api/v1`.
- L2L quote/shipment payload uses `collection_address.type = "locker"`.
- No source `terminal_id` is invented.
- Destination `terminal_id` is server-validated before use.
- **Returned** services (not a static box catalogue) determine availability.
- If M absent but L fits → **L** selected.
- If only XS and XL returned → smallest **fitting** selected.
- Tracking requests send **Bearer** auth.
- `customer-collected` → WC `delivered`.
- `in-locker` → **not** delivered (maps to Shipped).
- Unknown status → **no** WC advancement.
- Label URLs containing the API key are never logged or exposed.
- **No real shipment is created** in any automated test.

### 17.2 Packer tests (Phase 2)

4.5 kg kit fits S or M by dimensions; 5.8 kg kit selects correct box; 6.5 kg kit needs the
correct larger box; 10–11 kg kit; fractional loose-malt quantity; 20 kg exact limit;
>20 kg rejection; one dimension too long; total-volume overflow; missing weight/dimensions;
rotated fit; multiple cart lines; explicit locker-ineligible product;
source/destination box-size intersection (M needed, only XS+XL returned → XL).

### 17.3 Pricing/tax tests (Phase 2/3)

15% taxable store reconciles WC total to provider `rate` without double taxation;
`live`/`fixed`/`free-threshold` modes; TCG-Locker threshold independent of doorstep
threshold; both provider cost and customer charge persisted.

### 17.4 Regression checks (Phase 3, mostly manual/sandbox UAT)

Local Pickup unchanged; door TCG + Collivery rates remain; free doorstep logic remains;
`_locker` not stripped when free shipping present; heavy-class ingredient kits can use
lockers when packed weight/dimensions fit; split fulfilment never offers TCG Locker;
changing SLW location re-quotes/invalidates the locker origin; stale/tampered locker codes
cannot be submitted.

---

## 18. Phase plan (revised for L2L MVP)

| Phase | Deliverable |
|---|---|
| **0** | This document (contract lock). No PHP. |
| 1 | `ES_TCG_Locker_Client` (injectable HTTP seam, Bearer, lockers cache/search, `/rates`, `/shipments`, tracking, label/sticker methods), settings + per-location `tcg_locker_dispatch_enabled`, default-disabled bootstrap, response fixtures + tests. No UI, no live shipment. |
| 2 | `ES_TCG_Locker_Packer` + tests (smallest fitting from **returned** services). No checkout change. |
| 3 | Classic-checkout destination-locker search/select, L2L quote, `_locker` rate, split exclusion, exact quote persistence, PUDO-specific pricing, graceful failure. No booking. |
| 4 | HPOS-safe persistence, admin panel, manual idempotent L2L booking (cap+nonce+mutex+drift+ambiguous-timeout), authenticated admin label/sticker proxy. No auto-book. |
| 5 | Authenticated `tcg-locker` tracking provider + **meta-qualified poll-selection query incl. pre-shipment statuses** (§3.5), conservative forward-only mapping, diagnostics. No duplicate tracking items. |
| 6 | README/CLAUDE/CHANGELOG, admin setup guidance, sandbox UAT runbook, production activation checklist, rollback, profitability reporting notes, full syntax + test run, release-zip comparison, version bump (likely v1.13.0). No tag/push/deploy. |

---

*End of Phase 0 contract lock.*
