# QA Test Guide — Package Management, Commission Toggles & Package Upgrade

> **Audience:** Complete beginner QA tester. No programming knowledge needed.
> **App under test:** AltasLive MLM Binary System
> **Area under test:** Admin Packages page, Registration Codes (new code types), Member Registration, Genealogy, Earnings/Cap Status, Package Upgrade flow, and the commission engines that respect the new **per-package** commission toggles.

---

## 0. How to use this document

1. Read **Section 1–3** once before testing (environment, background, and how to set up test data).
2. Run each test case in **Section 4** in order (or by area).
3. For every test case, write the result in the **Summary Sheet at the end** (Pass / Fail / Blocked).
4. If something fails, fill in the **Bug Report Template** in Section 5 and give it to the developer.

**Total test cases: ~35. Estimated time: 2–3 hours.**

---

## 1. Environment & prerequisites

### 1.1 Access

| Item           | Value                                                                     |
| -------------- | ------------------------------------------------------------------------- |
| App URL        | `http://localhost/altaslive`                                              |
| Admin username | `admin`                                                                   |
| Admin password | `Admin@1234`                                                              |
| Browser        | Use Google Chrome first, then retest the key flows in Firefox if possible |

### 1.2 Logging in as Admin

1. Open `http://localhost/altaslive` in your browser.
2. You will see the public landing page (frontend). Click **Login** (top right).
3. On the login page enter username `admin` and password `Admin@1234`, click **Sign In**.
4. You should land on the **Admin Dashboard** (dark sidebar on the left).

### 1.3 ⚠️ VERY IMPORTANT — CAUTION

- This system is connected to a **live database with real member data**.
- **Do NOT delete** any real members, packages, or codes that you did not create yourself.
- **Do NOT modify** the existing **Starter** package settings unless a test says so. If you do, write down the original values and restore them.
- Only create **new** test packages and **new** test codes. Give them names you will recognise, e.g. `QA Test Pro`, `QA Test Retail`.
- If you are ever unsure, stop and ask the developer.

### 1.4 Where things are (Admin sidebar)

| Sidebar item       | Opens page with URL    |
| ------------------ | ---------------------- |
| 📊 Dashboard       | `?page=admin`          |
| 👥 Members         | `?page=admin_users`    |
| 📦 Packages        | `?page=admin_packages` |
| 🎟️ Reg Codes       | `?page=admin_codes`    |
| ➕ Register Member | `?page=register`       |
| 📅 DFI Admin       | `?page=admin_dfi`      |
| ⚙️ Settings        | `?page=admin_settings` |

### 1.5 Where things are (Member sidebar)

Shown when the logged-in user views member pages (click **Member View** in the admin sidebar, or log in as a normal member):

| Sidebar item        | Opens page                               |
| ------------------- | ---------------------------------------- |
| 🏠 Dashboard        | `?page=dashboard`                        |
| 💰 Earnings         | `?page=earnings`                         |
| 🛡️ Lifetime Cap     | `?page=cap_status`                       |
| 📅 DFI History      | `?page=dfi_history`                      |
| 🌳 Binary Tree      | `?page=genealogy&view=binary`            |
| 👥 Referral Network | `?page=genealogy&view=referral`          |
| ⬆️ Upgrade Package  | `?page=upgrade`                          |
| ➕ Register Member  | `?page=register&sponsor=<your_username>` |

---

## 2. Background — what changed and what we are testing

The goal of this project was to move three commission "switches" from **global settings** to **per-package** settings. Each package now decides for itself whether its members participate in:

| Toggle (on a package)                                  | Description                                                                                                                                                                                                                                                                                    |
| ------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 🌳 **Binary Network** (`pairing_enabled`)              | If ON, the member joins the binary tree (left/right legs) and can earn pairing bonuses. If OFF, the member is a "non-binary" member: **no binary placement, no binary tree, no leg counts, no pairing bonuses** — but they still get direct referral and (if enabled) indirect referral & DFI. |
| 🔗 **Indirect Referral** (`indirect_referral_enabled`) | If ON, members on this package earn the 10-level unilevel/indirect referral bonuses. If OFF, they never receive indirect commissions and the Indirect tabs/rows are hidden on their pages.                                                                                                     |
| 📅 **Daily Fixed Income** (`dfi_enabled`)              | If ON, members on this package receive the nightly DFI payout. If OFF, they never receive DFI and their DFI status shows "Disabled".                                                                                                                                                           |

The **old global toggles were removed** from the Settings page. There is no longer a master "DFI enable" switch or a master "indirect referral" switch.

**Second change — Registration Codes** (`reg_codes.code_type`):
Codes now have a type, chosen when generated:
| Code Type | Prefix | What it does |
|-----------|--------|--------------|
| 🎟️ Registration | (none) | Normal registration code (this is most existing codes). |
| ⏳ CD | `CD-` | Registration code that **auto-assigns Commission-Deduct** to the new member. Uplines still grow legs, but they **do not earn commissions from this member** (it is "source-filtered"). |
| ⬆️ Upgrade | `UP-` | Only usable to **pay the package upgrade diff**. **Rejected at registration.** It is linked to one target package. |

**Third change — Member Package Upgrade** (new feature):
A member can pay to move to a higher fee package. Only the **price difference** is charged. Payment is either from their **E-Wallet** or by an **Upgrade Code**. A special case: when a member moves from a **non-binary** package (pairing OFF) to a **binary** package (pairing ON), they must first pick a **binary upline + position** to be placed in the tree.

---

## 3. Test data setup (do this FIRST)

You need a controlled dataset. Follow these steps exactly. Names in `code` are your choice — use unique names (add today's date or your initials so you can find them again).

### 3.1 Create 3 test packages

Go to **📦 Packages** (`?page=admin_packages`). Click the **+ New Package** button (top right). This opens a **modal** — a pop-up form that you can scroll inside. The modal has a **Cancel** button (top-right ✕ or footer **Cancel**) and a footer **➕ Create Package** button that saves.

**Field order inside the modal:** the green **Commission Toggles** box comes **first** (above Package Name). Turn features on/off there first — when a feature's toggle is OFF, its setting fields are **hidden and reset to defaults automatically** (Binary OFF → Pairing Bonus & Daily Pair Cap reset to `0`; Indirect OFF → all 10 levels reset to `0.00`; DFI OFF → amount resets to `0`).

Create the following packages (leave all other fields at default unless stated):

| #   | Package Name    | Entry Fee  | Pairing Bonus | 🌳 Binary Network   | 🔗 Indirect Referral | 📅 Daily Fixed Income | DFI amount/day | DFI days |
| --- | --------------- | ---------- | ------------- | ------------------- | -------------------- | --------------------- | -------------- | -------- |
| P1  | `QA Pro`        | `25000.00` | `3000.00`     | ON (checked)        | ON (checked)         | ON (checked)          | `200.00`       | `90`     |
| P2  | `QA Retail`     | `15000.00` | — (auto `0`)  | **OFF (unchecked)** | ON (checked)         | ON (checked)          | `100.00`       | `90`     |
| P3  | `QA NoIndirect` | `20000.00` | `2500.00`     | ON (checked)        | **OFF (unchecked)**  | ON (checked)          | `150.00`       | `90`     |

Fill in the **Indirect Referral Bonuses (10 Levels)** if you want (e.g. Level 1 = 500, Level 2 = 300, levels 3–10 = 0). Any value is fine.

> **Note:** `P2` (binary OFF) ignores the Pairing Bonus column — those fields are hidden and saved as `0`. `P3` (indirect OFF) ignores the 10-level fields — they are hidden and saved as `0.00`.

Click the **➕ Create Package** button in the **modal footer**. The modal closes and a green success message appears. Each new package should appear in the **📦 All Packages** table (the full-width table below the 4 stat cards).

> **Minimum requirement for most tests:** you only strictly need `QA Pro` (binary, higher fee). The other two are used by the "non-binary" and "indirect OFF" tests. If you are short on time, create at least `QA Pro` and `QA Retail`.

### 3.2 Create test codes

Go to **🎟️ Reg Codes** (`?page=admin_codes`). In the **⚡ Generate Codes** form:

| Batch | Package   | Quantity | Code Price | Code Type            |
| ----- | --------- | -------- | ---------- | -------------------- |
| C1    | QA Pro    | `3`      | `25500.00` | 🎟️ Registration Code |
| C2    | QA Pro    | `2`      | `25500.00` | ⏳ CD Code           |
| C3    | QA Pro    | `2`      | `25500.00` | ⬆️ Upgrade Code      |
| C4    | QA Retail | `2`      | `15500.00` | 🎟️ Registration Code |

Click **Generate Codes**, confirm in the pop-up. You will see a green success message.

> The **auto price** fills entry fee + 500 when you pick a package. That is fine — override it if you want.
> Keep a note of the generated code strings (use the **📋** copy button next to each code). You will need some of them later.

### 3.3 Create 2–3 test members

A quality test needs some real member accounts. You can register them from the admin **➕ Register Member** page. Use a mix of codes:

- **M1** — use a **C1 (registration)** code for **QA Pro**, binary upline = your choice (if none exists, place under `admin`).
- **M2** — use another **C1 (registration)** code for **QA Pro**, place it under M1 (left or right).
- **M3** — use a **C4 (registration)** code for **QA Retail** (note: QA Retail is **non-binary**, so the binary section should be hidden).

Make sure you remember each member's username and password (e.g. password `QaTest123` for all).

> After registering, each member is immediately **active** (no activation needed when paying by code).

### 3.4 Give the test members some balance (optional, needed for the e-wallet upgrade test)

1. Go to **👥 Members** (`?page=admin_users`).
2. Find a test member and open their page (the **View/🔍** button or same-row link).
3. In their profile, look for the E-wallet section / **Top-Up** action. Top them up by at least `₱10,000` (e.g. use **💰 Top-Up Member** in the admin sidebar → select the member → amount `10000`).

---

## 4. Test cases

### How to read a test case

- **ID** — unique reference (e.g. `TC-01`).
- **Priority** — High / Medium / Low.
- **Steps** — exactly what to do, left to right.
- **Expected** — what must happen. All of it must appear.
- **Evidence** — what to screenshot/save.

Mark **PASS** only if _every_ bullet under "Expected" is true.

---

### AREA A — Admin Package Management (per-package toggles)

#### TC-01 (High) — Packages page has stats cards, full-width table & "Toggles" badges

**Precondition:** Admin is logged in.
**Steps:**

1. Go to **📦 Packages**.
2. Look at the top of the page and the table **📦 All Packages**.
   **Expected:**

- A row of **4 stat cards** is shown above the table: `Total Plans`, `Active` (green), `Inactive`, `🌳 Binary Plans` (e.g. "3 of 5").
- The table is **full-width** and has the columns: `Package` (name + ID), `Entry`, `Pair`, `Cap`, `DFI`, `Toggles`, `Status`, `Actions`.
- A **+ New Package** button sits in the top-right corner.
- Every package row shows a `Toggles` cell with 3 emoji badges: 🌳 (binary), 🔗 (indirect), 📅 (DFI).
- Badges are **green** when that toggle is ON for the package and **grey** when OFF.
- Hovering a badge shows a tooltip: e.g. `Binary Network: ON`, `Indirect Referral: OFF`, `Daily Fixed Income: ON`.
- Money values are right-aligned; each row has an **Edit** button (blue outline) in the `Actions` column.

**Evidence:** Screenshot of the 4 stat cards + the table showing both green and grey badges.

#### TC-02 (High) — Create a package via the modal (toggle-first) and save its toggles

**Precondition:** Admin is logged in, on **📦 Packages**.
**Steps:**

1. Click **+ New Package** (top right).
2. The **modal** opens with the title **"➕ New Package"**, the correct footer button **"➕ Create Package"**, and all fields **empty**.
3. The green **Commission Toggles** box is the **first** thing inside the modal — **above** the Package Name field.
4. Leave 🌳 **Binary Network** checked, then **uncheck** 🔗 **Indirect Referral** and 📅 **Daily Fixed Income**.
5. Immediately verify the **Indirect Referral Bonuses (10 Levels)** block and the pink **Daily Fixed Income** box **hide** (they should NOT be visible anymore).
6. Fill the remaining visible fields: Package Name `QA ToggleTest`, Entry Fee `18000.00`, Pairing Bonus `2500`, Daily Pair Cap `3` (the binary fields are still visible — 🌳 was left ON).
7. Click **➕ Create Package** in the modal footer.
   **Expected:**

- Turning a toggle OFF hides its settings instantly; on save those hidden fields are stored as their defaults (0 / 0.00).
- Green success flash message appears.
- The modal closes automatically.
- `QA ToggleTest` appears in the table.
- Its `Toggles` cell shows 🌳 **green**, 🔗 **grey**, 📅 **grey** (matches what you set).
  **Evidence:** Screenshots of the open modal (toggles on top, hidden DFI/indirect sections) and the new row with the grey 🔗 and 📅 badges.

#### TC-03 (High) — Edit modal auto-opens prefilled; toggles are saved correctly

**Precondition:** `QA ToggleTest` exists (from TC-02).
**Steps:**

1. In the package table, click **Edit** on `QA ToggleTest`.
2. The page reloads and the **modal auto-opens** with the title **"✏️ Edit Package"**, footer button **"💾 Update Package"**, and **all fields already filled** with `QA ToggleTest`'s current values (name, fee, toggles as you saved them).
3. The **Commission Toggles** box is at the top. Check 🔗 **Indirect Referral** and 📅 **Daily Fixed Income** back ON — the Indirect levels block and the pink DFI box **reappear**. Then **uncheck** 🌳 **Binary Network** — the Pairing Bonus + Daily Pair Cap fields **hide** and reset to `0`.
4. Click **💾 Update Package**.
5. Open **Edit** again for the same package.
   **Expected:**

- After saving, the modal closes and a success flash appears.
- On re-opening **Edit**, the modal auto-opens prefilled: 🌳 is **unchecked**, 🔗 and 📅 are **checked** (state persisted).
- With 🌳 OFF, the **Pairing Bonus / Daily Pair Cap** fields are **hidden** in the reopened modal (saved as `0`).
- In the list, the row shows 🌳 **grey**, 🔗 **green**, 📅 **green**.
  **Evidence:** Screenshots of the auto-opened Edit modal (prefilled, hidden binary group) and of the row after saving.

#### TC-04 (High) — Modal can be cancelled without losing the list, and Auto-Cap preview updates live

**Precondition:** On **📦 Packages**.
**Steps:**

1. Click **+ New Package** (opening the modal).
2. Type something in the **Package Name** field, then click **Cancel** (footer) or the top-right **✕**.
3. Confirm the modal closes and the packages list is unchanged (no package created).
4. Click **+ New Package** again — the modal should open **fresh**: all 3 toggles **checked ON** and all setting groups (binary, DFI, indirect) **visible**.
5. Close it, then click **Edit** on any package (modal auto-opens prefilled).
6. In the **Lifetime Income Capping** box (inside the modal), set Entry Fee = `10000`.
7. Set **Cap Multiplier** = `3`.
8. Watch the **Auto-Cap Preview** read-only field.
   **Expected:**

- Cancel closes the modal **without saving** — no new package appears.
- Re-opening via **+ New Package** restores the defaults (all toggles ON, all sections shown).
- The modal later re-opens normally via **Edit**.
- The **Auto-Cap Preview** instantly shows `₱30,000.00`.
- Changing entry fee to `20000` changes the preview to `₱60,000.00` (20000 × 3).
  **Evidence:** Screenshots showing the cancelled modal, the fresh re-opened modal, and the preview at 10000×3 and 20000×3.

#### TC-05 (Medium) — Indirect referral levels (10 levels) are saved

**Precondition:** On **📦 Packages**, a package exists that is set to be edited.
**Steps:**

1. Click **Edit** on that package (modal auto-opens prefilled).
2. In **🔗 Indirect Referral Bonuses (10 Levels)**, set Level 1 = `500`, Level 2 = `300`.
3. Click **💾 Update Package** in the modal footer.
4. Click **Edit** again for the same package (modal re-opens prefilled).
   **Expected:**

- Level 1 shows `500.00` and Level 2 shows `300.00` after re-opening.
  **Evidence:** Screenshot of the saved levels inside the Edit modal.

#### TC-06 (Medium) — Inactive package hidden from members

**Precondition:** A test package exists.
**Steps:**

1. Click **Edit** on a test package (modal auto-opens), set **Status** = `Inactive`, click **💾 Update Package**.
2. Log in as a normal member, open **⬆️ Upgrade Package** and also the **Register Member** page.
3. Open the member **Register Member** page and look at the package choices.
4. Go back to admin, click **Edit** on that package again, set **Status** = `Active`, **💾 Update Package**.
   **Expected:**

- While Inactive: the package does **NOT** appear in the member's upgrade list, and does **NOT** appear as a selectable package/option on the Register pages.
- After reactivating: it appears again.
  **Evidence:** Screenshots showing the package missing while Inactive, before and after.

---

### AREA B — Admin Registration Codes (code types)

#### TC-07 (High) — Generate normal Registration codes

**Precondition:** On **🎟️ Reg Codes**.
**Steps:**

1. In **⚡ Generate Codes**: Package = `QA Pro`, Quantity = `3`, Price = `25500`, Expiry = blank (optional), **Code Type** = `🎟️ Registration Code`.
2. Click **Generate Codes**, click **✓ Generate** in the pop-up.
   **Expected:**

- Green success message.
- Three new rows appear in the **Code List**.
- Their **Type** badge shows **"Regular"**.
- Codes **do not** start with `CD-` or `UP-` (they look like `XXXX-XXXX-XXXX`).
- Status = **Unused**.
  **Evidence:** Screenshot showing the new rows with "Regular" badge.

#### TC-08 (High) — Generate CD codes (CD- prefix)

**Precondition:** On **🎟️ Reg Codes**.
**Steps:**

1. **Code Type** = `⏳ CD Code`, package = `QA Pro`, quantity = `2`.
2. Generate and confirm.
   **Expected:**

- New rows appear whose **Type** badge is **"⏳ CD"**.
- Every CD code **starts with `CD-`** (e.g. `CD-XXXX-XXXX-XXXX`).
- Status = **Unused**.
  **Evidence:** Screenshot of the two CD badges and the `CD-` prefixes.

#### TC-09 (High) — Generate Upgrade codes (UP- prefix)

**Precondition:** On **🎟️ Reg Codes**.
**Steps:**

1. **Code Type** = `⬆️ Upgrade Code`, package = `QA Pro`, quantity = `2`.
2. Generate and confirm.
   **Expected:**

- New rows appear whose **Type** badge is **"⬆️ Upgrade"**.
- Every upgrade code **starts with `UP-`** (e.g. `UP-XXXX-XXXX-XXXX`).
- Status = **Unused**.
  **Evidence:** Screenshot of the two Upgrade badges and the `UP-` prefixes.

#### TC-10 (Medium) — Code price auto-fill on package select

**Precondition:** On **🎟️ Reg Codes**.
**Steps:**

1. Click into the **Package** dropdown and choose **QA Pro** (entry 25000).
   **Expected:**

- The **Code Price** field auto-fills with `25500.00` (entry fee + 500).
  **Evidence:** Screenshot.

#### TC-11 (Medium) — Filter codes by status and by package

**Precondition:** At least one Used code exists (register one member with a code, or use a code from C1).
**Steps:**

1. In **Filter & Export**, set **Status** = `Used` (auto-submits).
2. Clear filter, then set **Package** = `QA Pro`.
3. Click **✕ Clear filter** afterwards.
   **Expected:**

- Filtering by `Used` shows only codes with a **Used** badge.
- Filtering by `QA Pro` shows only codes for that package.
- `✕ Clear filter` restores the full list.
  **Evidence:** Screenshots of each filtered list.

#### TC-12 (Medium) — Export codes to CSV

**Precondition:** On **🎟️ Reg Codes**, with the new codes present.
**Steps:**

1. Click **📥 Export to CSV / Excel**.
   **Expected:**

- A file downloads named like `reg_codes_<today>.csv`.
- Opening it in Excel shows columns `Code, Package, Price, Status, Type, Created, Expires, Used By`.
- The **Type** column shows `registration`, `cd`, or `upgrade` matching the badges in the app.
  **Evidence:** The downloaded file.

#### TC-13 (Low) — Print view

**Precondition:** On **🎟️ Reg Codes**.
**Steps:**

1. Click **🖨️ Print / PDF**.
   **Expected:**

- A print-friendly view opens showing only **Unused** code cards with the code, package, price and expiry.
  **Evidence:** Screenshot of the print preview.

---

### AREA C — Admin Settings (global toggles removed)

#### TC-14 (High) — Global DFI / Indirect toggles are gone

**Precondition:** Admin logged in.
**Steps:**

1. Go to **⚙️ Settings** (`?page=admin_settings`).
2. Scan the whole page for any checkbox/switch named **"Daily Fixed Income"**, **"DFI"**, **"Indirect Referral"**, or **"Unilevel"**.
   **Expected:**

- **No** DFI or Indirect-Referral toggle exists anywhere on the Settings page.
- Other settings are still present (site name, payout addresses, fees, etc.).
  **Evidence:** Full-page screenshot of Settings.

#### TC-15 (Medium) — Settings still save correctly

**Precondition:** On **⚙️ Settings**.
**Steps:**

1. Change the **Minimum Payout** to a test value (e.g. `600`).
2. Save, reload the page.
3. Restore the original value.
   **Expected:**

- The saved value appears after reload (the settings save flow still works).
  **Evidence:** Screenshot showing the changed value.

#### TC-16 (Low) — DFI Admin page has no global switch

**Precondition:** Admin logged in.
**Steps:**

1. Open **📅 DFI Admin** (`?page=admin_dfi`).
   **Expected:**

- The page shows DFI statistics (DFI Paid Today, Total Paid, Members with DFI).
- There is **no** "DFI is Enabled/Disabled" global switch. Instead there should be a card that explains DFI is controlled per package (with a link to Packages).
  **Evidence:** Screenshot.

---

### AREA D — Admin User View (DFI badge & indirect labels)

#### TC-17 (High) — DFI status card shows correct Enabled/Disabled badge

**Precondition:** Two members exist: one on a package with DFI ON (`QA Pro`), and one on a package with DFI **OFF** (`QA ToggleTest` from TC-02/03, if you created it).
**Steps:**

1. Go to **👥 Members**, open a member whose package has DFI ON.
2. Go to the **🛡️ Cap & DFI** tab.
3. Look at the **📅 DFI Status** card.
4. Repeat with a member whose package has DFI OFF.
   **Expected:**

- For the DFI-ON member: badge in the card header shows **"DFI Enabled"** (green) and the card shows Daily Rate, Days Used, Total Earned, Status.
- For the DFI-OFF member: badge shows **"DFI Disabled"** (grey).
  **Evidence:** Screenshots of both cards.

#### TC-18 (Medium) — Commissions label reflects indirect being disabled

**Precondition:** A member on a package with 🔗 Indirect **OFF** (e.g. `QA NoIndirect` or `QA ToggleTest`) exists.
**Steps:**

1. Open that member in **👥 Members → view**.
2. Go to the **💰 Commissions** tab and look at the type labels of any indirect commission rows.
   **Expected:**

- The label for an indirect commission is **"🔗 Indirect (disabled)"** instead of "🔗 Indirect Lvl N".
- If they have no cap-blocked commissions either, the **🛡️ Cap & DFI** tab's blocked list would show the same "(disabled)" wording if present.
  **Evidence:** Screenshot with a "(disabled)" label (if data exists).

---

### AREA E — Member Registration (binary section & CD/upgrade codes)

> For these tests, use the **Register Member** page in the member sidebar, or the admin **➕ Register Member** page. The behaviour is the same.

#### TC-19 (High) — Binary section shows for a binary (pairing) package

**Precondition:** A member is logged in (or use admin's Register Member).
**Steps:**

1. Open **Register Member** (`?page=register`).
2. Choose the **QA Pro** package (🌳 binary).
3. Confirm a section **"Binary Upline Username"** with **Binary Position** (↙ Left / ↘ Right) is visible.
   **Expected:**

- The binary upline + position fields render for a pairing-enabled package.
  **Evidence:** Screenshot.

#### TC-20 (High) — Binary section is HIDDEN for a non-binary package

**Precondition:** `QA Retail` (pairing OFF) exists.
**Steps:**

1. Open **Register Member**.
2. Choose the **QA Retail** package (👥 non-binary).
   **Expected:**

- The **"Binary Upline Username"** and **"Binary Position"** fields are **hidden**/not shown.
- Registering does **not** require an upline or position for this package.
  **Evidence:** Screenshots of the form with QA Retail (no binary fields) vs QA Pro (binary fields).

#### TC-21 (High) — Upline must be on a binary package

**Precondition:** `M3` is on the **non-binary** `QA Retail` package. You are registering a binary (QA Pro) member under **M3**.
**Steps:**

1. Register a member with package **QA Pro** (binary).
2. In **Binary Upline Username**, type `M3` (the QA Retail member).
3. Try to continue.
   **Expected:**

- The system rejects M3 as upline, e.g. "not part of the binary network" message.
- Registration cannot proceed with a non-binary upline.
  **Evidence:** Screenshot of the error message.

#### TC-22 (High) — Upgrade code is REJECTED at registration

**Precondition:** An unused **UP-** code for `QA Pro` exists (from TC-09).
**Steps:**

1. Open **Register Member**.
2. Choose **Registration code** as the payment method.
3. Enter the `UP-XXXX-XXXX-XXXX` upgrade code.
   **Expected:**

- The code is **rejected** (e.g. "Upgrade codes cannot be used for registration" / invalid).
- The registration cannot continue with that code.
  **Evidence:** Screenshot of the rejection message.

#### TC-23 (High) — CD code registers fine and auto-assigns CD

**Precondition:** An unused **CD-** code for `QA Pro` exists (from TC-08).
**Steps:**

1. Register a member (`M_CD`) paying by code, using the **CD-** code, upline under a binary member.
2. After success, open `M_CD` in **👥 Members → view**.
3. Check the member's profile badges/section for Commission-Deduct (CD) status.
   **Expected:**

- Registration succeeds.
- The member has the Commission-Deduct (CD) status/badge assigned automatically.
- The code is now marked **Used** in **🎟️ Reg Codes**.
  **Evidence:** Screenshots of member profile showing CD assignment, and the code status Used.

---

### AREA F — Member Genealogy

#### TC-24 (High) — Binary member sees both tabs

**Precondition:** `M1` is on `QA Pro` (binary).
**Steps:**

1. Log in as `M1`.
2. Open **🌳 Binary Tree** (`?page=genealogy&view=binary`).
   **Expected:**

- Both tabs exist: **🌳 Binary Tree** and **👥 Referral Network**.
- The Binary Tree loads and shows the member's tree.
  **Evidence:** Screenshot.

#### TC-25 (High) — Non-binary member: Binary tab hidden + auto-redirect

**Precondition:** `M3` is on `QA Retail` (non-binary).
**Steps:**

1. Log in as `M3`.
2. Open the **Referral Network** page.
3. Look at the tab bar.
4. Manually type the binary URL: `?page=genealogy&view=binary`.
   **Expected:**

- The **🌳 Binary Tree** tab is **not shown** (only Referral Network).
- Forcing the binary URL redirects M3 back to the Referral Network view (they can never see a binary tree).
  **Evidence:** Screenshots of the tab bar and the forced-redirect result.

#### TC-26 (Medium) — Indirect-disabled members see direct referrals only

**Precondition:** A member on a package with 🔗 indirect **OFF** (e.g. `QA NoIndirect`) exists and has at least one direct referral.
**Steps:**

1. Log in as that member.
2. Open **👥 Referral Network**.
   **Expected:**

- The Referral Network shows their **direct** referrals table (not the indirect/matrix tree).
- No unilevel/indirect levels table appears.
  **Evidence:** Screenshot.

---

### AREA G — Member Earnings & Lifetime Cap pages

#### TC-27 (High) — Earnings page hides Indirect when package has it OFF

**Precondition:** A member on a package with 🔗 indirect **OFF** exists. Run once for that member, and once for a member on `QA Pro` (indirect ON).
**Steps:**

1. Log in as the member.
2. Open **💰 Earnings**.
   **Expected:**

- For the indirect-OFF member: the summary cards and the filter tabs **do not show** any "Indirect Referral" entry (no "🔗 Indirect" tab). They should still see Pairing, Direct, and DFI.
- For the indirect-ON (`QA Pro`) member: the **"🔗 Indirect"** summary card and/or filter tab **is present**.
  **Evidence:** Side-by-side screenshots of both members' Earnings pages.

#### TC-28 (Medium) — Lifetime Cap page hides Indirect when OFF

**Precondition:** The same two members as TC-27.
**Steps:**

1. Log in as the indirect-OFF member, open **🛡️ Lifetime Cap**.
2. Repeat for the indirect-ON member.
   **Expected:**

- The indirect-OFF member sees no "Indirect Referral" row in the earnings breakdown / timeline, even if they have indirect commission history.
- The indirect-ON member sees the Indirect Referral breakdown.
  **Evidence:** Screenshots.

---

### AREA H — Member Package Upgrade

> The upgrade feature is the biggest new functionality. Test carefully.

#### TC-29 (High) — Upgrade page lists only higher-fee packages with the correct diff

**Precondition:** `M2` is on `QA Pro` (₱25,000). `QA Retail` (₱15,000) and the Starter (₱10,000) exist.
**Steps:**

1. Log in as `M2`.
2. Open **⬆️ Upgrade Package** (`?page=upgrade`).
   **Expected:**

- The current package is shown at the top (`QA Pro`), with its entry fee.
- **No** package with a fee ≤ ₱25,000 is offered for upgrade (Starter and QA Retail are missing or not selectable). **Downgrades are not offered.**
- If a more expensive package exists (e.g. `QA NoIndirect` ₱20,000 — no; in this setup there is no package above QA Pro), then this setup has none — in that case **"already on the highest package"** is shown (see TC-31). Create a package `QA Elite` (₱30,000, pair ON) to complete this test: then `QA Elite` should appear with **To Pay = ₱5,000** (30000 − 25000).
  **Evidence:** Screenshot of the upgrade page list with the To Pay (`diff`) values.

#### TC-30 (High) — E-Wallet upgrade pays only the diff and credits admin

**Precondition:** `M2` (QA Pro, ₱25,000) has e-wallet balance ≥ ₱5,000. A package **QA Elite** (₱30,000, binary) exists. Note M2's starting balance; also write down the admin balance.
**Steps:**

1. Log in as `M2`, open **⬆️ Upgrade Package**.
2. Select **QA Elite** (To Pay shown as **₱5,000.00**).
3. Choose **💳 Pay from E-Wallet**.
4. Click **Upgrade Package**.
5. After success, log in as admin and check:
   - `M2`'s e-wallet balance dropped by exactly **₱5,000** (not ₱30,000).
   - `M2`'s package is now **QA Elite** (check in 👥 Members → view).
   - The admin account was **credited ₱5,000** with a description like "Upgrade fee".
     **Expected:**

- Only the difference is charged; member package changes; admin gets the diff.
  **Evidence:** Before/after balances for M2 and admin; the member's new package in the admin list.

#### TC-31 (High) — "Already highest package" message when no higher package

**Precondition:** A member is on the highest-fee package (e.g. `QA Elite` ₱30,000).
**Steps:**

1. Log in as that member, open **⬆️ Upgrade Package**.
   **Expected:**

- The page shows a message like **"You are already on the highest package. No upgrades available."** with no package list.
  **Evidence:** Screenshot.

#### TC-32 (High) — E-Wallet upgrade fails cleanly when balance is insufficient

**Precondition:** `M3` (QA Retail) has balance below the diff to `QA Pro` (₱25,000 − ₱15,000 = ₱10,000). Give M3 only e.g. ₱1,000.
**Steps:**

1. Log in as `M3`, open **Upgrade Package**.
2. Select **QA Pro**, choose **Pay from E-Wallet**, submit.
   **Expected:**

- A clear error message: **"Insufficient e-wallet balance. Required: ₱10,000.00"** appears.
- The package does NOT change.
  **Evidence:** Screenshot of the error.

#### TC-33 (High) — Upgrade Code payment works

**Precondition:** An unused **UP- code** for **QA Pro** exists (from TC-09). `M3` is on `QA Retail`.
**Steps:**

1. Log in as `M3`, open **Upgrade Package**.
2. Select **QA Pro**.
3. Choose **🎫 Upgrade Code**.
4. Enter the `UP-` code in the field.
5. Submit.
   **Expected:**

- Success message ("Package upgraded successfully!").
- `M3`'s package is now **QA Pro**.
- The UP- code now shows **Used** in **🎟️ Reg Codes**, with `M3` as the user.
  **Evidence:** Screenshots of success + code row showing Used by M3.

#### TC-34 (High) — Upgrade code must match the target package

**Precondition:** You have a `UP-` code for **QA Pro**. Attempt to use it to upgrade to **QA Elite** instead.
**Steps:**

1. Log in as a member on a lower package.
2. In the upgrade page pick a package **different** from the one the UP- code is for, pay by Upgrade Code, enter the `UP-` code, submit.
   **Expected:**

- Rejection like **"This upgrade code does not match the selected package."** (or invalid code).
- Package does NOT change.
  **Evidence:** Screenshot of the error.

#### TC-35 (High) — Non-binary → binary upgrade REQUIRES binary placement

**Precondition:** `M3` is on `QA Retail` (pairing OFF). `QA Pro` is binary. A binary member with a free slot exists (e.g. `M1` or `M2`).
**Steps:**

1. Log in as `M3`, open **Upgrade Package**.
2. Select **QA Pro** (binary).
3. Observe the **"🌳 Join the Binary Network"** card appears.
4. In **Search Binary Upline**, type a few letters of `M1`'s username.
5. Pick M1 from the dropdown results (shows which side is free).
6. Choose **Binary Position** (use a side shown as free).
7. Choose payment (e-wallet or code), submit.
   **Expected:**

- The upline search returns only **binary, active members with an available slot**.
- The chosen side is shown as free; after placement M3 appears under M1 on that side.
- **Submit button is disabled until** both an upline AND a position are chosen.
- After success, in **👥 Members → view** for M3, the binary placement (parent + position) matches what you chose.
  **Evidence:** Screenshots of the search results, the disabled submit, and M3's new binary placement.

#### TC-36 (High) — Non-binary → binary upgrade without placement is blocked

**Precondition:** `M3` on `QA Retail`.
**Steps:**

1. Log in as `M3`, open Upgrade, select **QA Pro**.
2. Choose a payment method, and **submit with NO upline and NO position** (do not fill the placement card).
   **Expected:**

- Either the submit stays disabled, or the server rejects with a message like "A binary position is required for this package."
- No upgrade happens.
  **Evidence:** Screenshot.

#### TC-37 (Medium) — Already-occupied binary position is rejected

**Precondition:** A binary member has **both** slots filled (place two members under M1: one left, one right).
**Steps:**

1. Start upgrading another non-binary member to a binary package.
2. Try to place them under M1 on a side that is **already occupied**.
   **Expected:**

- Rejection message (e.g. "That binary position is already taken" / "not free").
  **Evidence:** Screenshot of the error.

#### TC-38 (Medium) — Binary → binary upgrade keeps the tree position (no placement needed)

**Precondition:** A member is on a binary package and upgrades to another binary package.
**Steps:**

1. Log in as that member (e.g. `M2` on `QA Pro`).
2. Open Upgrade, pick `QA Elite` (binary).
   **Expected:**

- The **"🌳 Join the Binary Network"** card does **NOT** appear (they keep their existing spot in the tree).
- The upgrade works with no upline/position required.
  **Evidence:** Screenshot showing no placement card.

#### TC-39 (Medium) — Same-package and downgrade are blocked

**Steps:**

1. Log in as a member. In the upgrade page never shows their current package (already covered by TC-29).
2. Directly attempt a downgrade: on a QA Pro member, manually visit the upgrade page and confirm QA Retail/Starter are not listed (no way to select a cheaper package).
   **Expected:**

- Cheaper packages are never offered; the current package is not offered either.
  **Evidence:** Screenshot (the offered list contains only higher-fee packages).

---

### AREA I — Commission engine behaviour (behind-the-scenes checks)

> These check the engine, not just the UI. You verify results in **👥 Members → view → 💰 Commissions / 📒 E-Wallet Ledger** tabs.

#### TC-40 (High) — No indirect commissions for members on an indirect-OFF package

**Precondition:** `QA NoIndirect` (indirect OFF) member with a sponsor, and a fresh referral registered under them on any package.
**Steps:**

1. Register a member under the `QA NoIndirect` member (they are the sponsor).
2. Open the `QA NoIndirect` member → **💰 Commissions** tab.
3. Also open their **📒 E-Wallet Ledger**.
   **Expected:**

- **No** `indirect_referral` commission rows for that sponsor.
- Any direct/binary commissions they legitimately earned still appear.
  **Evidence:** Screenshots of the commissions tab.

#### TC-41 (High) — CD member does not generate commissions for uplines (source filter)

**Precondition:** `M_CD` (registered with a CD code, from TC-23) was placed under a binary member who also sponsors somewhere in the chain.
**Steps:**

1. Open the member who sponsors/sits above `M_CD` → **💰 Commissions** tab.
2. Look for direct/indirect/binary rows sourced from `M_CD`.
   **Expected:**

- The upline earned **no** direct/indirect/binary commission from `M_CD`.
- (Optional, if visible) `M_CD` still increased leg counts on their upline's binary-tree node, but generated no bonus.
  **Evidence:** Screenshots showing no commission rows referencing `M_CD` as source.

#### TC-42 (Medium) — DFI engine skips DFI-disabled packages

> Verify at the code/config level using the DFI Admin page counters or by checking that the daily cron only credits DFI-enabled members.
> **Precondition:** `QA ToggleTest` has DFI OFF.
> **Steps:**

1. Note the "Members with DFI" count on **📅 DFI Admin**.
2. Count the members who are on packages with DFI ON.
3. Compare.
   **Expected:**

- The DFI Admin "Members with DFI" number should reflect **only** members whose package has 📅 ON. No member with DFI OFF appears on that count.
- DFI History for a DFI-OFF member shows no daily credits.
  **Evidence:** Screenshot of the counter + a DFI-OFF member's history being empty.

---

## 5. Bug report template

Copy this into your bug report (or the issue tracker). Fill **every** field.

```markdown
**Bug ID:** TC-<number> (or leave as BUG-<date>-<n>)
**Title:** <one short line, e.g. "Upgrade page shows packages with equal entry fee">
**Priority:** High / Medium / Low
**Environment:** URL http://localhost/altaslive — Browser: <e.g. Chrome 131> — OS: <e.g. Windows 11>

**Steps to reproduce:**

1.
2.
3.

**Expected result:**
<what Section 4 said should happen>

**Actual result:**
<what really happened, paste any error text>

**Evidence:**
<attach screenshots / recording / CSV>

**Additional notes:**
<test data used: which packages/codes/members — so the developer can reproduce>
```

---

## 6. Summary sheet (fill in when done)

| Test case | Title (short)                              | Result (Pass/Fail/Blocked) | Note / Bug ID |
| --------- | ------------------------------------------ | -------------------------- | ------------- |
| TC-01     | Packages page: stats cards + table badges |                            |               |
| TC-02     | Create via modal (toggle-first)           |                            |               |
| TC-03     | Edit modal auto-prefill; toggles persist  |                            |               |
| TC-04     | Modal cancel + Cap preview live calc      |                            |               |
| TC-05     | Indirect levels saved                      |                            |               |
| TC-06     | Inactive package hidden                    |                            |               |
| TC-07     | Generate registration codes                |                            |               |
| TC-08     | Generate CD codes (CD- prefix)             |                            |               |
| TC-09     | Generate upgrade codes (UP- prefix)        |                            |               |
| TC-10     | Code price auto-fill                       |                            |               |
| TC-11     | Filter codes                               |                            |               |
| TC-12     | Export CSV                                 |                            |               |
| TC-13     | Print codes                                |                            |               |
| TC-14     | Settings: global toggles removed           |                            |               |
| TC-15     | Settings still save                        |                            |               |
| TC-16     | DFI Admin: no global switch                |                            |               |
| TC-17     | User view DFI badge                        |                            |               |
| TC-18     | Commissions "(disabled)" label             |                            |               |
| TC-19     | Register: binary section (pair ON)         |                            |               |
| TC-20     | Register: binary section hidden (pair OFF) |                            |               |
| TC-21     | Upline must be binary                      |                            |               |
| TC-22     | Upgrade code rejected at registration      |                            |               |
| TC-23     | CD code auto-assigns CD                    |                            |               |
| TC-24     | Genealogy: binary member both tabs         |                            |               |
| TC-25     | Genealogy: non-binary hides binary tab     |                            |               |
| TC-26     | Genealogy: indirect OFF → direct only      |                            |               |
| TC-27     | Earnings hides Indirect when OFF           |                            |               |
| TC-28     | Lifetime Cap hides Indirect when OFF       |                            |               |
| TC-29     | Upgrade lists higher packages + diff       |                            |               |
| TC-30     | E-wallet upgrade pays diff only            |                            |               |
| TC-31     | Already-highest message                    |                            |               |
| TC-32     | Insufficient balance error                 |                            |               |
| TC-33     | Upgrade code payment works                 |                            |               |
| TC-34     | Upgrade code mismatch rejected             |                            |               |
| TC-35     | Non-binary→binary requires placement       |                            |               |
| TC-36     | Non-binary→binary w/o placement blocked    |                            |               |
| TC-37     | Occupied position rejected                 |                            |               |
| TC-38     | Binary→binary keeps position               |                            |               |
| TC-39     | Same/downgrade blocked                     |                            |               |
| TC-40     | No indirect earnings when OFF              |                            |               |
| TC-41     | CD member: no commissions to uplines       |                            |               |
| TC-42     | DFI engine skips disabled                  |                            |               |

**Result totals:** Pass: \_**\_ Fail: \_\_** Blocked: \_\_\_\_
**Overall verdict:** ✅ Ready to release / ⚠️ Bugs found (attach report) / ❌ Major failure

---

## 7. Clean-up checklist (do at the end)

When you are finished testing:

- [ ] Deactivated or noted any test members you created (`QA Test` etc.). (Do **not** delete real data.)
- [ ] Restored the **Starter** package to its original settings if you changed it.
- [ ] Left a short note for the developer listing every package/code/member you created (so they can clean up).
