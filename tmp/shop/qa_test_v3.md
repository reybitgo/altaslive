# QA Test — Shop Storefront (Cart, Admin Products & Order Lifecycle)

**For:** a complete beginner testing the shop by hand, in the browser.
**App:** AltasLive — `http://localhost/altaslive`
**Test date:** 2026-10-09
**Status of the code:** implementation and automated testing are **complete** (see §0 below) — this v3 is the _manual_ (human) test that sits on top of the automated smoke test, and its steps/expected text have been updated to match the cart, checkout, shop and off-canvas cart UI currently implemented in the repo.

---

## 0. Confirmation — implementation and automated testing are complete

| Evidence                                         | Result                                                                                                                                                                                                                      |
| ------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Automated smoke test (`tmp/shop/smoke_test.php`) | **85 passed, 0 failed, exit code 0** — covers order creation, e-wallet & manual payment, packing/ship/deliver legs, holds, resumes, expiry, refund block, stock reservation/deduction, double-deduction guard, attempt caps |
| PHP syntax (lint) battery                        | Clean parse: `index.php`, `core/helpers.php`, all 7 shop models, both controllers                                                                                                                                           |
| Delivery-leg defects found during matrix testing | Fixed and re-proven: attempts-cap guard on re-dispatch, phantom attempt on refused fail, reship linkage (`reship_of`), stock double-deduction guard                                                                         |
| Cart stock-out message (S40/S45)                 | Fixed to the exact wording: _"Insufficient stock. Requested N, only M available."_                                                                                                                                          |
| Test data cleanup                                | All matrix orders (212–219), product 41, carts, proof images and shop ledger rows removed; test wallets restored to their original balances                                                                                 |

Everything below is what a human still needs to check by eye and by hand.

---

## 1. Before you start — 5 minutes of preparation

### 1.1 What you are testing

The app has **two sides**:

| Side                        | Who             | Where                                                       |
| --------------------------- | --------------- | ----------------------------------------------------------- |
| **Shop (member side)**      | A normal member | Sidebar → **Shop**, **My Orders**, cart icon in the top bar (opens the off-canvas cart drawer) |
| **Admin products & orders** | An admin        | Sidebar → **Shop Products**, **Shop Orders**                |

An order's life looks like this (each box is a status you will see):

```
pending → payment_review → paid → packing → ready_to_ship → shipped
              ↓ (if rejected)                                      ↓
        payment_failed (re-upload)                    out_for_delivery → delivered → completed
                                                       deliveries can fail → back to shop
```

> **UI note for this v3:** on the member side, the topbar cart icon (🛒 with a count badge) opens an **off-canvas cart drawer**. That drawer is also where you will often see a quick "View full cart" link into the dedicated `?page=cart` page. The member **cart page** itself is still `?page=cart`, and **checkout** is still reached from that cart page via **Proceed to checkout**.

### 1.2 You need

- [ ] A **member** login (e.g. `altas02` / its password) with at least ₱700 in the e-wallet.
- [ ] An **admin** login (e.g. `admin` — password `Admin@1234`).
- [ ] Any two small images on your computer to use as a "payment proof" (a screenshot of anything works).
- [ ] Google Chrome or Firefox. Use **one private/incognito window per account** so two
      logins never fight each other:

> **Golden rule:** member stuff in one window, admin stuff in another.
> When this document says "_in the admin window_" or "_in the member window_", switch windows.

### 1.3 Quick sanity check before any cart test

1. In the member window: open `http://localhost/altaslive/?page=shop`.
2. **PASS if:** you see product cards (or an empty "no products yet" message if the catalog is empty — that's OK, Test A creates one).
3. **FAIL if:** the page errors, or you see raw code, or you are bounced to login when you ARE logged in.

---

## 2. Test A — Admin: create your test product

_Purpose: give the shop something to sell. One product is enough for the whole suite._

**In the admin window:**

1. Open `http://localhost/altaslive/?page=admin_shop`.
2. Click the blue **+ New Product** button near the top-right of the page (above the products table).
3. In the modal that opens (title: **➕ New Product**), fill the form:
   - **Name \*:** `QA Widget`
   - **SKU:** `QA-001` (optional)
   - **Price (₱) \*:** `100.00`
   - **Stock:** `5`
   - **Short description:** anything you like ("A widget for testing the shop").
   - **Full description:** optional (leave blank or fill in).
   - **Image:** optional (leave empty for now — you can add one later in Test A6).
   - **Status:** 🟢 **Active** (default).
4. Click **➕ Create Product** (the blue button at the bottom-right of the modal footer).
5. **PASS if:** green flash _"Product created."_ and `QA Widget` appears in the list with stock 5.
6. **Negative check (optional):** edit the product, clear the _Price (₱)_ field (or set it to 0), and click **💾 Update Product**. → **PASS if** red flash _"Product name and a price above zero are required."_ and nothing is created. (The browser's HTML5 validation may block the submit first if the field is empty — that's also a pass; the server message is the backstop.)
7. **Image check (optional):** Edit the product, upload a JPG/PNG/GIF/WebP image, click **💾 Update Product** → **PASS if** a 72×72 thumbnail is shown in the list. Then edit again, tick the **Remove current image** checkbox, and click **💾 Update Product** → **PASS if** the thumbnail disappears.
   - Image rules: JPG, PNG, GIF or WebP, max 5 MB. On edit, leaving the file input empty keeps the current image (you don't need to re-upload to keep it).

**Record the product's numeric ID** — open it in the list and note the number in the URL, e.g. `?page=admin_shop&edit=41` → your product ID is **41**. Write it here: `____`

> **Modal note (2026-10-10):** the product modal has `data-bs-backdrop="static"` (you can't click outside to close) and is **scrollable** (`modal-dialog-scrollable`) — the body scrolls internally and the **Cancel** / **➕ Create Product** (or **💾 Update Product**) footer buttons stay pinned at the bottom. The close button (✕) in the top-right corner of the modal header also works. On **Edit**, the page re-opens with the modal already shown and the product's values pre-filled.

---

## 3. Test B — Member: add to cart (including stock-out)

**In the member window:**

1. Open `http://localhost/altaslive/?page=shop`.
2. Find **QA Widget** (price ₱100.00, 5 in stock).
3. Click **Add to cart** once (the card submits 1 unit with the request; the card itself has no quantity stepper).
4. **PASS if:** you land back on the shop page with green flash _"Added to cart."_, the cart drawer auto-opens on the right (URL ends in `?page=shop&cart=1`), and the topbar 🛒 badge shows **1**. On the product card the button now reads **Already in cart** (disabled) with a green notice _"Item already in the cart"_ under it.
5. To reach 2 units, use the cart drawer's **+** stepper (or open **View full cart** (`?page=cart`) and use its stepper) to bump the quantity to 2.
6. **PASS if:** the topbar badge and the drawer's count badge both read **2**, and the drawer subtotal shows `200.00 PHP`.
7. Click **View full cart** in the drawer → **PASS if:** the cart page lists `QA Widget` with quantity 2 and line total `200.00`.

> **Already-in-cart behavior (by design):** once a product is in the cart, its product card renders a **disabled "Already in cart" button** plus the notice _"Item already in the cart"_ — repeated taps on the card **cannot** add a second copy or bump the quantity. Use the drawer or cart page steppers to change quantity; use the ✕ remove control (or **Remove** in the drawer) to take it out.

> **Drawer notes:** the topbar 🛒 button opens the off-canvas drawer; "View full cart" has no underline. Remove/update inside the drawer happen over AJAX (no page reload); if removing the last item empties the cart, the drawer redraws itself with _"Your cart is empty"_ and a **Browse Products** button.

### B2 — stock-out guard (the S40 test)

1. In the **admin** window, edit `QA Widget` and set **Stock = 2**. Click **💾 Update Product**.
2. Back in the member window, on the shop page click **Add to cart** again once — the card is now showing **Already in cart** (disabled), so instead bump the drawer/cart quantity from 2 to **3** (your cart now wants 3, only 2 exist).
3. **PASS if:** a red/danger message appears saying exactly:
   **"Insufficient stock. Requested 3, only 2 available."**
   (The numbers will match whatever you actually have — the _shape_ `Requested N, only M available` is what matters.)
4. In the admin window set **Stock back to 5** (edit → Stock = 5 → **💾 Update Product**), and in the member window set the qty back to 2 in the cart.
   _(This guard is proven against real buyer data in the automated run too: requests beyond stock are refused at every gate — add, update and checkout.)_

> Beginner note: the "Sold out" badge appears on a product whose _available_ stock is 0 — you'll see it properly in Test B3 if you want:
> set stock to 0 in admin (edit → Stock = 0 → **💾 Update Product**), refresh the member shop page → the product card shows no add-form at all, just a "Sold out" badge. Set stock back to 5 afterwards.

---

## 4. Test C — Checkout & place order (two payment paths)

### C1 — E-wallet path (fastest, fully automatic)

1. Member window: cart → click **Proceed to checkout** (the blue button under the Summary card).
2. Fill: billing name (yours), phone, shipping address (any), city, province, postal.
3. **Payment method:** **E-Wallet**. Tick the terms checkbox.
4. Click **Place order**.
5. **PASS if:**
   - green flash _"Order placed and paid. Thank you!"_;
   - you land on the order page whose status pill says **Paid**;
   - the **History** card on the right shows `order_placed` and `ewallet_auto_verified` events;
   - your cart badge is back to **0** (the cart converts to an order).
6. Member window → **My Orders** (`?page=shop_orders`) → **PASS if** the order is listed with status **Paid**.

> Take note of the order number (looks like `AL26100900xxx`). Write it here: `______`

### C2 — Manual-payment path (GCash etc., needs the human loop)

1. Member window: shop → add `QA Widget × 1` → checkout.
2. **Payment method:** **GCash**. Terms ticked. **Place order**.
3. **PASS if:** order page status pill says **Pending payment** and shows a "_Pay by …_" deadline plus the proof-upload form.
4. **Try the confirm-lock checkbox:** the form requires the little "_I understand submitting this proof ends my option to cancel_" box — unset → **PASS if** the browser refuses to submit.

**In the member window, upload the proof:**

5. Attach any image, optionally a reference number, tick the lock, **Submit proof**.
6. **PASS if:** green flash _"Proof submitted…"_ and the status pill becomes **Payment under review**.
7. **Anti-cancel rule (S-critical):** after proof submission the page must NOT show a _"Cancel order"_ button anymore. **PASS if** it doesn't.

---

## 5. Test D — Admin: payment verification (matching the money exactly)

**In the admin window:**

1. Open `http://localhost/altaslive/?page=admin_shop_orders` — the _Payment review_ tab is default. Your member's order is listed.
2. Click the order. In the **Verify payment** panel that appears (✅ Verify payment header, green-bordered box), first try the **wrong amount**:
   - change the pre-filled _Amount received_ number to the order total **minus 10**, enter reference e.g. `QA-REF-1`, click **Mark paid** (green `btn-success` button, full-width) → **PASS if refused** with an "Amount mismatch" error showing both figures.
3. Now enter the **exact total** (`QA Widget` price, e.g. ₱100.00) in the _Amount received_ box, reference `QA-REF-2` in the _Payment reference_ box, and click **Mark paid**.
4. **PASS if:**
   - green flash, order status pill → **Paid**;
   - the proof row in the page shows **verified**;
   - the order's _History_ card shows `payment_verified`.

### D2 — duplicate reference guard (S16)

1. Place another tiny GCash order in the member window (add 1, checkout, GCash, place) but **don't** upload a proof yet.
2. In admin, try to verify the _first_ order again using reference `QA-REF-2` on a different order if you have a second pending proof — or simply re-verify with the same reference that another paid order used, in the amount box adjust to the new total. **PASS if refused** with _"Reference already used by another order."_

> Fine detail you don't need to worry about: marketed exact-amount rule means **₱100.00 paid = ₱100.00 order**. Any 1-cent difference is refused. This is by design — there is no "tolerance".

---

## 6. Test E — Admin: fulfilment chain (packing → delivered)

**On the SAME order (now Paid, from Test C1):**

1. Click **Start packing** → **PASS if** status = **Packing**.
2. Click **Finish packing** → **PASS if** status = **Ready to ship**.
3. Click **Ship** (hand to courier) — the form asks:
   - **Courier:** `TestExpress`
   - **Tracking:** `QA-TRK-100`
   - (Expected delivery date is optional.)
4. **PASS if:** status = **Shipped**; the shipment appears with a handoff timestamp and the tracking `QA-TRK-100`.

### E2 — Ship guards (things that must be refused)

1. Try **Ship** again on the same order (now shipped, not ready) → **PASS if refused** silently (no state change; the button just won't do anything new).
2. On a _different_ paid order: try Ship with a **blank tracking number** → **PASS if refused** with a flash and order still **Paid**.
3. On a _different_ order whose product was set to **Stock = 0** in admin (do this with the C2 order's product): try to ship → **PASS if refused**, the order stays **Ready to ship**, and stock stays exactly 0 (nothing "half-deducted").
   _(Set stock back to 5 and progress that order normally afterwards.)_

### E3 — delivery & POD

1. On the **shipped** order: click **Out for delivery** → **PASS if** status = **Out for delivery**.
2. Click **Mark delivered**; you must give either a **receiver name** or upload a POD photo.
   - Try clicking with BOTH empty → **PASS if refused** (`POD required`).
   - Now enter receiver name `QA Receiver` → **PASS if** status = **Delivered** and the shipment row shows `pod_receiver = QA Receiver` and a `report_window_end` date (+5 days by default).

### E4 — member sees the courier + confirms receipt

**Member window:**

1. Open the delivered order.
2. **PASS if:** the page shows the courier **TestExpress**, tracking **QA-TRK-100**, "Received by **QA Receiver**", and a green **✅ Confirm receipt** button.
3. Click **Confirm receipt** → confirm the popup → **PASS if** status pill becomes **Completed** and the page says "_Order complete_".
4. **Terminality check:** nothing actionable should remain — no cancel, no confirm, no transitions. **PASS if** so.

---

## 7. Test F — Delivery failure, return, reship (the "sad path")

This needs a fresh paid order (repeat C1 or use the C2 order admin-verified in Test D2).

**Admin window:**

1. Take the order to **Shipped** (pack → ready → ship with any tracking).
2. SO **delivery_failed** from **Shipped** (no out-for-delivery needed): give fault = `customer`, reason `nobody home`.
   **PASS if** status = **Delivery failed**, attempt counter shows **1 of 3**, and the member window's order page now shows the reason + "_make sure someone is available_" message.
3. Click **Out for delivery** again (retry) → **PASS if** status = **Out for delivery**.
4. Repeat the fail twice more. After the **3rd fail**:

   **PASS if** the shipment shows a `response_deadline` date (+10 days) and the retry buttons now refuse (the parcel must go **Returned to sender** or straight to **Delivered**).
5. Click **Returned to sender** → **PASS if** status = **Returned to sender** and the active shipment is closed.
6. Click **Reship** → **PASS if** green flash — "_Reshipment opened — the shop absorbs the cost_" — status returns to **Packing**, and the order now has **two** shipment rows (row 2 linked to row 1).
7. Finish the chain again (pack → ready → ship → deliver with POD) → **PASS if** completes normally and **stock did not drop a second time** for the same goods (check product stock in admin before/after the reship handoff: it must be the same number).

### F2 — member confirm still works after the emotion of a failed delivery

Member window: confirm receipt on the reship-delivered order → **PASS if** completed.

---

## 8. Test G — Hold & resume

**Admin window, on any pre-ship paid order:**

1. Click **Hold** with **empty** reason → **PASS if refused** ("reason, owner and deadline are all required").
2. Fill: reason `stock_shortage`, owner `admin`, deadline +1 day → **PASS if** status = **On hold**, and the form freezes the previous status (`previous_status` shown as the resume target).
3. Click **Resume** → **PASS if** status returns to exactly where it was (Paid/Packing/Ready to ship) and a `hold_resumed` history event appears.
4. **Incomplete-hold guard (S33):** try holding directly with a blank deadline via the form → **PASS if** refused (server-side rule).

---

## 9. Test H — Refund block & deny (S29/S30)

1. Admin: on a **Delivered** order (repeat C1/fulfilment), open the **Refund** panel, cause `customer_request`, amount = order total, click **Open refund task**.
2. Member window: refresh the order page → **PASS if** the "Confirm receipt" affordance is gone/locked and the page shows the refund task (open).
3. Try to confirm receipt anyway (nothing to click? then POST is impossible from the UI — that IS the pass). If you have dev skills only, skip: the automated smoke test proves the block.
4. **Back in admin:** click **Deny** on the refund → **PASS if** member window now shows the confirm button again; confirm receipt → **PASS if** the order completes.

> The full refund _approval_ path (approve → mark paid with a receipt reference) is a workflow you don't need for storefront confidence; the automated smoke test covers its guard rules.

---

## 10. Test I — Expiry & admin button (S35/S36/S38)

**Member window:** place a pure GCash order (add 1, checkout, GCash, place). Leave it **pending**, don't pay.

**Speed up time (you won't wait hours):**

1. In admin, open the **Shop Orders** list; find the new **pending** order's payment deadline — it's normally +24h from now.
2. If you have DB access (ask a dev if not), run:
   ```sql
   UPDATE shop_orders SET payment_deadline = NOW() - INTERVAL 1 HOUR WHERE status='pending' AND member_id=<your member id>;
   ```
3. In admin window, go to `http://localhost/altaslive/?page=admin_shop_orders` and click the **⏱ Run expiry** button (top-right of the orders list, next to the heading).
4. **PASS if:** flash says bodies cancelled ≥1; the pending order became **Cancelled**, reason `payment_expired`; and its line is released (product "available" count rises back).

**Exempt check (S36):** on your OTHER order in **Payment under review** (from C2 fast path): run expiry again → **PASS if** it is NOT touched (submitted proof is exempt from expiry). This is one of the most important business rules in the whole shop.

**Idempotent cron (S38):** run the expiry button twice in a row → **PASS if** the second run reports 0 cancelled and no errors.

---

## 11. Test J — Shop on/off switch

1. In admin → **Settings** (`?page=admin_settings`). The page opens on the **Site Basics** tab.

2. Find the **🛍️ Enable Shop** toggle switch (with helper text _"Members and staff can browse the catalog and place orders"_). Turn it **off** (toggle to the right).
3. Scroll to the bottom of the tab and click **💾 Save Settings** (blue button, full-width).
4. Member window: click **Shop** → **PASS if** redirected away with flash _"The shop is currently closed."_
5. Back in admin Settings, turn the **🛍️ Enable Shop** toggle back **on** and click **💾 Save Settings**.

---

## 12. Test K — When the cart misbehaves (edge cases for patience)

| #   | You do                                                                                        | You expect                                                              |
| --- | --------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------- |
| 1   | Open `?page=cart` with an empty cart                                                          | "_Cart is empty._" + a "Browse the Shop" link, no error                  |
| 2   | On the cart page set a line's qty to `0` in the qty box and update                            | The line disappears (qty 0 = remove)                                    |
| 3   | Set a line's qty to a number bigger than available stock, update                              | Red flash "**Insufficient stock. Requested N, only M available.**"      |
| 4   | In admin, click **Delete** on your QA product (with QA orders still existing)                 | Refused: _"Cannot delete product: it has existing shop orders."_        |
| 5   | On the shop page in the member window, look at the top-right cart badge after each add/remove | The number always matches the count of units in the cart (0 when empty) |

> **Drawer vs page badge:** the same count should also be reflected in the off-canvas cart drawer when you open it from the topbar. If the page badge and drawer badge disagree, note both numbers in Appendix B.

---

## 13. Final pass — what "done" looks like

Tick every box. Any box you cannot tick = find a developer and show them this document.

- [ ] A test product was created, edited, and (if you made a throwaway) **not** deletable while orders exist.
- [ ] Two orders completed the full happy path (one e-wallet, one manual payment).
- [ ] Proof upload, exact-amount verification, and duplicate-reference refusal all behaved.
- [ ] Packing → ready → ship → out for delivery → delivered → completed, all with POD.
- [ ] A delivery failure path reached returned_to_sender, was reshipped, and stock never double-deducted.
- [ ] A hold + resume round-tripped.
- [ ] An open refund blocked confirm-receipt until denied.
- [ ] Expired pending orders auto-cancelled; payment_review orders were NEVER expired.
- [ ] shop_enabled=0 closed the shop; re-enabled it.
- [ ] Cart and off-canvas drawer badges matched the real cart contents; empty cart showed "Cart is empty"; the "Proceed to checkout" / "View full cart" / "Back to cart" labels matched what you saw.

## 14. Cleanup after testing

1. Admin → Shop Orders: cancel every order still in an open state (pending/revision/ready/etc.).
2. Delete your QA product **if** no orders reference it; otherwise leave it and delete the orders first, then the product.
3. Back to the DB (dev): the smoke test cleans itself; your manual orders are `DELETE FROM shop_orders WHERE ...` — confirm each order's child rows go with it (cascade does it).
4. Delete proof/POD images in `uploads/shop_proofs/` and `uploads/shipments/` that you created.
5. Log out of both windows.

---

## Appendix A — URL cheat sheet

| Purpose             | URL                                                                |
| ------------------- | ------------------------------------------------------------------ |
| Member shop         | `http://localhost/altaslive/?page=shop`                            |
| Member cart         | `http://localhost/altaslive/?page=cart`                            |
| Member orders list  | `http://localhost/altaslive/?page=shop_orders`                     |
| One member order    | `http://localhost/altaslive/?page=shop_order&id=NNN`               |
| Admin shop products | `http://localhost/altaslive/?page=admin_shop`                      |
| Admin shop orders   | `http://localhost/altaslive/?page=admin_shop_orders`               |
| Admin one order     | `http://localhost/altaslive/?page=admin_shop_order&id=NNN`         |
| Admin run expiry    | `http://localhost/altaslive/?page=admin_shop_expire` (POST button) |
| Member dashboard    | `http://localhost/altaslive/?page=dashboard`                       |

## Appendix B — If something looks wrong

1. **Note the exact status pill text** and the exact flash message (red/green banner at the top).
2. Note the order number and what you clicked immediately before.
3. Check `logs/php_errors.log` in the project root for a stack trace (devs only).
4. DO NOT delete database rows yourself unless Test I told you to — the smoke test and the
   shop code assume referential integrity; a dev can clean up properly.

---

## Appendix C — Admin UI reference (what you actually see)

### Product modal (`?page=admin_shop`, "+ New Product" button)

| Field             | Label in UI         | Notes                                                                                                                                                                                                     |
| ----------------- | ------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Name              | `Name *`            | Required, max 160 chars                                                                                                                                                                                   |
| SKU               | `SKU`               | Optional, max 60 chars                                                                                                                                                                                    |
| Price             | `Price (₱) *`       | Required, number, min 0, step 0.01                                                                                                                                                                        |
| Stock             | `Stock`             | Number, min 0; helper text: "Absolute inventory — deducted at courier handoff."                                                                                                                           |
| Short description | `Short description` | Optional, max 255 chars                                                                                                                                                                                   |
| Full description  | `Full description`  | Textarea, 4 rows                                                                                                                                                                                          |
| Image             | `Image`             | File input accepting JPG/PNG/GIF/WebP, max 5 MB. On edit, a 72×72 thumbnail + "Remove current image" checkbox appears. Helper: "JPG, PNG, GIF or WebP · max 5 MB · leave empty to keep the current image" |
| Status            | `Status`            | Dropdown: 🟢 Active / ⚪ Inactive                                                                                                                                                                         |

**Modal footer:** `Cancel` (outline secondary, dismisses modal) + `➕ Create Product` (blue primary, submits). On edit mode the button reads `💾 Update Product`.

**Modal title:** `➕ New Product` (create) or `✏️ Edit Product` (edit).

> If the modal in your build is scrollable / has a static backdrop, the footer buttons stay pinned while the form scrolls; the ✕ close button in the modal header also works. If it looks different, follow the visible labels — the manual pass criteria are the flashes and list behavior, not the exact modal chrome.

### Package modal (`?page=admin_packages`, "+ New Package" button)

**Top of modal — Commission Toggles panel** (filled in FIRST, before the rest):
A teal-bordered panel titled **Commission Toggles** with subtitle "Toggle features on first — disabled settings are hidden and reset to their defaults." Contains three toggle switches:

| Switch | Label              | Helper text                                                               |
| ------ | ------------------ | ------------------------------------------------------------------------- |
| 🌳     | Binary Network     | "Member joins the binary tree, builds legs and earns pairing bonuses."    |
| 🔗     | Indirect Referral  | "Pays the 10-level indirect referral bonuses for this package's members." |
| 📅     | Daily Fixed Income | "Enables the daily fixed income payout for this package."                 |

**After the toggles — the rest of the form:**

| Field                 | Label in UI                  | Notes                                                              |
| --------------------- | ---------------------------- | ------------------------------------------------------------------ |
| Package Name          | `Package Name *`             | Text input, placeholder "e.g. Starter, Pro, Elite"                 |
| Package Image         | `Package Image`              | File input, JPG/PNG/WebP, max 5 MB                                 |
| Entry Fee             | `Entry Fee (₱) *`            | Number                                                             |
| Direct Referral Bonus | `Direct Referral Bonus (₱)`  | Number; helper: "Paid once to sponsor on join"                     |
| Pairing Bonus         | `Pairing Bonus (₱) *`        | Number; helper: "Per pair paid out"                                |
| Daily Pair Cap        | `Daily Pair Cap *`           | Number; helper: "Flush-out limit per member per day"               |
| Cap Multiplier        | `Cap Multiplier`             | Number; helper: "Lifetime cap = Entry Fee × Multiplier"            |
| Auto-Cap Preview      | `Auto-Cap Preview`           | Read-only display; helper: "Calculated automatically"              |
| Reactivation Fee      | `Reactivation Fee (₱)`       | Number                                                             |
| Reactivation Window   | `Reactivation Window (days)` | Number; helper: "Days to reactivate before permanent deactivation" |
| Daily Fixed Income    | `Daily Fixed Income (₱/day)` | Number; helper: "Set 0 to disable DFI for this package"            |
| Max DFI Days          | `Max DFI Days`               | Number; helper: "Maximum days of fixed income per member"          |
| Status                | `Status`                     | Dropdown: 🟢 Active / ⚪ Inactive                                  |

**Indirect Referral Bonuses (10 Levels)** section:
A sub-section titled "🔗 Indirect Referral Bonuses (10 Levels)" with 10 rows (Level 1 through Level 10), each with a `₱` label and a number input. Helper: "Set 0 to disable a level. Paid once to each upline sponsor on member join."

**Modal footer:** `Cancel` (outline secondary) + `➕ Create Package` (blue primary, submits the `packageForm`). On edit mode: `💾 Update Package`.

**Modal title:** `➕ New Package` (create) or `✏️ Edit Package` (edit mode — set by JS when the page loads with `?edit=ID`).

### Admin packages list page

- Header: "Packages" + subtitle "Manage entry plans, bonuses, capping, DFI & commission toggles"
- Stats row: **TOTAL PLANS** (e.g. 8), **ACTIVE** (e.g. 8), **INACTIVE** (e.g. 0)
- "🌳 BINARY PLANS" counter (e.g. "1 of 8")
- "📦 All Packages" label with "🌳 binary · 🔗 indirect · 📅 DFI" tags
- **Rows** dropdown selector (5/10/25/50/100) — controls pagination size
- Table columns: PACKAGE (name + "ID: N"), ENTRY (fee), VOLUME (cap), CAP (DFI/day + days), DFI (toggle icons 🌳🔗📅), TOGGLES, STATUS (● Active / ○ Inactive), ACTIONS (View, Edit)
- Empty state: "Click **+ New Package** to create your first plan."
- Blue **+ New Package** button at top-right

### Shop product list (admin)

- Table columns: PRODUCT (image thumbnail + name + SKU), PRICE, STOCK, RESERVED (warning text), AVAILABLE (green/red), STATUS (● Active / ○ Inactive), ACTIONS (Edit, Delete)
- Empty state: emoji 🛍️ + "No products yet. Click **+ New Product** to create the first one."
- Helper below table: "Stock is absolute inventory. It is deducted automatically when an order is handed to the courier; adjust it here for deliveries, write-offs, and recounts."
- Pagination footer when multiple pages.

### Off-canvas cart drawer (member topbar)

- The topbar cart icon (🛒 with count badge) opens an off-canvas cart drawer for the logged-in user.
- Drawer shows the same kinds of line items as the cart page: product, unit price, qty stepper, remove control, subtotal/total.
- Empty drawer state should be consistent with the empty cart page ("Cart is empty" family of messaging) and include a way back to shop.
- **View full cart** link in the drawer leads to `?page=cart`.

---

_Automated counterpart of this document: `php tmp/shop/smoke_test.php` — 85 assertions, all passing as of 2026-10-09._

_This is v3 of the manual cart/shop QA test. If any instruction or expected text above no longer matches the live app, treat that line itself as a QA finding and record it in Appendix B._
