# Shop — Guest Buyer Workflow: Frontend Checkout to Completion

> **Status: PROPOSED SPEC — reconciled against the source and industry practice, 2026-10-19.**
> Unlike `buyer_workflow.md` (derived from executable source), the feature
> described here does not exist in code yet. Everything marked *(existing)* or
> cited as `file:line` was checked against the repository snapshot
> (`altaslive.zip`); routes, columns, settings, tables, helpers and classes
> marked *(new)* are placeholder names. §15 records what the source review
> confirmed and changed; §16 maps the design to industry practice, with
> references; §17 lists the files the build touches, the tests to add and the
> delivery order. When the feature ships, re-check the citations and drop this
> banner.

A guest's-eye walkthrough of shopping from the public frontend **without
registering first**. The visitor browses a storefront that looks and behaves
like the member shop, adds products to a cart, and when ready fills in one
checkout form. Placing the order creates a free account in that same step,
signs the guest in, and lands them on the normal member order page. From there
the journey is the member journey of `buyer_workflow.md`, through to
`completed`.

The purpose is engagement: a prospective package-paying member gets the member
shopping experience before committing to anything, and the account that results
is the first step toward a package. That is why add-to-cart is open to everyone
and why the guest shop reuses the member templates instead of imitating them.

This document covers only what is *different* for a guest: entry, sponsor
capture, the shared storefront, the cart, checkout, the atomic register-and-order
step and the handoff. It does **not** repeat the 14-status order journey; it
points into `buyer_workflow.md` instead (D10).

Conventions: **BW §N** = section N of `buyer_workflow.md`; plain **§N** = a
section of this document; **D#** = a design decision (§1); *(new)* = to be
built; *(existing)* = reused unchanged.

Strategy C reminder: guest orders earn **zero** compensation, like every shop
order — no PV, no pairing/unilevel bonus, no CD split, no DFI;
`Commission`/`CapEngine` are never involved. A free (pending) registration
writes no commissions and increments no leg counts — everything defers to
activation (BW §1; `User::register()` runs `processBinaryPlacement()` with
`incrementCounts = false` and skips the referral bonuses when the status is
`pending`). `core/Commission.php` is **not modified** by this feature (§17).

---

## 1. What "guest" means here

A **guest** is a visitor with **no account yet** — not an anonymous buyer (D1).
Browsing and filling a cart need nothing from them. Checkout creates an account:
there are no account-less orders, because proof upload, cancel, confirm-receipt,
invoices and refunds are owner-scoped member features behind
`Auth::guard('member')` (BW §8).

| | Member journey (`buyer_workflow.md`) | Guest journey (this doc) |
|---|---|---|
| Account before shopping | Required — free registration (BW §1) | **Not required** — created at order placement |
| Where they shop | `?page=shop` (member layout, behind `guard('member')`) | `?page=store` *(new)* — the same templates in a no-sidebar shell |
| Cart owner | member id | random cookie token → claimed by the new member |
| Extra checkout fields | — | username (auto-suggested) and one password field; a sponsor only if the guest types one |
| Payment methods | five, incl. `ewallet` | four manual methods only |
| Order creation | `createFromCart(memberId, …)` | the same call, inside the register-and-order transaction |
| After placement | order page `?page=shop_order&id=` | the same page, already signed in, with a one-time "Your login" card |

**Why one transaction.** The schema makes an owner mandatory before an order or
a cart can exist: `shop_orders.member_id` is `NOT NULL … ON DELETE RESTRICT`,
`carts.member_id` is `NOT NULL`, and `ShopOrder::createFromCart()` refuses to run
unless `Cart::getActive($memberId)` is the cart it was handed
(`models/ShopOrder.php:687`). "Place the order, *then* register" therefore cannot
be done literally. Registration moves *inside* the order's transaction:
register user → claim cart → create order → commit. Any failure rolls back all
of it. To the guest the rule still reads "order placed ⇒ account exists", and a
failed checkout never leaves an orphan user or an orphan order.

**"Successful" means placed, not paid** (D2). A brand-new account has a zero
wallet balance, so the guest pays by manual proof (BW §1, §7b), and proof upload
lives on the member order page — the guest has to be signed in the moment the
order exists. The account is created with the order (status `pending`) and
**outlives** it: if the order later expires or is cancelled, only the order dies
(`cron/shop_expiry.php` only ever acts on shop orders and their lines and events,
never on users).

**Sponsor and binary placement.** Every guest account has a sponsor: the referral
link's sponsor if the guest arrived through one, otherwise the primary admin
(D4′). Exactly as for a free registration made from a referral link, the account
is placed in the binary tree **only** when the referral rule of D5 applies;
otherwise it is created **unplaced** and chooses its placement at activation.
Either way it consumes one seat (§3).

**Unchanged:** the 14-status order state machine, events, invoices, refunds, the
expiry cron, admin screens, the member journey and `?page=register` — guest
checkout is an *additional* way in, not a replacement.

### Design decisions in force

D3′, D4′ and D7′ replace the original D3, D4 and D7 after the product discussion
and the industry review (§16). D5 and D8 were refined during the source review
(§15). Their shared intent — "behave like free registration" — is unchanged.

| ID | Decision | Detail |
|---|---|---|
| D1 | Guest = visitor without an account; checkout creates one | §1 |
| D2 | Account created at order placement (`pending` order), atomically with it; survives an expired or cancelled order | §1, §7, §8 |
| D3′ | Credentials stay in checkout but with minimal friction: username auto-suggested (visible, editable), **one** password field with show/hide, no confirm field, no rule beyond 8 characters. Deferring the password to a step after placement is documented as the alternative, held until SMS verification exists | §6, §16 |
| D4′ | Sponsor: referral cookie → read-only "Referred by @x"; otherwise the primary admin (`User::primaryAdminId()`), **no field shown**; an optional "I was referred by someone" link reveals one. No `guest_default_sponsor` setting | §2, §6 |
| D5 | Placement = the free-registration referral rule: under the sponsor's next free slot **only** when the sponsor came from a referral link, is a valid binary upline and has a free slot; otherwise **unplaced** until activation. No upline/position fields | §7 |
| D6 | Manual payment methods only; `ewallet` hidden and rejected server-side | §6 |
| D7′ | Gate in two tiers: shop and cart need `shop_enabled` + `guest_shop_enabled`; the account-creating checkout additionally needs `guest_checkout_enabled`, `free_registration_enabled` and a free seat. When checkout is closed the button becomes "Join to continue" → register, cart preserved | §3, §7, §9 |
| D8 | Deviations from member checkout: mobile number required and validated; address always saved (after commit, best effort); account-creation notice | §6, §7 |
| D9 | Returning members: "Log in & continue" merges the guest cart; signed-in users never see guest pages | §9 |
| D10 | Scope: guest front half in full; order page → `completed` by reference to `buyer_workflow.md` | §8 |
| D11 | Parity scope: same product grid, cart drawer, cart page and checkout look and behaviour, from shared templates; no e-wallet, saved addresses, wallet chrome or sidebar | §4 |
| D12 | Architecture: a separate thin guest controller and `store*` routes over shared templates; member routes untouched | §4, §13 |
| D13 | Where it lives: a dedicated `?page=store` in the member-style look, linked from the landing page; a featured-products strip on the landing page can come later | §2, §4 |
| D14 | Field budget: one Recipient block (name and mobile) feeds billing and shipping; about nine visible inputs; mobile stays required | §6 |
| D15 | Attribution: last click wins, 30-day cookie, referral sponsor read-only; a first-click lock is the alternative | §2 |

---

## 2. Entry and sponsor capture

Entry points:

- **Landing page** (`frontend/index.php`). `.htaccess` serves it at the root URL
  (any request without a `page=` parameter is rewritten to it) and it is
  deliberately standalone: it loads `config/db.php`, `core/helpers.php` and
  `models/Package.php`, but not `Auth`, and never starts a session. A **Shop**
  entry *(new)* is therefore just a link — `<?= $base ?>/?page=store`, beside the
  existing `?page=login` and `?page=register` links
  (`frontend/index.php:1773-1774`) — rendered only while `guest_shop_open()`
  *(new helper in `core/helpers.php`, §3)* is true. The existing "Partner / Join"
  buttons and `?page=register` are untouched.
- **The guest shell's own links.** Its topbar carries **Log in** (`?page=login`)
  and **Become a partner** (`?page=register`, with `&ref=1&sponsor=<cookie sponsor>`
  appended when there is one, as for "Join to continue" in §9) beside the cart
  button (§4).
- **Shopper referral links** *(new)*: `?page=store&sponsor=<username>`. Members
  copy their existing register link from `views/member/genealogy.php:267`
  (`?page=register&sponsor=<username>&ref=1`); a **Shop link** *(new)* is added
  beside it. Optionally `showRegister` also records the sponsor cookie when it
  sees `sponsor=`, so a referred visitor who wanders into the store keeps their
  sponsor.

Sponsor capture (D4′, D15):

| Aspect | Detail |
|---|---|
| Capture | Any `store*` request carrying `sponsor=<username>` stores the value in cookie `store_sponsor` *(new)* — only if it passes `is_valid_username()` **and** names an existing user; anything else is ignored. httponly, SameSite=Lax, `secure` when `APP_ENV === 'production'`, path = the path component of `APP_URL`, 30 days. It is set only when a visitor arrives through a referral link; everyone else has no sponsor cookie |
| Attribution (D15) | **Last click wins**: a later referral link overwrites the cookie. That matches the register page, where each referral link anchors the form, and the affiliate-industry default (§16). The alternative is a first-click lock that keeps the original sponsor until the cookie expires; since sponsorship here is permanent, that is a policy choice for the owner |
| Trust | The cookie is **untrusted input — only a prefill**. It is re-validated (`User::findByUsername`) at checkout render and again inside the place-order transaction. A name that no longer exists is dropped and the guest is treated as having no referral |
| Referral sponsor | Shown as "Referred by @name", **read-only** — the register form likewise locks the field in referral mode (`$lockSponsor`, `views/auth/register.php:24`). No input is posted for it; the server reads the cookie |
| No referral | The sponsor is the primary admin, `User::primaryAdminId()` (`models/User.php:618`: the first admin by id; `isPrimaryAdmin()` is literally id 1, `:631`). It is resolved by **id**, so renaming the admin account cannot break it. **No sponsor field is shown.** A small "I was referred by someone" link reveals a sponsor field for a guest who knows a username |
| Typed sponsor | A sponsor the guest types must exist (`User::findByUsername`) and is recorded as the sponsor, but — as on the plain register page — it does **not** place the account in the binary tree (D5) |
| Sponsor rule | The sponsor must exist ("Sponsor username not found."). That is the **only** sponsor rule in free registration today — no status or package check (`AuthController.php:348`) |
| Cookie notice | The cart cookie (§5) is the kind of cookie that remembers a basket and is normally treated as strictly necessary; the sponsor cookie exists for attribution, so disclose it in the privacy notice (§11) |

---

## 3. Availability gates (D7′)

Order of checks on **every** `store*` request:

1. **Signed in?** → redirect to the member equivalent, merging any leftover guest
   cart first (§9). Guest pages are never shown to a signed-in user.
2. **Tier-1 gate** — below.
3. The route's own logic; the checkout routes also apply the **tier-2 gate**.

`maintenance_mode = 1` needs nothing here: `index.php:52` already serves the 503
page to non-admins for every `?page=` route, `store*` included.

The gate has two tiers so that the engagement feature — browse and fill a cart —
never depends on being able to create an account:

| Tier | Covers | Opens when | When closed |
|---|---|---|---|
| 1 — shop and cart, `guest_shop_open()` *(new)* | `store`, `store_add_to_cart`, `store_cart`, `store_update_cart_item`, `store_remove_cart_item` | `shop_enabled = 1` (BW §3) **and** `guest_shop_enabled = 1` *(new, default `0`)* | shop off → "The shop is currently closed." rendered in place (the member-side `guardShop()` flashes and bounces to the dashboard, `MemberController.php:1209`, but guests have no dashboard). `guest_shop_enabled` off → `store*` redirects to the landing page and the landing **Shop** entry is hidden |
| 2 — account-creating checkout, `guest_checkout_open()` *(new)* | `store_checkout`, `store_place_order` | tier 1, **and** `guest_checkout_enabled = 1` *(new, default `0`)*, `free_registration_enabled = 1` (BW §1) and `isSeatLimitReached()` false (`core/helpers.php:165`) | browsing, add-to-cart and the cart keep working. The cart's checkout button reads **"Join to continue"** and goes to `?page=register` (the cart merges after registration, §9). If the cause is a full house, the button is replaced by "Registration is currently closed" with a login link, because `index.php:254-257` blocks `register` with a 403 when seats run out |

Two source facts shape the seat check:

- `seatsRemaining()` counts `users WHERE role = 'member'` against
  `settings.seat_limit`, read straight from the DB (`core/helpers.php:155-167`).
  **Every guest account consumes a seat**, placed or not.
- `index.php:254-257` blocks only `register`, `do_register` and `validate_code`
  when seats run out (a 403 page). `store*` routes are not in that list, so they
  run their own check.

Both helpers return a verdict plus a reason code (`open`, `shop_closed`,
`disabled`, `checkout_disabled`, `registration_closed`, `seats_full`) for
messaging. They use only `setting()` and `isSeatLimitReached()`, so the
standalone landing page can call `guest_shop_open()` too. The gates are evaluated
three times:

- **Storefront / landing render** — decides what to show.
- **Cart and checkout render** — the cart swaps its checkout button for "Join to
  continue" when tier 2 is closed, and the checkout page itself blocks early with
  that message instead of letting the guest fill in the form.
- **Place order** — authoritative; tier 2 is re-checked **inside the
  transaction** (§7), because the last seat can be taken between render and
  submit and settings can flip mid-session. Forged POSTs while closed are
  refused: no user, no order.

Closing a gate never destroys guest carts; they wait until their TTL (§5).
Salesforce's commerce settings expose the same split — separate switches for
guest browsing, guest cart and guest checkout (§16).

---

## 4. Guest storefront — shared templates (D11–D13)

A guest sees the same shop a member sees, served from the same templates, at
`?page=store`:

| Screen | Template | Member-specific code in it | For the guest |
|---|---|---|---|
| Product grid | `views/member/shop.php` (159 lines) | about 5 lines: layout partials, route names, the cart lookup | same template, guest shell |
| Cart drawer | `views/partials/cart_offcanvas.php` (289 lines) | about 13: an early return when nobody is signed in (`:8`), `Cart::getActive(Auth::id())`, route names | same partial, driven by the shop context |
| Cart page | `views/member/cart.php` (225 lines) | about 7: layout partials and route names | same template, guest shell |
| Checkout | `views/member/checkout.php` (393 lines) | about 24: `Auth::user()`, saved addresses, the e-wallet block | **guest variant** built from shared partials (§6) |
| Order page | `views/member/shop_order.php` | — | the same page after sign-in, plus a one-time welcome card (§8) |

*(The counts are a coarse grep, not an audit.)* The only real member chrome is
the sidebar and topbar — wallet, avatar, navigation.

**Mechanism.** A small context helper pair *(new, `core/helpers.php`)*:
`shop_ctx()` returns `member` or `guest`, and `shop_link($name)` resolves a route
name for that context — `add_to_cart`, `cart`, `checkout`, … for members and
`store_add_to_cart`, `store_cart`, `store_checkout`, … for guests. The shared
templates call `link_to(shop_link('add_to_cart'))` instead of hard-coding the
member route. The controllers still supply the data (`$products`, `$cartId`,
`$cartCount`), so the templates stay owner-agnostic; the drawer asks the context
for its cart (session member or cookie token). `views/partials/footer.php:13`
includes the drawer on every page, so that is where the context switch lives.
With `shop_ctx() === 'member'` every template must render exactly as it does today
(§17 proves it).

**Guest shell** *(new partials)*: a small topbar — brand, **Log in**, **Become a
partner**, and the cart button copied from `topbar.php:105-110` so it keeps the
`.topbar-cart-badge` hook the drawer script updates — and **no sidebar**.
`.main-content` is offset by `--sidebar-w` in `assets/css/layout.css`, which
already zeroes the offset on narrow screens, so the shell only adds a `no-sidebar`
modifier. The page sets `$pageTitle = 'Shop'` so `head.php` loads `shop.css`.
`head.php` hard-codes `noindex,nofollow`; whether the public storefront should be
indexable is a product call, and the default stays `noindex` until decided.

**What a guest does not get, by design (D11):** e-wallet payment and balance, the
saved-address picker, wallet and avatar chrome, sidebar navigation, order history
(until the account exists).

**Behaviour parity.** The member shop adds a product with a plain `<form
method="post">` and `csrf_field()` (`views/member/shop.php:74-75`); the handler
redirects to `?page=shop&cart=1`, which auto-opens the drawer
(`cart_offcanvas.php:274-280`). A card shows "Already in cart" instead of a
second add, and quantities are edited in the drawer. Stock badges ("Sold out",
"N left", "In stock") and the line "Total is what you pay. No shipping or service
fee." sit on the product, cart and checkout screens. The guest shop keeps all of
it — it is the experience being offered. Guests have a session and a CSRF token
because `index.php:425` seeds `csrf_token()` on every request. A later polish
step upgrades the add to AJAX (no reload, button state change, drawer opens) in
the shared templates, for members and guests alike (§17).

**Why a separate controller (D12).** The member shop handlers call `Auth::id()` and
`Auth::guard('member')` throughout. Opening those routes to guests would put the
live member flow at risk for no visible gain, since shoppers never see route
names. `GuestShopController` *(new)* is a thin sibling: it resolves the cart from
the cookie token, prepares the same view data and renders the shared templates.

**Where it lives (D13).** A dedicated `?page=store`, reached from the landing
page. A featured-products strip with add-to-cart on the landing page itself can
follow once the cookie cart exists.

---

## 5. Guest cart

| Aspect | Detail |
|---|---|
| Owner | A **random token, not a member id**: 32 random bytes → 64 hex chars in cookie `guest_cart` *(new)* — httponly, SameSite=Lax, `secure` in production, path = path of `APP_URL`, lifetime `guest_cart_ttl_days` (default **14**). Stored as `carts.guest_token`; `carts.member_id` stays `NULL` while it is a guest cart; exactly one of the two is set (§10) |
| Created | Lazily, on the first `store_add_to_cart` — browsing creates nothing |
| Logic | Only three `Cart` methods are member-keyed: `getActive`, `getOrCreate`, `itemCountForMember`. Everything else already takes a `$cartId` — `addItem`, `getItems`, `getTotals`, `updateItemQuantity`, `removeItemById`, `validateStock`, `refreshPrices`, `markConverted` (`models/Cart.php`). The guest path adds `getActiveByToken`, `getOrCreateForToken`, `claimFor` and `mergeInto` *(new)* and reuses the rest unchanged, so stock and price rules cannot drift |
| Stock | The cart holds none (BW §4). Reservation happens only when the order is placed (§7) |
| Funnel marker | `carts.guest_started_at` *(new)* is set when a guest cart is created and survives the claim, so guest carts started, converted and abandoned can be counted later (§10) |

Operations mirror BW §4–§5 and `MemberController`'s cart verbs:

- `store_add_to_cart` — `product_id` + `quantity` (min 1), `csrf_verify()`; AJAX
  is detected by `X-Requested-With: XMLHttpRequest` and answers `{ok, count}`, or
  `{ok:false, error}` with HTTP 400; a normal POST flashes and redirects to
  `?page=store&cart=1`. An existing line for the same product is incremented
  (still stock-checked); over-stock makes `Cart::addItem()` throw
  `InvalidArgumentException` ("Insufficient stock. Requested N, only M
  available.") and nothing is written.
- `store_cart` — runs `Cart::validateStock()` and shows stale-price /
  out-of-stock warnings. +/- buttons clamp to the server-rendered `max`;
  `store_update_cart_item` / `store_remove_cart_item` answer JSON for XHR
  (`{ok, subtotal, count}` or `{ok:false,error}` with HTTP 400); the checkout
  button is disabled at 0 items; summary and badges refresh in the browser.
- **Ownership.** The guest equivalent of `requireOwnCart()`
  (`MemberController.php:1329`, defect C3): the cart is **always resolved from
  the cookie token**; a posted `item_id` is paired with that cart's id; the
  client never supplies a cart id. The token is never accepted from a URL or
  body and never logged.
- **Lifetime.** `carts.updated_at` only moves when the `carts` row itself is
  written, not when `cart_items` change — so every guest mutation also runs
  `UPDATE carts SET updated_at = NOW() WHERE id = ?`, making the TTL mean "idle
  time". A cart idle past `guest_cart_ttl_days` is marked `abandoned` and its items
  deleted by a new leg of `cron/shop_expiry.php`; the empty row is purged after 90
  days (§10). A guest who returns with a stale cookie simply gets a new cart. No
  inventory effect — carts hold no stock.
- **Creation cap.** Each new guest cart is a DB row any anonymous client can
  mint; creation is rate limited per IP (§11). Add-to-cart itself carries no
  CAPTCHA: friction there costs conversions, and a cart reserves nothing.
- **Cookies disabled** → no token can persist; add-to-cart answers "Please
  enable cookies to shop."

---

## 6. Guest checkout — `?page=store_checkout`

Server prerequisites (as `MemberController::checkout()`, `:1388`): the tier-2
gate is open (§3; otherwise the guest is sent back to the cart, whose button
reads "Join to continue"); a non-empty active guest cart (else "Your cart is
empty." → `?page=store`); `Cart::refreshPrices()` (catalog price is
authoritative) and `Cart::validateStock()` — a stock problem bounces to
`?page=store_cart` with the first error.

Page, top to bottom:

1. **"Already have an account? Log in & continue"** strip → §9.
2. **Order summary** — items and total, with the member page's line "Total is what
   you pay. No shipping or service fee." Hidden extra costs are a leading cause of
   abandonment (§16), so the line stays.
3. **Delivery** — the **Recipient block** *(new partial)*: full name and mobile
   number. Below it the address picker and street address. The region / province
   / city / barangay picker is already a standalone partial with no `Auth`
   dependency (`views/partials/address_picker.php`; it calls the public PSGC API
   from the browser) and is reused as is.
4. **Your login** *(new)* — username (auto-suggested, editable) and one password
   field with show/hide. Under them, the sponsor line: "Referred by @name" when a
   referral cookie exists, otherwise an "I was referred by someone" link that
   reveals a sponsor field (§2).
5. **Payment method** — four manual methods, none pre-selected, `required`.
6. **Add a note** — collapsed; `notes_member` is optional.
7. Terms checkbox, the account-creation notice, and submit. The single-submit
   guard and terms gate that today live inline in `checkout.php` move into a
   shared partial *(new)*.
8. Hidden `idem` token (`uniqid('', true)`, as the member form) and a hidden
   honeypot field.

Fields posted to `?page=store_place_order`:

| Field | Required | Rule / note |
|---|---|---|
| `payment_method` | yes | one of `gcash`, `maya`, `usdt_trc20`, `usdt_bep20`. `ewallet` is not rendered and is **rejected by the guest handler** — `ShopOrder::PAYMENT_METHODS` still lists it, so the model will not refuse it for us (D6) |
| `shipping_name` | yes | labelled "Full name", reported as "full name". The server copies it to `billing_name`, and it becomes `users.full_name` — the admin member search matches username, full name and email only, so this is what lets support find the account (§12) |
| `shipping_phone` | **yes** (D8) | labelled "Mobile number"; must pass `is_valid_mobile()` (`09XXXXXXXXX` or `+639XXXXXXXXX`, `core/helpers.php:362`). The server copies it to `billing_phone` and `users.mobile` (VARCHAR(20)) |
| `shipping_address` | yes | reported as "delivery address" |
| `shipping_region`, `shipping_province`, `shipping_city`, `shipping_barangay`, `shipping_postal` and the four `shipping_*_code` | no | as member checkout; filled by the PSGC picker |
| `notes_member` | no | |
| `terms` | yes (`=== '1'`) | covers terms `v1` **and** account creation |
| `username` | yes | `strtolower(trim())`, then `is_valid_username()` (3–40 chars, letters/numbers/underscore, starts with a letter) and not already taken (`User::usernameExists`); prefilled by script |
| `password` | yes | at least 8 characters and nothing more; bcrypt cost 12. There is **no** confirm field (D3′) |
| `sponsor_username` | only if revealed | posted only when the guest opened "I was referred by someone"; must exist. Ignored when a referral cookie sponsor exists (the field is not shown then) |
| `idem` | yes | idempotency token |
| `website` (honeypot) | must be empty | any value is treated as a failed attempt with a generic error |
| `billing_name`, `billing_phone` | not posted | derived server-side from the Recipient block (D14) |
| `save_address`, `address_label` | not posted | the address is always saved (D8) |
| upline / position | not posted | resolved server-side (D5) |

Behaviour:

- **Why the credentials stay in checkout (D3′).** The research favours deferring
  account creation to the confirmation step: forced account creation costs about
  one checkout in five, and most sites still ask too early (§16). Two facts about
  this codebase argue for a lighter version of that advice rather than full
  deferral. Login is username + password (`AuthController::doLogin`, `:16`), and
  there is no mailer, SMS, OTP or self-service password reset anywhere — so a
  guest whose password was never set, or never delivered, would be locked out as
  soon as the session lapses. The form therefore takes the minimum credentials as
  cheaply as possible: the username arrives prefilled, **one** password field with
  a show/hide toggle replaces the confirm field, and there are no complexity rules
  (strict rules are themselves a cause of failed sign-ins). The deferred
  alternative — create the account with a random password and gate the order page
  behind a one-field "Choose your password" step — is described in §16 and should
  be revisited once SMS verification exists.
- **Username suggestion** (client-side convenience): once the full name is typed
  and `username` is untouched, suggest a lowercase/underscore form of the name
  (prefixed with a letter if needed, clamped to 3–40), then call the existing
  availability endpoint — `?page=check_username&username=…`, role `any`,
  answering `{available, message}` (`AuthController::ajaxCheckUser`, `:513`).
  The server validates only what is posted.
- **Billing = recipient (D14).** Guests fill one name and one mobile number. The
  server mirrors them into `billing_name` / `billing_phone` for the order snapshot
  and invoices, inverting `placeOrder`, which defaults the *recipient* from the
  billing name. The member form keeps its separate billing and shipping fields.
- **Remembered input.** `shop_checkout_remember_input()` /
  `shop_checkout_old_input()` (`core/helpers.php:587-640`) are **whitelist-based**
  — address fields, notes and `payment_method` only — so a password can never be
  stashed by them. They lack `username` and `sponsor_username`, so guest variants
  `guest_checkout_remember_input()` / `guest_checkout_old_input()` *(new)* extend
  the whitelist and still never include `password`. A guest has no address book,
  so there is no saved-address prefill.
- **Validation failures** write nothing, keep everything typed (except the
  password) and name exactly what is wrong. Shop fields use the member wording —
  "Please fill in the full name, mobile number, delivery address." Account fields
  reuse the registration messages verbatim ("Username must be 3–40 characters,
  letters/numbers/underscore, start with a letter.", "Username is already
  taken.", "Password must be at least 8 characters.", "Sponsor username not
  found."), produced by one shared validator `User::validateNewMember()` *(new)*
  extracted from the common-validation block of `AuthController::doRegister` so
  the two paths cannot drift. The validator takes a flag that skips the
  "Passwords do not match." check for guests.
- **Account-creation notice** *(proposed copy)*, directly above the submit
  button: "Placing this order creates your free account **@username**. You'll use
  it to upload your payment proof, track your order and download invoices." The
  terms label covers both the shop terms and account creation; have the wording
  reviewed against your privacy notice (Data Privacy Act of 2012).
- **Single submit.** The submit button disables after the first click and the
  terms gate keeps it disabled until the box is ticked (the shared JS partial);
  the server re-checks `terms` regardless.
- **Field budget (D14).** Visible inputs: full name, mobile, delivery area
  (picker), street address, postal code, payment method, username (prefilled),
  password, terms — about nine, seven of them the usual delivery-and-payment
  fields, against a research benchmark of 6–8 for a guest flow (§16). Mobile is
  required: one study found about 14% of shoppers decline to give a phone number,
  but couriers and cash-on-delivery need it here and it is the only contact
  channel on the account.

---

## 7. Placing the order — `?page=store_place_order`

Mirrors `MemberController::placeOrder()` (`:1423`) up to `createFromCart`. What is
new is that **registration and the cart claim run inside the same transaction**
as the order.

### 7.1 Pre-transaction checks (no locks, nothing written)

1. `csrf_verify()` — on a mismatch it `die`s with a plain 403 page
   (`core/helpers.php`), existing behaviour; honeypot empty; the per-IP attempt
   limit (§11).
2. **Signed in?** → `redirect('/?page=shop_orders')`; no second order (§9).
3. **Replay lookup** — compute the guest idempotency key
   `sha1('guest|' . cart_token . '|' . idem)` from the cookie token and the posted
   `idem`. This is the guest analogue of the member key
   `sha1(Auth::id() . '|' . cart_id . '|' . idem)`: no uid exists yet, and the cart
   id cannot be used because the claim in 7.2-4 nulls the token. If an order
   already carries this key (`shop_orders.idempotency_key` is `UNIQUE`) → replay
   response (§7.3); nothing is created.
4. Resolve the guest cart from the token; missing or empty → `?page=store_cart`.
5. Tier-2 gate open (§3).
6. Validate input — shop fields as in §6, mirroring `shipping_name` /
   `shipping_phone` into the billing fields; account fields through
   `User::validateNewMember()`; `payment_method` must be one of the four manual
   methods. Then **check** the success caps — `guest_place_success` per IP and
   `guest_place_success_phone` per hashed mobile number (§11). Failure →
   `guest_checkout_remember_input($_POST)` → flash → back to `store_checkout`.
7. **Hash the password now** (`password_hash`, bcrypt cost 12 — a few hundred
   milliseconds) and pass the hash into registration, so the seat lock below is
   never held while hashing.

### 7.2 The transaction

0. `$pdo->beginTransaction()`.
1. **Seat lock and gate re-check.** `SELECT value FROM settings WHERE key_name =
   'seat_limit' FOR UPDATE` (the row is seeded by `install.sql`) serialises
   concurrent guest checkouts at the seat boundary. Then re-run
   `guest_checkout_open()` — its `isSeatLimitReached()` read is the transaction's
   first consistent read, so it sees every committed registration. Closed →
   rollback, "Registration is closed right now". *Note:* `doRegister` and
   `User::register()` check `isSeatLimitReached()` with no lock today, so a guest
   checkout racing a normal registration for the very last seat is not
   serialised; having the shared registration code take the same lock removes
   that.
2. **Cart refresh.** `Cart::refreshPrices($cartId)` then
   `Cart::validateStock($cartId)`. A problem rolls back and returns to
   `store_cart` with the first error (BW §6).
3. **Register**, through the shared registration code. `User::register()`
   (`models/User.php:11`) calls `$pdo->beginTransaction()` itself, and PDO cannot
   nest transactions, so it is changed to **adopt an open transaction** — the
   `$ownsTxn = !$pdo->inTransaction()` pattern that `ShopOrder::createFromCart()`
   and `transition()` already use — and to accept a precomputed `password_hash`
   and `registered_via`. The work it currently does *after* its commit
   (`Commission::processBinaryPlacement()`, which for a pending member increments
   nothing, and the referral bonuses, skipped when pending) moves into
   `User::afterRegister()` *(new)*, which the guest handler runs after the outer
   commit.
   - **Sponsor (D4′):** the referral cookie's sponsor if it still exists
     (`referral = true`); otherwise a sponsor the guest typed, if it exists
     (`referral = false`); otherwise `User::primaryAdminId()` (`referral = false`).
   - **Username:** validated by `User::validateNewMember()`; `users.username` is
     `UNIQUE`, so that constraint is the final authority — a SQLSTATE 23000 on
     insert is reported as "Username is already taken."
   - **Placement (D5):** only when `referral` is true **and**
     `User::isValidBinaryUpline($sponsorId)` (active, and either the primary admin
     or on a pairing package): compute the sponsor-anchored slot with
     `User::findNextBinarySlot($sponsorId)` — the breadth-first search that is a
     *private* method of `AuthController` today (`:207`) and moves to `User` so
     both paths share it. Lock the chosen upline (`SELECT id FROM users WHERE id =
     ? FOR UPDATE`), re-check `User::isSlotFree()`, and recompute a few times if
     another registration won the slot. No referral (including the admin default
     and a typed sponsor), a sponsor who is not a valid binary upline, or a full
     tree → **unplaced**: `binary_parent_id` and `binary_position` stay `NULL`, as
     `doRegister` leaves a guest who has no `ref_link`/upline
     (`AuthController.php:387`). `users` has no unique index on
     `(binary_parent_id, binary_position)` and `register()`'s slot check is an
     unlocked `COUNT(*)`, which is why this step locks and re-checks (§10 suggests
     the index).
   - **Row written exactly as free registration (BW §1):** `status='pending'`,
     `reg_payment_method='pending'`, `package_id=NULL`, `reg_code_id=NULL`,
     `reg_paid_by=NULL`, `sponsor_id`, plus `registered_via='guest_checkout'`;
     profile columns `full_name` ← `shipping_name` and `mobile` ←
     `shipping_phone`.
   - **No commissions, no leg counts** — everything defers to activation.
4. **Claim the cart** — `UPDATE carts SET member_id = :uid, guest_token = NULL,
   updated_at = NOW() WHERE id = :cid AND guest_token = :token AND member_id IS
   NULL AND status = 'active'`. It must match exactly one row (the PDO connection
   uses `CLIENT_FOUND_ROWS`, so `rowCount()` counts matched rows), otherwise
   abort.
5. **Create the order** — `ShopOrder::createFromCart($uid, $cartId, $addr,
   $method, $key)` **unchanged** (`models/ShopOrder.php:687`), with `$key` the
   guest key from 7.1-3. It adopts the open transaction; `Cart::getActive($uid)`
   now returns the claimed cart, so its ownership check passes; it re-prices,
   re-validates stock, inserts the order (`status='pending'`, `payment_deadline =
   NOW() + shop_payment_deadline_hours`, default 24 h, terms `v1`, `order_no` =
   `AL` + yymmdd + zero-padded id), inserts every line `reserved` (**stock is
   now reserved**), marks the cart `converted` and logs the event `null →
   pending` (reason `order_placed`, note "Order placed via <method>", actor
   `customer`).
6. **Commit.**

Any exception rolls back **all** of it: no user, no order, the cart is still a
guest cart, stock untouched. Errors follow `placeOrder`: an
`InvalidArgumentException` or `RuntimeException` shows its (buyer-safe) message,
anything else shows "Could not place the order. Please try again."; either way the
typed input comes back (no password) on `store_checkout`.

### 7.3 Replay and double-submit

- A second request from a browser that is already signed in is caught by 7.1-2:
  redirected, nothing created. The submit button also disables on first click.
- A **duplicate-key outcome** inside the transaction — the username, the cart
  claim matching 0 rows, or the idempotency key — rolls back and re-runs the
  replay lookup. Found → replay response; not found → the genuine error
  (username taken, or cart no longer available). `createFromCart` has its own
  fast path and 23000 handler, but they only protect the order insert; the guest
  handler's lookup runs first so a replay never registers a second account.
- **Replay response**: nothing is created; the guest is sent to `?page=login`
  with "Your order was already placed — sign in as @username to continue." This
  also covers a response lost after commit: the cookie token is still in the
  browser, the cart is already claimed, and the key still finds the order.

### 7.4 After commit

- `User::afterRegister()` (post-commit hooks), then `Auth::login(User::find($newId))`
  — `Auth::login()` takes the **user row**, not an id (`core/Auth.php:112`); it
  regenerates the session id, sets `user_id/user_role/username` and stamps
  `last_login`, exactly as the free-registration auto-login does
  (`AuthController.php:455`).
- **Count the success caps** (`guest_place_success` per IP,
  `guest_place_success_phone` per hashed mobile number — §11).
- **Save the address**, best effort: the member-side `maybeSaveCheckoutAddress()`
  (`MemberController.php:1557`) is private, runs *after* commit, swallows
  failures and is keyed to `$_POST['save_address']`. It is extracted into a shared
  helper; guests always save, and `MemberAddress::create()` makes a member's
  first address the default.
- **Welcome card.** The handler stores `$_SESSION['guest_welcome'] = ['username'
  => …]` for the order page to show once (§8); the flash helper only carries one
  string per key, so a card needs its own session key.
- The `guest_cart` and `store_sponsor` cookies are cleared.
- Flash *(proposed copy)*: "Order placed. Upload your payment proof to continue."
- Redirect to `?page=shop_order&id=<id>` — the order page (BW §8), status
  `pending`, proof form showing. `redirect()` calls `session_write_close()`, so
  login, session key and flash are done before it. **The guest journey ends
  here; the member journey takes over (§8).**
- Should the login somehow fail after commit, redirect to `?page=login` with a
  flash naming the username; account and order are intact.

---

## 8. Handoff — the order page is the confirmation page

After §7 the guest *is* an ordinary `pending` member holding a `pending` order;
account status `pending` is sufficient for the whole journey (BW §1). The order
page (`views/member/shop_order.php`, rendered by `MemberController::shopOrder()`,
`:1251`) doubles as the confirmation page. Its first-visit layout, top to bottom:

1. The order number and status — "Order placed" — as for any member.
2. The payment instructions and proof-upload form already on that page. For a
   manual-payment order this stays the primary action.
3. **"Your login" card** *(new, shown once from `$_SESSION['guest_welcome']`)*:
   "Your account **@username** is ready. Sign in with the password you just chose.
   You can track this order any time under My Orders." Order tracking is the order
   page itself, one tap from the dashboard.
4. The rest of the page as today — timeline, shipments, refunds.

No new upsell is built: the dashboard already shows a "Account Pending Activation"
banner with an **Activate Now** button to `?page=activate`
(`views/member/dashboard.php`), which is the existing path from a pending member
to a package. Everything from here is `buyer_workflow.md`:

| Stage | Where | Guest-specific note |
|---|---|---|
| Order page and actions (`proof` / `cancel` / `confirm`) | BW §8 | unchanged — `canMemberAct()` and the own-order checks apply to the new member |
| `pending` | BW §9.1 | 24 h payment window; the proof form is already on screen after the redirect |
| Proof → `payment_review` → `paid` / `payment_failed` | BW §9.2–§9.5 | unchanged. The first order can never be e-wallet, so there is no auto-verify path |
| `packing` → `ready_to_ship` → `shipped` → `out_for_delivery` | BW §9.6–§9.8 | unchanged; stock is deducted once, at `shipped` (BW §9.7) |
| `delivery_failed` / `returned_to_sender` / `on_hold` | BW §9.9, §9.10, §9.14 | unchanged — reachability matters, which is why the mobile number is mandatory (D8) |
| `delivered` → `completed` | BW §9.11–§9.12 | unchanged: buyer confirms, or the cron auto-completes after `shop_report_window_days` |
| `cancelled` | BW §9.13 | unchanged — and **the account survives** (D2) |
| Refunds | BW §10 | the first order is always manual-pay, so any refund settles out of band, never to an e-wallet |
| Timers | BW §11 | unchanged — `shop_expiry.php` may cancel an unpaid order at its deadline but never touches the account |

Afterwards the buyer is a normal member: later orders go through `?page=shop`
(member cart; e-wallet only once funded) with the address saved at checkout
prefilled. Activation stays optional and never on the buying path; an **unplaced**
account (D5) picks its binary connection at activation, as any unplaced free
signup does.

---

## 9. Returning members and cart continuity (D9)

| Situation | Behaviour |
|---|---|
| Guest with a cart clicks **"Log in & continue"** | `?page=login&next=checkout` — `views/auth/login.php` carries `next` as a hidden field, limited to a whitelist (`checkout`, `cart`). `doLogin` (`AuthController.php:16`) today redirects straight to the admin or member dashboard after `Auth::login($user)`; it is extended to merge the guest cart first and then honour `next` for members (admins still land on the admin dashboard) |
| Guest registers the normal way (`do_register`) | After the existing auto-login (`AuthController.php:455`) the guest cart is merged into the new member's cart; destination unchanged (`?page=dashboard`); the flash adds "Your cart items were kept." This is the package-first path: a prospect who joins with a package finds the cart waiting |
| Checkout closed (tier 2, §3) — the cart's **"Join to continue"** button | `?page=register`, with `&ref=1&sponsor=<cookie sponsor>` appended when a referral cookie exists, so a referred visitor keeps their sponsor. When free registration is off, a referral link degrades to paid registration (code or e-wallet), as it does today. The cart merges after `do_register`, as in the row above. When the cause is a full house the button is replaced by a closed message (§3) |
| Signed-in user opens any `store*` URL | Redirect to the member equivalent — `store`→`shop`, `store_cart`→`cart`, `store_checkout`→`checkout` — after merging any leftover guest cart. Admins and superadmins included: they pass `guard('member')` (BW §2). A POST to `store_place_order` redirects to `?page=shop_orders` instead |
| Suspended account | Login is refused as in BW §2; nothing merges |

Merge rules (`Cart::mergeInto($userId)` *(new)*) — the same default model
commercetools uses (§16):

- Each guest line is added to the member's line for the same product (or creates
  it), **clamped to available stock** by the BW §4 rules (`Product::availableStock`);
  a line that cannot be added at all is dropped with a notice naming the product.
  If the member has no active cart, the guest cart's lines simply become it.
- The guest cart row is deleted (its items cascade) and the `guest_cart` cookie
  cleared. A merge is not an order, so it does not count as a conversion in the
  funnel figures (§10).
- Destination is `?page=checkout` for a clean merge. If the member already had
  items, or any line was clamped or dropped, it is `?page=cart` with a flash
  explaining the merge, so quantities are reviewed before paying.
- Idempotent: a second call finds no guest cart and does nothing.

---

## 10. Settings and data changes

Settings are saved per admin tab through allow-lists in
`AdminController::saveSettings` (`:520-623`): every key must be listed under its
group, checkbox keys are listed in `$checkboxKeys` and clamped integers in
`$hourKeys` (`:596-597`). The three new keys join the `basics` group beside
`shop_enabled`, with fields in `views/admin/settings.php` next to the shop block
(`:68-93`) and seed rows in the settings block of `install.sql` (`:745-751`) and
in `migrate_guest_checkout.sql` *(new)*, the pattern `migrate_shop.sql` follows.

| Key | Default | Type in `saveSettings` | Meaning |
|---|---|---|---|
| `guest_shop_enabled` *(new)* | `0` | checkbox | tier-1 switch (§3): storefront, add-to-cart and cart for guests. Ships **off** |
| `guest_checkout_enabled` *(new)* | `0` | checkbox | tier-2 switch (§3): the account-creating checkout. Ships **off**; turned on after the shop has been live for a while |
| `guest_cart_ttl_days` *(new)* | `14` | integer ≥ 1 (`$hourKeys`) | guest cart and cookie lifetime, and the idle-cleanup threshold |

Existing settings that interact: `shop_enabled` and `free_registration_enabled`
(the gates, §3), `seat_limit`, `shop_payment_deadline_hours` (24) and the rest of
BW §11. There is deliberately **no** default-sponsor setting: the fallback sponsor
is the primary admin, resolved by id (D4′).

Data:

| Where | Change |
|---|---|
| `carts` | make `member_id` nullable; add `guest_token CHAR(64) NULL` (unique) and `guest_started_at DATETIME NULL`. The FK to `users` (`ON DELETE CASCADE`) is unaffected by `NULL` rows. Invariant, enforced in code: an active cart has exactly one of `member_id` / `guest_token`. There is no DB-level "one active cart per member" index today (only `idx_carts_member_status`), so none is added |
| `users` | add `registered_via VARCHAR(20) NOT NULL DEFAULT 'register'`; guest checkout writes `'guest_checkout'`; existing rows take the default; `User::register()`'s `INSERT` gains the column |
| `rate_limits` *(new table)* | `key_name VARCHAR(120) PRIMARY KEY`, `hits INT UNSIGNED`, `window_start INT UNSIGNED` — the IP- and mobile-keyed counters of §11, one atomic upsert per attempt |
| `users` (recommended hardening) | `UNIQUE KEY (binary_parent_id, binary_position)`. MySQL allows many `NULL` pairs, so unplaced members are unaffected; check production for duplicate pairs first. It would make the DB, not application locking, the authority on slots |
| `shop_orders`, `shop_order_items`, `shop_order_events`, invoices, refunds | **no change** — `member_id` stays `NOT NULL`, `idempotency_key` stays `UNIQUE` |
| `cron/shop_expiry.php` | new **leg 3**, between auto-complete and the overdue-hold alert: (a) mark guest carts idle past `guest_cart_ttl_days` as `abandoned` and delete their items; (b) purge `abandoned` guest rows older than 90 days; (c) purge expired `rate_limits` rows. Logged in the script's existing style |
| `reset.php` | the DEV wipe deletes members (`reset.php:57`) and their carts cascade, but guest carts have no member to cascade from — add a delete of `member_id IS NULL` carts |
| `install.sql` | every change above lands here too: `tests/run.php` rebuilds the `*_test` schema from `install.sql`, not from the `migrate_*.sql` files |

**Guest funnel (optional admin widget, no new tables).** Started = carts with
`guest_started_at` in the period; ordered = those with `status = 'converted'`
(claimed by a checkout); expired = `status = 'abandoned'`. Carts merged into an
existing account on sign-in are deleted and not counted. The same
started-versus-ordered ratio is the signal that exposes inventory hoarding (§11).

Admin: an optional "guest checkout" badge on `views/admin/users.php` and
`user_view.php`, derived from `registered_via` — display only.

---

## 11. Abuse controls and security

Open add-to-cart lets any anonymous client write cart rows, and guest checkout
removes the registration hurdle, so bots can mint carts and accounts, burn seats
and reserve stock. Three facts about the existing code decide how this is
controlled:

- **The existing limiter is per session, not per IP.** `rate_limit_check()`,
  `rate_limit_hit()` and `rate_limit_clear()` keep their counters in
  `$_SESSION['rl']` (`core/helpers.php:379-405`; login keys them on the
  username). A client that drops its cookie gets a clean counter on every request,
  so it cannot protect a public endpoint that creates carts and accounts. The
  guest path gets a new DB-backed limiter instead.
- **`check_username` has no throttle at all today**, and the guest checkout makes
  it reachable by anyone.
- **The only client-IP source in the app is `$_SERVER['REMOTE_ADDR']`**
  (`core/Auth.php:76`, `AuthController.php:92`, `ShopOrder.php:850`); there is no
  forwarded-header handling. Behind a CDN or reverse proxy it may be the proxy's
  address, in which case per-IP limits would throttle everyone together — verify
  on the production host before relying on them.

**Where stock is held.** A cart reserves nothing (BW §4), so filling carts cannot
deplete stock. The point where inventory *is* held is order placement: each
pending manual-pay order reserves stock for up to 24 hours (§7.2-5), and a guest
checkout lowers the barrier to creating those. That is the surface to guard —
the pattern OWASP calls "denial of inventory" (§16). Friction therefore goes at
placement (honeypot, IP and mobile caps), never at add-to-cart.

| Control | Detail |
|---|---|
| CSRF | every guest POST, via `csrf_verify()`; guests have a session and a token (`index.php:425`) |
| No CAPTCHA on add-to-cart | it costs conversions and protects nothing, since carts hold no stock. CAPTCHA is the escalation path **at checkout** if the honeypot plus limits are not enough — no provider chosen |
| Honeypot | hidden field on the checkout form must be empty |
| DB-backed limiter *(new)* | `db_rate_limit($key, $max, $windowSeconds)` in `core/helpers.php`, an atomic upsert on `rate_limits`, keyed `bucket:ip` or `bucket:sha1(normalised mobile)`. *Proposed starting points:* `guest_cart_create` 20 / IP / hour; `guest_place_attempt` 10 / IP / 15 min; `guest_place_success` 3 / IP / 24 h and `guest_place_success_phone` 2 / mobile / 24 h (both **counted on commit**, so they cap junk accounts and 24 h stock holds); `check_username` 30 / IP / 15 min. Shared networks (mobile carriers, offices) can trip per-IP caps — start generous and tune from logs. A capped guest sees a generic "Too many orders from this connection or number today. Please try again tomorrow or log in." |
| Hoarding signal | compare guest cart creation and add-to-cart volume with checkouts: a surge in adds with conversion collapsing toward zero is the hoarding signature. The funnel figures of §10 provide it without extra tooling |
| Unpaid accounts | **not** auto-deleted in v1. An unplaced account holds a seat but no binary slot; a placed one holds both, which is structural, so revisit with real data |
| Token hygiene | 256-bit random, httponly cookie only, never in URLs or logs, nulled on claim |
| Passwords | never remembered (the remember-input helpers are whitelists), flashed, logged or placed in URLs; the hash is computed before the transaction and only stored by the registration code |
| Server authority | prices, stock, payment method, gates and sponsor existence are re-checked server-side inside the transaction; hidden UI is never the only guard |
| Ownership | after commit, every order action is protected by the existing own-order checks; guest carts are reachable only by token |
| Redirects | `next` is a whitelist of internal routes — no open redirects |
| Enumeration | the availability check reveals whether a username exists, as at registration today; it is now rate limited |
| Session cookie | `Auth::start()` (`core/Auth.php:20-31`) sets `session.cookie_httponly`, `use_strict_mode`, `cookie_secure` and the `mlm_sess` name, but **nothing calls it** — `index.php:46` calls bare `session_start()`, so those flags depend on `php.ini` and the cookie is the default `PHPSESSID`. Guests now hold sessions before they log in, so wiring `Auth::start()` (or setting the same values before `session_start()`) is recommended with this feature; `Auth::login()` already calls `session_regenerate_id(true)`, which limits fixation |
| Cookies and consent | the cart cookie remembers a basket, which UK/EU guidance normally treats as strictly necessary; the sponsor cookie exists for attribution and is assessed separately, so disclose it in the privacy notice. That guidance is not Philippine law — the Data Privacy Act of 2012 is — so treat this as a checklist for whoever owns your privacy notice, not legal advice |
| Account-creation consent | the notice and terms wording in §6 |

---

## 12. Edge cases and failure matrix

| Scenario | Behaviour |
|---|---|
| Username taken (including a concurrent submit) | rollback; "Username is already taken."; input kept, the password not |
| Password under 8 characters | "Password must be at least 8 characters."; nothing written |
| Typed sponsor does not exist | "Sponsor username not found." at the sponsor field; nothing written |
| Sponsor is suspended or deactivated | still accepted — free registration checks existence only (`AuthController.php:348`); tightening that is a separate change that would apply to both paths |
| No referral | sponsor is the primary admin (D4′); the account is created **unplaced** (D5) |
| Typed sponsor | recorded as the sponsor; the account is created **unplaced** (D5) |
| Referral sponsor is not a valid binary upline, or the sponsor's tree is full | account created **unplaced**; placement is chosen at activation |
| Two guests race for the same free slot | the loser re-computes after the upline lock (§7.2-3); if nothing is free it registers unplaced |
| Two guests race for the last seat | serialised by the seat lock (§7.2-1); the loser sees "Registration is closed right now" |
| Checkout tier closed (flag off, free registration off) with items in the cart | the cart works normally; its button reads "Join to continue" → `?page=register`; the cart merges after registration (§9) |
| Seats full | the cart works; the button is replaced by "Registration is currently closed" with a login link |
| Seat limit reached, or `free_registration_enabled` turned off, between render and submit | rollback; "Registration is closed right now"; cart kept; no order |
| `shop_enabled` turned off mid-checkout | rollback; closed message; cart kept |
| Product sold out or short between cart and submit | rollback; back to `store_cart` with the first error (BW §6) |
| Double-click or refresh | §7.3 — no second account, no second order |
| Browser Back after success | the user is now signed in, so `store*` redirects to the member side; the cart is already converted, so nothing resubmits |
| CSRF mismatch or expired session | the existing plain 403 "Invalid security token. Please go back and try again."; the guest cart survives in its cookie, typed input does not |
| Forged `payment_method=ewallet` | rejected as an invalid method |
| Forged POST while tier 2 is closed | refused; nothing written |
| `maintenance_mode` on | `store*` returns the 503 page to non-admins (`index.php:52`) |
| IP or mobile cap reached | the generic "Too many orders…" message (§11); nothing written; the cart is kept |
| Unpaid order | cron cancels it at the deadline (`payment_expired`); the account stays, placed or unplaced as it was; the guest can sign in and shop from `?page=shop` |
| Guest signs out and returns later | `?page=login` with username and password → `?page=shop_orders` → the order → proof, if the window is still open |
| Forgot username or password | no self-service recovery exists in the app. Support can reset a member's password from the user page (`admin_change_password`, `AdminController.php:201`) and find the account through the admin member search, which matches username, **full name** and email — not mobile. Hence `full_name` ← `shipping_name` (§6), and the "Your login" card shows the username (§8) |
| Same person checks out twice as a guest | two accounts — duplicates are not detected, as in free registration today (BW §1); the mobile cap only slows repeats |
| Guest cart older than the TTL | marked `abandoned` and emptied by cron; a returning visitor gets a new cart |
| `guest_shop_enabled` switched off with live guest carts | carts wait until their TTL; `store*` redirects to the landing page; members unaffected |
| Cookies disabled | no cart can be held; "Please enable cookies to shop" |
| Login fails after commit | redirect to `?page=login`, flash names the username; account and order intact |

---

## 13. Routes and shared helpers

Routes (role `any` for every `store*` route — `index.php:408-423` applies no check
to `any` — so each action performs the signed-in redirect of §3 itself; the route
table to extend is at `index.php:272`):

| Route | Handler | Gate | Purpose |
|---|---|---|---|
| `store` | `GuestShopController::index` *(new)* | tier 1 | public storefront |
| `store_add_to_cart` | `addToCart` *(new)* | tier 1 | add (AJAX/POST) |
| `store_cart`, `store_update_cart_item`, `store_remove_cart_item` | cart actions *(new)* | tier 1 | manage the guest cart |
| `store_checkout` | `checkout` *(new)* | tiers 1 + 2 | Recipient, login, payment form |
| `store_place_order` | `placeOrder` *(new)* | tiers 1 + 2 | atomic register + order, then sign in |
| `check_username` | `AuthController::ajaxCheckUser` *(existing, role `any`)* | — | reused; now rate limited |
| `login` / `do_login` | `AuthController` — **extended** | — | `next` parameter; cart merge on success |
| `do_register` | `AuthController` — **extended** | — | cart merge on success |
| `shop_order`, `shop_submit_proof`, `shop_cancel_order`, `shop_confirm_receipt`, `shop_orders`, `shop_invoice` | *(existing, BW §12)* | — | unchanged — where the guest lands and continues; `shop_order` gains the welcome card |

Shared helpers *(new, `core/helpers.php` unless noted)*:

| Helper | Purpose |
|---|---|
| `shop_ctx()`, `shop_link($name)` | which context a template renders in, and the route name for it (§4) |
| `guest_shop_open()`, `guest_checkout_open()` | the two gate tiers with reason codes (§3) |
| `db_rate_limit($key, $max, $windowSeconds)` | the DB-backed limiter (§11) |
| `normalize_mobile($raw)` | `+639…` → `09…`, digits only, used before hashing for the mobile cap |
| `guest_checkout_old_input()`, `guest_checkout_remember_input($post)` | whitelist-based, never `password` (§6) |
| `save_checkout_address($uid, $addr)` | the shared best-effort address save extracted from `MemberController` (§7.4) |
| `User::validateNewMember()`, `User::afterRegister()`, `User::findNextBinarySlot()` (`models/User.php`) | the shared registration pieces (§6, §7.2) |
| `Cart::getActiveByToken()`, `getOrCreateForToken()`, `claimFor()`, `mergeInto()` (`models/Cart.php`) | the token cart (§5, §9) |

All new names are placeholders.

---

## 14. Happy-path summary

```
landing / referral link (?page=store&sponsor=<username>)
        │  sponsor remembered in a cookie (read-only at checkout)
        ↓
store (member-style shell) → add to cart → cart drawer → store_cart
        │        (token cart, no account — open to everyone)
        │
        ├─ checkout closed? → "Join to continue" → register → cart merges (§9)
        ↓
store_checkout   Recipient (name + mobile) · Your login · manual payment
        │                                  │
        │       "Log in & continue" ───────┴─→ login → merge cart → checkout (§9)
        ↓
store_place_order ── ONE transaction ─────────────────────────────
   gate + seat lock → refresh/validate cart
        → register (pending; sponsor = referral or admin; placed only for referral)
        → claim cart → createFromCart (order pending)
   any failure ⇒ full rollback: no user, no order
        ↓ commit
save address (best effort) → Auth::login → ?page=shop_order&id=…
        ↓                       payment proof + one-time "Your login" card
        ↓                       ── from here: buyer_workflow.md ──
manual:  pending → payment_review ─(admin verifies)→ paid
                          └─(reject)→ payment_failed →(re-upload)→ payment_review
paid → packing → ready_to_ship → shipped → out_for_delivery → delivered
                                                   ↓ (POD; delivery_failed ⇄ OFD)
delivered →(buyer confirms | cron auto-completes)→ completed
```

Terminal states are unchanged: `completed`, `cancelled`. Stock: reserved at
placement → committed at `paid` → deducted once at `shipped` → released on
`cancelled`/`payment_failed`; the guest cart reserves nothing. Account: created
`pending` at placement, placed or unplaced per D5, survives order expiry or
cancellation; activation stays optional and never on the buying path.

---

## 15. Source review results

This spec was first written from `buyer_workflow.md` alone, with six assumptions
flagged for verification. The repository snapshot answered all six; several
answers changed the design.

| Tag | Question | Finding | Effect |
|---|---|---|---|
| V1 | Does `frontend/index.php` share the app's bootstrap and session? | No. `.htaccess` rewrites the root URL to it; it loads `config/db.php`, `core/helpers.php` and `models/Package.php` but not `Auth`, and never starts a session. The app session starts in `index.php:46` with bare `session_start()` | the Shop entry is a plain link; sponsor and cart cookies live in the `store*` routes (§2) |
| V2 | Which `users` columns exist beyond the register form's? | `full_name`, `email`, `mobile`, `address`, `photo` and the payout fields, all nullable, edited on the profile page; `User::register()` sets none of them | guest checkout writes `full_name` and `mobile`; no email field in v1 (§6, §7.2) |
| V3 | Registration internals | `User::register()` opens its own transaction (cannot nest), checks the seat limit without a lock, hashes the password inside the transaction, and checks slots with an unlocked `COUNT(*)` and no unique index. Placement is **conditional** — see below. `findNextBinarySlot` is private to `AuthController`. `users.username` is `UNIQUE` | `register()` adopts an outer transaction; seat lock; slot lock-and-recheck; BFS moves to `User` (§7.2) |
| V4 | Any email/SMS or password reset? | None anywhere. `users.email` is unverified. An admin **can** set a member's password and search members by username, full name and email | recovery is admin-mediated (§12); credentials stay in checkout (D3′) |
| V5 | `carts` schema | `member_id NOT NULL` with a cascading FK, plain `idx_carts_member_status` (no unique), `updated_at ON UPDATE CURRENT_TIMESTAMP`; `cart_items` is `UNIQUE (cart_id, product_id)`. Almost every `Cart` method already takes a `$cartId` | small migration; reuse `Cart` (§5, §10) |
| V6 | Reuse points | `check_username` exists (role `any`, unthrottled); role `any` applies no check; remember-input helpers are whitelists; the PSGC picker is a standalone partial but the delivery markup and submit JS are inline in `checkout.php`; the rate limiter is per session; `Auth::login()` takes a user row | shared partials, new limiter, helper variants (§6, §7, §11) |

Findings that went beyond the six questions:

- **Placement (changed D5).** A guest who registers free is placed in the binary
  tree only when the registration came from a referral link *and* the sponsor is
  a valid binary upline with a free slot (`AuthController.php:387`;
  `User::isValidBinaryUpline`). Everyone else registers **unplaced** and chooses a
  connection at activation. The first draft assumed "sponsor's next free slot"
  unconditionally.
- **Sponsor field (superseded by D4′).** The register form defaults a guest's
  sponsor to `admin` (`views/auth/register.php:29-32`) and makes the field
  read-only in referral mode (`:24`, `:309-313`). The product decision keeps that
  default but hides the field when there is no referral, and shows a referral
  sponsor read-only.
- **Phone (refined D8, D14).** The member checkout has two phone fields,
  `billing_phone` and `shipping_phone`, and requires neither
  (`MemberController.php:1423`). The guest form collapses them into one required
  mobile number.
- **Address save (refined).** The member flow saves the address *after* commit,
  best effort, via a private helper — not inside the transaction.
- **Coupling is light (enables D11–D12).** The member shop and cart templates have
  only a handful of member-specific lines (§4), which is what makes the
  shared-template approach cheap.
- **Rate limiting (new requirement).** The existing limiter is session-based and
  cannot throttle a cookie-less bot (§11).
- **Dead session hardening.** `Auth::start()` is never called (§11).
- **Tests do exist.** `AGENTS.md` says the repo has no automated tests; it does:
  `php tests/run.php` (§17).

Still open — to settle during the build:

1. Is `REMOTE_ADDR` the real client IP on the production host (§11)?
2. Does production hold duplicate `(binary_parent_id, binary_position)` pairs? If
   not, add the unique index (§10).
3. Should the public storefront be indexable by search engines (§4)?
4. Should checkout collect an optional email (unverified, for support lookup)?
5. Should every registration path take the seat lock, not just guest checkout
   (§7.2-1)?
6. Last click or first-click lock for the sponsor cookie (D15)? Revisit if
   referral disputes appear.
7. Revisit the deferred-password alternative once SMS verification exists (§16).
8. Wire `Auth::start()` (§11).

---

## 16. Industry practice alignment

The design was compared with published research and platform documentation. Figures
vary by source and year; several sources are vendor blogs; none is specific to
MLM sponsor assignment, so D15 is a policy call rather than a standard.

| Practice | What the research or documentation says | In this spec |
|---|---|---|
| Guest path first; account timing | Baymard attributes about 19% of checkout drop-outs to forced account creation and advises saving account creation for the confirmation step; most sites still ask earlier. Guest checkout should be the most prominent option and clearly labelled | There is no account-selection step: the checkout *is* the guest form and "Log in & continue" is secondary (§6). Credentials stay in checkout but minimal (D3′) because this codebase has no recovery channel |
| Field count | Baymard's 2024 benchmark: 11.3 fields on average, 18% of users abandoning over complexity; a guest flow can be done in 6–8 | About nine visible inputs, one Recipient block, notes collapsed (D14) |
| Hidden costs | Extra costs are a leading abandonment reason | "No shipping or service fee" stays on product, cart and checkout (§4, §6) |
| Guest cart persistence | Salesforce defaults guest carts to 7 days (maximum 30); Shopify's cart cookie lasts about two weeks and does not reserve stock (quoted figures range from 10 to 30 days) | `guest_cart_ttl_days` defaults to 14; carts hold no stock (§5) |
| Anonymous cart → account | commercetools assigns the anonymous cart straight to a new customer at sign-up, and on sign-in merges its lines into the active cart or promotes it if there is none | claim at checkout (§7.2-4); merge at sign-in and registration (§9) |
| Separate guest switches | Salesforce exposes separate settings for guest browsing, guest cart, guest checkout and keeping the cart on login | the two gate tiers (D7′, §3) |
| Add-to-cart feedback | A badge change alone is easy to miss; the convention is a mini-cart or slide-out with the item, quantity, subtotal and Checkout / View Cart actions, updated without a reload, alongside a full cart page | v1 keeps the member behaviour (drawer auto-opens, full cart page); AJAX add is a polish step for both audiences (§4, §17) |
| Referral attribution | Affiliate tooling defaults to last click, with the cookie overwritten by each new link; first click is the alternative; windows run 7–90 days | D15: last click, 30 days |
| Inventory abuse | OWASP's "denial of inventory" (OAT-021): bots hold stock through carts or reservations; protect wherever stock is held; CAPTCHAs on add-to-cart hurt conversion | stock is held only at placement, so the caps and honeypot sit there (§11) |
| Cookie classification | UK/EU guidance treats a basket cookie as normally exempt from consent and assesses attribution cookies separately | cart cookie vs sponsor cookie (§2, §11) |
| Local familiarity | Shopee requires a verified phone number to buy and uses SMS codes, one number per account | the mobile number is required now; SMS verification, login by mobile and one account per number are later options, not part of this build |

**Alternative to D3′ — deferred password (documented, not built).** Create the
account at placement with a random, unusable password and a flag such as
`must_set_password`; show a one-field "Choose your password" step on the order page
(gating the rest of it), with a new member-only route that accepts it only while the
flag is set and clears it. This is the research-preferred shape and removes the two
account fields from checkout. It is held back because, with no email or SMS
recovery, a guest who leaves before setting the password cannot get back in without
an admin reset. Revisit it once SMS verification exists.

References:

- Baymard — current state of checkout UX: <https://baymard.com/blog/current-state-of-checkout-ux>
- Baymard — post-checkout UX (account creation at confirmation): <https://baymard.com/blog/post-checkout-ux-best-practices>
- Baymard — reducing cart abandonment: <https://baymard.com/blog/reduce-cart-abandonment>
- Baymard — checkout form fields: <https://baymard.com/blog/checkout-flow-average-form-fields>
- Baymard — from 16 form fields to 8: <https://baymard.com/blog/checkout-optimization-from-16-fields-to-8>
- Shopify Enterprise — faster checkout (phone-number figure): <https://shopify.co.nz/enterprise/faster-checkout-process>
- PageFly — cart page vs drawer vs mini cart: <https://pagefly.io/blogs/shopify/shopify-cart-page>
- scandiweb — add-to-cart best practices: <https://scandiweb.com/blog/add-to-cart-best-practices-in-2022-nudging-prospects-to-buy/>
- Fireart — shopping cart design: <https://fireart.studio/blog/best-practices-for-shopping-cart-page-design/>
- Salesforce — WebStoreTemplate (guest cart settings): <https://developer.salesforce.com/docs/atlas.en-us.254.0.api_tooling.meta/api_tooling/tooling_api_objects_webstoretemplate.htm>
- commercetools — cart merge strategies: <https://docs.commercetools.com/learning-implement-carts-and-shopping-lists/manage-signups-and-signins/cart-merge-strategies>
- commercetools — migrating anonymous resources: <https://docs.commercetools.com/learning-implement-carts-and-shopping-lists/manage-signups-and-signins/migrate-anonymous-resources-to-authenticated-accounts>
- Klaviyo community — Shopify cart lifetime: <https://community.klaviyo.com/questions/3225>
- Dub — attribution models: <https://dub.co/docs/concepts/attribution>
- PostAffiliatePro — first vs last touch: <https://cdn.qualityunit.com/faq/first-last-affiliate-attribution-tracking>
- OWASP — OAT-021 Denial of Inventory: <https://owasp.org/www-project-automated-threats-to-web-applications/assets/oats/EN/OAT-021_Denial_of_Inventory.html>
- Impart Security — OAT-021 in practice: <https://securityboulevard.com/2026/04/impart-security-blog-2026-04-16-200000/>
- Boyes Turner — summary of ICO cookie guidance: <https://www.boyesturner.com/article/guidance-on-the-use-of-cookies>
- Shopee Help Centre — phone verification: <https://help.shopee.sg/portal/4/article/76621-[My-Account]-How-do-I-add/verify-my-phone-number>
- Ginee — Shopee Philippines sign-up and OTP: <https://ginee.com/ph/insights/how-to-change-phone-number-in-shopee>

---

## 17. Code touch list, tests and delivery order

**New**

| File | Purpose |
|---|---|
| `controllers/GuestShopController.php` | the seven `store*` actions, token-cart resolution and the tier gates |
| `views/store/checkout.php` | the guest checkout page, built from shared partials plus the Recipient and "Your login" blocks |
| `views/partials/guest_topbar.php` (and a guest layout wrapper) | the no-sidebar shell: brand, Log in, Become a partner, cart button |
| `views/partials/checkout_summary.php`, `checkout_payment.php`, `checkout_submit_js.php` | extracted from `views/member/checkout.php`, which switches to them |
| `migrate_guest_checkout.sql` | carts, users, `rate_limits`, settings |
| `tests/GuestCheckoutTest.php` | the suite below |

**Modified**

| File | Change |
|---|---|
| `views/member/shop.php`, `cart.php`, `views/partials/cart_offcanvas.php`, `footer.php` | route names via `shop_link()`; the drawer loads its cart from the shop context instead of returning early for guests. Member output must stay identical |
| `index.php` | seven routes in `$routes` (`:272`), role `any` |
| `models/User.php` | `register()` adopts an open transaction and accepts `password_hash` / `registered_via`; new `afterRegister()`, `validateNewMember()`; `findNextBinarySlot()` moved in from `AuthController` |
| `controllers/AuthController.php` | `doRegister` uses the shared validator and moved BFS, and merges the cart; `doLogin` merges the cart and honours `next`; optional sponsor cookie in `showRegister` |
| `models/Cart.php` | `getActiveByToken`, `getOrCreateForToken`, `claimFor`, `mergeInto`, idle cleanup |
| `core/helpers.php` | the helpers of §13 |
| `controllers/MemberController.php` | `maybeSaveCheckoutAddress()` delegates to the shared helper |
| `controllers/AdminController.php`, `views/admin/settings.php` | the three settings; optional badge and funnel widget in the admin views |
| `cron/shop_expiry.php` | leg 3 (idle guest carts, expired `rate_limits`) |
| `install.sql`, `reset.php` | schema and seeds; also wipe guest carts |
| `frontend/index.php`, `views/member/genealogy.php` | Shop links |
| `views/auth/login.php` | hidden `next` field |
| `views/member/shop_order.php` | the one-time "Your login" card |
| `assets/css/layout.css` | the `no-sidebar` modifier |

**Not modified:** `core/Commission.php` — `tests/predeploy.php` diffs it against
the baseline tag and fails the deploy gate on any drift (`:97`) — and
`models/ShopOrder.php`; `createFromCart()` is reused as is.

**Delivery order**

1. **Reuse refactor, no behaviour change.** `shop_ctx()` / `shop_link()`; make the
   shared member templates and the drawer context-aware; extract the checkout
   partials. Prove the member pages are unchanged with `ViewRenderTest` plus a
   before/after render diff.
2. **Data and cart layer.** Migration and `install.sql`; `Cart` token methods;
   `db_rate_limit()`; cron leg 3; the three settings, both flags off.
3. **Milestone 1 — guest shop and cart (no accounts created).**
   `GuestShopController`, guest shell, drawer parity, landing Shop link, sponsor
   cookie, cart-creation cap. The cart's button reads "Join to continue". This
   delivers the engagement feature on its own; enable `guest_shop_enabled`.
4. **Milestone 2 — account-creating checkout.** The `User::register()` refactor,
   `validateNewMember()`, `findNextBinarySlot()` move; `store_checkout` and
   `store_place_order`; welcome card; IP and mobile caps; enable
   `guest_checkout_enabled`.
5. **Continuity and hardening.** Login `next` and cart merge, merge after normal
   `do_register`, Shop link in the genealogy page, honeypot, wire `Auth::start()`,
   admin settings UI, `reset.php`, optional badge and funnel widget.
6. **Polish.** AJAX add (no reload, button state, drawer opens) in the shared
   templates; sponsor-cookie notice in the privacy notice. Later, outside this
   build: SMS verification → login by mobile, one account per number, recovery,
   then revisit the deferred password.
7. **Tests and rollout.** Ship with both flags off; enable the shop first, then
   checkout.
8. **Reconcile** this document against the finished code.

**Tests to add** (run with `php tests/run.php GuestCheckout`; the harness rebuilds
a `*_test` schema from `install.sql`):

- parity: `shop.php`, `cart.php` and the drawer render without warnings in both
  contexts, and member output is unchanged;
- the two-tier gate matrix: shop off, `guest_shop_enabled` off, checkout flag off,
  free registration off, seats full — each with the right message or "Join to
  continue";
- atomicity: a failure after the user insert leaves no user, no order, and the
  cart still a guest cart;
- double submit and replay (incl. a lost response); concurrent same username;
- last seat; last stock unit;
- forged `ewallet`; forged POST while tier 2 is closed;
- cart-token isolation: guest A cannot read or touch guest B's cart;
- sponsor and placement matrix: referral + binary sponsor → placed; no referral →
  admin sponsor, unplaced; typed sponsor → unplaced; non-binary sponsor →
  unplaced; full tree → unplaced;
- merge clamping to available stock; merge idempotency; "Join to continue" then
  `do_register` merges the cart;
- no password in session, flash or remembered input;
- limiter windows without a session cookie: per IP and per hashed mobile;
- the welcome card is shown once.

The existing `TemplateLintTest` scans every file under `views/` automatically
(no `e()` inside inline event handlers — use `js()`; no file-scope functions in
views), so the new views are covered the moment they exist; add them to
`ViewRenderTest` as well, and keep `SchemaDriftTest` green by updating
`install.sql` with every column.
