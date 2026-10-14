# Address Book QA Test — Beginner-Friendly Guide

This checklist walks a complete beginner through testing the **member delivery address book** end-to-end. Follow it exactly, in order. Do not skip steps.

An "address book" is simply a place where a member saves the delivery addresses they use, so they don't have to retype them every time they check out. One saved address can be marked as the **default** (the one that fills in automatically).

---

## Prerequisites

- [ ] **A member account.** You must be able to log in as a normal member (not admin).
- [ ] **One browser window** is enough. Use a private/incognito window so you start clean.
- [ ] **Optional (only for the last few tests):** a product in the shop so you can add it to a cart and reach the checkout page. If the shop is empty, ask whoever manages the site to add one, or reuse an existing product.
- [ ] Keep a **FAIL log** (any text file). Write down the exact step number and what you saw whenever something fails.

## Golden Rule

> Everything in this test happens **as the logged-in member**, in one window.

If you are ever told to "log in as admin", that is a mistake in your notes — this feature is member-only.

## Definitions (read once)

| Term | What it means |
|---|---|
| **Saved address** | An address you stored in your profile for later. |
| **Default address** | One saved address marked as the default. It shows a green **Default** badge and is used to prefill checkout automatically. |
| **Flash message** | The green (success) or red (error) banner shown at the top of a page after an action. |
| **Modal** | A pop-up box that appears over the page (e.g. the "Add delivery address" window). |

## Starting Point

1. Log in as your member.
2. Go to your profile page: `http://localhost/altaslive/?page=profile`
   (or click **Profile** in the left sidebar).
3. Scroll down **below the big blue "Save Changes" button**.
4. **PASS if** you see a card titled **📍 Delivery Addresses** with a **+ Add address** button.
5. **FAIL if** the card is missing, the page is blank/white, or you see raw PHP code.

> If you previously saved addresses, they will be listed here. That is fine — the tests below describe the full lifecycle.

---

## Test A — Add your first address

**On the profile page:**

1. Click **+ Add address**.
2. A pop-up appears titled **Add delivery address**.
3. Fill it in exactly:

| Field | Value |
|---|---|
| Label | `Home` |
| Recipient name * | `Juan Dela Cruz` |
| Phone | `0917 000 0000` |
| Address * | `123 Mabini Street, Barangay Uno` |
| City | `Quezon City` |
| Province | `Metro Manila` |
| Postal | `1100` |
| Set as default | (leave ticked or unticked — see note) |

4. Click **Save address**.
5. **PASS if:**
   - A green message appears: **"Delivery address saved."**
   - The new address is listed in the **📍 Delivery Addresses** card.
   - It shows the label **Home**, the recipient name, and the address.
6. **Since this is your first address, it should automatically carry the green "Default" badge** even if you did not tick "Set as default". This is intentional.

> **Note:** The very first address a member saves always becomes the default. This guarantees checkout always has something to prefill.

---

## Test B — Add a second address

**On the profile page:**

1. Click **+ Add address** again.
2. Fill it in:

| Field | Value |
|---|---|
| Label | `Office` |
| Recipient name * | `Maria Santos` |
| Phone | `0918 111 2222` |
| Address * | `500 Ayala Avenue, Ground Floor` |
| City | `Makati` |
| Province | `Metro Manila` |
| Postal | `1226` |
| Set as default | **leave UNticked** |

3. Click **Save address**.
4. **PASS if:**
   - Green message **"Delivery address saved."**
   - Both addresses now appear in the list.
   - **Only ONE** address has the green **Default** badge (the first one). The second must **not** be default.
5. **FAIL if** two addresses both show the Default badge.

---

## Test C — Edit an address

**On the profile page:**

1. On the **Office** address, click **Edit**.
2. The pop-up opens titled **Edit delivery address**, and it is **pre-filled** with that address's values (label `Office`, recipient `Maria Santos`, etc.).
3. **PASS if** every field already contains the saved values (you do not have to retype them).
4. Change the **Label** to `Office — Makati` and change **Postal** to `1227`.
5. Click **Save address**.
6. **PASS if:**
   - Green message **"Delivery address updated."**
   - The list shows the label **Office — Makati** and postal `1227`.
7. **FAIL if** a new duplicate row was created instead of updating the existing one.

> Editing an address must never create a second copy.

---

## Test D — Change which address is the default

**On the profile page:**

1. Look at the **Office — Makati** row. Because it is *not* the default, it should show a **Set default** button.
2. Click **Set default** on the Office address.
3. **PASS if:**
   - Green message **"Default delivery address updated."**
   - The **Default** badge moves to **Office — Makati**.
   - The Home address **no longer** has the Default badge.
   - The Office row no longer shows a **Set default** button (it is already default).
4. **FAIL if** two addresses are default at the same time.

---

## Test E — Delete an address (and automatic default promotion)

**On the profile page:**

1. On the **default** address (**Office — Makati**), click **Delete**.
2. A confirmation pop-up appears titled **"Delete this address?"** with a red **Delete** button and a **Cancel** button.
3. Click **Cancel** first.
4. **PASS if** nothing was deleted (the Office address is still listed).
5. Click **Delete** again, then in the pop-up click the red **Delete** button.
6. **PASS if:**
   - Green message **"Delivery address removed."**
   - The Office address is gone from the list.
   - The remaining Home address **automatically becomes the new default** (green Default badge), even though you never clicked Set default.

> **Why this matters:** a member with at least one saved address must always keep a default, so checkout always has something to prefill. Deleting the default promotes the newest remaining address.

7. **FAIL if** you deleted the default and no remaining address was promoted to default.

---

## Test F — Required fields and the 10-address limit

### F1 — Required fields

1. Click **+ Add address**.
2. Fill in only the **Label** (e.g. `Empty test`) and leave **Recipient name** and **Address** **blank**.
3. Click **Save address**.
4. **PASS if** the browser refuses to submit and shows a small tooltip like **"Please fill out this field"** on the first empty required field (Recipient name or Address).
5. **FAIL if** the address is saved with a blank name or blank address.

> The fields marked with **\*** (Recipient name, Address) are required. The browser blocks the pop-up from being saved until they are filled.

### F2 — Maximum of 10 addresses (optional / advanced)

This is slow to test by hand. Only do it if you need full coverage; otherwise mark it **SKIPPED**.

1. Add addresses one at a time until you already have **10** saved.
2. Try to add an **11th**.
3. **PASS if** a red message appears: **"You can save up to 10 addresses."** and no 11th address is added.
4. **FAIL if** an 11th address is saved.

> If skipped, write **SKIPPED — not tested** in your log. Do not mark it PASS.

---

## Test G — Checkout: pick a saved address to fill the form

> This test needs at least one product in the shop and one saved address in your profile.

**As the member:**

1. Go to the shop (`?page=shop`), **add any product to your cart**.
2. Open your cart and click **Proceed to checkout** (or go to `?page=checkout`).
3. In the **📍 Delivery details** card, near the top you should see a dropdown labelled **Pick a saved address**.
4. **PASS if** the dropdown lists your saved addresses, each showing the label, recipient, and address, and the default one is marked **"(default)"**.
5. Choose your saved address from the dropdown.
6. **PASS if** the fields below fill in automatically:
   - Recipient name
   - Recipient phone
   - Delivery address
   - City, Province, Postal
7. Now **manually change** the City field to something else (e.g. `Test City`).
8. **PASS if** you are allowed to edit it freely — the saved address is only a shortcut, not a lock.
9. **FAIL if** you cannot edit the fields, or choosing a saved address did nothing.

---

## Test H — Checkout: save a new address while ordering

**As the member, on the checkout page:**

1. In the **📍 Delivery details** card, near the bottom, find the checkbox:
   **"Save this address to my address book for next time."**
2. **If you already have saved addresses**, this box starts **UNticked**.
   **If you have none**, it starts **ticked** (to encourage saving the first one).
   Confirm the starting state matches your situation.
3. Tick the box (if it isn't already).
4. Fill in the delivery fields with a **brand-new** address you have not saved before, for example:
   - Recipient name: `Test Save`
   - Address: `999 Test Boulevard`
   - City: `Cebu City`, Province: `Cebu`, Postal: `6000`
5. Complete the order normally:
   - Choose a payment method you can use (e.g. a non-e-wallet method is fine).
   - Tick the **Terms** checkbox.
   - Click **Place order**.
6. **PASS if** the order is placed successfully (green message, order page shown).
7. Go back to `http://localhost/altaslive/?page=profile` and scroll to **📍 Delivery Addresses**.
8. **PASS if** the new address (`Test Save` / `999 Test Boulevard`) now appears in your saved list.
9. **FAIL if** the order was placed but the address was not saved.

> Tip: if the new address becomes a duplicate of an existing label, that is fine — this test only checks that *a* new entry was added.

---

## Test I — Checkout: the default address prefills automatically

This checks that a **fresh** checkout starts with the default address already filled in.

1. Make sure you know which address is your **default** (green Default badge on the profile page).
2. Start checkout with a **new cart item** (add a product, go to checkout).
   - Important: do this on a **fresh visit** to checkout, not after a failed order attempt.
3. **PASS if** the delivery fields (Recipient name, address, city, province, postal) are **already filled in** with your default address — without you touching the dropdown.
4. **FAIL if** the fields are empty or show the wrong address.

> Note: if your previous order attempt failed, the system restores what you typed on that attempt instead. That is a different, also-correct behavior. To see the default prefill, use a clean checkout.

---

## Test J — Security spot check (optional / advanced)

Addresses belong to a single member. Another member must never be able to see or change them.

- This is hard to check by hand. If you want to try: log out, log in as a **different** member, and confirm their 📍 Delivery Addresses card shows **only their own** addresses (never the first member's).
- **PASS if** each member sees only their own addresses.
- If you cannot test this, write **SKIPPED — not tested**. Do not mark it PASS.

---

## Test K — Edge cases

| # | Action | Expected Result |
|---|---|---|
| K1 | Open `?page=profile` with **no** saved addresses | Card shows **"No saved addresses yet."** No errors. |
| K2 | Save an address with an **empty Label** | It is still saved; the list shows a fallback title **"Address"**. Not a FAIL. |
| K3 | Enter a very long Label / Address | Values are trimmed to the allowed length; saving still succeeds. No error page. |
| K4 | Click **Delete**, then **Cancel** in the pop-up | Nothing is deleted. |
| K5 | Delete **all** addresses | Card returns to **"No saved addresses yet."** No errors, and checkout shows no saved-address dropdown. |
| K6 | On checkout with **no** saved addresses | The **Pick a saved address** dropdown does **not** appear; the **Save this address** checkbox starts **ticked**. |

---

## Accessibility Spot Check (recommended)

1. On the profile **📍 Delivery Addresses** card, press **Tab** repeatedly from the **+ Add address** button.
2. **PASS if** the focus outline moves through the buttons in a sensible order and is always visible.
3. Open the add/edit pop-up. Press **Tab**. **PASS if** focus enters the first field and you can reach every field and button with the keyboard alone.
4. In the pop-up, click the **Label** text (not the box). **PASS if** focus jumps into the Label input (the labels are linked to their fields).
5. Press **Esc** while the pop-up is open. **PASS if** it closes.

---

## Final Checklist

Tick only what you actually verified. Ticking something you did not test is worse than leaving it blank.

- [ ] Starting Point: 📍 Delivery Addresses card is present on the profile page
- [ ] Test A: First address saves; becomes default automatically
- [ ] Test B: Second address saves; only one default at a time
- [ ] Test C: Edit opens pre-filled and updates the same row (no duplicate)
- [ ] Test D: Set default moves the badge and removes the old default
- [ ] Test E: Delete asks for confirmation; deleting the default promotes another address
- [ ] Test F1: Required fields are enforced
- [ ] Test F2: 11th address is refused (or SKIPPED)
- [ ] Test G: Checkout "Pick a saved address" fills the delivery fields; still editable
- [ ] Test H: "Save this address" checkbox adds the address after a successful order
- [ ] Test I: Fresh checkout prefills from the default address
- [ ] Test J: Members see only their own addresses (or SKIPPED)
- [ ] Test K: Edge cases pass
- [ ] Accessibility: Keyboard navigation and label linking work

**End of QA.** Record any FAILs with the exact step number and, if possible, a screenshot.

---

### How to report a FAIL

Write it down like this:

```
FAIL — Test C, step 5
Expected: message "Delivery address updated."
Saw:      message "Delivery address saved." and a second copy appeared in the list
```

Include the browser you used and whether you were on a phone or desktop.
