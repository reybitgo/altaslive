# QA Test Plan — Superadmin + Super Login (S-Login)

**Feature under test:** The "Superadmin" role and the passwordless "Super Login" impersonation feature.
**App URL:** `http://localhost/altaslive`
**Written for:** Complete beginner QA testers. Follow the steps in order. Do not skip the "Expected" step — if what you see matches, Mark as PASS; otherwise Mark as FAIL and fill in the bug report section.

---

## 0. What this feature does (read this first)

The system has 3 account levels:

| Level | Username (default) | Password | Sees |
|-------|--------------------|----------|------|
| Member | any existing member | own password | Member portal only |
| Admin | `admin` | `Admin@1234` | Admin panel |
| Superadmin | `sadmin` | `Sadmin@1234` | Admin panel **+ Super Login** |

**Super Login (S-Login)** lets the superadmin open ANY active member account **without knowing the member's password**. The superadmin:

1. Goes to the S-Login page (`?page=slogin`).
2. Types a member's username (no password field).
3. Clicks "Open Member Session".
4. The system opens the member's portal **in the same browser tab**, acting exactly as that member.
5. Everything the superadmin does (view earnings, genealogy, request payouts, etc.) is done as if they were that member.

**The superadmin is a "mirror" of the admin.** Wherever they look, the superadmin should see exactly what the regular admin (`admin`) sees — and nothing more — plus ⭐ Super Login. This matters for pages like the **Binary Tree** (`?page=genealogy&view=binary`). Because `admin` and `sadmin` are staff accounts (not members placed in the network), the system roots their tree at the **top-most node of the whole binary network** (the member(s) at the very top of the placement tree). So the superadmin must see the FULL network tree, not just a single node labelled `sadmin`. If the tree ever shows only one node for `sadmin` while `admin` sees the whole network → that is a FAIL (mirror broken).

**Important security idea (the URL token):**
- The member view runs in a special "impersonation session" that lives in the **browser address bar** as `?imp=<64-character code>`. Example:
  `http://localhost/altaslive/?page=dashboard&imp=9f3bd2c1a5e8… (64 chars)`
- The superadmin's own login (the "Admin" tab) is NOT affected. They can flip between Admin and the impersonated member freely.
- Every session is **audited**: a database table `impersonation_log` records who entered which member, from what IP/browser, when it started, and how it ended. This is your proof a superadmin entered a member account.
- A session auto-expires after **8 hours**, and is bound to the same **IP address + web browser**. Copying the `?imp=` URL to a different computer/browser will NOT work.
- The superadmin NEVER enters a password for the member. If the S-Login page ever asks for a member password → that is a FAIL (bug).

---

## 1. Before you start (set up)

### 1.1 What you need
- Google Chrome (recommended) or Firefox, on the same computer where the app runs.
- Ability to open `http://localhost/altaslive` in your browser.
- (Optional but recommended) Ability to open **phpMyAdmin** (usually `http://localhost/phpmyadmin`) to check the database. If you don't have it, ask the developer for a database read-only tool — the audit steps need it.

### 1.2 Make sure the feature code is applied (5 minutes)

> If `sadmin` can log in (step 1.3 below), the feature is already applied and you can SKIP the SQL step and go to 1.3.

The database migration `migrate_superadmin.sql` must have been run once. It is safe to run again (it is "idempotent" — running it twice changes nothing). To apply it:

1. Open **phpMyAdmin** in your browser.
2. Select the database on the left. It is `u938213108_altas3_db`.
3. Click the **SQL** tab at the top.
4. Open the file `migrate_superadmin.sql` (found at `C:\laragon\www\altaslive\migrate_superadmin.sql`) with Notepad, copy ALL its contents, paste into phpMyAdmin's SQL box.
5. Click **Go** / **Run**.
6. Confirm no red error appears. A green "Your SQL query has been executed successfully" is expected.

### 1.3 Quick sanity check — can you log in as the superadmin?

1. Open `http://localhost/altaslive/?page=login` in a browser tab. Call this tab **TAB A** (the "superadmin / admin tab").
2. Username: `sadmin`  Password: `Sadmin@1234`
3. You should land on the **Admin Panel** (Dashboard) page with an admin sidebar.
4. On the left sidebar, near the bottom (under 🚪 Logout), you should see a **⭐ Super Login** link.

- If you land on the **Member** dashboard instead of the Admin panel → **FAIL** (superadmin privileges not working).
- If the ⭐ Super Login link is missing → **FAIL**.

### 1.4 Gather your test member usernames

You need at least 2 normal member accounts (one of them can be used for the negative tests if you don't mind it being temporarily blocked for 15 min — better to pick a 3rd for that).

1. In TAB A, click **👥 Members** in the admin sidebar (`?page=admin_users`).
2. Write down **2 or 3 usernames** whose Status is **Active** (not Suspended, not Deactivated, not Pending).
   - Example names in this document: **MEMBER-A** and **MEMBER-B** — replace them with your real usernames.
3. Pick 1 more username that **does not exist** (e.g. `zz_nobody_553`). You will use it for the block/blast test.

> Tip: "Pending" members pay later and cannot be logged into. Only use Active members as happy-path targets.

### 1.5 Important: keep these two rules while testing

- **Rule 1 — One tab per identity.** The impersonated member lives in a NEW tab with `?imp=` inside the URL. Never paste an `?imp=` URL into TAB A, and never log out of TAB A during a session test.
- **Rule 2 — Clean up after yourself.** If a test suspends or blocks a member, restore them at the end (final test section). Never leave a member suspended.

---

## 2. Test case convention

For every test write down:
- **Steps** — do exactly this.
- **Expected** — what should happen.
- **Actual** — write what actually happened (or just "PASS" if it matches).
- **Result** — PASS / FAIL.

---

## Group A — Superadmin account & privileges

### TC-01 — Superadmin can log in with their own credentials
**Steps:**
1. In TAB A, log out (click 🚪 Logout), then go to `?page=login`.
2. Enter username `sadmin` and password `Sadmin@1234`.
**Expected:** Login succeeds and you land on the **Admin Panel** (not the member dashboard).
**Result:** PASS / FAIL

### TC-02 — Superadmin sees the ⭐ Super Login link in the admin sidebar
**Steps:**
1. As `sadmin`, look at the bottom of the left admin sidebar.
**Expected:** A **⭐ Super Login** link exists, above 🚪 Logout, and opens a **new tab** when clicked.
**Result:** PASS / FAIL

### TC-03 — Superadmin can open all admin pages
**Steps:**
1. In TAB A, click through the admin sidebar links: 👥 Members, 📦 Packages, 🎟️ Reg Codes, 💸 Payouts, 💱 E-Wallet Transfer, 💰 Top-Up Member, 📊 E-Wallet Monitor, 🛡️ Cap Monitor, 📅 DFI Admin, 🔄 Reactivation Log, ⚙️ Settings.
**Expected:** Every page opens normally. No "Access denied", no blank page, no error.
**Result:** PASS / FAIL

### TC-04 — Superadmin's own "Member View" shows the extra Super Login entry too
**Steps:**
1. In TAB A, click **👤 Member View** in the admin sidebar (`?page=dashboard`).
2. Look at the left sidebar and the top-right dropdown menu.
**Expected:** You are viewing the member-style dashboard of `sadmin`. The sidebar shows **Admin View** and **⭐ Super Login** entries. The top-right dropdown shows **⭐ Super Login** too.
**Result:** PASS / FAIL

### TC-05 — A normal member CANNOT open the S-Login page
**Steps:**
1. NOTE: do this in a separate browser or private/incognito window (not in any of your current tabs).
2. Log in as **MEMBER-A** using their real password.
3. In the address bar, type: `http://localhost/altaslive/?page=slogin` and press Enter.
**Expected:** A message "Access denied." appears and the browser is sent back to the login page. The S-Login form must NOT appear.
**Result:** PASS / FAIL

### TC-06 — A normal ADMIN (role admin, not superadmin) CANNOT open the S-Login page
**Steps:**
1. In a private/incognito window, log in with `admin` / `Admin@1234`.
2. Go to `http://localhost/altaslive/?page=slogin`.
**Expected:** "Access denied." and redirect to the login page. The S-Login form must NOT appear.
**Result:** PASS / FAIL

### TC-07 — Superadmin's Binary Tree mirrors the admin's (whole network, not a single node)
**Steps:**
1. In TAB A (logged in as `sadmin`), open **🌳 Binary Tree** (`http://localhost/altaslive/?page=genealogy&view=binary`).
2. Look at the drawn tree (wait a few seconds if it is large).
3. Write down the top-most (root) username you see.
4. Now open a private/incognito window, log in as `admin` (`admin` / `Admin@1234`), and open the same page.
5. Look at the drawn tree and write down the root username.
**Expected:**
- The `sadmin` tree is NOT a single node. It shows a network with the root node and members branching below it.
- The root username on the `sadmin` page is **the same** as the root username on the `admin` page (mirror requirement).
- The `admin` tree shows the same network (it has shown this before the fix; the `sadmin` page must now match it).
**Result:** PASS / FAIL

---

## Group B — S-Login happy path (the main flow)

### TC-10 — The S-Login page renders correctly
**Steps:**
1. In TAB A (logged in as `sadmin`), click **⭐ Super Login**. It opens a new tab. Call it **TAB B** (the "impersonation tab").
2. Look at the page.
**Expected:** The page title says **Super Login**. Near the top there is a yellow badge saying "Operating as @sadmin". There is ONE input field labelled "Member username" and a single button **Open Member Session**. There is a small note: "No password needed". There must be NO password field. Link "Back to Admin Panel" exists.
**Result:** PASS / FAIL

### TC-11 — S-Login into an active member (HAPPY PATH)
**Steps:**
1. In TAB B, type **MEMBER-A** into the username field.
2. Click **Open Member Session**.
**Expected:**
- You land on a page that looks like the **member Dashboard**.
- The address bar looks like `http://localhost/altaslive/?page=dashboard&imp=` followed by a **64-character** code (letters and numbers). Written down, the URL says `&imp=` — that is the proof of a session.
- The member's **own name/username** appears (check the top-right corner — it must NOT say "sadmin").
- Their e-wallet balance, stats, etc. belong to MEMBER-A, not the superadmin.
**Result:** PASS / FAIL

### TC-12 — The impersonated member view shows that member's real data
**Steps:**
1. While impersonating MEMBER-A (TAB B), look at the Dashboard and the left sidebar.
**Expected:** The sidebar has NO "Admin View", NO "⭐ Super Login", NO admin pages (Members, Packages, etc.). Only member pages (Dashboard, Earnings, Lifetime Cap, DFI History, Binary Tree, Referral Network, Upgrade Package, Register Member, Send Money, Payouts, Profile & Settings). This confirms you are acting as a normal member.
**Result:** PASS / FAIL

### TC-13 — Clicking around keeps the ?imp= token in the URL
**Steps:**
1. In TAB B, click each of the member sidebar links in turn: **💰 Earnings**, **🛡️ Lifetime Cap**, **📅 DFI History**, **🌳 Binary Tree**, **👥 Referral Network**, **💱 Send Money**, **💳 Payouts**, **⚙️ Profile & Settings**.
2. Look at the address bar after each click.
**Expected:** Every page keeps `&imp=<64 characters>` present in the URL. The pages load as MEMBER-A, never as sadmin, and never kick you out.
**Result:** PASS / FAIL

### TC-14 — The Lifetime Cap page and its "Reactivate" link work while impersonating
**Steps:**
1. In TAB B go to **🛡️ Lifetime Cap** (`?page=cap_status&imp=…`).
2. If the member is under the cap, click the **← Dashboard** button at the top.
**Expected:** You return to the Dashboard **as MEMBER-A**, URL still contains `&imp=`.
**Result:** PASS / FAIL

### TC-15 — The Earnings page filters still respect the session
**Steps:**
1. In TAB B, open **💰 Earnings**.
2. Use the tabs (e.g. Direct Referral / Indirect Referral / Pairing Bonus / CD Ledger) and the "Rows per page" dropdown.
**Expected:** The page re-loads showing MEMBER-A's earnings, and the address bar still contains `&imp=`.
**Result:** PASS / FAIL

### TC-16 — Forms that submit must still work as the member
**Steps (pick ONE of these, the Profile update is the simplest and safest):**
1. In TAB B, open **⚙️ Profile & Settings**.
2. Change nothing — just click the **Save** button.
**Expected:** The page comes back and shows a success message "Profile updated" (or similar). The address bar still contains `&imp=` and you are still viewing MEMBER-A's profile — NOT asked to log in, NOT switched to sadmin.
> If the superadmin has never saved this member's profile before, you may be asked for some required fields; fill the same values in and save.
**Result:** PASS / FAIL

### TC-17 — The Family Tree (Binary Tree) AJAX lazy-load still works
**Steps:**
1. In TAB B, open **🌳 Binary Tree**.
2. Wait for the tree to draw. Click a node that has children (or tap the +/- control if available) to expand deeper nodes.
3. Also open the Developer Tools (F12) → Console tab and look for red errors.
**Expected:** The tree loads and expands without page errors. No red errors in the console (network requests may appear, but no red error messages). Everything is MEMBER-A's own tree.
**Result:** PASS / FAIL

### TC-18 — Pagination inside a member page still keeps the session
**Steps:**
1. In TAB B, open **👥 Referral Network**.
2. If MEMBER-A has more than a few direct referrals, click through the page numbers in the card footer.
**Expected:** Each page keeps `&imp=` and shows MEMBER-A's referrals.
**Result:** PASS / FAIL

---

## Group C — S-Login negative cases (things that must be REJECTED)

### TC-20 — Empty username is rejected
**Steps:**
1. In TAB B (still on the S-Login page — use the ⭐ tab, or go back), leave the username field empty.
2. Click **Open Member Session**.
**Expected:** A red error message appears: "Enter a valid member username." (the browser may also simply stop you via "Please fill out this field" if the field is empty — in that case note it; the important thing is that no session starts).
**Result:** PASS / FAIL

### TC-21 — A username that doesn't exist is rejected
**Steps:**
1. In TAB B, type a made-up username like `definitely_not_a_user_99123`.
2. Submit.
**Expected:** A red error message appears: **"Member not found."** No session starts.
**Result:** PASS / FAIL

### TC-22 — Trying to S-Login into an ADMIN account is rejected
**Steps (use TAB C = a fresh ⭐ Super Login tab, so you don't lose TAB B):**
1. Open S-Login in a new tab (⭐ Super Login → new tab). Call it TAB C.
2. Type the admin username: `admin`.
3. Submit.
**Expected:** A red error: **"S-Login is only allowed for member accounts."** No session starts.
**Result:** PASS / FAIL

### TC-23 — Trying to S-Login into ANOTHER SUPERADMIN is rejected
**Steps:**
1. In a new S-Login tab, type `sadmin`.
2. Submit.
**Expected:** The same error: "S-Login is only allowed for member accounts." (Superadmins are not "member" accounts.)
**Result:** PASS / FAIL

### TC-24 — A suspended (or deactivated) member is rejected
**Preparation (ask a coworker/developer, or do it yourself via Admins):**
1. In TAB A, open **👥 Members**, find **MEMBER-B**, and click to suspend it (the button usually says "Suspend" — if unsure, pause here and ask the developer for help, because you must restore it at the end).
2. In a new S-Login tab, type **MEMBER-B** and submit.
**Expected:** A red error: **"This member account is suspended or deactivated."** No session starts.
**Result:** PASS / FAIL

### TC-25 — Rate limiting: 5 wrong attempts block the username for 15 minutes
**Warning: use a fake username here, NOT a real member, so a real member is never blocked.**
1. In a new S-Login tab, submit the SAME fake username (e.g. `zz_fake_member_0001`) with a wrong/made-up attempt **5 times**.
   - (Because the form always searches usernames, submit it 5 times even though the username is "fake".) You may need to type it 5 times, one after another.
2. Submit it a 6th time.
**Expected:** On the 6th attempt a red message appears: **"Too many attempts. Please wait 15 minutes."** This confirms attacker-protection is on. (To test again sooner, use a different fake username.)
**Result:** PASS / FAIL

### TC-26 — Username is case-insensitive
**Steps:**
1. Using MEMBER-A's username in **all UPPERCASE** letters, S-Login into it from a fresh S-Login tab.
**Expected:** It works exactly like lowercase — the member session opens. (The system lowercases the input automatically.)
**Result:** PASS / FAIL

---

## Group D — Multi-tab / session isolation (the whole point of the ?imp= design)

### TC-30 — Two different members impersonated at the same time in two tabs
**Steps:**
1. In TAB B you are impersonating **MEMBER-A**.
2. Open a NEW S-Login tab (⭐ Super Login). Call it **TAB D**.
3. S-Login into **MEMBER-B** in TAB D.
4. Switch back and forth between TAB B and TAB D.
**Expected:** TAB B shows MEMBER-A's dashboard, TAB D shows MEMBER-B's dashboard, and each tab's address bar has its own different `&imp=` code. They never overwrite each other. Both work at the same time.
**Result:** PASS / FAIL

### TC-31 — The superadmin's own tab (TAB A) is unaffected
**Steps:**
1. After the steps above, click TAB A.
2. Click through admin pages.
**Expected:** TAB A is still the normal `sadmin` admin session. Its URLs have **NO** `&imp=` code. You can do admin work normally. (Nothing about impersonating members "locked out" the admin.)
**Result:** PASS / FAIL

### TC-32 — Removing the ?imp= from the address bar switches back to the superadmin's own view (NOT the member's)
**Steps:**
1. In TAB B (impersonating MEMBER-A), edit the address bar and **delete** the `&imp=…` part so it reads exactly `http://localhost/altaslive/?page=dashboard` and press Enter.
**Expected:** You now see the superadmin's own view (whatever `sadmin` would see as a user — e.g. `sadmin`'s dashboard), NOT MEMBER-A's dashboard. This proves the member data is only reachable through the token.
> After this, put the `&imp=` code back into the address bar to return to the member session (or open a new one).
**Result:** PASS / FAIL

---

## Group E — Ending an impersonation session (logout) & audit trail

### TC-40 — Logging out of the imp tab returns you to the S-Login page (and does NOT log out the superadmin)
**Steps:**
1. In TAB B (impersonating MEMBER-A), click **🚪 Logout** in the sidebar.
**Expected:**
- You land on the **S-Login page** (`?page=slogin`), NOT the normal login page.
- The address bar has **NO** `&imp=` code anymore.
- Click TAB A: the superadmin is STILL logged in and can keep working.
**Result:** PASS / FAIL

### TC-41 — The audit log records the logout
**Steps (requires phpMyAdmin):**
1. Open phpMyAdmin → `u938213108_altas3_db` → **impersonation_log** table → click **Browse**.
2. Sort by `id` descending (newest first). Find the newest row — it should look like:
   - `superadmin_name = sadmin`
   - `target_username = MEMBER-A`
   - `status = logged_out`
**Expected:** The newest row for MEMBER-A shows `status = logged_out`, and has filled-in `ip`, `user_agent`, `created_at`, `expires_at`, and a `nonce_hash` (64-character code — NEVER the raw `?imp=` code, by design).
**Result:** PASS / FAIL

### TC-42 — Right after logging out of one member, you can S-Login into another
**Steps:**
1. You are on the S-Login page after TC-40.
2. S-Login into **MEMBER-B**.
**Expected:** It opens instantly, no password, no error, no rate limit.
**Result:** PASS / FAIL

### TC-43 — An ACTIVE (in-progress) session appears as status `active` in the log
**Steps:**
1. While still impersonating a member (from the previous step), check phpMyAdmin → impersonation_log → newest row.
**Expected:** The newest row for that member shows `status = active`.
**Result:** PASS / FAIL

---

## Group F — Integrity / security hardening

### TC-50 — Tampered ?imp= token does NOT grant access
**Steps:**
1. While impersonating MEMBER-A, edit the address bar so the `imp=` value is replaced by a random string of the same length, e.g. change the first character to `0` or type `0000…` (keep it 64 chars).
2. Press Enter.
**Expected:** You are bounced to the **S-Login page**. No member data is shown. There is no error page, no blank screen, no member dashboard.
**Result:** PASS / FAIL

### TC-51 — An impersonated user cannot reach admin pages
**Steps:**
1. While impersonating MEMBER-A (fresh token, do TC-11 again), literally type `http://localhost/altaslive/?page=admin&imp=<same code>` and press Enter.
2. Also try `?page=admin_users&imp=<code>`.
**Expected:** A message "Access denied." appears (or you are redirected to the dashboard). The admin panel must NOT open.
**Result:** PASS / FAIL

### TC-52 — A suspended member's open session is killed on next refresh
**Steps:**
1. Impersonate MEMBER-B (or MEMBER-A) — an open session.
2. In TAB A, suspend that member (Admin → 👥 Members → Suspend).
3. Go back to the impersonation TAB and press **F5 / Refresh**.
**Expected:** The member session is killed — you are bounced to the S-Login page. It must NOT keep showing the member's dashboard.
**Result:** PASS / FAIL

### TC-53 — Session expiry (8 hours) — verification only, not fully testable in one sitting
**Expected (code-reviewed, record in the results table):**
- The system levels the session out after 8 hours (`expires_at` in the log = start time + 8h). A newcomer can verify indirectly: in TC-41 the `expires_at` column of a fresh session should be about **8 hours in the future**. If it ever shows 0 or a short time like 30 minutes → report a bug.

### TC-54 — CSRF protection on the S-Login form
**Steps (needs Developer Tools / basic network skill — skip if uncomfortable, mark "not executed"):**
1. Open the S-Login page. Open Developer Tools (F12) → **Network** tab.
2. Submit the form once for a valid member.
3. Look at the request named `do_slogin`. In the **Payload**, confirm a value named `csrf_token` is present.
**Expected:** Every S-Login submission carries a CSRF token. (If you download the HTML of the S-Login page, you will also find a hidden input `csrf_token`.)
**Result:** PASS / FAIL / NOT EXECUTED

---

## Group G — Cleanup & finishing

### TC-60 — Restore everything you changed
**Steps:**
1. In TAB A: if you suspended MEMBER-B (TC-24 / TC-52), reactivate / unsuspend it now (Admin → 👥 Members → Reactivate / Activate).
2. Log out of any extra S-Login tabs you left open.
3. Confirm NO member is left suspended.
**Expected:** All test members are back to Active. No test artifacts left behind.
**Result:** PASS / FAIL

### TC-61 — Final wrap-up note for the developer/owner
- Change the default `sadmin` password (`Sadmin@1234`) and `admin` password before going to production (record who you notified).

---

## 3. Full results table (fill in as you go)

| Test ID | Test name | Result | Notes / screenshot link |
|---------|-----------|--------|--------------------------|
| TC-01 | Superadmin logs in | | |
| TC-02 | ⭐ Super Login in admin sidebar | | |
| TC-03 | All admin pages open | | |
| TC-04 | Member View shows Super Login | | |
| TC-05 | Member cannot open S-Login | | |
| TC-06 | Admin cannot open S-Login | | |
| TC-07 | Binary Tree mirrors admin | | |
| TC-10 | S-Login page renders | | |
| TC-11 | S-Login happy path | | |
| TC-12 | Member data / no admin links | | |
| TC-13 | Nav keeps ?imp= | | |
| TC-14 | Lifetime Cap links work | | |
| TC-15 | Earnings filters work | | |
| TC-16 | Profile form submit works | | |
| TC-17 | Binary Tree AJAX loads | | |
| TC-18 | Pagination keeps session | | |
| TC-20 | Empty username rejected | | |
| TC-21 | Nonexistent user rejected | | |
| TC-22 | Admin target rejected | | |
| TC-23 | Superadmin target rejected | | |
| TC-24 | Suspended member rejected | | |
| TC-25 | Rate limit after 5 attempts | | |
| TC-26 | Case-insensitive username | | |
| TC-30 | Two members, two tabs | | |
| TC-31 | Superadmin tab unaffected | | |
| TC-32 | Removing ?imp= switches back | | |
| TC-40 | Imp logout → S-Login page | | |
| TC-41 | Audit log = logged_out | | |
| TC-42 | Immediate S-Login after logout | | |
| TC-43 | Audit log = active | | |
| TC-50 | Tampered token bounced | | |
| TC-51 | Imp cannot reach admin pages | | |
| TC-52 | Suspended member's session dies | | |
| TC-53 | 8h expiry verified | | |
| TC-54 | CSRF token present | | |
| TC-60 | Cleanup done | | |
| TC-61 | Password-change notified | | |

---

## 4. Bug report template (copy for each FAIL)

```
BUG REPORT
Test ID:        (e.g. TC-24)
Severity:       Critical / High / Medium / Low
Title:          (one line — what is wrong)
Steps to reproduce:
  1.
  2.
  3.
Expected:
Actual:
Environment:    Browser + version, OS, date/time
Screenshots:    (attach or paste link)
Network/Console errors: (paste any red error text from F12)
```

---

## 5. Glossary (for beginners)

- **S-Login / Super Login** — Staff-only passwordless login into a member account.
- **Impersonation session** — a "member view" opened by the superadmin. It is temporary, watched, and logged.
- **?imp= token** — the 64-character code in the URL that holds the member session. Only valid on the same computer + browser.
- **Audit log (`impersonation_log`)** — the permanent record ("paper trail") of every impersonation.
- **CSRF token** — a secret code PHP puts in every form to stop fake submissions.
- **PHPMailer / phpMyAdmin** — just the web tool you used to read the database.
- **Admin / Superadmin** — `admin` (regular admin) and `sadmin` (superadmin). Only `sadmin` has ⭐ Super Login.