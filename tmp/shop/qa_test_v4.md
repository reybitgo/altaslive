# QA Test v4 — Shop Storefront (Cart, Admin Products & Order Lifecycle)

**For:** someone who has never done QA testing before, testing AltasLive's shop by hand in a browser.
**App:** AltasLive — <http://localhost/altaslive>
**Revised:** 2026-10-10
**Covers:** storefront, cart, checkout, payment verification, fulfilment, delivery failure, refunds, holds, expiry.

---

## How to use this document

Read this part first. It is the most important part.

### What "QA testing" actually means here

You are not looking for the app to be *perfect*. You are answering one question:

> **Does the shop do what it promises, every time?**

That means for each step you will do two things:

1. **Do something specific** (click this, type that).
2. **Compare what you see against what this document says you should see.**

Then you write down one of exactly two words: **PASS** or **FAIL**.

- **PASS** = it looked or behaved as described.
- **FAIL** = it did something else, or something broke.

A FAIL is **not** a problem with your work. A FAIL is exactly the thing we want to know about. Finding one is you doing the job correctly. Do not stop, do not fix it yourself, and do not tidy up after it — just write it down and keep going.

### The one rule that makes this work

> **Change exactly one thing at a time, and look at the result before doing anything else.**

Do not click three buttons and then decide whether it worked. You will not know which one mattered.

### Words you will see, explained

| Word | What it means here |
|---|---|
| **PASS** | It behaved as this document says. |
| **FAIL** | It behaved differently. Write down exactly what you saw. |
| **flash** | The coloured message that appears at the top of the page after an action. |
| **pill / badge** | The small coloured label showing an order's status. |
| **drawer** | The cart panel that slides in from the right when you click the 🛒 icon. |
| **member** | A normal customer account. |
| **admin** | The shop-staff account. |

### Keep a log as you go

Every time you write **FAIL**, also write down:

- Which step number it was.
- What you **expected** (this document will tell you).
- What you **actually saw** — copy the message text exactly, don't paraphrase.

A FAIL written as *"the thing was broken"* helps nobody. A FAIL written as
*"Test D2: expected 'Amount mismatch', but the order became Paid"* is instantly actionable.

---

## Before you start

### What you need

- [ ] **Two browser windows**, and this matters:
  - **Window M** (member) — logged in as a normal member.
  - **Window W** (admin) — logged in as the shop admin.
  - Use a **private / incognito window** for each, or open one in a different browser (e.g. Chrome for admin, Firefox for member). This stops the two logins from overwriting each other, which otherwise causes very confusing failures.
- [ ] **A member account with money in the e-wallet.** At least **₱1,000** — you will place several orders. Check by logging in and looking at your e-wallet balance.
- [ ] **The admin login.** Default is `admin` / `Admin@1234`. If that does not work, ask whoever set this up — it may have been changed.
- [ ] **Two small image files** on your computer to use as fake payment proofs. A screenshot of your desktop is fine.
- [ ] A pen and paper, or a text file, for your FAIL log.

### The golden rule

> **Member stuff in Window M. Admin stuff in Window W.**

When this document says *"in Window M"* or *"in Window W"*, switch windows before you do anything.

### Starting point

1. In **Window M**, go to <http://localhost/altaslive/?page=shop>.
2. **PASS if** you see the shop page — either product cards, or a message saying *"No products are available right now."* Both are fine; the next test creates a product.
3. **FAIL if** you see raw code, a blank white page, an error, or you get bounced to the login page when you are logged in.

> If the shop page says the shop is closed, stop and go to **Test J** first to re-open it.

---

## What an order goes through

An order is a parcel that moves through stages. You will push two orders through this, one step at a time.

```
pending  →  payment_review  →  paid  →  packing  →  ready_to_ship  →  shipped
Pending     Payment under       Paid     Packing     Ready to ship    Shipped
payment      review                                   
   ↓ (admin rejects the proof)
payment_failed  →  buyer uploads a new proof  →  back to payment_review

shipped  →  out_for_delivery  →  delivered  →  completed
          Out for delivery      Delivered      Completed
                 ↓ (delivery fails)
          delivery_failed  →  returned_to_sender  →  reshipped  →  packing …
          Delivery failed      Returned to sender
```

**The rule that governs all of it:** an order can only move to the *next* legal stage. If you try to skip one, the app refuses and tells you so. That refusal is correct behaviour, not a bug — several tests below check that you are refused.

---

## Test A — Admin: create a test product

*We need one product to buy. This is where we make it.*

**In Window W:**

1. Go to <http://localhost/altaslive/?page=admin_shop>.
2. Click **+ New Product**.
3. Fill in the form:

   | Field | Type this |
   |---|---|
   | Name * | `QA Widget` |
   | SKU | `QA-001` |
   | Price (₱) * | `100` |
   | Stock | `5` |
   | Short description | `A widget for testing` |
   | Full description | `This product exists only for QA testing.` |
   | Image | (skip for now, or pick any image) |
   | Status | `active` |

4. Click **💾 Create Product**.
5. **PASS if** you see a green message **"Product created."** and `QA Widget` appears in the product list at ₱100.00.

> **Note the two different button labels.** When *creating*, the button says **Create Product**. When *editing* an existing product, it says **Update Product**. They are the same form.

6. Now click **Edit** on `QA Widget`, change **Stock** to `5` again (leave as is), and click **Update Product**.
7. **PASS if** you see **"Product updated."**

> **You will use this product for everything below.** Do not delete it until the Cleanup section at the end.

---

## Test B — Member: add to cart

**In Window M:**

1. Go to <http://localhost/altaslive/?page=shop>.
2. Find **QA Widget** (₱100.00).
3. You will see an **Add to cart** button on the card (no Qty box — the card always adds one unit per click).
4. Click **Add to cart**.
5. **PASS if:**
   - a green message **"Added to cart."** appears,
   - the 🛒 cart icon at the top shows a badge with **1**,
   - the cart drawer slides in from the right showing QA Widget,
   - the card's button now reads **Already in cart** (disabled); no quantity notice is shown under it.

> **What changed on 2026-10-11:** the card's Qty stepper was removed — the card always adds exactly one unit per click,
> and once the product is in your cart the button reads disabled **Already in cart**. To buy more than one, open the
> cart (drawer or `?page=cart`) and use the quantity stepper there.

6. Open the drawer, set the QA Widget line's quantity to `2` (use the **+** button or type it), and click **Update**.
7. **PASS if** the drawer badge and the 🛒 badge both read **2**, and the drawer shows a subtotal of `200.00 PHP`.
8. Click **View full cart** in the drawer.
9. **PASS if** you land on the cart page (`?page=cart`) and it lists **QA Widget**, quantity **2**, line total **200.00**.

> **If clicking a +/− button seems to do nothing for a moment:** that is intentional. If you click again before the
> first update finishes, the extra click is ignored, so the quantity and the badge can never disagree. Wait a second
> and it will update. If it *never* updates, that is a FAIL.

### B2 — the stock guard (an important one)

The shop must never let you order more items than exist. Let's prove it refuses.

1. **In Window W**, click **Edit** on `QA Widget`, set **Stock** to `2`, click **Update Product**.
2. **In Window M**, go back to the shop page (press F5 to refresh).
3. Open the cart and set the quantity to `3` (the card's button is disabled once the item is in the cart).
4. **PASS if** a red message appears saying exactly:

   ```
   Insufficient stock. Requested 3, only 2 available.
   ```

   The numbers will match whatever you actually have. The *shape* of the message is what matters.
5. **PASS if** your quantity did **not** increase to 3 — the cart still holds 2.
6. **In Window W**, set Stock back to `5` and **Update Product**.
7. **In Window M**, set the cart quantity back to `2`.

### B3 — the sold-out display

1. **In Window W**, set Stock to `0`, **Update Product**.
2. **In Window M**, refresh the shop page.
3. **PASS if** the card shows a grey **Sold out** badge and **no Add button at all** — you cannot add a sold-out item.
4. **In Window W**, set Stock back to `5`, **Update Product**.

---

## Test C — Checkout and placing an order

You will place **two** orders: one paying with the e-wallet, one paying by hand (GCash). They behave differently and we test both.

### C1 — E-Wallet order (fastest, fully automatic)

**In Window M:**

1. Open your cart (`?page=cart`). Confirm it holds QA Widget × 2 = ₱200.00.
2. Click the blue **Proceed to checkout** button.
3. Fill in the delivery form:
   - Recipient name * — your name
   - Recipient phone
   - Delivery address * — any street address
   - City, Province, Postal
   - Billing name, Billing phone
4. Choose a payment method.
5. **Before ticking terms:** the **Place order** button should be greyed out, reading
   *"Tick the terms checkbox to place your order"*. Try clicking it — nothing submits.
6. Tick the **terms** checkbox.
7. **PASS if** the button turns solid blue, reads **Place order**, and clicking it places the order.

> **If your e-wallet balance is too low:** the **E-Wallet** radio button will be *greyed out* and a red note will say
> your balance does not cover the order. That is correct behaviour (new as of 2026-10-10) — pick another method, or ask
> for a top-up. Do not report it as a FAIL.

7. **PASS if:**
   - a green message **"Order placed and paid. Thank you!"**,
   - you land on the order page and the status pill reads **Paid**,
   - the **History** card on the right lists events,
   - your 🛒 cart badge is back to **0**.

8. In Window M, go to **My Orders** (<http://localhost/altaslive/?page=shop_orders>).
9. **PASS if** the order is listed with status **Paid**.

> **Write down the order number.** It looks like `AL26101000xxx`. It is on the order page, near the top.
> Order number: `______________________`

> **Your delivery address should still be filled in** if checkout bounces you back with an error. That is new
> behaviour as of 2026-10-10 — previously a failed checkout threw away everything you had typed. Test it in C3.

### C2 — GCash order (needs you to act as both buyer and shop staff)

This order starts unpaid, so you can test the manual-payment path.

**In Window M:**

1. Go to the shop page, add QA Widget × 1, open the cart, **Proceed to checkout**.
2. Fill in the address as before.
3. Choose **GCash** as the payment method.
4. Tick terms, click **Place order**.
5. **PASS if:**
   - a green message **"Order placed. Upload your payment proof to continue."**,
   - the status pill reads **Pending payment**,
   - you see a "pay by…" deadline and a proof-upload form.

> **There is no confirm-lock checkbox.** An earlier version of this test told you to tick one. It was removed on
> 2026-10-10 because the server never read it — submitting without it worked exactly the same, so it gave the
> impression of a protection that did not exist. A plain sentence now sits under the form instead. **Its absence is
> not a FAIL.**

6. Attach any image, optionally type a reference number like `GC-QA-001`, and click **Submit proof**.
7. **PASS if:**
   - a green message appears,
   - the status pill becomes **Payment under review**,
   - **there is no "Cancel order" button any more.** This is important — once a proof is in, the buyer must not be
     able to cancel. **PASS if the button is gone.**

> Write down this order number too: `______________________`

### C3 — a failed checkout keeps what you typed

1. In Window M, start a checkout and fill in the whole delivery form.
2. Try to place the order **without ticking the terms checkbox**.
3. **PASS if** you are returned to the checkout page with an error, **and your delivery address is still filled in.**
4. **FAIL if** the form came back empty — that is the old bug.

---

## Test D — Admin: verifying the money

**In Window W:**

1. Go to <http://localhost/altaslive/?page=admin_shop_orders>.
2. Click the **Payment under review** tab. Your C2 order is listed.
3. Click the order to open it.
4. Find the green-bordered **Verify payment** panel.
5. **First, try the wrong amount on purpose:**
   - Change **Amount received** to the order total **minus 10** (e.g. `90` when the total is `100`).
   - Type a reference like `QA-REF-1`.
   - Click **Mark paid**.
6. **PASS if** it is **refused** with an "Amount mismatch" error showing both numbers, and the order stays **Payment under review**.
   - *A refusal here is the correct result. If the order became Paid, that is a serious FAIL.*

7. **Now enter the exact total** (`100`), type reference `QA-REF-2`, click **Mark paid**.
8. **PASS if:**
   - a green message **"Order moved to Paid."**,
   - the status pill reads **Paid**,
   - the proof shows a green **verified** badge,
   - the History card gained a `payment_verified` entry.

### D2 — the duplicate-reference guard

Two orders must never be able to share one payment reference.

1. **In Window M**, place another GCash order and submit a proof with reference `QA-REF-DUP`.
2. **In Window W**, open that order, enter Amount = exact total, and set **Payment reference** to `QA-REF-2` (the reference already used in step 7).
3. **PASS if** it is **refused** with a duplicate-reference error.

---

## Test E — Fulfilment: from paid to delivered

**In Window W**, open the C1 order (the E-Wallet one). Press F5 between steps if a button doesn't appear.

> Each panel has a heading and a button, and they are worded differently. The **heading** tells you which stage it is;
> the **button** tells you what it does. Use the button names below.

1. Panel **Start packing** → click **Packing** → **PASS if** the pill reads **Packing**.
2. Panel **Finish packing** → click **Ready to ship** → **PASS if** the pill reads **Ready to ship**.
3. Panel **Courier handoff**. Fill in courier (e.g. `QA Express`) and tracking (e.g. `TRK-001`), then click
   **Mark shipped (stock deducts)**.
   - **PASS if** the pill reads **Shipped**.
   - The button says "(stock deducts)" because this is the moment the goods leave the shop's stock.
4. Panel **Out for delivery** → click **Dispatch** → **PASS if** the pill reads **Out for delivery**.
5. Panel **Mark delivered**. Fill in **Received by** (a name) or attach a photo — one of the two is required.
   Then click **Delivered**.
   - **PASS if** the pill reads **Delivered**.
   - *If the app refuses and says a receiver or photo is required, that is correct behaviour.*
6. **In Window M**, open the order.
   - **PASS if** you can see the courier name and tracking number, and there is a **Confirm receipt** button.
7. Click **Confirm receipt** → **PASS if** the pill reads **Completed**.

### E2 — actions that must be refused

On the now-**Completed** order, in Window W, look at the action panels.

- **PASS if** there is no **Mark paid**, no **Mark shipped**, and no **Cancel order** panel available.
- **FAIL if** any finished-stage action is still offered.

> This is one of the most important tests in this document. It is how you know a customer cannot, for example, ship an
> order that was never paid.

---

## Test F — Delivery failure, return, reship (the "sad path")

Real parcels get lost and returned. The shop must handle it **without losing or double-counting stock**.

**In Window M:** place a new **E-Wallet** order for QA Widget × 2 (₱200), and note the number: `______________________`

**In Window W:**

1. Mark it **Paid**, then **Packing** → **Ready to ship** → **Mark shipped** (courier `QA Express`, tracking `TRK-002`).
2. Panel **Out for delivery** → click **Dispatch**.
3. Panel **Delivery failed**. Choose a fault (`customer`, `carrier` or `shop`), type a reason, then click **Record failure**.
   - **PASS if** the pill reads **Delivery failed**.
4. Panel **Return to sender** → click **Return to sender**.
   - **PASS if** the pill reads **Returned to sender**.
5. Panel **Reship** → click **Reship order**.
   - **PASS if** the pill goes back to **Packing**, and the order's shipment panel shows **two** shipments.
6. **Critical check — stock:** on the product edit page, note the current **Stock** number. It must be the *same* as it
   was before this test started. If stock has gone *up* or *down* by a whole unit of QA Widget, that is a **serious
   FAIL** — record the before and after numbers exactly.
7. Drive the order back out: **Ready to ship** → **Mark shipped** → **Dispatch** → **Delivered** → (in Window M)
   **Confirm receipt**.
   - **PASS if** it reaches **Completed**, and the Stock number is *still* unchanged from step 6.

> **This is the single most important test in this document.** The goods are the same physical goods coming back, so
> the stock count must not move a second time. If it does, someone could buy stock that is already in a van.

---

## Test G — Hold and resume

Holding an order means "pause this, we need to check something".

**In Window W**, on the completed C1 order — hold won't apply to a finished order, so use a fresh order. Place a new
E-Wallet order in Window M, mark it **Paid** in Window W, then:

1. Click **Place hold**. Give a reason and submit.
   - **PASS if** the pill reads **On hold**.
2. A **Resume to Paid** button should now appear (it names the status you will go back to) → click it.
   - **PASS if** the pill goes back to **Paid**.

> **Note:** while an order is on hold, the buyer can still cancel it. That is intentional — a hold is a pause, not a
> lock. Do not report it as a FAIL.

---

## Test H — Refunds

### H1 — an open refund blocks the buyer

1. **In Window W**, on a **Delivered** order, find the **Refund** panel.
2. Fill in:
   - Amount — the full order total
   - Cause — choose `customer_dispute` (the list is `admin_cancel` / `delivery_failure` / `customer_dispute` / `other`)
   - **Payout route** — choose `E-wallet`
   - Destination — type anything, e.g. `original payment method`
3. Click **Open refund task** → **PASS if** you see **"Refund task opened."**
4. **In Window M**, refresh the order page.
   - **PASS if** the **Confirm receipt** button is gone and the order now shows the open refund task.

> **The Payout route field is new (2026-10-10).** It decides where the money goes when the refund is paid out.
> Only **E-wallet** credits the buyer's wallet automatically. `cash`, `bank` and `manual` (use this for GCash) are
> settled by hand and do **not** touch the wallet. The *Destination* box is now just a note for your records — it no
> longer chooses the route. (An earlier bug meant a GCash refund silently paid into the buyer's wallet.)

### H2 — denying the refund unblocks the buyer

5. **In Window W**, click **Deny** on the refund.
6. **In Window M**, refresh → **PASS if** the **Confirm receipt** button is back.
7. Click **Confirm receipt** → **PASS if** the pill reads **Completed**.

### H3 — paying out a refund (optional but worth doing once)

1. On a **Delivered** order, open a refund with **Payout route = E-wallet**, click **Open refund task**.
2. Click **Approve**.
3. Note the buyer's e-wallet balance (Window M).
4. Click **Mark paid (receipt required)** and type any receipt reference.
5. **PASS if** you see **"Refund marked paid."** and the buyer's balance has gone **up** by exactly the refund amount.
6. **Try clicking Mark paid again.**
   - **PASS if** it is refused and the balance does **not** rise a second time. A double credit here means the shop
     would pay a customer twice for one order — report it immediately.

---

## Test I — Expiry (unpaid orders give up)

An unpaid order should eventually cancel itself so the items go back on sale.

**In Window M:** place a GCash order for QA Widget × 1 and **do not** pay it. Leave it at **Pending payment**.

**In Window W:**

1. Open <http://localhost/altaslive/?page=admin_shop_orders>.
2. Note the Stock number on the QA Widget product page.
3. Click the **Run expiry** button (top-right, next to the heading).
4. **PASS if** the flash reads something like *"Expiry run: 1 order(s) cancelled, 0 auto-completed."* and your unpaid
   order now shows **Cancelled**.

> **If nothing expires:** the order's deadline is still in the future. To test this you need to backdate it, which
> means running one SQL statement. Ask a developer to run:
> ```sql
> UPDATE shop_orders SET payment_deadline = NOW() - INTERVAL 1 HOUR WHERE status = 'pending';
> ```
> Then click **Run expiry** again. If you cannot get this run, write *"SKIPPED — needed DB access"* rather than PASS or
> FAIL. An honest skip is fine; a guessed PASS is not.

### I2 — orders under review must never expire

This is the most important rule in the expiry logic: if a customer has already sent proof, the clock stops.

1. Find an order sitting at **Payment under review** (from C2 or D2).
2. Click **Run expiry** again.
3. **PASS if** that order is **untouched** and still says **Payment under review**.
4. Click **Run expiry** a third time → **PASS if** it reports **0 cancelled** and shows no errors.

---

## Test J — The shop on/off switch

1. **In Window W**, go to <http://localhost/altaslive/?page=admin_settings>.
2. Find the **🛍️ Enable Shop** toggle.
3. Turn it **off**, scroll down, click **💾 Save Settings**.
4. **In Window M**, click **Shop** in the sidebar.
   - **PASS if** you are redirected away with a red message **"The shop is currently closed."**
5. **In Window W**, turn the toggle back **on**, **💾 Save Settings**.
6. **In Window M**, refresh the shop page → **PASS if** it loads normally.

> Remember to leave the switch **ON** when you finish.

---

## Test K — Edge cases worth a few minutes

| # | Do this | You expect |
|---|---|---|
| 1 | Open `?page=cart` with an empty cart | "Cart is empty" and a **Browse the Shop** link. No error. |
| 2 | Set a cart line's quantity to `0` and update | The line disappears (0 = remove) |
| 3 | Set a quantity higher than available stock | Red message `Insufficient stock. Requested N, only M available.` |
| 4 | Click **Delete** on QA Widget (you have orders using it) | The button looks greyed out. Clicking it shows a toast: *"Cannot delete: this product has been bought."* — and nothing is deleted |
| 5 | After every add/remove, check the 🛒 badge | It always equals the real number of units in the cart (0 when empty) |
| 6 | Empty the cart from the drawer | The drawer redraws with "Your cart is empty", and **no Checkout button** — a dead Checkout link on an empty cart was a real bug, fixed 2026-10-10 |

---

## Test L — Accessibility spot-check (optional but recommended)

Five minutes. It catches things only a human can.

1. Open the shop page and press **Tab** repeatedly from the top of the page.
   - **PASS if** focus moves through the links and buttons in a sensible order and you can always see where focus is.
2. Tab to a product card's **Add to cart** button — it should be a single, clearly labelled control (the card has no
   Qty box; quantity is adjusted in the cart).
   - Without a screen reader, use the browser's accessibility inspector (Chrome: F12 → Elements → right-click an
     element → Inspect Accessibility).
3. **PASS if** every field on the checkout page has a real label attached to it. A quick way to check: click the grey
   text *"Delivery address"*, then press **Tab** — focus should land in the address box. (Nine checkout fields had no
   label at all before 2026-10-10; the whole delivery block was announced as "edit text, blank".)
4. Open the cart drawer with the keyboard only (Tab to the 🛒 button, press Enter) → **PASS if** it opens.

---

## Final pass — what "done" looks like

Tick every box you actually verified. **Leave a box unticked if you did not test it** — an unticked box tells the
developer something true. A ticked box you did not check is worse than no box at all.

- [ ] A test product was created, edited, and could not be deleted while orders referenced it.
- [ ] Deleting a product with **no** orders opened a small confirmation modal; confirming removed the row from the table with a fade, without a page reload.
- [ ] Two orders completed the full happy path (one e-wallet, one manual payment).
- [ ] Proof upload, exact-amount verification, and duplicate-reference refusal all behaved correctly.
- [ ] Packing → ready to ship → shipped → dispatched → delivered → completed, all with proof of delivery.
- [ ] A delivery failure reached returned-to-sender, was reshipped, and **stock never changed**.
- [ ] A hold + resume round-tripped.
- [ ] An open refund blocked confirm-receipt until denied.
- [ ] A refund paid out with route `ewallet` credited the wallet **exactly once**; paying again was refused.
- [ ] Expired pending orders auto-cancelled; **payment_review orders were NEVER expired**.
- [ ] `shop_enabled=0` closed the shop; re-enabled it.
- [ ] Cart and drawer badges always matched the real cart contents; an empty cart showed "Cart is empty" and no Checkout button.
- [ ] The product card offered a single **Add to cart** button with no Qty stepper, and read disabled **Already in cart** once the item was in the cart.
- [ ] Repeated clicks on one cart row never left the quantity and the badge disagreeing.
- [ ] With a balance below the order total, the **E-Wallet radio was disabled** with an explanatory note.
- [ ] A failed checkout **kept the delivery address** that had been typed.
- [ ] No finished-stage action (Mark paid, Ship, Cancel) was offered on a completed order.

---

## Appendix A — URL cheat sheet

**Window M (member)**

| Page | URL |
|---|---|
| Shop | `?page=shop` |
| Cart | `?page=cart` |
| Checkout | `?page=checkout` |
| My Orders | `?page=shop_orders` |
| One order | `?page=shop_order&id=<order id>` |

**Window W (admin)**

| Page | URL |
|---|---|
| Shop Products | `?page=admin_shop` |
| Shop Orders | `?page=admin_shop_orders` |
| One order | `?page=admin_shop_order&id=<order id>` |
| Settings | `?page=admin_settings` |

Base: `http://localhost/altaslive`

---

## Appendix B — Status pills you will see

| Internal status | What the pill says |
|---|---|
| `pending` | Pending payment |
| `payment_review` | Payment under review |
| `payment_failed` | Payment rejected |
| `paid` | Paid |
| `packing` | Packing |
| `ready_to_ship` | Ready to ship |
| `shipped` | Shipped |
| `out_for_delivery` | Out for delivery |
| `delivery_failed` | Delivery failed |
| `returned_to_sender` | Returned to sender |
| `delivered` | Delivered |
| `completed` | Completed |
| `cancelled` | Cancelled |
| `on_hold` | On hold |

A pill reading **Unknown** is always a FAIL — report it.

---

## Appendix C — If something looks wrong

Write it down like this. Fill in every line:

```
Test:      (e.g. D2)
Step:      (the number)
I did:     (what you clicked / typed)
I expected:(the exact message or behaviour this document promised)
I saw:     (the exact message or behaviour that happened — copy it verbatim)
Screenshot:(filename, or "none")
Window:    (M or W)
```

**Then keep going.** Do not stop the run because of one FAIL. A run that finds four problems is worth far more than a
run that stopped at the first one.

### Things that are NOT bugs

Do not report these — they are the app working correctly:

- Trying to skip a stage (e.g. shipping an unpaid order) and being refused.
- Trying to complete an order with an open refund and being refused.
- Marking a delivery failed too many times and being refused (there is a deliberate attempt cap).
- A cancelled order keeping its order number instead of getting a new one.
- The cart refusing a quantity above available stock.
- The confirm-lock checkbox no longer existing (removed 2026-10-10).
- The E-Wallet option being disabled when the balance is too low.

### Things that ARE bugs — report these loudly

- Money left the wallet but the order did not become Paid.
- A refund credited the wallet twice.
- Stock went **down** by the same goods twice after a reship.
- Stock went **up** on its own after an order was cancelled or returned.
- A completed order still offered Mark paid / Ship / Cancel.
- One customer could see or change another customer's order or cart.
- Raw code, a blank page, or a 500 error page.
- A pill reading **Unknown**.

---

## Appendix D — Cleanup

The automated suite (`php tests/run.php`) runs against a throwaway database and cleans up after itself, so it needs no
cleanup.

Your manual run writes to the **real** database. If this is a shared or demo environment, tidy up:

1. Delete your test orders and carts (they can be removed from the database directly — there is no bulk-delete button
   in the shop UI).
2. Leave QA Widget in place, or delete it once no order references it.
3. Make sure the **Enable Shop** toggle is back **ON**.

If this is your own local machine, you can leave the test data in place — it is harmless and is actually useful if you
want to re-run this document.

---

## Appendix E — What this document cannot test

Being straight with you about the limits:

- **Automated counterpart:** `php tests/run.php` — **535 assertions, 0 failures** across 5 suites as of 2026-10-10.
  It covers the money, the stock, the state machine and the database contracts far more thoroughly than any person
  clicking through a browser can.
- **What only you can cover:** whether the pages actually *look* right, whether the drawer and other AJAX pieces
  behave smoothly, and whether the accessibility spot-checks pass with your eyes and screen reader.
- Neither replaces the other. If your run finds something the suite did not, that is a genuine gap worth reporting.
