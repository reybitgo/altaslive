# Order Workflow Guide: Checkout to Completion

*Draft 1 · 5 October 2026 · From placing an order to closing it as completed or cancelled*

## 1. Purpose, scope, and assumptions

This guide describes how one order travels from the moment a customer places it to the moment it closes as `completed` or `cancelled`. It gathers the status model and the 24 numbered rules (T1 to T24) worked out so far into one reference, and it explains the reasoning behind each rule so you can defend it, tune it, and test it.

**Covered.** Checkout and placement; payment by transfer with proof upload and staff verification; cancellation and expiry; holds; picking and packing; courier handoff; delivery tracking; failed deliveries; reshipping; lost parcels; customer problem reports; completion.

**Not covered.** Catalog and pricing, customer accounts, card or gateway payments, returns of faulty goods, partial or multi-parcel shipments, commissions (only a hook), and cross-border shipping. Section 14 says where each would attach.

**On “best practice.”** No single standard defines order-management best practice, and what is sensible depends on payment method, volume, and country. This guide applies practices that are widely used for the problems this workflow actually has: concurrent updates, money in flight, stock allocation, unreliable carrier data, and customer communication. Where a rule is a policy choice rather than a technical necessity, the text says so. Windows, caps, and SLAs are starting defaults to tune against your own data, not benchmarks. Anything touching refunds, cancellation rights, or personal data should be checked against the rules in your country.

**Provenance.** Everything not labelled comes from the design worked out earlier. Recommendations added for this guide are labelled *(new)*.

### Assumptions

- Customers pay by bank or e-wallet transfer and upload proof; staff verify it against the bank or e-wallet record.
- A customer can cancel only before submitting proof, and again if the proof is rejected (“lock at upload”).
- One parcel per order. A reship is a new shipment record on the same order.
- Carrier events arrive by webhook or polling. Where a carrier has no API, staff enter updates with the carrier’s proof attached.
- Up to three delivery attempts and a five-day report window after delivery. There is no return flow: a wrong or damaged item is fixed by replacement or capped compensation while the customer keeps it.
- Problem reports are included. That is the one open decision (Section 14).

## 2. Design principles

Twelve principles run through the design. Later sections refer back to them.

1. **One order, one status, many records.** The order carries a single status that customers and staff both see. Payment, shipment, report, refund, hold, and stock reservation each have their own record and state. That is how the model runs on 14 statuses instead of the 25 in the first draft: facts like “part-paid,” “reshipped,” or “report open” live in records, not in extra statuses.
2. **One transition handler.** No code path writes `status` directly. The handler confirms the transition is legal, the actor is allowed, and the guards hold, then applies it atomically.
3. **Conditional writes decide races.** Every transition is a write that succeeds only if the order is still in the status the caller saw. Two tabs, two admins, or a timer against a customer then resolve to exactly one winner (Section 11.1).
4. **Idempotency wherever input can repeat:** checkout submits, proof uploads, carrier events, notification sends, and refund tasks. A repeat must never produce a second effect.
5. **Snapshot at the moment of promise.** Prices, taxes, discounts, shipping, address, and the terms version accepted are copied onto the order at placement. Later catalog or profile edits never rewrite history.
6. **Defer what can’t be undone.** Stock is reserved at placement, committed at payment verification, and physically deducted at carrier handoff. The review request and any commission wait for completion. That is why `delivered` can safely reverse.
7. **Transaction for facts, outbox for messages (new).** Status, stock movement, and refund tasks commit in one database transaction. Emails, SMS, and courier calls are queued from that transaction and sent by a worker that retries.
8. **Append-only history.** Every transition records from, to, actor, reason code, source, and time, with the raw payload for carrier events. Corrections are new rows, never edits.
9. **Fault is recorded, not assumed.** Every failure carries a cause (customer, carrier, or shop), because the cause decides who pays.
10. **Overrides are possible but visible.** They need a role, a reason code, and evidence. Money outcomes above a threshold need a second person (new).
11. **Time is data.** Deadlines are stored on the order and applied by a scheduler using conditional transitions. Pausing and resuming are explicit events.
12. **Say what happens next and what it ends.** Every notice states the current state, the next step, the deadline, and the consequence of the customer’s action, for example “Submitting proof ends your option to cancel.”

## 3. The lifecycle at a glance

### 3.1 Statuses

| Status | Meaning | Customer can cancel | Waiting on |
| --- | --- | --- | --- |
| `pending` | Order placed, stock reserved, waiting for proof of payment | Yes | Customer, before the deadline |
| `payment_review` | Proof submitted and being verified | No | Payment verifier, within the review SLA |
| `payment_failed` | Proof rejected; correction window open | Yes | Customer: re-upload or cancel |
| `paid` | Payment verified; stock committed | No | Warehouse |
| `packing` | Being picked and packed | No | Warehouse |
| `ready_to_ship` | Packed and labelled, waiting for courier pickup | No | Courier |
| `shipped` | In the carrier’s custody | No | Carrier events |
| `out_for_delivery` | Courier is delivering (optional status) | No | Carrier events |
| `delivery_failed` | An attempt failed; fault recorded | No | Customer and staff, to fix and retry |
| `returned_to_sender` | Parcel returning or back at the shop | No | Customer decides on reship; staff receive and inspect |
| `delivered` | Proof of delivery stored; report window open | No (file a report instead) | Customer or the timer |
| `completed` | Closed and read-only (terminal) | No | Nobody |
| `cancelled` | Closed without delivery (terminal); refund tasks may remain open | n/a | Nobody; staff close any refund task |
| `on_hold` | Paused, with reason, owner, and deadline | Follows `resume_to` | The named owner |

### 3.2 Flow map

```
Main path
pending → payment_review → paid → packing → ready_to_ship → shipped
  → out_for_delivery → delivered → completed

Side paths
payment_review → payment_failed → payment_review       rejected, re-upload
paid → payment_review                                  staff revert, before packing
pending, payment_failed → cancelled                    customer, or expiry
out_for_delivery → delivery_failed → out_for_delivery  retry
delivery_failed → returned_to_sender → packing         reship
returned_to_sender → cancelled                         closed under the terms
delivered → packing                                    replacement after an approved report
any pre-handoff state ⇄ on_hold                        hold and resume
```

Only `completed` and `cancelled` are terminal. If your courier does not report out-for-delivery, drop that status and T14, which leaves 13 statuses. The map shows the main routes; T15, T16, T18, and T20 also accept the extra source states named in their entries.

### 3.3 Phases and transitions

| Phase | Statuses involved | Transitions | Where |
| --- | --- | --- | --- |
| A. Placement and payment | `pending`, `payment_review`, `payment_failed`, `paid` | T1 to T5 | Sections 4, 5 |
| B. Cancellation and expiry | `cancelled` | T6 to T8 | Sections 6.1 to 6.4 |
| C. Holds | `on_hold` | T11, T12 | Section 6.5 |
| D. Fulfilment and handoff | `packing`, `ready_to_ship`, `shipped` | T9, T10, T13 | Section 7 |
| E. Delivery | `out_for_delivery`, `delivery_failed`, `returned_to_sender`, `delivered` | T14 to T19 | Section 8 |
| F. Exceptions and remedies | `packing` again, or `cancelled` | T20 to T23 | Section 9 |
| G. Completion | `completed` | T24 | Section 10 |

Each transition entry in Sections 4 to 10 names the **actor**, the **guards** (conditions that must hold at the moment of the write), and the **effects** (what the system does as a result: status, stock, and money changes in one transaction; messages through the outbox).

## 4. Stage 1: Checkout and placement (T1)

**T1: new → `pending`** (actor: customer)

At checkout the customer commits to a specific basket at specific prices. The system’s job is to validate everything that can be validated now, freeze the result, reserve stock, and say exactly how to pay.

**Guards.** All checks run on the server; totals sent by the browser are never trusted.

- Every line is in stock for the quantity requested.
- The address is serviceable by your courier, held in structured fields, and comes with a phone number the courier can call. “Unreachable” and “address incorrect” are both on the delivery-failure list in Section 8, so catching them here is cheaper than catching them later.
- The terms box is ticked. It covers the cancel rule, no refunds after payment, and the shop’s right to decline an order. Store the terms version and the time of acceptance.
- The idempotency key has not been used before. A repeated submit (double click, refresh, retry after a timeout) returns the existing order instead of creating another.

**Effects, in one transaction.**

1. Prices, taxes, discounts, shipping, and address are snapshotted onto the order and its lines.
2. Stock is reserved with an expiry.
3. The payment deadline is set.
4. A payment record is created in `awaiting_proof` with a unique payment reference.
5. The “order received” email is queued with payment instructions, the deadline, and the rule: you can cancel until you submit proof.

**Practice notes**

- **One source for the amount.** The stored grand total is the only figure payments are ever compared against.
- **The payment reference** is what staff match against the bank record. Make it short, easy to read aloud (no look-alike characters), and unique across all orders, cancelled ones included.
- **Reservation lifetime** should cover the payment deadline plus a small grace period. Choose the deadline by what holding stock costs you: a shorter deadline frees stock sooner but loses slower payers.
- **Unpaid-order cap (new).** Reserving stock at placement lets one customer tie up inventory without paying. Cap simultaneous `pending` orders per customer and watch for repeated expiries.
- **Public order number (new).** Use one that doesn’t reveal your order volume, and keep a separate internal ID.

## 5. Stage 2: Payment proof and verification (T2 to T5)

Manual payment is the riskiest stretch of the workflow, because it depends on a person comparing a customer’s claim with a bank record. The rules below keep that comparison honest and stop the order drifting while it happens.

### 5.1 Submitting proof

**T2: `pending` → `payment_review`** (actor: customer)

- **Guards:** before the deadline; a valid file; reference, amount, and date entered; the customer confirms “this ends your option to cancel.”
- **Effects:** the payment record becomes `proof_submitted`; cancel closes; the expiry clock pauses; the order joins the admin queue with an SLA timer; a “proof received, we’re verifying your payment” notice goes out.
- **File handling:** accept only the file types you need, set a size limit, check the actual content type rather than trusting the extension, scan the file if you can, and store it privately (Section 11.7).

**Why lock at upload.** The dangerous window in manual payment is the gap between the customer sending money and staff seeing it. If cancel stayed open in that gap, every cancel would require reasoning about money in flight. Locking at upload removes that case: a cancel can involve money only when a rejected proof carried a part-payment, and the one remaining race, upload against cancel from two tabs, is settled by conditional writes on `pending`. The price is that a wrong upload can’t be withdrawn; it waits for rejection. Two mitigations follow: put the rule on the button itself (“Submitting proof ends your option to cancel”), and keep the review SLA tight, because a locked-in customer is waiting while stock stays reserved.

### 5.2 Working the queue (new)

- Oldest first, with a visible SLA clock.
- A reviewer claims an order so two people never verify the same one.
- The reviewer sees the order total, the payment reference, the proof, and any earlier attempts.
- Only the payment-verifier role can verify or reject (Section 11.6).

### 5.3 Verifying

**T5: `payment_review` → `paid`** (actor: payment verifier)

- **Guards:** reference and amount are read from the bank or e-wallet record, not the screenshot; the amount, together with any earlier part-payment, equals the order total; the reference is not used by any other order, cancelled ones included; status is still `payment_review` at write time.
- **Effects:** the payment record becomes `verified` (reference, amount, verifier, time); the stock reservation is committed; a receipt is issued; the order joins the pick queue; a “payment confirmed” notice goes out. An overpayment queues the excess as a refund task.

**Why the bank record, not the screenshot.** A screenshot is a claim. It can be edited, reused, or taken before the transfer settled. The bank or e-wallet record is the evidence.

**Transfer fees (new, policy choice).** Some transfer methods deduct a fee and leave a small shortfall. The plan as written requires an exact match, which is simplest to audit. If you allow a tolerance, make it a fixed small amount, record the difference as a write-off, and require a manager above it.

### 5.4 Rejecting

**T3: `payment_review` → `payment_failed`** (actor: payment verifier)

- **Guards:** a reason code with customer-facing text (not received, amount short, unreadable, wrong reference, wrong proof); the amount actually received, if any, is recorded; attempts are counted.
- **Effects:** the payment record becomes `rejected`; cancel reopens; a fresh correction window starts (e.g., 24 hours); the notice gives the reason, a re-upload link, and the option to cancel.
- **Funds in transit.** If the money may still arrive, don’t reject. Set the payment record to `awaiting_funds` with a recheck time and leave the order in review. That avoids rejecting a legitimate payment that simply hasn’t settled.

### 5.5 Re-uploading

**T4: `payment_failed` → `payment_review`** (actor: customer)

- **Guards:** the correction window is open and attempts are under the cap (e.g., 3).
- **Effects:** as T2. At the cap, uploads close and the order moves to `on_hold` for a support call, which also limits proof spam.

### 5.6 Reverting a mistaken verification

Staff can move `paid` back to `payment_review` with a reason, until `packing` starts. Customers can’t cancel from review, so the revert reopens nothing.

### 5.7 Late, duplicate, and stray payments

- A transfer that arrives after expiry goes to the refund queue. The order is never revived.
- A second transfer against the same reference is an overpayment; the excess becomes a refund task.
- A reference that already belongs to another order blocks T5 and goes to a manager.
- **Daily reconciliation (new).** Match bank and e-wallet statement lines to payment records, and list unmatched credits and proofs still unverified. Each mismatch opens a reconciliation task, which blocks completion of the order it touches (T24).

### 5.8 Fraud and abuse controls (new)

Unique references, verification against the source record, an attempt cap, file validation, a verifier role separate from refund approval, and the shop’s right to decline an order under the terms all work together. Watch for repeated rejected proofs from one account, and for one account repeatedly letting orders expire.

## 6. Cancellation, expiry, and holds (T6 to T8, T11, T12)

### 6.1 Who can cancel, and when

| Actor | Allowed from | Not allowed from | Money consequence |
| --- | --- | --- | --- |
| Customer (T6) | `pending`, `payment_failed` | `payment_review` onward | None, unless a rejected proof carried a part-payment: then a refund task for that amount, with a return date in the notice |
| System expiry (T7) | `pending`, `payment_failed`, once the deadline or correction window has passed | `payment_review` | A late transfer goes to the refund queue; the order is never revived |
| Admin (T8) | Any pre-handoff state (including `on_hold`), `returned_to_sender`, or any status while the shipment is flagged lost | `shipped`, `out_for_delivery`, `delivery_failed` unless flagged lost; `completed` | From `payment_review`: check the bank and refund if money is found. From `paid` on: full refund including shipping, to the paying account. Undeliverable orders: see Section 9.4 |

### 6.2 Customer cancel

**T6: `pending`, `payment_failed` → `cancelled`** (actor: customer)

- **Guards:** the owner’s session; status in the allowed set at write time; the reason is optional and never blocks. From `payment_review` on, the cancel is refused with a plain message (“payment is being verified” or “payment confirmed”), and the same refusal applies to direct API calls, not just the button.
- **Effects:** release the reservation; expire the payment instructions; keep the payment reference locked so a late transfer can still be traced and refunded.

### 6.3 System expiry

**T7: `pending`, `payment_failed` → `cancelled`** (actor: system)

- **Guards:** the payment deadline or correction window has passed. Never from `payment_review`.
- **Effects:** as T6, with reason `payment_expired`. Late uploads are refused. A late transfer goes to the refund queue; the order is never revived.

### 6.4 Admin cancel

**T8: any pre-handoff state, `returned_to_sender`, or a lost shipment → `cancelled`** (actor: admin)

- **Guards:** a permitted role; a shop-side reason code; no goods in carrier custody at write time. That is why T8 is refused in `shipped`, `out_for_delivery`, and `delivery_failed` unless the shipment is flagged lost; request an intercept instead (T18).
- **No “customer request” reason.** Otherwise the lock after proof upload would leak back in through staff discretion. Use shop-side codes, such as out of stock, suspected fraud, declined under the terms, customer unreachable, undeliverable, and lost shipment.
- **Effects:** release stock (restock after a physical check or, for a returned parcel, after inspection; lost goods are written off); void the label and remove the order from the pick list; refund as in the table above, closed only by a receipt; send a notice with a plain reason and the refund timing.

### 6.5 Holds

A hold pauses an order without cancelling it, so exceptions have a home that isn’t a pile of special-case statuses.

**T11: any pre-handoff state → `on_hold`** (actor: staff or a rule)

- **Guards:** a reason code, an owner, and a deadline; the previous status is stored as `resume_to`.
- **Effects:** timers pause; an order being picked is pulled from the pick list; the customer is notified if their input is needed. Cancel rights follow the `resume_to` state, and a hold placed from review keeps the payment lock.

**T12: `on_hold` → `resume_to`** (actor: staff)

- **Guards:** the reason is marked resolved.
- **Effects:** timers restart with the time they had left. If the deadline passes with no customer answer, T8 applies with reason `customer_unreachable`.

Later waits (`delivery_failed`, `returned_to_sender`) carry their own deadlines instead of using holds. A hold without an owner and a deadline becomes permanent limbo, so both are mandatory; review open holds daily *(new)*.

## 7. Stage 3: Fulfilment and handoff (T9, T10, T13)

### 7.1 Release to the warehouse

**T9: `paid` → `packing`** (actor: warehouse)

- **Guards:** stock is allocated; the address is not under edit.
- **Effects:** a pick list is assigned and the packing SLA timer starts.
- From here the address is locked. A correction goes through staff under a hold (Section 6.5).
- *(new)* Sort pick lists by storage location, and if goods carry expiry dates, allocate the earliest-expiring stock first.

### 7.2 Packing

**T10: `packing` → `ready_to_ship`** (actor: warehouse)

- **Guards:** every line scanned and quantities matching; the parcel weighed; a label generated.
- **Effects:** label and packing slip stored (the label stays voidable until handoff); the order joins the courier pickup list; an optional “packed” notice.
- **Practice (new).** Scanning each item against the pick list catches wrong-item errors at the cheapest point. Comparing the parcel’s weight with the expected weight catches missing items. For higher-value orders, photograph the packed contents: the photo is useful evidence when a “wrong item” or “missing item” report arrives.
- **Shortages at pick (new).** If stock isn’t where the system says it is, don’t ship a partial order (partial shipments are outside this plan). Place the order on hold with reason `stock_shortage`, an owner, and a same-day deadline. Either a recount finds the item and the order resumes, or the shop cancels under T8 with reason `out_of_stock` and a full refund. This reuses T11 and T8 and adds no status.

### 7.3 Handoff to the carrier

**T13: `ready_to_ship` → `shipped`** (actor: carrier)

- **Guards:** a carrier scan or signed handoff, not label printing.
- **Effects:** physical stock is deducted; cancel closes until the parcel is back or flagged lost; tracking and the expected date are sent; the delivery watchdog starts.
- **Practice (new).** Hand parcels over against a manifest, count them with the courier, and keep the signed or scanned manifest. The handoff scan is when responsibility passes to the carrier, which is why it is also when stock is deducted.

## 8. Stage 4: Shipping and delivery (T14 to T19)

### 8.1 Rules for carrier events

Carrier data is the least reliable input in the workflow: events arrive late, twice, out of order, or in contradiction. These rules keep the order status sane.

- **Attach events to a shipment, not the order.** Only the order’s current shipment may move its status. A reshipped parcel’s old tracking number can log events but never change the order.
- **Order by the carrier’s timestamp,** not by arrival time.
- **Drop duplicates,** keyed on shipment plus carrier event ID, or tracking number plus code plus time.
- **Apply an event only if the transition is legal** from the current status. A late `out_for_delivery` after `delivered` is logged and ignored. A contradictory pair (delivered, then returned) raises an alert instead of overwriting.
- **Log the carrier as actor,** with the raw event attached.
- **Map carrier codes to internal reasons through a table.** Unknown codes go to a review queue. Never guess.
- **Webhooks first, polling as the fallback.** *(new)* Authenticate each webhook (shared secret or signature), store the raw payload first, acknowledge quickly, and process asynchronously.
- **No API?** Staff enter updates with the carrier’s proof attached, audited like any override.

### 8.2 Transitions

**T14: `shipped` → `out_for_delivery`** (actor: carrier event)

- **Guards:** the event is for the current shipment and newer than the last applied one.
- **Effects:** an “out for delivery” notice, with courier contact or ETA if provided. Skip this step if your courier doesn’t report it.

**T15: `shipped`, `out_for_delivery`, `delivery_failed` → `delivered`** (actor: carrier event, or staff with evidence)

- **Guards:** the event is for the current shipment; proof of delivery is stored (receiver, time, photo, signature, or OTP); a manual mark needs the carrier’s proof attached.
- **Effects:** the report window starts (e.g., 5 days); watchdogs clear; a “delivered” notice gives the window’s end date, **Confirm receipt**, and **Report a problem**; a reminder goes out 24 hours before the window closes.

**T16: `shipped`, `out_for_delivery` → `delivery_failed`** (actor: carrier event)

- **Guards:** the carrier code maps to a reason (recipient unavailable, address incorrect or incomplete, refused, unreachable, access problem, force majeure); the attempt counter goes up by one.
- **Effects:** fault is recorded (customer, carrier, or shop); the notice gives the reason and the options (confirm availability, correct the phone number or address, reschedule); a staff task is created to call within 24 hours. An address change after handoff is verified by staff, logged, and passed to the carrier as a redirect.

**T17: `delivery_failed` → `out_for_delivery`** (actor: carrier event)

- **Guards:** attempts are under the maximum (e.g., 3).
- **Effects:** an “another attempt today” notice.

**T18: `shipped`, `out_for_delivery`, `delivery_failed` → `returned_to_sender`** (actor: carrier event)

- **Guards:** attempts exhausted, the carrier’s holding period over, the recipient refused, or a staff-requested intercept confirmed.
- **Effects:** a staff task to expect the parcel; a customer notice explaining what happened, how to get the order reshipped (confirm or correct the address, and pay the reship fee if the failure was theirs), and a response deadline (7 to 14 days).

**T19: parcel received back** (actor: warehouse; no status change)

- **Guards:** the scan matches the shipment.
- **Effects:** inspect the parcel (seal, contents, photos); the goods stay allocated to this order and are not sellable; T20 and T8 unlock.

### 8.3 Practice notes

- A prompt call after a failed attempt costs little compared with a returned parcel, a reship fee, and a second delivery. That is why T16 creates the call task automatically.
- Store proof of delivery for every delivered shipment. It is your evidence when a “not received” report arrives.
- *(new)* If the carrier supports redirects or rescheduling, put a self-service confirm-or-reschedule link in the failed-delivery notice.

## 9. Stage 5: Exceptions and remedies (T20 to T23)

### 9.1 Reshipping

**T20: `returned_to_sender`, `delivered` (after an approved report), or any in-transit state with the shipment flagged lost → `packing`** (actor: staff)

- **Guards:** a reason code and the fault set; for a returned parcel, received and inspected; for customer fault, the reship fee verified like any payment (it sits on the payment record, with no new order state); for a lost parcel, the loss flagged under T21; stock available; one reship per order unless a manager overrides.
- **Effects:** a new shipment record is created and the old one closes, so its later events are logged only. A returned parcel’s goods are reused (a failed inspection counts as lost). For lost or reported items, fresh stock is allocated and the loss written off. The address is reconfirmed. A “we’re sending your order again” notice goes out.

### 9.2 Lost shipments

**T21: shipment flagged lost** (actor: staff; no status change)

- **Guards:** no scan for N days with a trace open, and the carrier confirms the loss or the trace deadline passes.
- **Effects:** the shipment is marked `lost`; the carrier claim is tracked separately; T20 and T8 unlock. If a “delivered” event later arrives for a lost shipment, raise an alert instead of applying it.

### 9.3 Problem reports

Reports are how “not received,” “damaged,” “wrong item,” and “missing item” reach staff during the report window. They are not refund requests: staff choose the remedy.

**T22: problem report filed** (actor: customer; no status change)

- **Guards:** the order is `delivered` and inside the window; a type is chosen; photos or video are attached for damaged, wrong item, and missing item; one open report at a time, and one per shipment.
- **Effects:** the completion timer pauses; the report joins the staff queue with a 48-hour SLA; an acknowledgement notice goes out.

**T23: report resolved** (actor: staff, and a manager for any money; no status change)

- **Guards:** a reason code and exactly one outcome: rejected; replacement (T20); not-received confirmed (the shipment is flagged lost, then T20 or T8); or compensation (capped, manager-only).
- **Effects:** if rejected, the completion timer resumes where it paused. Compensation becomes a refund-queue task closed by a receipt, and the order stays on its path. Every outcome ends with a customer notice giving a plain reason.

**Why keep reports.** They are the only way a “delivered but never received” parcel reaches you before the order completes. They also act as a safety valve: consumer law in many places gives buyers remedies for faulty or misdescribed goods that terms can’t waive. With no return flow, the customer keeps a wrong or damaged item and gets a replacement or capped compensation.

### 9.4 Who pays

Fault is recorded on every failure, and it decides the cost.

| Cause | Typical cases | Who pays | Remedy |
| --- | --- | --- | --- |
| Customer | Absent; wrong or incomplete address; refused; unreachable | Customer | A new shipping fee to reship. If there is no answer by the deadline, staff close the order under T8 (reason `undeliverable`) with a refund less the shipping actually paid |
| Carrier | Lost; damaged in transit | Shop (pursue the carrier claim) | Replacement; with no stock to resend, refund the affected items in full |
| Shop | Wrong item; missing item; packing error | Shop | As for carrier |

The deduction for customer-caused failures must be written into your terms of sale. Have a lawyer check the wording, since rules differ by country.

## 10. Stage 6: Completion (T24)

**T24: `delivered` → `completed`** (actor: the system when the window elapses, or the customer pressing **Confirm receipt**)

- **Guards:** the window has elapsed or the customer confirmed (the button says it ends reporting); no open report, refund task, or reconciliation task; status still `delivered` at write time.
- **Effects:** the order becomes read-only; reporting closes; the review request is sent; records are finalised. If you add commissions later, their release attaches here.

**Why `delivered` isn’t final.** Nothing irreversible attaches to it. Stock was deducted at `shipped`, and the downstream effects (review request, later commission) wait for `completed`. A reship or an approved report can therefore pull the order back to `packing` without undoing anything. The cost shows up in metrics: measure time-in-state per shipment, or a reship will distort your ship-to-deliver averages.

**Why the window and the button say what they end.** The window gives the customer a fixed, stated period to report problems and gives you a point after which the books can close. Confirm receipt is a shortcut to that point, so its label should say that it ends reporting, just as the upload button says it ends cancelling.

**Open money blocks completion.** A refund task or reconciliation task means money and orders still disagree somewhere. Making them block T24 forces those questions to be settled before the order is sealed.

## 11. Cross-cutting practices

### 11.1 Concurrency, idempotency, and consistency

Every transition is a single statement that names the status it expects:

```sql
UPDATE orders
SET status = 'payment_review', version = version + 1, updated_at = NOW()
WHERE id = :id AND status = 'pending';
-- 1 row changed: this request won.
-- 0 rows changed: another actor moved the order first. Reload and respond.
```

- **Re-check guards at write time,** inside the same transaction, not only on the screen that showed the button.
- **Unique constraints are the backstop:** payment reference, checkout idempotency key, shipment plus carrier event ID, one open report per order.
- **One transaction for facts, an outbox for messages** *(new)*. Status, stock, and refund tasks commit together; emails, SMS, and courier calls are queued and retried. Handlers must be safe to run twice.
- **Timer jobs use the same path.** The expiry job selects candidates, then performs each as a conditional transition, so a proof uploaded a moment earlier wins.
- **Every door uses the one handler:** UI, API, admin tools, carrier events, and timers.

### 11.2 Inventory lifecycle

| Stage | Set at | Meaning | Undone by |
| --- | --- | --- | --- |
| Reserved | T1 | Stock set aside, with an expiry | Cancel or expiry (release) |
| Committed | T5 | Reservation made permanent; expiry removed | Admin cancel before handoff (release after a physical check) |
| Deducted | T13 | Physical stock leaves the building | A returned parcel is inspected (T19), then reused (T20) or restocked (T8) |
| Written off | T20, T21 | Loss recorded against its fault | Carrier claim recovery, tracked separately |

- Prevent overselling by reserving with an atomic conditional decrement (`WHERE available >= :qty`) rather than reading a count and then writing it.
- *(new)* Run a job that releases expired reservations, and a periodic check that every reservation belongs to an open order. A mismatch is a leak.
- *(new)* Count stock cyclically and investigate differences against the system.
- A returned parcel’s goods stay allocated to the order and aren’t sellable until inspected.

### 11.3 Money handling

- **Payment record states:** `awaiting_proof`, `proof_submitted`, `awaiting_funds`, `rejected`, `verified`. A reship fee is another payment on the same record, verified the same way.
- **Refunds** go to the paying account, for the amount actually received, and are closed only by a receipt such as a transfer reference. A refund task without a receipt is still open money.
- **Amounts.** A cancel after payment refunds in full, including shipping. Customer-caused undeliverable orders refund less the shipping actually paid. Remedies for shop or carrier fault are the shop’s cost.
- **Promises.** Every notice that promises a refund states when it will arrive.
- **Segregation of duties (new).** Whoever verifies payments shouldn’t be the only approver of refunds above a threshold, and compensation is manager-only (T23).
- **Reconciliation (new).** Daily, as in Section 5.7. Keep receipts for accounting.

### 11.4 Customer communication

- One notice per meaningful event; each transition’s effects name its notice.
- Every notice carries the order number, the current state in plain language, the next step, the deadline, and the consequence of the customer’s action.
- *(new)* Offer an order page with a timeline that mirrors the status, so customers can check without contacting you.
- *(new)* Queue notices through the outbox, send them idempotently, and record sends and bounces. Choose the channel by urgency: a failed delivery is time-critical.
- *(new)* Keep transactional notices separate from marketing. The review request (T24) is the only message here that edges toward marketing, and it goes after completion.

### 11.5 Timers and SLAs

Store every deadline as data on the order, shipment, or report. The values are defaults to tune.

| Timer | Starts | Default | Behaviour |
| --- | --- | --- | --- |
| Payment deadline | T1 | Your policy, limited by what holding stock costs | Pauses at T2; expiry applies T7 |
| Correction window | T3 | 24 hours | Expiry applies T7 |
| Re-upload cap | T3 | 3 attempts | At the cap, the order moves to `on_hold` (T4) |
| Review SLA | T2 | Set to what staff can actually meet | Alert only; the system never auto-approves |
| Funds recheck | `awaiting_funds` set | Per payment method | Order stays in review |
| Packing SLA | T9 | Alert over 24 hours | Pauses on hold |
| Courier pickup | T10 | Alert if not collected in 24 hours |  |
| Delivery watchdog | T13 | Alert after N days without a scan | Opens the trace behind T21; cleared at T15 |
| Delivery attempts | T16 | Maximum 3 | At the cap, T18 |
| Failed-delivery contact | T16 | Staff call within 24 hours | Alert |
| Return-to-sender response | T18 | 7 to 14 days | Expiry: staff close under T8 (`undeliverable`) |
| Report window | T15 | 5 days; reminder 24 hours before the end | Pauses while a report is open |
| Report SLA | T22 | 48 hours | Alert |
| Hold deadline | T11 | Per reason code | With no customer answer: T8 (`customer_unreachable`) |

*(new)* Store timestamps in UTC, compute deadlines from server time only, and show every deadline in notices with date, time, and time zone.

### 11.6 Roles and permissions (new)

The earlier design says “permitted role” without naming roles. This is a suggested separation; adapt it to your team. In a very small team one person may hold several roles, and then the audit trail matters more.

| Role | Transitions and actions |
| --- | --- |
| Customer | T1, T2, T4, T6, T22; Confirm receipt (T24); confirm or correct the address after a failed delivery |
| Payment verifier | T3, T5, `awaiting_funds`, revert to review |
| Warehouse | T9, T10, T19; inspect returned parcels |
| Support | T11, T12, T20, T21, failed-delivery follow-up, a manual delivered mark with the carrier’s proof (T15), T23 outcomes that involve no money, T8 within the refund threshold |
| Manager | T8 above the refund threshold, a second reship (T20 override), T23 compensation, tolerance write-offs |
| System | T7, T24, timers and alerts |
| Carrier integration | Events for T13 to T18 |

### 11.7 Audit, privacy, and security (new)

- **Status history.** One row per transition: order, from, to, actor type (customer, staff, system, carrier), actor ID, reason code, source (UI, API, webhook, timer), UTC time, request ID, and the raw payload for carrier events. Rows are never updated or deleted.
- **Overrides** (manual delivery marks, T8, a second reship, tolerance write-offs) need a reason code and attached evidence, and appear in a periodic report to a manager.
- **Proof and delivery files.** Store them outside the public web root, serve them through an authenticated handler or short-lived signed links, and record who views them.
- **Least privilege.** A payment verifier needs the proof and the amount, not the full address. The warehouse needs the address, not bank details.
- **Direct calls.** Cancel, confirm receipt, upload, and report endpoints check ownership and status on the server, and state-changing requests carry CSRF protection.
- **Retention.** Decide how long proof images, addresses, and phone numbers are kept after completion, then delete or anonymise them on a schedule, subject to tax and accounting record requirements. Data-privacy and consumer rules differ by country, so confirm yours.
- **Carrier data.** Send the carrier only what delivery needs.

### 11.8 Monitoring

**Stuck-state alerts** (no status change; each opens a task): `payment_review` past its SLA; `paid` or `packing` over 24 hours; `ready_to_ship` not collected in 24 hours; `shipped` with no scan for N days (this opens the trace behind T21); `delivery_failed` with no customer contact in 24 hours; `returned_to_sender` past its response deadline; reports past their SLA; holds past their deadline; refund tasks past their promised date; bank credits that match no order. The last three are new.

**Indicators to track (new):**

- Payment verification time (T2 to T3 or T5) and rejection rate by reason.
- Expiry rate (T7). A high rate suggests unclear payment instructions or a deadline that is too short.
- Pay-to-handoff time, split into pick-and-pack (T9 to T10) and wait for pickup (T10 to T13).
- Ship-to-deliver time, per shipment (T13 to T15).
- First-attempt delivery rate, and failure rate by reason and fault.
- Return-to-sender rate; lost-parcel rate and claim recovery.
- Report rate by type; replacement and compensation cost.
- Cancellation rate by stage and reason; refund count, value, and age.
- Perfect-order rate: the share of orders delivered complete and undamaged with no report.

## 12. Data model at a glance (new)

An outline of the records this design needs. Column types, indexes, and DDL are the next deliverable.

| Entity | Holds | Notes and key constraints |
| --- | --- | --- |
| `orders` | Status, version, totals snapshot, address snapshot, terms version and time, deadlines, idempotency key | Unique idempotency key; status changes only through the handler |
| `order_items` | Product reference, name and SKU snapshot, quantity, unit price, tax, line total | A snapshot, not a live join |
| `payments` | Order, status, unique reference, expected total, amount received so far, attempt count, verifier, verified time, recheck time | Reference unique across all orders, cancelled included; reship fees recorded here |
| `payment_proofs` | One row per upload: file, entered reference, amount, date, outcome, reason code | Private file storage |
| `shipments` | Order, sequence, carrier, tracking number, state (active, closed, lost), attempts, fault, label, handoff and delivery times, proof of delivery, report window end | Only the current shipment moves the order |
| `shipment_events` | Carrier event ID, code, mapped reason, carrier time, received time, raw payload, applied or ignored | Unique on shipment plus carrier event ID |
| `reports` | Order, shipment, type, evidence, state, outcome, reason code, SLA due, compensation amount | One open per order; one per shipment |
| `refund_tasks` | Order, amount, cause, destination account, state, receipt reference, approvers, promised date | Closed only with a receipt |
| `holds` | Order, reason code, owner, deadline, `resume_to`, opened and closed times | Owner and deadline required |
| `stock_reservations` | Order line, quantity, state (reserved, committed, deducted, released, written off), expiry | Atomic conditional decrement on reserve |
| `reconciliation_tasks` | The mismatch, related payment or order, state, owner | Blocks T24 |
| `status_history` | Append-only transition log | Never updated or deleted |
| `reason_codes` | Category, internal label, customer-facing text, default fault | Changed by managers; versioned |
| `notification_outbox` | Event, recipient, template, state, attempts, provider response | Idempotent sends |

## 13. Testing and go-live

### 13.1 What to build

- A **transition matrix test** *(new)*: every status against every transition, expecting “allowed” or “refused” exactly as this guide says.
- **Guard tests** for each transition, including the write-time status check.
- A **carrier simulator** in test mode, with buttons for scan, out for delivery, fail (with a reason), deliver, return, lost, “send duplicate,” and “send out of order,” plus **run timers now**. Every simulated event replays through the same handler as a real webhook, so the tests exercise production code.
- **Concurrency runs:** hundreds of repetitions of each race, asserting exactly one winner.
- **Permission tests** that call the API directly, not only through the UI.

### 13.2 Dry-run scenarios

1. **Upload against cancel** from two tabs, a few hundred runs: exactly one wins. After the upload, cancel is refused in the UI and by direct API call. After a rejection it reopens.
2. **Expiry during review.** The expiry job runs while a proof sits in review: the order stays put. A hold placed from review keeps the lock.
3. **Happy path** with a short window, ending in `completed`. Confirm receipt completes it early, and is refused while a report is open.
4. **Duplicate and out-of-order carrier events:** one transition each, and a late `out_for_delivery` after `delivered` is logged, not applied.
5. **Failed deliveries.** Fails twice and is delivered on the third try (counter at 2). Separately, fails to the cap and goes to `returned_to_sender`.
6. **Return exits.** Parcel scanned back, then each exit. Reship needs a verified fee for customer fault and none for carrier fault. Staff cancel refunds less shipping, needs a receipt, and restocks only after inspection.
7. **Lost parcel:** trace, flag, reship with fresh stock. The original later reports delivered, and only an alert is raised. With no stock, the order is cancelled with a full refund.
8. **Reports and the timer.** A report filed inside the window pauses completion. Filed at the boundary against the timer, exactly one outcome wins. A rejection resumes the timer, and a replacement runs back through `packing` and earns its own window.
9. **Admin cancel boundaries.** Refused at `shipped`, `out_for_delivery`, and `delivery_failed`; allowed at `returned_to_sender` once the parcel is scanned in.
10. **Second reship** on one order is refused without a manager override.

*(new)* Add a scenario for each recommendation you adopt, for example a pick shortage (hold, then a recount or an `out_of_stock` cancel with full refund) and the unpaid-order cap.

### 13.3 Go-live (new)

- **Map the old statuses.** Write a table from your current statuses to the 14 new ones, noting which facts move into records, and backfill open orders before switching. Orders with no clean mapping get a manual review.
- **Run in test mode** with the simulator until all scenarios pass.
- **Pilot** on a small number of real orders, watching alerts and status history daily.
- **Write short procedures** for each role: verifying payment, packing, handling failed deliveries, resolving reports, issuing refunds. Make the reason-code lists part of training.
- **Keep a manual fallback** for carrier events (staff entry with proof) so a carrier-integration outage doesn’t stop orders.
- **Review the numbers after the first few weeks** and tune windows, caps, and SLAs.

## 14. Scope boundaries and the open decision

### The open decision: problem reports

This guide includes customer problem reports (T22, T23) because they are the only route by which a “delivered but never received” parcel reaches you before the order completes. If you decide against them, `delivered` simply runs out the clock, and T22, T23, and part of T20 drop away.

### Not covered, and where each would attach

- **Returns of faulty goods.** This would add a small return leg after `delivered`. The current plan leaves the item with the customer and uses replacement or compensation.
- **Partial or multi-parcel shipments.** The shipments record allows several per order, but the statuses assume one parcel. Supporting several means deriving the order status from its shipments.
- **Order edits.** None after placement. To change items, cancel while that is allowed and reorder. Address corrections go through staff under a hold.
- **Card or gateway payments.** The review step becomes webhook-driven. Use a hosted payment page so card data never touches your servers.
- **Cash on delivery.** Moves payment to T15 and brings different risk, refund, and reconciliation rules.
- **Commissions or rewards.** Hook in at `completed` (T24).
- **Invoices and tax documents.** These depend on your jurisdiction.
- **Cross-border orders.** Customs, duties, and longer carrier timelines.

### Next step

The natural follow-on is the schema: DDL, indexes, and constraints for the entities in Section 12.

## Appendix: Transition index

| # | Transition | Actor |
| --- | --- | --- |
| T1 | new → `pending` | Customer |
| T2 | `pending` → `payment_review` | Customer |
| T3 | `payment_review` → `payment_failed` | Payment verifier |
| T4 | `payment_failed` → `payment_review` | Customer |
| T5 | `payment_review` → `paid` | Payment verifier |
| T6 | `pending`, `payment_failed` → `cancelled` | Customer |
| T7 | `pending`, `payment_failed` → `cancelled` | System |
| T8 | Pre-handoff state, `returned_to_sender`, or lost shipment → `cancelled` | Admin |
| T9 | `paid` → `packing` | Warehouse |
| T10 | `packing` → `ready_to_ship` | Warehouse |
| T11 | Pre-handoff state → `on_hold` | Staff or rule |
| T12 | `on_hold` → `resume_to` | Staff |
| T13 | `ready_to_ship` → `shipped` | Carrier |
| T14 | `shipped` → `out_for_delivery` | Carrier event |
| T15 | `shipped`, `out_for_delivery`, `delivery_failed` → `delivered` | Carrier event, or staff with proof |
| T16 | `shipped`, `out_for_delivery` → `delivery_failed` | Carrier event |
| T17 | `delivery_failed` → `out_for_delivery` | Carrier event |
| T18 | `shipped`, `out_for_delivery`, `delivery_failed` → `returned_to_sender` | Carrier event |
| T19 | Parcel received back (no status change) | Warehouse |
| T20 | `returned_to_sender`, `delivered`, or a lost in-transit shipment → `packing` | Staff |
| T21 | Shipment flagged lost (no status change) | Staff |
| T22 | Problem report filed (no status change) | Customer |
| T23 | Report resolved (no status change) | Staff; manager for money |
| T24 | `delivered` → `completed` | System or customer |
| Revert | `paid` → `payment_review`, until `packing` starts | Staff |
