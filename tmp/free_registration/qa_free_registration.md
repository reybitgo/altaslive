# QA Test - Free Registration & Binary Placement

**Feature tested:** Free (no-cost) member registration and how new members get
connected to the binary network.

**Version under test:** Free-registration + "Has Binary" toggle + Auto/Manual placement

**For:** Independent QA testers (no code knowledge needed)

---

## 1. Before You Begin - Read This First

Thank you for testing! This guide is written step-by-step. Please:
- Follow the steps **in order**.
- Use **different usernames** every time you repeat a test (e.g. `qa1_...`, `qa2_...`).
- Take a **screenshot** whenever you see something that looks wrong.
- If a step doesn't behave as described, mark that test as **FAILED** and fill in the
  "Actual result" box. Do not try to fix it yourself.

### Test session info (fill this in)

| Item | Value |
|------|-------|
| Tester name | |
| Date | |
| Website address (URL) | |
| Browser + version | |
| Test database (fresh / reused) | |
| Started at | |
| Finished at | |

---

## 2. What Changed (in Plain English)

The website is a member system with two kinds of selling packages:
- **Binary packages** - a member can join the "binary tree" (a left/right network that
  earns pairing bonuses),
- **Non-binary packages** - a member earns other bonuses but is not in the binary tree.

Before this change, **every** free registration (free = no payment yet) forced the
registrar to pick a binary connection. This change makes it flexible:

1. **Free registration by a binary registrar:** a new switch called **"Has Binary"**
   appears. It is ON by default (so the member is connected to the binary tree as
   before). Switch it **OFF** to defer: the new member is created WITHOUT a binary
   connection and can decide at activation time instead.
2. **Binary-less registrars (members WITHOUT a binary package):** the "Has Binary"
   switch is **not shown**. Free registrations count as "defer".
3. **Direct paid registration into a binary package (code or e-wallet):**
   - a **binary** registrar must always connect (switch locked ON),
   - a **non-binary** registrar can choose **Auto** (the system picks the best free
     spot in the whole network) or **Manual** (they pick the connection themselves).
4. **Auto-select:** the system finds the "best" free spot, searching the whole network.
   If literally no spot exists, the new member becomes a **network root** (top of
   their own mini-tree).
5. **Activation:** a pending member who has no connection yet is shown **Auto** (system
   picks) or **Manual** (they pick) when they activate with a binary package.
6. **Referral links <code>?page=register&ref=1&sponsor=...</code>** behave exactly as
   before: they always place the member under the sponsor's tree.
7. Anything that is **not** a binary package stays untouched by the binary tree.

---

## 3. Quick Glossary (Simple Words)

| Word | Meaning |
|------|---------|
| **Registrar** | The person (or guest) filling in the registration form. |
| **Registrant** | The NEW member being created. |
| **Binary package** | A package that participates in the left/right binary tree. |
| **Non-binary package** | A package that does NOT participate in the binary tree. |
| **Sponsor** | The member who referred the new person. |
| **Upline** | The member the new person is placed *under* in the binary tree. |
| **Position** | Left or Right. Every upline has one Left spot and one Right spot. |
| **Placement** | Connecting the new member under an upline in Left or Right position. |
| **Pending** | A free-created account that has not paid yet; it must be **activated**. |
| **Activation** | The pending member pays (code or e-wallet) and becomes active. |
| **Has Binary toggle** | The on/off switch on the registration form (binary registrars only). |
| **Auto** | The system picks the best free spot automatically. |
| **Manual** | The registrar types the upline + picks Left/Right themselves. |
| **Network root** | A member with no upline - the top of their own subtree. |

---

## 4. What You Need Before Testing (Pre-flight)

Make sure you can do all of these. Tick them off as you go.

- [ ] I can open the website and see the landing page.
- [ ] I can log in as the **admin** (default test login is `admin` / `Admin@1234`;
      if this fails, ask the project lead for the correct admin credentials).
- [ ] I can open the register page at `/?page=register`.
- [ ] I have **at least 2 registration codes** ready:
      one for a **binary** package and one for a **non-binary** package.
      (Created by the admin - see section 5.)
- [ ] I know which package is binary and which is not.
      (Ask the project lead, or look at the admin Packages page `/?page=admin_packages`.)
- [ ] The tester account set from section 5 is created and logged in.

> **Hint:** The member list page is `/?page=admin_users` (search by username).
> The binary tree page is `/?page=genealogy`.
> The activation page is `/?page=activate` (opens only when logged in as a pending member).

---

## 5. Preparing Your Tester Accounts (Do This Once)

You need a few saved accounts. Use these names (replace `X` with your initials, e.g.
`tt`), and remember their passwords. Write them in the table.

### Step 5.1 - Get registration codes (as admin)

1. Log in as admin.
2. Open `/?page=admin_codes` (Registration Codes).
3. Generate/note two codes:
   - Code **B-code**: belongs to a **binary package**.
   - Code **N-code**: belongs to a **non-binary package**.
4. Keep the code text handy - you will paste it into forms.

### Step 5.2 - Create the active binary member `binarytester_X`

1. Log out (so you are a guest).
2. Open `/?page=register`.
3. Enter a CODE or E-WALLET? -> Use **code**. Paste **B-code**.
4. Create username `binarytester_X`, a password of your choosing, and a sponsor
   (if asked, use the admin username).
5. Follow the rest of the form. The member is created **active** (because it
   used a code). It should be placed in the binary tree.
6. Log in as `binarytester_X` and open `/?page=genealogy`. If the tree page opens,
   this member **has binary**. Write "yes" below.

### Step 5.3 - Create the active NON-binary member `normalTester_X`

Repeat step 5.2 but use **N-code** and username `normalTester_X`.
After login, try `/?page=genealogy`. A non-binary member is redirected away from
the binary view (or does not show a binary tree). Write "no" below.

### Step 5.4 - Create a second binary member `upline_X`

Repeat step 5.2 with **B-code** and username `upline_X`. This member will be the
"target upline" in several tests. It should be active and in the binary tree.

### Step 5.5 - Optional: an unplaced pending member for activation tests

You will create these during the tests (section: Suite E). No need to pre-create.

### Record your test accounts

| Role | Username | Password | Package type | In binary tree? |
|------|----------|----------|--------------|-----------------|
| Admin | `admin` | (given) | - | - |
| Binary member | `binarytester_X` | | binary (B-code) | yes |
| Non-binary member | `normalTester_X` | | non-binary (N-code) | no |
| Second binary member | `upline_X` | | binary (B-code) | yes |

---

## 6. How the Test Cases Work

Each test case (TC) has:
- **Steps** - exactly what to click/type.
- **Expected** - what a correct system does.
- **Result** - tick PASS or FAIL, write the actual result and any notes.

Use a fresh, unique username for every registrant (the system rejects duplicate
usernames).

> **Registration closed?** If you see "Registration Closed", the member seat limit
> was reached. Ask the project lead to raise `seat_limit` in settings, or reset the
> test database (dev `reset.php` utility - admin only).

---

## 7. Test Suites

### Suite A - Free Registration by a BINARY Registrar

**You are logged in as:** `binarytester_X`

#### TC-A1 - Free registration, "Has Binary" toggle ON (default)

**Purpose:** A binary registrar's free signup should connect the new member to the
binary tree by default.

Steps:
1. Log in as `binarytester_X`.
2. Open `/?page=register`.
3. Payment method: **Free** (pre-selected).
4. Look at step 2 ("Account Setup") after clicking Continue on step 1.
5. Expected at step 2:
   - The **"Has Binary" toggle is ON** and can be switched.
   - The **Binary Upline** and **Left/Right position** fields are VISIBLE.
6. Fill in a new username (e.g. `freeA1_X`), password, sponsor (`binarytester_X`),
   upline (`upline_X`), and position **Left**.
7. Submit.

Expected result:
- A success message appears ("registered successfully... awaiting activation").
- Admin member list shows `freeA1_X` with status **Pending**.
- In the binary tree / member view, `freeA1_X` is placed **under `upline_X` Left**.

Result: [ ] PASS  [ ] FAIL
Actual result / notes:

---

#### TC-A2 - Free registration, toggle OFF (defer)

**Purpose:** A binary registrar should be able to turn OFF the connection; the new
member is then created pending and UNPLACED.

Steps:
1. Log in as `binarytester_X`, open `/?page=register`, payment = **Free**.
2. At step 2, switch **"Has Binary" to OFF**.
3. Expected: the upline/position fields DISAPPEAR. The toggle is still ON/OFF
   (still interactive - you can switch it back).
4. Fill username (e.g. `freeA2_X`), password, sponsor. Do NOT enter an upline.
5. Submit.

Expected result:
- Success message appears.
- `freeA2_X` is **Pending**.
- Member view shows **no upline** and **no position** (unplaced).
- When you later log in AS `freeA2_X`, the activation page shows an "Auto / Manual"
  connection section (you can test this fully in Suite E).

Result: [ ] PASS  [ ] FAIL
Actual result / notes:

---

#### TC-A3 - Toggle reacts to the chosen package

**Purpose:** When a binary registrar switches to a **non-binary** package, the
toggle should automatically lock OFF.

Steps:
1. Log in as `binarytester_X`, open `/?page=register`.
2. Payment method: **E-Wallet** (if the member has balance; if you cannot choose
   e-wallet, skip to "Notes" and mark the test as N/A for your environment).
3. Select a **non-binary package**.
4. Expected: the "Has Binary" toggle turns OFF by itself and becomes **locked**
   (greyed out / cannot be changed). No upline/position fields.
5. Now select a **binary package**.
6. Expected: the toggle turns ON by itself and stays **locked ON**. Upline/position
   fields are REQUIRED and shown.

Result: [ ] PASS  [ ] FAIL  [ ] N/A
Actual result / notes:

---

### Suite B - Direct Paid Registration (CODE) by a BINARY Registrar

**You are logged in as:** `binarytester_X`

#### TC-B1 - Code for a BINARY package

**Purpose:** A binary registrar using a code for a binary package MUST connect; the
toggle is locked ON.

Steps:
1. Log in as `binarytester_X`, open `/?page=register`.
2. Payment method: **Code**. Enter **B-code** and click **Validate**.
3. At step 2, check the toggle.
4. Expected: toggle is **ON and locked** (cannot be switched). Upline/position
   fields required.
5. Complete with a new username (e.g. `codeB1_X`), upline `upline_X`, position
   **Right**. Submit.

Expected result:
- Member `codeB1_X` is created **Active** (code = paid), placed under `upline_X`
  Right.
- Toggle stayed ON the whole time (no way to turn it off).

Result: [ ] PASS  [ ] FAIL
Actual result / notes:

---

#### TC-B2 - Code for a NON-BINARY package

**Purpose:** Non-binary packages should never trigger a binary connection.

Steps:
1. Log in as `binarytester_X`, open `/?page=register`.
2. Payment: **Code**. Enter **N-code**, click Validate.
3. At step 2, check the toggle and fields.
4. Expected: toggle locked **OFF**; no upline/position fields; no binary rows in the
   review summary.
5. Complete username (e.g. `codeB2_X`), submit.

Expected result:
- `codeB2_X` created Active, with **no upline / no position**.
- No errors asked for binary details.

Result: [ ] PASS  [ ] FAIL
Actual result / notes:

---

### Suite C - Registration by a NON-BINARY Registrar

**You are logged in as:** `normalTester_X`

#### TC-C1 - Free registration (no toggle)

**Purpose:** A non-binary registrar should see NO "Has Binary" toggle, and their
free signup is automatically deferred.

Steps:
1. Log in as `normalTester_X`, open `/?page=register`.
2. Payment: **Free**.
3. Look for the "Has Binary" toggle at step 2.
4. Expected: the toggle is **NOT shown at all**. No upline/position fields.
5. Complete username (e.g. `freeC1_X`), submit.

Expected result:
- Member `freeC1_X` is **Pending** with no upline/position.

Result: [ ] PASS  [ ] FAIL
Actual result / notes:

---

#### TC-C2 - Code for a BINARY package, AUTO placement

**Purpose:** A non-binary registrar registering someone into a binary package should
see Auto/Manual choice; Auto is default and the system picks the position.

Steps:
1. Log in as `normalTester_X`, open `/?page=register`.
2. Payment: **Code**. Enter **B-code**, click Validate.
3. Expected: a "Binary Connection" section appears with **Auto (default)** and
   **Manual** options.
4. Leave it on **Auto**. Notice the preview text tells you which member/position the
   system will use (e.g. "Auto will place this member under @...").
5. Complete username (e.g. `codeC2_X`), submit.

Expected result:
- `codeC2_X` is created **Active** and placed somewhere in the binary network (Auto).
- The review screen before submit showed the auto-picked connection (or "Auto").

Result: [ ] PASS  [ ] FAIL
Actual result / notes:

---

#### TC-C3 - Code for a BINARY package, MANUAL placement

**Purpose:** Manual should let the registrar type a valid upline and pick position.

Steps:
1. Log in as `normalTester_X`, open `/?page=register`.
2. Payment: **Code**, **B-code**, Validate.
3. Choose **Manual**.
4. Expected: upline/position fields appear.
5. Enter upline `upline_X`, position **Left**. New username `codeC3_X`. Submit.

Expected result:
- `codeC3_X` created Active, placed under `upline_X` Left.

Result: [ ] PASS  [ ] FAIL
Actual result / notes:

---

#### TC-C4 - Manual with an INVALID upline

**Purpose:** Invalid uplines must be rejected with a clear message.

Steps:
1. As `normalTester_X`, again use Code + B-code, choose **Manual**.
2. Type an upline that is NOT binary-capable (e.g. `normalTester_X` themselves, or
   an account that is **not active**). New username `codeC4_X`.
3. Submit or wait for the upline check.

Expected result:
- An error message appears (e.g. "not part of the binary network" / "not an active
  member"). Registration is blocked. No member `codeC4_X` is created.

Result: [ ] PASS  [ ] FAIL
Actual result / notes:

---

### Suite D - Guest Registration (Not Logged In)

#### TC-D1 - Guest uses a code for a binary package

**Purpose:** Guests only have the code option and should get Auto/Manual for binary
packages; no toggle anywhere.

Steps:
1. Log out. Open `/?page=register`.
2. Enter **B-code**, click Validate.
3. Expected: a "Binary Connection" section with **Auto (default)** and **Manual**.
   **No "Has Binary" toggle** is visible.
4. Use Auto. Fill username (e.g. `guestD1_X`), password, sponsor (use
   `binarytester_X`).
5. Submit.

Expected result:
- `guestD1_X` is created Active, placed by Auto. The registrar is logged in as the
  new member after registration (normal guest flow).

Result: [ ] PASS  [ ] FAIL
Actual result / notes:

---

### Suite E - Activation of a Pending Member

**You will log in as the pending members you create in these tests.**

#### TC-E1 - Pending member already placed at registration

**Purpose:** A member who was placed at registration keeps that spot; activation
shows NO binary picker.

Setup: Create a free pending member WITH placement using TC-A1 steps (toggle ON,
upline `upline_X`). Then:

1. Log out. Log in **as that new member** (e.g. `freeA1_X`).
2. Open `/?page=activate`.
3. Expected: the top banner says the binary position is **reserved**. There is
   **NO Auto/Manual section** on the form.
4. Activate with **B-code**.
5. Expected: activation succeeds; member placed under `upline_X` Left (the reserved
   spot). Status Active.

Result: [ ] PASS  [ ] FAIL
Actual result / notes:

---

#### TC-E2 - Pending member UNPLACED activates with a binary package (AUTO)

Setup: Create a free pending UNPLACED member using TC-A2 steps (toggle OFF) or
TC-C1 steps.

1. Log in **as that member** (e.g. `freeA2_X`).
2. Open `/?page=activate`.
3. Expected: banner says "No binary placement yet...". An **Auto/Manual** section is
   visible once a binary package is chosen.
4. Leave **Auto**. Activate with **B-code**.
5. Expected: activation succeeds and the member is now placed somewhere in the
   network (Auto). Member Active.

Result: [ ] PASS  [ ] FAIL
Actual result / notes:

---

#### TC-E3 - Unplaced member activates MANUAL with a taken position

Setup: Use the member `freeE3_X` created like TC-E2 (unplaced pending).

1. Log in as `freeE3_X`, open `/?page=activate`.
2. Choose code **B-code**, pick **Manual**.
3. Enter upline `upline_X` and the position **already used** in TC-E1 (e.g. Left).
4. Activate.

Expected result:
- An error appears ("position already taken") and the member is NOT activated /
  is not moved. The system must NOT crash.

Result: [ ] PASS  [ ] FAIL
Actual result / notes:

---

#### TC-E4 - Unplaced member activates with a NON-BINARY package

Setup: A pending unplaced member (same as TC-E2).

1. Log in as them, open `/?page=activate`.
2. Activate with **N-code**.
3. Expected: NO Auto/Manual section is shown (package is not binary). Activation
   succeeds, member Active, still unplaced (no upline/position - correct for a
   non-binary member).

Result: [ ] PASS  [ ] FAIL
Actual result / notes:

---

### Suite F - Referral Link

**Two flavors exist:**
- Sponsor **has binary** → the referral link places the member under the sponsor's
  tree immediately (unchanged behavior, TC-F1).
- Sponsor **does NOT have binary** → no placement at registration; the member is
  created pending and UNPLACED, and chooses their binary connection at activation
  (Auto by default = network-wide best spot, TC-F2 / TC-F3).

#### TC-F1 - Referral link from a BINARY sponsor (unchanged behavior)

Steps:
1. Log out. Open:
   `/?page=register&ref=1&sponsor=binarytester_X`
2. Expected: the form JUMPS straight to account setup (no step 1). Upline and
   position are pre-filled automatically. NO "Has Binary" toggle is shown.
3. Complete with a new username (e.g. `refF1_X`), password.
4. Submit.

Expected result:
- Success message. Member `refF1_X` is **Pending** and already placed in the sponsor
  `binarytester_X`'s tree.
- Member view shows an upline and position reserved.

Result: [ ] PASS  [ ] FAIL
Actual result / notes:

---

#### TC-F2 - Referral link from a NON-BINARY sponsor (deferred)

**Purpose:** When the sponsor has no binary, the referral link must NOT fail and must
NOT place the member. It creates a pending, UNPLACED member.

Steps:
1. Log out. Open:
   `/?page=register&ref=1&sponsor=normalTester_X`
2. Expected at the form (it jumps straight to account setup):
   - There is a warning notice like "Your sponsor does not have a binary network,
     so no binary connection is made at registration."
   - The upline/position fields are **NOT** shown (or are hidden).
   - There is **no error** about an invalid upline.
3. Complete with a new username (e.g. `refF2_X`), password. Submit.

Expected result:
- Success message. Member `refF2_X` is **Pending** with **no upline / no position**
  (unplaced).
- Check the member list: no upline, no position.

Result: [ ] PASS  [ ] FAIL
Actual result / notes:

---

#### TC-F3 - Activating a deferred referral member (AUTO)

**Purpose:** The deferred member picks a binary package at activation, the system
auto-places them network-wide.

Setup: use the member `refF2_X` from TC-F2.

1. Log in **as `refF2_X`** (they were auto-logged-in after registration, or log in
   with the username/password you chose).
2. Open `/?page=activate`.
3. Expected: the banner says "No binary placement yet..." and an **Auto/Manual**
   section appears once a binary package is chosen.
4. Leave **Auto**. Activate with **B-code**.
5. Expected: activation succeeds; the member is now **Active** and placed somewhere
   in the binary network (Auto - may be under the admin root or any member with a
   free spot). No error.

Result: [ ] PASS  [ ] FAIL
Actual result / notes:

---

#### TC-F4 - Activating a deferred referral member (MANUAL)

**Purpose:** The deferred member can also pick the connection themselves.

Setup: create another deferred member (repeat TC-F2 with username `refF4_X`), or reuse
an unplaced pending member.

1. Log in as them, open `/?page=activate`.
2. Choose code **B-code**, pick **Manual**.
3. Enter upline `upline_X`, position **Right**.
4. Activate.

Expected result:
- Activation succeeds; member Active, placed under `upline_X` Right.
- Invalid choices (inactive member, occupied position) must be blocked with a clear
  error, exactly like the non-referral activation tests.

Result: [ ] PASS  [ ] FAIL
Actual result / notes:

---

#### TC-F5 - Guest registration defaults to Free (logout)

**Purpose:** A non-logged-in visitor on `/?page=register` gets a Free/Code toggle with
Free selected by default — no mandatory registration code.

1. **Log out** / incognito, open `/?page=register`.
2. Check Step 1: there is a **🎁 Free / 🎫 Registration Code** toggle and **Free is
   checked**; the "Enter your registration code" code-only field is NOT pre-shown.
3. Leave Free selected, enter a sponsor, username, password, continue → review →
   submit.

Expected result:
- Success. Member created **pending**, **unplaced**, `reg_payment_method = pending`
  (same as a referral-link free signup; activates later via `/?page=activate`).
- Switching to **Code** shows the code field; the existing active-with-code flow is
  unchanged.

Result: [ ] PASS  [ ] FAIL
Actual result / notes:

---

### Suite G - "Register New Member" Pop-up (in the binary tree page)

**You are logged in as:** `binarytester_X`

#### TC-G1 - Free, toggle ON (default) - anchored placement

Steps:
1. Open `/?page=genealogy`.
2. Click **Register New Member** (pop-up opens).
3. Payment: **Free** (default). Look for the "Has Binary" toggle.
4. Expected: toggle is ON; upline and position are pre-filled to the spot you
   clicked (or the highlighted node). The member will be connected there.
5. Complete with a new username and submit.

Expected result:
- Success message. New member placed (pending) at the expected node.

Result: [ ] PASS  [ ] FAIL
Actual result / notes:

---

#### TC-G2 - Free, toggle OFF (defers)

Steps:
1. Re-open the pop-up, payment **Free**.
2. Switch **Has Binary OFF**.
3. Expected: the pre-filled position area disappears; review shows no binary rows.
4. Complete and submit.

Expected result:
- New member created **pending** and **unplaced**.

Result: [ ] PASS  [ ] FAIL
Actual result / notes:

---

#### TC-G3 - Code for a binary package (locked ON)

Steps:
1. Re-open the pop-up. Payment: **Code**, enter **B-code**, Validate.
2. Check the toggle and the connection area.
3. Expected: toggle **locked ON**, must connect. Auto/Manual applies only if the
   registrar is non-binary (in this pop-up our registrar IS binary, so it should
   be the pre-filled manual connection).
4. Complete and submit.

Expected result:
- Member created, connected at the pre-filled spot.

Result: [ ] PASS  [ ] FAIL
Actual result / notes:

---

### Suite H - General Safety Checks

#### TC-H1 - No technical errors anywhere

While doing ALL tests above, watch out for:

1. A blank/broken page.
2. A page saying "Fatal error", "500", or a long scary PHP message.
3. An unhandled red error box instead of a friendly message.

If any test hits one of these, record it - it is a HIGH priority defect even if the
rest of the test "worked".

Result: [ ] PASS  [ ] FAIL
Actual result / notes:

---

#### TC-H2 - Review screen honesty

**Purpose:** The "review/confirm" step before submitting must show exactly what will
happen (binary or no binary; auto or manual; which upline).

1. Repeat TC-C2 (manual or auto). On the final review screen, check:
   - If Auto: shows the auto-picked upline or "Auto".
   - If Manual: shows the upline and position you chose.
   - If toggle OFF or non-binary package: NO binary rows (no upline/position lines).
2. Expected: review matches what actually happened after submission.

Result: [ ] PASS  [ ] FAIL
Actual result / notes:

---

## 8. Final Summary

| Test | Result |
|------|--------|
| TC-A1 | |
| TC-A2 | |
| TC-A3 | |
| TC-B1 | |
| TC-B2 | |
| TC-C1 | |
| TC-C2 | |
| TC-C3 | |
| TC-C4 | |
| TC-D1 | |
| TC-E1 | |
| TC-E2 | |
| TC-E3 | |
| TC-E4 | |
| TC-F1 | |
| TC-F2 | |
| TC-F3 | |
| TC-F4 | |
| TC-F5 | |
| TC-G1 | |
| TC-G2 | |
| TC-G3 | |
| TC-H1 | |
| TC-H2 | |

**Overall verdict (tick one):** [ ] PASS  [ ] PASS WITH NOTES  [ ] FAIL

**How many failures?** ____

---

## 9. Defect Report Template

Copy this block for every failure.

```
DEFECT ID:   DEF-<your initials>-<number>, e.g. DEF-TT-01
Related test:  TC-____ (e.g. TC-C4)
Title:         Short summary, e.g. "Invalid upline accepted when it should be rejected"
Severity:      [ ] Critical (data lost/crash)   [ ] Major (feature broken)
               [ ] Minor (cosmetic / wording)   [ ] Suggestion
Environment:   URL / browser / logged-in as ______
Steps to reproduce:
1.
2.
3.
Expected result:
Actual result:
Screenshots:   (attach or describe file name)
Notes:         any other info
```

**Severity guide for beginners:**
- **Critical** - the page crashes, money/members disappear, or data could be lost.
- **Major** - the feature does not do what the steps say.
- **Minor** - it works but looks wrong / bad wording / wrong color.
- **Suggestion** - an idea to make it better, even if not a bug.

---

## 10. Good Testing Habits (Quick Tips)

1. **One username per attempt.** Reusing a username will fail with "already taken".
2. **Test in private/incognito mode** for guest tests (or just log out).
3. **Verify in the Admin list**, not just by the success message - the message can
   lie, the member list is the truth.
4. **Check the binary tree page** to confirm where a member landed.
5. **Write down the codes you used.** Used codes cannot be reused.
6. If a test needs "a free slot" and every position is taken, use a fresh upline
   (`upline_X` is re-usable for MANY members - each has two positions, but only once
   per position).
7. When unsure what to do, mark the step as "unclear" in Notes and continue. It is
   better to finish the tests than to stop.

Thank you! Your report makes the product better. 😊