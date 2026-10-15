# Shop QA Test (v4) - Beginner-Friendly Guide

This checklist walks you through testing the shop feature end-to-end. Follow it exactly. Do not skip instructions.

## Prerequisites

- [ ] **Two browser windows**: one for the Member (Window M), one for the Admin (Window W).
  - Use private/incognito for at least one of them, or different browsers (e.g. Chrome for Admin, Firefox for Member). This prevents login sessions from overwriting each other.
- [ ] **Member account** with enough e-wallet balance. You need at least **₱1,000** total (you will place several orders). Check balance in Window M.
- [ ] **Admin login.** Default is `admin` / `Admin@1234`. If it doesn't work, confirm with whoever set it up.
- [ ] **Two small image files** on your computer (to use as fake payment proofs). A screenshot of your desktop is fine.
- [ ] Keep a **FAIL log** (notepad/text file) and write down order numbers when asked.

## Golden Rule

> **All member actions in Window M. All admin actions in Window W.**

Whenever the test says "In Window M" or "In Window W", switch windows first.

## Starting Point

1. **In Window M**, go to `http://localhost/altaslive/?page=shop`.
2. **PASS if** you see the shop page (product cards, OR "No products are available right now"). Both are acceptable.
3. **FAIL if** you see raw PHP/code, a blank white page, an error, or you're forced to log in while already logged in.

> If it says "The shop is currently closed", go to **Test J** first to re-open it.

## How an Order Flows (Reference)

Orders move through stages. You must only move to the next legal stage.

```
pending → payment_review → paid → packing → ready_to_ship → shipped
  (unpaid)   (proof under   (paid)  (packing) (ready)        (shipped)
                review)

pending: buyer places order (non-ewallet) but hasn't uploaded proof yet.
payment_review: buyer uploaded payment proof; admin must verify it.

If admin rejects proof:
payment_failed → (buyer uploads new proof) → payment_review

From shipped:
shipped → out_for_delivery → delivered → completed
                ↓ (fails)
           delivery_failed → returned_to_sender → reshipped → packing
```

**Important:** The app only allows legal transitions. If you try to jump stages, it must refuse. A refusal is the correct behavior.

---

## Test A — Admin: Create a Test Product

**In Window W:**

1. Go to `http://localhost/altaslive/?page=admin_shop`.
2. Click **+ New Product**.
3. Fill the form exactly:

| Field | Value |
|---|---|
| Name * | `QA Widget` |
| SKU | `QA-001` |
| Price (₱) * | `100` |
| Stock | `5` |
| Short description | `A widget for testing` |
| Full description | `This product exists only for QA testing.` |
| Image | (optional; can leave blank or pick any image) |
| Status | `active` |

4. Click **Create Product**.
5. **PASS if** you see a green "Product created." message and `QA Widget` appears in the list at ₱100.00.
6. Click **Edit** on `QA Widget`, verify/update Stock to `5`, click **Update Product**.
7. **PASS if** you see "Product updated." (or similar success message).

> Creating shows "Create Product"; editing shows "Update Product".

---

## Test B — Member: Browse and Add to Cart

**In Window M:**

1. Go to `http://localhost/altaslive/?page=shop`.
2. Find `QA Widget`. Click **Add to cart**.
3. **PASS if:** the cart badge (top-right) increases by 1, and the button on that product card becomes "Already in cart" (disabled).
4. Open the cart (`?page=cart` or cart drawer).
5. **PASS if:** QA Widget is listed, quantity is 1, line total is ₱100.00, cart total is ₱100.00.

> Quantity adjustment happens in the cart, not on the product card.

---

## Test C — Checkout and Placing an Order

You will place **two** orders: (C1) E-Wallet (auto-paid), (C2) GCash (manual proof upload). They behave differently.

### C1 — E-Wallet Order (Automatic Payment)

**In Window M:**

1. Add 2x `QA Widget` to cart (or set quantity to 2 in cart). Cart total should be **₱200.00**.
2. From cart, click **Proceed to checkout**.
3. Fill delivery/billing details (all required fields marked *):
   - Recipient name, phone, delivery address, city/province/postal code
   - Billing name/phone (if shown)
4. Under Payment Method, select **E-Wallet** (if available).  
   > **Note:** If your balance is below the order total, the E-Wallet option will be **greyed out** with a red explanatory note. That is correct behavior — pick another payment method or top up. Do not fail this.
5. **Terms checkbox:** Before ticking terms, the "Place order" button should be greyed out and read *"Tick the terms checkbox to place your order"*. Clicking it should do nothing.
6. Tick the **Terms & Conditions** checkbox.
7. **PASS if** the button becomes solid blue and reads **Place order**.
8. Click **Place order**.
9. **PASS if:**
   - You see a green message: **"Order placed and paid. Thank you!"**
   - You land on the order details page with status pill showing **Paid**
   - History/events are listed on the right
   - Cart badge returns to **0**

10. Go to **My Orders** (`?page=shop_orders`). **PASS if** this order shows status **Paid**.
11. **Write down Order #1 (E-Wallet):** `______________________`

> Failed checkout should preserve the delivery form you filled in (Test C3 below).

### C2 — GCash Order (Manual Payment + Proof Upload)

This order starts unpaid so you can test the manual payment flow.

**In Window M:**

1. Go to Shop → Add **1x QA Widget** → Open cart → **Proceed to checkout**.
2. Fill delivery details.
3. Choose **GCash** as payment method.
4. Tick terms, click **Place order**.
5. **PASS if:**
   - Green message: **"Order placed. Upload your payment proof to continue."**
   - Status pill reads **Pending payment**
   - You see a "pay by..." deadline and the **payment proof upload form**

> **Note:** There is no "confirm-lock" checkbox. The upload form shows a short explanation instead. This is intentional and not a FAIL.

6. **Upload payment proof:** Attach one of your test images.  
   **Reference number (IMPORTANT):** In the "Reference no." field, type something like `GC-QA-001` (optional). You may also leave this field **completely blank** — the system accepts proof without a reference number.
7. Click **Submit proof**.
8. **PASS if:**
   - You see a green success message
   - Status pill becomes **Payment under review**
   - **The "Cancel order" button is gone.** Once proof is submitted, the buyer cannot cancel. This must be the case.

9. **Write down Order #2 (GCash):** `______________________`

> The reference number entered here is **advisory only**. If you left it blank, that is fine. The admin will record the canonical reference when verifying payment (see Test D).

### C3 — Failed Checkout Preserves Form Data

1. **In Window M**, start checkout with a new cart item. Fill in **all** delivery fields completely.
2. Try to place the order **without ticking the Terms checkbox**.
3. **PASS if:** You're returned to checkout with an error message, **and all delivery fields you typed remain filled in**.
4. **FAIL if:** The form came back completely empty.

---

## Test D — Admin: Verify Payment (Reference Number Handling)

Order #2 is now in **Payment under review**. This is where the reference number becomes canonical and enforced.

**In Window W:**

1. Go to `http://localhost/altaslive/?page=admin_shop_orders`.
2. Click the **Payment under review** tab. You should see Order #2 (GCash).
3. Open Order #2.

### D1 — Amount validation and correct verification

1. Look for the green-bordered **Verify payment** panel (top/right or in Actions).
2. **Test wrong amount (must be rejected):**
   - Set **Amount received** to `90.00` (10 less than order total ₱100.00)
   - In **Payment reference**, type `QA-REF-1` (this is just a test value)
   - Click **Mark paid**
3. **PASS if:** Rejected with an "Amount mismatch" error showing both amounts. The order **stays in Payment under review**. Do not proceed if it became Paid.
4. **Test correct verification (with reference):**
   - Set **Amount received** to the **exact total** (`100.00`)
   - In **Payment reference**, type `QA-REF-2` (must be a valid reference string)
   - Click **Mark paid**
5. **PASS if:**
   - Green message: **"Order moved to Paid."**
   - Status pill reads **Paid**
   - The latest proof shows a **green "verified" badge**
   - History card shows a `payment_verified` event

> **About the Payment reference field (admin):** This is the **canonical transfer reference** (GCash/Maya/Bank/USDT tx hash, etc.). Even if the member left it blank when submitting proof, the admin **must** enter a valid, usable reference here to mark the order as Paid. The system normalizes it (uppercase, keeps A-Z 0-9 _ -) and **requires a non-empty usable value**. It will reject empty/invalid characters.

### D2 — Duplicate reference guard

1. **In Window M**, place a **new GCash order** (add 1x QA Widget to cart, checkout with GCash). Submit proof with reference `QA-REF-DUP` (optional; you can also leave blank — we’ll test admin entering duplicate).
2. **In Window W**, open that new order (should be in Payment under review).
3. Enter **Amount = exact total (₱100.00)**.
4. In **Payment reference**, enter `QA-REF-2` (this was already used in D1 step 4).
5. Click **Mark paid**.
6. **PASS if:** Rejected with a **duplicate reference** error. The order stays in **Payment under review**. The reference cannot be reused on a different order.

### D3 — What if member submitted proof with NO reference number?

This is a common case. The workflow is: **inspect the proof image, extract the actual reference, enter it at verification.**

1. **In Window M**, place another GCash order (1x QA Widget). When submitting proof, **leave Reference no. blank** and attach an image. Submit.
2. **In Window W**, open that order → Payment under review. View the proof image.
3. The admin Verify payment panel still requires a Payment reference. Type the actual transaction reference you see on that proof image (or from your payment channel records). If the image truly shows no reference, use a realistic unique one for testing (e.g. `QA-REF-NOREF-001`).
4. Enter exact amount, click **Mark paid**.
5. **PASS if:** Order moves to **Paid**. The canonical reference you entered is stored on the order and shown at the top (ref line).

**Key takeaway:** Member reference is optional/advisory. Admin reference is **required, canonical, and uniqueness-checked** at verification time.

---

## Test E — Fulfilment: From Paid to Delivered

**In Window W**, open the C1 order (E-Wallet, already Paid). Refresh between steps if buttons don't appear.

1. **Start packing** panel → Click **Packing**. **PASS if** status pill becomes **Packing**.
2. **Finish packing** panel → Click **Ready to ship**. **PASS if** status becomes **Ready to ship**.
3. **Courier handoff** panel → Fill Courier (e.g. `QA Express`), Tracking (e.g. `TRK-001`). Click **Mark shipped (stock deducts)**. **PASS if** status becomes **Shipped**. (Stock deduction happens at this moment.)
4. **Out for delivery** panel → Click **Dispatch**. **PASS if** status becomes **Out for delivery**.
5. **Mark delivered** panel → Enter **Received by** (name) **OR** attach POD photo. One of them is required. Click **Delivered**. **PASS if** status becomes **Delivered**. If refused saying receiver/photo required, that’s correct.
6. **In Window M**, open that order. **PASS if** courier/tracking are visible and there is a **Confirm receipt** button.
7. Click **Confirm receipt**. **PASS if** status becomes **Completed**.

### E2 — No Actions on Completed Orders

On the Completed order (Window W), look at Actions panels.  
**PASS if:** No "Mark paid", no "Mark shipped", no "Cancel order" panels are available.  
**FAIL if:** Any finished-stage action is still offered.

---

## Test F — Delivery Failure, Return, Reship (No Stock Double-Count)

This tests the "sad path". Stock must **not** change when returning/reshipping the same goods.

**In Window M:** Place a new **E-Wallet** order for QA Widget (2x or 1x; note quantity). Write order number: `______________________`

**In Window W:**

1. Move it through: **Paid** → **Packing** → **Ready to ship** → **Mark shipped** (Courier `QA Express`, Tracking `TRK-002`).
2. **Dispatch** (Out for delivery).
3. **Delivery failed** panel → Choose fault (customer/carrier/shop), enter reason → Click **Record failure**. **PASS if** status becomes **Delivery failed**.
4. **Return to sender** → Click **Return to sender**. **PASS if** status becomes **Returned to sender**.
5. **Reship** → Click **Reship order**. **PASS if** status returns to **Packing** and the order shows **2 shipments**.
6. **Check stock (critical):** Go to Admin → Shop Products → Edit QA Widget. Note current **Stock** value. Compare to the stock value **before** step 1 of this test. **PASS if stock is unchanged.** If it increased/decreased by a full unit, this is a **SERIOUS FAIL**. Record before/after numbers.
7. Complete the reshipment: **Ready to ship** → **Mark shipped** → **Dispatch** → **Delivered** (with receiver/photo) → **In Window M: Confirm receipt**. **PASS if** status becomes **Completed** and stock remains unchanged from step 6.

> **Critical:** Returning/reshipping must not move stock. Goods returned to sender are the same goods.

---

## Test G — Hold and Resume

### What a "Hold" is (read this first)

A **Hold** is a **reversible pause**. Picture a shelf in a warehouse with a sign that says **"DO NOT SHIP YET"**. You move an order onto that shelf when something needs sorting out before it goes out — the customer asked to wait, you want to check the stock, a payment looks off, and so on. While an order is on that shelf:

- it is **not cancelled** and **not shipped** — nothing is thrown away;
- the goods stay **reserved** for this buyer (the stock does **not** go back up for sale);
- the automatic "too late, auto-cancel" timers **stop counting** while it sits there.

When the question is settled you **Resume** it, and it carries on from the **exact same step** it was on before (a **Paid** order resumes to **Paid** — not forward to Packing, not back to Pending).

Every hold must name **three things**, so an order can never sit on the shelf forever with nobody responsible:

| Field | What it means |
|---|---|
| **Reason** | A short note saying *why* it was paused. |
| **Owner** | *Who* is responsible for taking it off the shelf. The dropdown already defaults to **you** — the admin who is logged in — but you can hand the job to another staff member. The "owner" is the person on the hook, not necessarily the person who clicked. |
| **Deadline** | By when it should be looked at again. |

> If you try to hold an order without a reason, an owner, or a deadline, the system **refuses** it — owner-less, deadline-less holds are "permanent limbo" and are not allowed.

### Steps

Use a fresh order (not completed).

**In Window M:** Place an **E-Wallet** order (it is paid automatically at checkout). **In Window W:** open it and confirm the status is **Paid** — the only action shown is **Start packing / Packing** (there is no "Mark paid" button).

1. In the **Hold** card on the right, fill in the three fields:
   - **Reason** — type `customer_requested_delay`.
   - **Owner** — leave the dropdown on its default. It already shows **you** (e.g. `@admin`). The list contains only staff who can own a hold; if more than one exists you may pick a different person.
   - **Deadline** — pick a date/time a day or two in the future (e.g. two days from today at 5:00 PM).
   Click **Place hold**.
2. **PASS if** a green **"Order placed on hold."** message appears and the status badge becomes **On hold**.
3. **PASS if** the right rail now shows an **On hold** card containing all three values you entered:
   - **Reason:** `customer requested delay` (the underscore is shown as a space),
   - **Owner:** the **username** you selected (e.g. `admin`) — shown as a name, not a number,
   - **Deadline:** the date/time you entered.
4. **PASS if** the stock did **not** change while on hold (a hold pauses the order; it does not move goods). Note the product's stock on `?page=admin_shop` before and after — it must be identical.
5. Click **Resume to Paid**. **PASS if** the status returns to **exactly** where it was — **Paid** (not forward to Packing, not back to Pending) — and the action button reads **Start packing / Packing** again.
6. **PASS if** the **On hold** card disappears and the reason/owner/deadline are cleared.

> **Why it works this way:** a hold is a *pause, not a lock*. The buyer may still cancel while the order is on hold (the cancel follows the status it was paused from), and stock stays reserved so the goods are not sold to someone else. It is a way to say "wait for a human decision" without losing the order.

> **Edge case (not a FAIL):** if you put a **Pending payment** order on hold, the **Resume** button reads "Resume to Pending Payment" — but resuming is refused ("the previous status is no longer reachable") because "Pending payment" is not a step you can move *an order into*. Holds are safest on a **Paid** or later pre-ship order.

**Optional (only if two or more staff accounts exist):** repeat steps 1–3 but choose a **different** owner in the dropdown. **PASS if** the On-hold card shows **that** person's username.

---

## Test H — Refunds

### H1 — Open refund blocks buyer confirmation

1. **In Window W**, open a **Delivered** order.
2. Refund panel → Amount = full order total. Cause = `customer_dispute`. **Payout route** = `E-wallet`. Destination = e.g. `original payment method` (note). Click **Open refund task**. **PASS if** "Refund task opened."
3. **In Window M**, refresh order page. **PASS if** **Confirm receipt** button is gone and the open refund task is visible.

> Payout route determines automatic crediting: **E-wallet** auto-credits buyer wallet. cash/bank/manual do **not** auto-credit wallet (handled manually). Destination is informational.

### H2 — Deny refund unblocks buyer

1. **In Window W**, click **Deny** on the refund.
2. **In Window M**, refresh. **PASS if** **Confirm receipt** button returns.
3. Click **Confirm receipt**. **PASS if** status becomes **Completed**.

### H3 — Approve and pay out refund (with wallet credit)

1. On a **Delivered** order, open refund with **Payout route = E-wallet** → **Open refund task**.
2. Click **Approve**. **PASS if** a credit note number is issued on the refund row.
3. **In Window M**, note buyer’s **e-wallet balance**.
4. **In Window W**, click **Mark paid** → optionally enter an **External settlement ref** → Submit. **PASS if** "Refund marked paid." and buyer balance increased by **exactly** refund amount.
5. Try clicking **Mark paid** again. **PASS if** it’s refused and balance does **not** increase a second time (no double credit).

---

## Test I — Expiry (Auto-Cancel Unpaid Orders)

Pending (unpaid) orders should auto-cancel so stock returns. Orders already in **Payment under review** must **never** expire.

### I1 — Pending orders expire

1. **In Window M**, place a **GCash** order (1x QA Widget). **Do not submit proof**. Leave it in **Pending payment**.
2. **In Window W**, note QA Widget stock. Go to `?page=admin_shop_orders`.
3. Click **Run expiry** (top-right).
4. **PASS if** flash says something like *"Expiry run: 1 order(s) cancelled, 0 auto-completed."* and that order is now **Cancelled**.

> If nothing expires (deadline still future): you may backdate for testing. Run:
> ```sql
> UPDATE shop_orders SET payment_deadline = NOW() - INTERVAL 1 HOUR 
> WHERE status = 'pending';
> ```
> Then click **Run expiry** again. If DB access not available, record **SKIPPED — needed DB access** (do not guess PASS).

### I2 — Payment under review must NOT expire

1. Find an order currently in **Payment under review** (C2 or D3).
2. Click **Run expiry** again.
3. **PASS if** that order is **unchanged** (still Payment under review).
4. Click **Run expiry** a third time. **PASS if** reports **0 cancelled** with no errors.

---

## Test J — Shop On/Off Switch

1. **In Window W**, go to `?page=admin_settings`.
2. Find **Enable Shop** toggle → Turn **OFF** → Scroll down → Click **Save Settings**.
3. **In Window M**, click **Shop** in sidebar. **PASS if** redirected with red message: **"The shop is currently closed."**
4. **In Window W**, turn toggle **ON** → **Save Settings**.
5. **In Window M**, refresh shop page. **PASS if** it loads normally.
6. Leave shop **ON** when finished.

---

## Test K — Edge Cases

| # | Action | Expected Result |
|---|---|---|
| K1 | Open `?page=cart` while empty | Shows "Cart is empty" + "Browse the Shop" link. No errors. |
| K2 | Set cart line quantity to `0` and update | Line disappears (treat 0 as remove). |
| K3 | Set quantity higher than available stock | Red error: `Insufficient stock. Requested N, only M available.` |
| K4 | Try to delete QA Widget while orders reference it (Admin → Shop Products) | Delete button appears disabled or clicking shows toast: "Cannot delete: this product has been bought." Product not deleted. |
| K5 | Add/remove items repeatedly | Cart badge always equals total units in cart (0 when empty). |
| K6 | Empty cart from drawer | Drawer shows "Your cart is empty" and **no Checkout button** appears. |
| K7 | With balance < order total, try E-Wallet | E-Wallet radio is disabled with explanatory note. |

---

## Test L — Accessibility Spot Check (Recommended)

1. **Keyboard nav:** On shop page, press **Tab** repeatedly from top. **PASS if** focus moves logically and focus indicator is always visible.
2. Product cards: Tab to **Add to cart** button. It should be a single clear control (no quantity stepper on card). **PASS if** focusable and labeled.
3. **Checkout labels:** Click label text (e.g. "Delivery address") → press Tab. **PASS if** focus moves into the associated input field. All checkout fields must have proper labels.
4. **Cart drawer:** Tab to cart/badge button → press Enter. **PASS if** drawer opens/closes via keyboard.

---

## Final Checklist

Tick only what you actually verified. Unticked is fine if not tested; ticking untested is worse.

- [ ] Test A: Product created/edited; cannot delete while referenced
- [ ] Test B: Browse/add to cart works; badge/disabled state correct
- [ ] Test C1: E-Wallet order completes automatically to Paid
- [ ] Test C2: GCash order → Pending payment → Proof submitted → Payment under review (Cancel button removed). Reference optional on submit.
- [ ] Test C3: Failed checkout preserves filled delivery form
- [ ] Test D1: Wrong amount rejected; exact amount + valid reference marks Paid; proof verified; history logged
- [ ] Test D2: Duplicate reference rejected across orders
- [ ] Test D3: Proof submitted with NO reference → admin can still verify by entering canonical reference from proof
- [ ] Test E: Full fulfilment to Completed with delivery confirmation
- [ ] Test E2: No finished-stage actions on Completed orders
- [ ] Test F: Delivery failure → returned-to-sender → reshipped → completed with **stock unchanged**
- [ ] Test G: Hold + resume works
- [ ] Test H1: Open refund blocks confirm-receipt
- [ ] Test H2: Denied refund unblocks confirm-receipt
- [ ] Test H3: E-wallet refund credits exactly once; double-pay refused
- [ ] Test I1: Expired pending orders auto-cancelled
- [ ] Test I2: Payment under review orders **never** expire
- [ ] Test J: Shop enable/disable works (leave ON)
- [ ] Test K: Edge cases pass
- [ ] Test L: Basic accessibility checks pass

**End of QA.** Record any FAILs with exact steps and screenshots if possible.
