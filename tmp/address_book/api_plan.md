# Implementation Plan — Region/Province/City/Barangay picker in the delivery address book

Scope: `?page=profile` Add/Edit delivery address modal **and** checkout Delivery details.
Guide for API fetching: `tmp/address_book/api_address.html` (psgc.cloud v2 cascade).

## Revision history

- **r1** — original plan (full-screen overlay picker).
- **r2 (current)** — pivot: the picker is presented as a **Bootstrap modal dialog** instead of a
  full-screen overlay. Only `views/partials/address_picker.php` changes (markup + scoped CSS + JS);
  `profile.php` / `checkout.php` continue to call `PsgcPicker.attach()` unchanged.

## Locked decisions

1. **Picker UX** — Step-by-step picker (search + breadcrumb trail + region grouping + Metro Manila
   "flat" province level) ported from `api_address.html`, presented as a **Bootstrap modal dialog**
   (`modal-dialog-centered modal-dialog-scrollable`) **stacked above `#addressModal`** with explicit
   z-index handling. Not a full-screen overlay.
2. **Storage** — Add `region` + `barangay` columns to `member_addresses` (plus PSGC `*_code`
   columns for clean edit-mode re-resolution).
3. **Scope** — Profile modal **and** checkout carry the new Region/Barangay fields.

## Current-state notes

- Picker markup/JS: `views/partials/address_picker.php` (included once per page).
  - **Current implementation is a full-screen overlay** `#psgcPicker`
    (`position:fixed; inset:0; z-index:2000; background:#f5f5f5`, inner sheet `max-width:560px`),
    toggled via the `hidden` attribute, with manual body-scroll lock, manual focus save/restore and
    a custom Esc handler. **This is what r2 converts to a Bootstrap modal.**
  - Public API: `PsgcPicker.attach({ trigger, onPick })`; `onPick(picked)` receives
    `{ region, province, city, barangay }`, each `{ code, name }` (province/barangay may be null).
  - Callers: `views/member/profile.php` (inside `DOMContentLoaded`) and
    `views/member/checkout.php` (inline IIFE at parse time).
- Profile address modal: `views/member/profile.php` (fields: label, recipient_name, phone,
  location trigger + hidden region/province/city/barangay(+codes), address_line, postal,
  is_default). POSTs to `?page=shop_address_save`.
- Controller: `MemberController::saveAddress()` → `collectAddressInput()` → `MemberAddress`.
- Table `member_addresses` now has `region`, `barangay` and `*_code` columns
  (`migrate_member_addresses_region.sql`; also in `install.sql`).
- Checkout: `views/member/checkout.php`; writes back via
  `MemberController::maybeSaveCheckoutAddress()`.

## API contract (psgc.cloud/api/v2)

- Base: `https://psgc.cloud/api/v2`
- `/regions`
- `/regions/{code}/provinces`
- `/regions/{code}/cities-municipalities`  (Metro Manila: no provinces)
- `/provinces/{code}/cities-municipalities`
- `/cities-municipalities/{code}/barangays`
- Response: `{ data: [{code,name}], links: { next } }`; follow `links.next` for pagination.
- Region grouping by first two digits of the region code (Metro Manila / North Luzon /
  South Luzon / Visayas / Mindanao), per the guide's `GROUPS`.

## Tasks

### 1. Schema  *(done)*
- `migrate_member_addresses_region.sql` (idempotent): `region`, `barangay`,
  `region_code`, `province_code`, `city_code`, `barangay_code`; `install.sql` updated to match.

### 2. Model — `models/MemberAddress.php`  *(done)*
- New fields in `FIELDS`, `create()`, `update()`.

### 3. Controller — `controllers/MemberController.php`  *(done)*
- `collectAddressInput()` reads region/barangay + codes; `placeOrder()` `$addr` carries
  `shipping_barangay`/`shipping_region` (+ codes); `maybeSaveCheckoutAddress()` persists them.
- `shop_checkout_old_input()` / `shop_checkout_remember_input()` allow-lists include the new
  shipping fields.

### 4. Profile view — `views/member/profile.php`  *(done)*
- Single read-only trigger "Region, Province, City, Barangay" + hidden inputs (+ codes); the
  `<input name="address_line">` is labelled "Street Name, Building, House No." and sits below the
  trigger; `postal` after it.
- Calls `PsgcPicker.attach({ trigger: '#addr_loc', onPick })`; edit button carries
  region/barangay/codes data attributes.

### 5. Picker partial — `views/partials/address_picker.php`  *(r2 — TO DO)*
Convert the full-screen overlay to a Bootstrap modal:
- Markup:
  - `#psgcPickerModal.modal.fade` → `.modal-dialog.modal-dialog-centered.modal-dialog-scrollable`
    → `.modal-content`.
  - Header: back button (left), title (e.g. "Select Region"), close `btn-close` (right).
  - Body: search `#psgcQ` (sticky), breadcrumb `#psgcCrumbs`/`#psgcTrail` + Reset, section title
    `#psgcSec`, list `#psgcList` (letter grouping, loading / "No matches" / retry).
- Scoped CSS: delete all full-screen layout rules; keep only list/breadcrumb/letter styling using
  Bootstrap utilities where possible.
- JS:
  - `attach()` only registers the trigger click + caches bindings; **create the `bootstrap.Modal`
    instance lazily on first `open()`** (checkout's attach runs before footer loads Bootstrap).
  - `open(cb)`: reset `draft`, store callback, `modal.show()`.
  - `commit()`: `modal.hide()` then invoke `onPick(picked)`.
  - Options: `keyboard:false`, `backdrop:'static'`; keep the partial's own Esc/close handling so
    the underlying `#addressModal` is never closed by the picker.
  - Focus `#psgcQ` on `shown.bs.modal`.
- Modal stacking:
  - On show, set the picker modal `z-index` above the parent (e.g. `1065`).
  - On `shown.bs.modal`, set the **newest** `.modal-backdrop` (last in `document.body`) to ~`1060`
    so the parent address modal is dimmed and cannot show through.
  - On `hidden.bs.modal`, if any other `.modal.show` remains, ensure `body.modal-open` stays
    (guards a Bootstrap scroll-lock reset).
  - Deterministic in JS; avoids brittle `.modal-backdrop:last-of-type` CSS.
- Keep fetch/cache/pagination, `buildGroups()`, `order()` (Metro Manila flat), `load()`,
  `render()`, `paint()` unchanged apart from the show/hide calls.

### 6. Checkout view — `views/member/checkout.php`  *(done; inherits the modal via the partial)*
- Same trigger + hidden `shipping_*` fields; saved-address dropdown and default prefill populate
  region/barangay(+codes); trigger renders on load.

### 7. Display / prefill consistency  *(done)*
- Profile address card renders the full chain; checkout prefill includes region/barangay.

### 8. Verification (manual — no test runner for the picker)
- Profile: trigger opens the **modal** (not full-screen); it sits **above** the address modal with
  the address modal dimmed behind it; Esc/close leaves the address modal open.
- Level-by-level selection, Metro Manila skip-province, breadcrumb/back/reset, search, "No matches".
- Commit fills the trigger + hidden inputs; save/edit/delete/set-default intact.
- Checkout: single modal (no parent), default prefill, saved-address dropdown, failed-order restore.
- API unreachable → retry shown; rest of form still submittable.
- `php tests/run.php` (QaSuite, SchemaDrift, ShopStateMachine, ViewRender) green; TemplateLint's
  pre-existing `the doc quotes a suite result` failure is unrelated.

## Defaults (unless objected)

- Store the 4 PSGC codes.
- Do **not** add region/barangay to the `orders` table.
- Keep city and province internally; the trigger presents them as one chain.
- Picker modal is a normal centered dialog (not fullscreen). Can switch to
  `modal-fullscreen-sm-down` (full-screen on phones only) if mobile usability needs it.
