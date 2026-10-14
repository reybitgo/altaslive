# Address Book — Region / Province / City / Barangay Picker — QA Test (Beginner Guide)

This checklist walks a complete beginner through testing the **new address picker** that was
added to the member delivery address book. Follow it in order. Do not skip steps.

Previously the "Add delivery address" pop-up asked you to type **City** and **Province** by hand.
It now asks you to pick **Region, Province, City and Barangay** from a searchable list that is
loaded from a free public database (PSGC — the official Philippine Standard Geographic Code).
The app fetches each list live from `psgc.cloud`.

---

## Prerequisites

- [ ] **A member account.** You must be able to log in as a normal member (not admin).
- [ ] **Internet access.** The picker loads its lists over the internet from `psgc.cloud`. If you
      are offline, the picker will show an error (there is a test for that further down).
- [ ] **One browser window.** Use a private/incognito window so you start clean.
- [ ] **Optional (only for the checkout tests):** at least one product in the shop, so you can add
      it to a cart and reach the checkout page.
- [ ] Keep a **FAIL log** (any text file). Write the exact step number and what you saw whenever
      something fails.

## Golden Rule

> Everything in this test happens **as the logged-in member**, in one window.

If you are ever told to "log in as admin", that is a mistake in your notes — this feature is
member-only.

## Definitions (read once)

| Term | What it means |
|---|---|
| **Saved address** | An address you stored in your profile for later. |
| **Default address** | One saved address marked as the default. It shows a green **Default** badge and is used to prefill checkout automatically. |
| **Modal / pop-up** | A box that appears over the page (e.g. the "Add delivery address" window). |
| **Picker** | The window that opens over the current form when you tap "Region, Province, City, Barangay". It is a pop-up (modal), like the address window itself. |
| **Chain** | The picked levels shown together, e.g. `Bagong Pag-asa, Quezon City, Metro Manila`. |
| **Flash message** | The green (success) or red (error) banner at the top of a page after an action. |
| **PSGC** | The official Philippine list of Regions, Provinces, Cities/Municipalities and Barangays. |

### What "Region" shows

The picker groups the regions into five buckets: **Metro Manila, North Luzon, South Luzon,
Visayas, Mindanao**. When you pick a bucket, that bucket name is what gets saved as the
**Region** in the address (for example `Metro Manila` or `Visayas`). Inside each bucket you still
choose the real Province and City.

### Metro Manila is special

Metro Manila has **no province**. When you pick the **Metro Manila** bucket, the picker skips the
Province step and goes straight to **City**.

---

## Starting Point

1. Log in as your member.
2. Go to your profile page: `http://localhost/altaslive/?page=profile`
   (or click **Profile** in the left sidebar).
3. Scroll down **below the big blue "Save Changes" button**.
4. **PASS if** you see a card titled **📍 Delivery Addresses** with a **+ Add address** button.
5. **FAIL if** the card is missing, the page is blank/white, or you see raw PHP code.

---

## Test A — Add your first address with the picker (Metro Manila path)

**On the profile page:**

1. Click **+ Add address**.
2. A pop-up appears titled **Add delivery address**.
3. Fill in the text fields:

   | Field | Value |
   |---|---|
   | Label | `Home` |
   | Recipient name * | `Juan Dela Cruz` |
   | Phone | `0917 000 0000` |
   | Postal | `1100` |
   | Street Name, Building, House No. * | `123 Mabini Street` |

   (The **Street Name, Building, House No.** field sits just below the
   **Region, Province, City, Barangay** field, so you can fill it after the next step.)

4. Find the field labelled **Region, Province, City, Barangay**. It shows grey placeholder text
   **"Select region, province, city, barangay…"**. Click it.
5. **PASS if** a picker pop-up opens **on top of** the address pop-up (the address pop-up stays
   behind it, dimmed but not closed), with a search box, a section heading **REGION**, and a list
   containing **Metro Manila**, **North Luzon**, **South Luzon**, **Visayas**, **Mindanao**.
6. Click **Metro Manila**.
7. **PASS if** the screen now shows the heading **CITY** and **does not** ask for a Province
   (Metro Manila has none). The top shows a breadcrumb line with **Metro Manila** and a **Reset**
   button, and the next step **Select City**.
8. Click any city, for example **Quezon City**.
9. **PASS if** the screen now shows the heading **BARANGAY** and a list of barangays.
10. Click any barangay, for example **Bagong Pag-asa**.
11. **PASS if** the picker closes by itself and you are back on the **Add delivery address**
    pop-up. The **Region, Province, City, Barangay** field now shows the chain you picked, for
    example:
    `Bagong Pag-asa, Quezon City, Metro Manila`

12. Click **Save address**.
13. **PASS if:**
    - A green message appears: **"Delivery address saved."**
    - The new address is listed in the **📍 Delivery Addresses** card, showing the label **Home**,
      the recipient name, and the address chain.
14. **Since this is your first address, it should automatically carry the green "Default" badge**
    even if you did not tick "Set as default". This is intentional.

> **Note:** The very first address a member saves always becomes the default. This guarantees
> checkout always has something to prefill.

---

## Test B — Add a second address (Province path)

This time choose a bucket that has a Province step.

**On the profile page:**

1. Click **+ Add address** again.
2. Fill it in:

   | Field | Value |
   |---|---|
   | Label | `Office` |
   | Recipient name * | `Maria Santos` |
   | Phone | `0918 111 2222` |
   | Postal | `1226` |
   | Street Name, Building, House No. * | `500 Ayala Avenue` |

3. Click the **Region, Province, City, Barangay** field to open the picker.
4. Click **Visayas**.
5. **PASS if** the heading becomes **PROVINCE** and a breadcrumb shows **Visayas**.
6. Click **Cebu**.
7. **PASS if** the heading becomes **CITY**.
8. Click **Cebu City**.
9. **PASS if** the heading becomes **BARANGAY**.
10. Click any barangay, for example **Apas**.
11. **PASS if** the chain now reads something like:
    `Apas, Cebu City, Cebu, Visayas`
    (notice the order: Barangay, City, Province, Region).
12. Click **Save address**.
13. **PASS if:**
    - Green message **"Delivery address saved."**
    - Both addresses now appear in the list.
    - **Only ONE** address has the green **Default** badge (the first one, Home). The second must
      **not** be default.
14. **FAIL if** two addresses both show the Default badge.

---

## Test C — Edit an address (the chain is remembered)

**On the profile page:**

1. On the **Office** address, click **Edit**.
2. Cut off: the pop-up opens titled **Edit delivery address**.
3. **PASS if** every field is already filled in — including the **Region, Province, City,
   Barangay** field, which must show `Apas, Cebu City, Cebu, Visayas` (the values you saved). You
   should **not** have to retype or re-pick anything.
4. Change the **Label** to `Office — Cebu` and change **Postal** to `6000`.
5. Click **Save address**.
6. **PASS if:**
   - Green message **"Delivery address updated."**
   - The list shows the label **Office — Cebu** and postal `6000`, with the same location chain.
7. **FAIL if** a new duplicate row was created instead of updating the existing one, or if the
   location chain disappeared.

> Editing an address must never create a second copy, and must never lose the saved location.

---

## Test D — Change the location on an existing address

1. On the **Office — Cebu** address, click **Edit**.
2. Click the **Region, Province, City, Barangay** field to open the picker.
3. **PASS if** the picker opens at the **Region** step (a fresh start, not the old selection). The
   old chain is still visible on the field behind the picker until you finish a new selection.
4. Pick a **different** chain — for example **Metro Manila → Pasig → any barangay**.
5. Click **Save address**.
6. **PASS if** the list for that address now shows the **new** chain
   (`..., Pasig, Metro Manila`) and the old `Cebu` chain is gone.

---

## Test E — The search box

1. Click **+ Add address**, then click the **Region, Province, City, Barangay** field.
2. At the **REGION** step, type `mi` into the search box.
3. **PASS if** the list shrinks to entries whose name contains `mi` (for example **Mindanao**).
4. Clear the search box and choose **Mindanao**.
5. At the **PROVINCE** step, type a few letters of a province you know exists (for example `davao`).
6. **PASS if** the list filters down as you type.
7. Type something impossible, for example `zzzzzz`.
8. **PASS if** the list shows **No matches**.
9. Clear the box and click **Cancel** in the picker, then close the pop-up. (Nothing should be
   saved from this test.)

---

## Test F — Breadcrumb, Back and Reset

1. Click **+ Add address**, then open the picker.
2. Pick **North Luzon → any province → any city** (you should now be looking at the BARANGAY step).
3. Look at the breadcrumb near the top. **PASS if** it lists your chosen items and ends with a
   muted line **Select Barangay**.
4. Click one of the already-chosen items in the breadcrumb, for example the **province**.
5. **PASS if** the province (and everything after it — city, barangay) is cleared and the screen
   returns to the **PROVINCE** step, with the region still chosen.
6. Click the **‹** (back arrow) at the top-left.
7. **PASS if** the previous choice is undone step by step (this back arrow works like the browser
   Back button for the picker only).
8. Click **Reset**.
9. **PASS if** all choices are cleared and you are back at the **REGION** step with no breadcrumb.
10. Click the **×** at the top-right to close the picker. Then click **Cancel** on the pop-up.

---

## Test G — Changing a higher level clears the lower ones

1. Click **+ Add address**, open the picker.
2. Pick **South Luzon → any province → any city → any barangay** so the picker would normally
   close and fill the chain.
3. Re-open the picker by clicking the location field again.
4. Pick **Mindanao**, then a province, then a city, then a barangay.
5. Click **Save address**.
6. **PASS if** the saved chain uses only the **Mindanao** branch (no leftover province/city from
   South Luzon).

---

## Test H — Required fields (browser blocks a blank save)

1. Click **+ Add address**.
2. Fill in only the **Label** (for example `Empty test`) and leave **Recipient name** and
   **Street Name, Building, House No.** blank.
3. Click **Save address**.
4. **PASS if** the browser refuses to submit and points at the first empty required field with a
   tooltip like **"Please fill out this field"**.
5. **FAIL if** the address is saved with a blank name or blank street.

> The fields marked with **\*** (Recipient name, Street Name/Building/House No.) are required. The
> picker's location is optional — an address can be saved without a picked location.

---

## Test I — Change which address is the default

1. Look at the **Office — Cebu** (or whichever is *not* default) row. It should show a
   **Set default** button.
2. Click **Set default** on it.
3. **PASS if:**
   - Green message **"Default delivery address updated."**
   - The **Default** badge moves to that address.
   - The other address **no longer** has the Default badge.
   - The row you promoted no longer shows a **Set default** button.

---

## Test J — Delete an address (and automatic default promotion)

1. On the **default** address, click **Delete**.
2. A confirmation pop-up appears titled **"Delete this address?"** with a red **Delete** button and
   a **Cancel** button.
3. Click **Cancel** first.
4. **PASS if** nothing was deleted (the address is still listed).
5. Click **Delete** again, then in the pop-up click the red **Delete** button.
6. **PASS if:**
   - Green message **"Delivery address removed."**
   - The address is gone from the list.
   - The remaining address **automatically becomes the new default** (green Default badge), even
     though you never clicked Set default.

> **Why this matters:** a member with at least one saved address must always keep a default, so
> checkout always has something to prefill.

---

## Test K — Checkout: the default address prefills the whole chain

> This test needs at least one product in the shop and one saved address.

1. Make sure you know which address is your **default** (green Default badge on the profile page),
   and note its **location chain**.
2. Add a product to your cart (`?page=shop` → add to cart).
3. Open your cart and click **Proceed to checkout** (or go to `?page=checkout`).
4. **Do this on a fresh visit** to checkout (not after a failed order attempt).
5. **PASS if** the **Region, Province, City, Barangay** field is **already filled in** with your
   default address's chain — without you touching the picker.
6. **FAIL if** the field is empty or shows the wrong location.

---

## Test L — Checkout: pick a saved address to fill the form

1. On the checkout page, near the top of the **📍 Delivery details** card, find the dropdown
   **Pick a saved address**.
2. **PASS if** the dropdown lists your saved addresses, each showing the label, recipient and
   address, with the default one marked **"(default)"**.
3. Choose a saved address.
4. **PASS if** these fields fill in automatically:
   - Recipient name
   - Recipient phone
   - Region, Province, City, Barangay (shows the saved chain)
   - Street Name, Building, House No.
   - Postal
5. Now manually open the picker and change the city or province.
6. **PASS if** you are allowed to edit it freely — the saved address is only a shortcut, not a
   lock.

---

## Test M — Checkout: save a new address while ordering

1. On the checkout page, near the bottom of the **📍 Delivery details** card, find the checkbox:
   **"Save this address to my address book for next time."**
2. If you already have saved addresses, this box starts **unticked**. If you have none, it starts
   **ticked**. Confirm the starting state matches your situation.
3. Tick the box (if it isn't already).
4. Fill in the delivery fields with a **brand-new** address you have not saved before, for example:
   - Recipient name: `Test Save`
   - Location (picker): **Visayas → Bohol → Tagbilaran → any barangay**
   - Street Name, Building, House No.: `999 Test Boulevard`
5. Complete the order normally:
   - Choose a payment method you can use.
   - Tick the **Terms** checkbox.
   - Click **Place order**.
6. **PASS if** the order is placed successfully (green message, order page shown).
7. Go back to `http://localhost/altaslive/?page=profile` and scroll to **📍 Delivery Addresses**.
8. **PASS if** the new address (`Test Save` / `999 Test Boulevard` / `..., Tagbilaran, Bohol,
   Visayas`) now appears in your saved list.

---

## Test N — Failed order restores the picked location (advanced)

This checks that if the order fails on the server, the location you picked is not thrown away.
A beginner-friendly way to force a server error is to enter only **spaces** in the **Billing
name** field — the browser thinks it is filled in, but the server trims it to empty and rejects
the order.

1. Start a fresh checkout (add a product, go to checkout).
2. Fill in the delivery fields and **pick a location chain** with the picker.
3. In the **Billing name** field, type only spaces: `   ` (three spaces, nothing else).
4. Tick **Terms** and click **Place order**.
5. **PASS if:**
   - A red error message appears about the **billing name**.
   - You are returned to the checkout page.
   - The **Region, Province, City, Barangay** field still shows the chain you picked, and the
     other fields (recipient, street, postal) are still filled in.
6. Now correct the **Billing name** (a real name), tick **Terms** again, and click **Place order**.
7. **PASS if** the order is placed successfully.

> If you cannot trigger this safely, write **SKIPPED — not tested** in your log. Do not mark it
> PASS.

---

## Test O — When the internet / the PSGC service is unavailable (advanced)

The picker needs the internet. This test makes sure a broken connection does not break the rest of
the page.

1. Open the profile page and click **+ Add address**.
2. Open your browser's **Developer Tools** → **Network** tab and set it to **Offline**
   (or block just `psgc.cloud`).
3. Click the **Region, Province, City, Barangay** field to open the picker.
4. **PASS if** the picker shows a message like **"Couldn't load this list. Try again"** with a
   **Try again** link — instead of a blank screen or a broken page.
5. Turn **Offline** back off, then click **Try again**.
6. **PASS if** the region list loads.
7. Close the picker. **PASS if** you can still fill in the other fields and click **Save address**
   to save an address **without** a location (the picker is a convenience, not a blocker).
8. Clean up the test address you just made (click its **Delete**).

> If you cannot safely change network settings, write **SKIPPED — not tested**. Do not mark it
> PASS.

---

## Test P — Maximum of 10 addresses (optional / advanced)

This is slow to test by hand. Only do it if you need full coverage; otherwise mark it
**SKIPPED**.

1. Add addresses one at a time until you already have **10** saved.
2. Try to add an **11th**.
3. **PASS if** a red message appears: **"You can save up to 10 addresses."** and no 11th address is
   added.

---

## Test Q — Security spot check (optional / advanced)

Addresses belong to a single member. Another member must never be able to see or change them.

- Log out, log in as a **different** member, and confirm their **📍 Delivery Addresses** card shows
  **only their own** addresses (never the first member's).
- **PASS if** each member sees only their own addresses.
- If you cannot test this, write **SKIPPED — not tested**. Do not mark it PASS.

---

## Edge cases

| # | Action | Expected Result |
|---|---|---|
| E1 | Open `?page=profile` with **no** saved addresses | Card shows **"No saved addresses yet."** No errors. |
| E2 | Save an address with an **empty Label** | It is still saved; the list shows a fallback title **"Address"**. Not a FAIL. |
| E3 | Save an address **without** picking a location | It is still saved (the location is optional). No errors. |
| E4 | Enter a very long Label / Street Name, Building, House No. | Values are trimmed to the allowed length; saving still succeeds. No error page. |
| E5 | Click **Delete**, then **Cancel** in the pop-up | Nothing is deleted. |
| E6 | Delete **all** addresses | Card returns to **"No saved addresses yet."** No errors, and checkout shows no saved-address dropdown. |
| E7 | On checkout with **no** saved addresses | The **Pick a saved address** dropdown does **not** appear; the **Save this address** checkbox starts **ticked**. |
| E8 | Refresh right after opening the picker | The picker closes on reload or re-opens cleanly at the **Region** step; no error page. |

---

## Accessibility spot check (recommended)

1. Open the profile **+ Add address** pop-up.
2. Press **Tab** repeatedly. **PASS if** focus reaches the **Region, Province, City, Barangay**
   field and the focus outline is always visible.
3. With that field focused, press **Enter** (or **Space**). **PASS if** the picker opens.
4. In the picker, press **Tab**. **PASS if** focus moves through the search box and the list items.
5. Press **Esc**. **PASS if** the picker closes and focus returns to the location field on the
   pop-up.
6. In the picker, use **arrow keys / Tab** to reach the **Try again** link when an error is shown
   (Test O), confirming it is keyboard-reachable.

---

## Final checklist

Tick only what you actually verified. Ticking something you did not test is worse than leaving it
blank.

- [ ] Starting Point: 📍 Delivery Addresses card is present
- [ ] Test A: First address adds a Metro Manila chain and becomes default
- [ ] Test B: Second address adds a province chain; only one default at a time
- [ ] Test C: Edit opens pre-filled with the saved chain and updates the same row
- [ ] Test D: Changing the location on an existing address works
- [ ] Test E: Search filters, and "No matches" appears for nonsense
- [ ] Test F: Breadcrumb, Back arrow and Reset behave correctly
- [ ] Test G: Changing a higher level clears the lower ones
- [ ] Test H: Required fields (Recipient, Street Name/Building/House No.) are enforced
- [ ] Test I: Set default moves the badge
- [ ] Test J: Delete confirms, and deleting the default promotes another address
- [ ] Test K: Fresh checkout prefills the default chain
- [ ] Test L: "Pick a saved address" fills the whole form including the chain
- [ ] Test M: "Save this address" adds the address after a successful order
- [ ] Test N: Failed order restores the picked location (or SKIPPED)
- [ ] Test O: Offline picker shows a retry and does not break saving (or SKIPPED)
- [ ] Test P: 11th address is refused (or SKIPPED)
- [ ] Test Q: Members see only their own addresses (or SKIPPED)
- [ ] Edge cases pass
- [ ] Accessibility: keyboard navigation works

**End of QA.** Record any FAILs with the exact step number and, if possible, a screenshot.

---

### How to report a FAIL

Write it down like this:

```
FAIL — Test C, step 3
Expected: Edit pop-up shows the saved chain "Apas, Cebu City, Cebu, Visayas"
Saw:      The location field was empty
```

Include the browser you used and whether you were on a phone or desktop.
