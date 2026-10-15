# Invoice & Credit Note QA Test — Beginner-Friendly Guide

This checklist walks you through testing the **printable sales invoice** and the
**refund credit note / refund receipt** end-to-end. Follow it exactly. Do not skip
instructions.

Two facts to remember before you start:

- The invoice is **derived from the order**, not stored. It re-renders every time you
  open it, so it always reflects current settings and order data.
- The credit note number is **issued automatically when a refund is approved** and is
  then frozen on the refund row. You never type it.

---

## Prerequisites

- [ ] **Two browser windows**: one for the Member (**Window M**), one for the Admin (**Window W**).
  - Use private/incognito for at least one of them, or two different browsers (e.g. Chrome for Admin, Firefox for Member). This stops the login sessions overwriting each other.
- [ ] **Admin login.** Default is `admin` / `Admin@1234`. If it does not work, ask whoever set it up.
- [ ] **Member account** with e-wallet balance and **at least one order that has reached `Paid`** (an order in `packing`/`ready_to_ship`/`shipped`/`delivered`/`completed` also works). If you have none, place and pay for a small order first — see the main shop QA doc (`qa_test_v4.md`) Tests D/E.
- [ ] **A printer profile or "Save as PDF"** available in the browser (Chrome/Edge: Print → Destination → "Save as PDF").
- [ ] Keep a **FAIL log** (a text file) and write down the **order number** (`AL…`) and **refund id** when asked. Refund ids are not shown on-screen; read the number from the URL of a refund receipt page (step F below) or from the DB if you have access.

## Golden Rule

> **All member actions in Window M. All admin actions in Window W.**

Whenever the test says "In Window M" or "In Window W", switch windows first.

---

## Where the Documents Live (Reference)

| Button / link | Where it is | Opens |
|---|---|---|
| **🖨 Invoice** | Admin → open an order (top-right) | `/altaslive/?page=admin_order_invoice&id=<ORDER_ID>` |
| **🖨 Invoice** | Member → **My Orders** → open an order (top-right, only for paid-or-later statuses) | `/altaslive/?page=shop_invoice&id=<ORDER_ID>` |
| **view / print** | Admin → order → **💸 Refunds** card, on a refund that has a credit note | `/altaslive/?page=admin_order_refund_receipt&refund_id=<REFUND_ID>` |

Both documents open in a **new browser tab** (`target="_blank"`), are set to
`noindex,nofollow`, and carry a **🖨 Print / Save as PDF** button at the top-right.

### Numbering rules (what makes a test pass)

- **Invoice number** = the order's stored `order_no` (looks like `ALyymmdd#####`). It never changes.
- **Credit note number** = `<PREFIX>-<order_no>-<NN>` where `NN` is the refund id zero-padded to 2 digits.
  - Default `PREFIX` is `CN`, configurable in Settings (see Test A).
  - Example: order `AL25011500042`, refund id `7` → `CN-AL25011500042-07`.
  - Once issued it is **frozen**. Changing the prefix later must **not** change an already-issued note.

---

## Test A — Configure the document settings

Do this first so the invoices carry real company details.

1. **In Window W**, go to **Settings** (`?page=admin_settings`).
2. Open the **Invoices & Documents** tab (the 🧾 icon in the settings rail). **PASS if** you see the card title **Invoices & Documents**.
3. Fill in:
   - **Registered Business Name** = `QA Trading Co.`
   - **Business Address** = `123 Test Street`
     `Quezon City`
   - **TIN** = `123-456-789`
   - **Contact Phone** = `+63 900 000 0000`
   - **Credit Note Prefix** = `CN`
   - **Currency Label** = `PHP`
   - **Tax Note** = `Prices are inclusive of applicable taxes.`
   - **Document Footer** = `QA footer line.`
4. Click **💾 Save Settings**. **PASS if** a green **"Settings saved."** message appears.
5. **FAIL if** you see a red error, a blank page, or raw PHP.

> Note: the seller **email** on documents comes from the site's general contact email
> setting, not from this tab. That is expected.

---

## Test B — Invoice from the admin desk

1. **In Window W**, go to **Admin → Shop Orders** and open the order you paid for.
2. **PASS if** the top-right shows a **🖨 Invoice** button next to the order total.
3. Click **🖨 Invoice**. A new tab opens.
4. **PASS if** the page shows:
   - Heading **SALES INVOICE**
   - The order number (`AL…`) printed under the heading
   - **QA Trading Co.** and `123 Test Street` in the top-left (your Test A values)
   - **TIN: 123-456-789** and `+63 900 000 0000`
   - **Billed To** block with the buyer name and `@<username>`
   - **Ship To** block with the delivery name/address you entered at checkout
   - An items table with columns **Description · SKU · Qty · Unit Price · Amount**
   - A totals block with **Subtotal**, **Total**, and **Amount Paid**
   - A **Payment** box showing **Method:** and, if present, `Reference:` and `Paid <datetime>`
   - Footer lines: your **Tax Note** (`Prices are inclusive of applicable taxes.`) and **Document Footer** (`QA footer line.`)
5. **PASS if** the top-right of the page shows **Close** and **🖨 Print / Save as PDF**.
6. **FAIL if** any value is missing, shows a PHP warning/notice, `Unknown`, `undefined`, `Array`, or raw `<?php`.
7. **FAIL if** the URL does not contain `page=admin_order_invoice&id=`.

### A2 — Totals arithmetic

1. Add up the **Amount** column by hand (or with the browser's calculator). **PASS if** it equals the **Subtotal** shown.
2. **PASS if** **Total** equals the order's grand total (the big number on the admin order page).
3. For a **paid-or-later** order, **PASS if** **Amount Paid** equals **Total**.
4. Open a **pending** or **payment_review** order's invoice (see Test E). **PASS if** **Amount Paid** shows **₱0.00** while **Total** stays full.

---

## Test C — Invoice from the member side + access control

1. **In Window M**, go to **My Orders** (`?page=shop_orders`) and open the same paid order.
2. **PASS if** the top-right shows **🖨 Invoice** (it appears for `paid` and every later status).
3. Click it. **PASS if** the invoice matches Test B (same order number, same totals, same seller details).
4. Open a **pending** order in Window M (an unpaid one). **PASS if** there is **NO** **🖨 Invoice** button on the member page for `pending`, `payment_review`, `payment_failed`, or `cancelled`.

### C1 — A member cannot read someone else's invoice

1. **In Window M**, note your order id from the invoice URL (`…&id=<ORDER_ID>`).
2. Change the number to an id you do **not** own (e.g. add or subtract 1) and press Enter.
3. **PASS if** you are bounced to **My Orders** with a red **"Order not found."** message and **no** invoice is displayed.

### C2 — Access control on the routes

1. **In Window M** (logged in as a member), manually open `?page=admin_order_invoice&id=1`.
   **PASS if** you get a red **"Access denied."** and are sent to the login page — never the admin invoice.
2. Log out, then open `?page=shop_invoice&id=1` as a guest.
   **PASS if** you get **"Please log in to continue."** and the login page.
3. **FAIL if** any of these ever render the document.

---

## Test D — Seller details and escaping

1. **In Window W**, back in **Settings → Invoices & Documents**, set **Registered Business Name** to:
   `<b>QA</b> & Co.`
   and **Document Footer** to `<i>footer</i>`.
2. **💾 Save Settings**, then reopen the admin invoice (Test B).
3. **PASS if** the name is printed **literally** as `<b>QA</b> & Co.` — you can see the angle brackets as text, the title is **not** bold, and the footer shows `<i>footer</i>` literally.
4. **FAIL if** the browser renders a bold `QA`, an italic footer, or anything that suggests HTML was executed. (This is the XSS/escaping check.)
5. **PASS if** the `<title>` of the browser tab reads `Invoice <ORDER_NO> — <b>QA</b> & Co.` (the name is escaped everywhere, including the tab title).
6. Restore the friendly values from Test A and save again.

### D2 — Fallbacks on a fresh install

1. In Settings, **clear** the **Registered Business Name** and save.
2. Reopen an invoice. **PASS if** the top-left name falls back to the **site name** (not blank, not "Array").
3. Restore `QA Trading Co.` and save.

---

## Test E — Status shown on the invoice

1. **In Window W**, open the invoice for an order in each of these statuses (move an order through the stages if needed, using the main shop QA doc): `paid`, `packing`, `shipped`, `delivered`.
2. **PASS if** the **Status:** badge under the invoice number matches the order status, with underscores replaced by spaces (e.g. `out_for_delivery` shows as **OUT FOR DELIVERY**).
3. **PASS if** the **Date:** line uses the paid date when the order has one, otherwise the placed date.
4. For a `pending` order, **PASS if** the date shown is the **placed** date (there is no paid date yet).

---

## Test F — Refund credit note: it is issued at Approve

Prerequisite: an order that has a refund task, or create one now.

1. **In Window W**, open an order (a `delivered` or `completed` order is ideal) and find the **💸 Refunds** card.
2. Create a refund: amount = full order total, cause = `customer_dispute`, **Payout route** = `E-wallet — credits the member's wallet automatically`, destination note optional, then click **Open refund task**.
   **PASS if** a green **"Refund task opened."** appears and a new refund row shows with a `open` badge.
3. **PASS if** the `open` row does **NOT** yet show a `credit note …` line or a `view / print` link (nothing is issued until approval).
4. Click **Approve**. **PASS if** a confirm dialog says **"Approve this ₱<amount> refund? A credit note will be issued."**
5. Confirm. **PASS if** a green **"Refund approved."** appears.
6. **PASS if** the refund row now shows:
   - a green/`approved` badge
   - a line `· credit note CN-<ORDER_NO>-<NN>` where `NN` is the refund id padded to 2 digits
   - a **view / print** link
7. Write down the **refund id** for the next test. You can read it from the **view / print** URL: `?page=admin_order_refund_receipt&refund_id=<REFUND_ID>`.

> **FAIL if** the credit note number is missing after approval, or if it appears *before* approval.

---

## Test G — The credit note / refund receipt document

1. **In Window W**, click **view / print** on the approved refund row. A new tab opens.
2. **PASS if** the URL contains `page=admin_order_refund_receipt&refund_id=<REFUND_ID>`.
3. **PASS if** the page shows:
   - Heading **CREDIT NOTE / REFUND RECEIPT**
   - The credit note number `CN-<ORDER_NO>-<NN>`
   - **QA Trading Co.** details top-left (Test A values)
   - **Refund To** block (buyer name, `@username`)
   - **Original Invoice** block: `Invoice: <ORDER_NO>`, `Placed: <date>`, `Order total: ₱<total>`
   - A figures table: **Refund amount**, **Reason** (= `Customer Dispute`), **Settlement route** (= `E-wallet credit`), and **Total refunded**
   - A **Settlement** box reading that the amount was **Credited to the member's e-wallet on <datetime>**
   - A footer: `Reference this credit note (CN-…) in any correspondence about invoice <ORDER_NO>.`
4. **PASS if** there is **NO** signature block (there is no **Received by (name & signature)** line for an e-wallet refund — that only appears for cash/manual routes; see Test I).
5. **FAIL if** any value is blank, shows `undefined`/`Array`, or a PHP warning appears.

### G2 — Numbering is deterministic and frozen

1. Note the credit note number, e.g. `CN-AL25011500042-07`.
2. **PASS if** it equals `<PREFIX>-<ORDER_NO>-<REFUND_ID padded to 2>`.
3. Reload the receipt page. **PASS if** the number is **identical**.
4. **In Window W**, change **Credit Note Prefix** in Settings to `CRN` and save.
5. Reload the **already-issued** receipt. **PASS if** the number is **still** `CN-…` (unchanged — it was frozen at issue). Do **not** expect new prefixes to rewrite old notes.
6. Approve a **different** refund and **PASS if** its note uses the new `CRN-…` prefix. (Then optionally set the prefix back to `CN`.)

> Legacy note (only if you have DB access): a refund row created *before* the feature and
> never approved has no `receipt_ref`; opening its receipt regenerates a number from the
> order + id, and marking it paid stores it. This is a compatibility path, not a normal flow.

---

## Test H — Mark paid, external reference, and immutability

1. **In Window W**, on the **approved** refund row, **PASS if** you see a text box **External settlement ref (optional)** and a **Mark paid** button.
2. Leave the box empty and click **Mark paid**.
   - **PASS if** a green **"Refund marked paid."** appears, the row badge becomes **processed**, and the `credit note CN-…` + **view / print** link are **still** present.
3. Reopen the credit note. **PASS if** the **Settlement** box now shows the processed date for the wallet credit.
4. **FAIL if** the credit note number changed, disappeared, or the link vanished after marking paid.

### H2 — Optional external reference

1. Create and **approve** a second refund (e.g. a `bank` route this time).
2. In the **External settlement ref (optional)** box type `GC-99887766`, then click **Mark paid**.
3. Reopen that credit note. **PASS if** the **Settlement** box shows a line beginning **Note:** that ends with `External settlement ref: GC-99887766`.
4. **PASS if** this reference is **additional** information — the credit note number is unchanged.

### H3 — Double-pay is refused

1. On an already-**processed** refund, there is no **Mark paid** button. **PASS if** that is true.
2. (If you can) attempt to re-submit a paid refund via a stale form or by replaying the POST. **PASS if** it is refused, the row stays `processed`, and the member's wallet is **not** credited a second time. For the wallet-balance proof, follow Test H3 in `qa_test_v4.md` (note the balance in Window M before and after).

---

## Test I — Cash / manual route shows a signature block

1. **In Window W**, on a `delivered`/`completed` order, create a refund with **Payout route** = `Cash — settled manually, no wallet credit`.
2. Click **Open refund task** (**"Refund task opened."**), then **Approve** (**"Refund approved."**).
3. Click **view / print** for that refund.
4. **PASS if** the **Settlement route** reads **Cash**, and a signature/acknowledgement block appears with:
   - **Received by (name & signature)**
   - **Date received**
5. **PASS if** the **Settlement** line says the amount was paid out via **Cash** (not "Credited to the member's e-wallet").
6. Optionally **Mark paid** with a reference and **PASS if** the note is appended as in H2.

---

## Test J — Print / Save as PDF

Do this for **both** an invoice and a credit note.

1. Click **🖨 Print / Save as PDF** (or press `Ctrl+P`). The print preview opens.
2. **PASS if** the **Close** and **🖨 Print / Save as PDF** buttons are **hidden** in the preview (they must not print).
3. **PASS if** the document sheet fills the page sensibly (no grey page background, no shadow, content not clipped at the right edge).
4. **PASS if** the items table and totals are readable and the amounts are right-aligned.
5. Choose **Destination → Save as PDF** and save. Open the PDF.
   - **PASS if** the seller details, invoice/credit-note number, items, and totals are all present and legible.
6. **FAIL if** a scrollbar, a stray button, or a blank/duplicate page prints.

---

## Test K — Edge cases

1. **In Window W**, open an invoice and note the **SKU** column. **PASS if** each item shows the SKU that was stored on the order line when it was purchased (an old/legacy line with no stored SKU shows a dash **—**).
   - Now go to **Admin → Shop Products**, edit that product, change its **SKU**, save, and reopen the invoice. **PASS if** the invoice still shows the **original** SKU — the invoice prints the purchase-time snapshot, not the product's current value.
2. **In Window W → Settings → Invoices & Documents**, set **Business Address** to two lines:
   ```
   123 Test Street
   Quezon City
   ```
   Save, then reopen an invoice. **PASS if** the top-left business address shows on **two lines** (it uses line-break rendering). Then restore the one-line address and save.
3. On the same invoice, **PASS if** the **Ship To** block shows the delivery name, phone, street address, and the city/province/postal/country line you entered at checkout.
4. Open the invoice direct URL for a **non-existent** id: `?page=admin_order_invoice&id=99999999`.
   **PASS if** you see the plain text **Order not found** (HTTP 404) — no stack trace, no partial page.
5. Open the credit note direct URL for a **non-existent** refund id: `?page=admin_order_refund_receipt&refund_id=99999999`.
   **PASS if** you see the plain text **Refund not found** (HTTP 404).
6. Open a **guest** invoice/credit-note URL in a fresh private window.
   **PASS if** you are redirected to login and **never** see the document.
7. **FAIL if** any of these leak data or show a PHP error.

---

## Test L — Credit note numbers are stable across reprints

1. Approve a refund and record its credit note number `CN-…`.
2. **Mark paid** it, then open the receipt, close it, and reopen it three times (from the **view / print** link each time).
3. **PASS if** the number is **identical** on every open, both before and after marking paid.
4. **PASS if** the invoice number (`AL…`) likewise never changes across repeated opens.

---

## Results Summary

Copy this into your FAIL log and tick as you go.

- [ ] Test A: Document settings saved and appear on documents
- [ ] Test B: Admin invoice renders with correct content and totals
- [ ] Test B-A2: Subtotal / Total / Amount Paid arithmetic correct
- [ ] Test C: Member invoice renders; hidden for unpaid statuses
- [ ] Test C-C1: Member cannot open another member's invoice
- [ ] Test C-C2: Route access control (member→admin, guest→member)
- [ ] Test D: Seller fields escaped (no HTML execution); fallbacks work
- [ ] Test E: Invoice status badge and date are correct
- [ ] Test F: Credit note issued at **Approve**, not before
- [ ] Test G: Credit note document content correct (e-wallet route)
- [ ] Test G-G2: Numbering deterministic and frozen across prefix changes
- [ ] Test H: Mark paid keeps the credit note; optional external ref appended
- [ ] Test H-H3: Double-pay refused (no second wallet credit)
- [ ] Test I: Cash/manual route shows signature block
- [ ] Test J: Print / Save as PDF hides the toolbar and looks right
- [ ] Test K: Edge cases (SKU snapshot, address wrap, 404s, guest redirect)
- [ ] Test L: Numbers stable across reprints

**End of QA.** Record any FAILs with the exact step, the expected vs actual text, and a screenshot if possible. For every FAIL, include the **order number** or **refund id** you were testing.
