/**
 * Executes the real app.js against a stub DOM and asserts that showConfirm()
 * produces a usable modal.
 *
 * Why this exists: every other check passed while the Delete button silently
 * did nothing, because the failure was a JavaScript PARSE error in the page's
 * own inline script. Nothing about the markup, the routes or the model was
 * wrong, so nothing that inspects source could see it. Only executing the code
 * showed that no listener was ever attached.
 *
 * Skipped, not failed, when node is unavailable — the PHP suite must not
 * depend on a JS runtime being installed.
 *
 * @file   tests/js/showconfirm.test.js
 */

const fs = require("fs");
const path = require("path");

const APP_JS = path.join(__dirname, "..", "..", "assets", "js", "app.js");

const inserted = [];
const mk = () => ({
  addEventListener() {}, removeEventListener() {},
  style: {}, dataset: {}, textContent: "",
  classList: { add() {}, remove() {}, contains: () => false },
  // showToast() writes into whatever getElementById hands back, and appends
  // the toast to it — so the stub element needs to accept markup too.
  insertAdjacentHTML(_pos, html) { inserted.push(html); },
});

global.document = {
  body: { insertAdjacentHTML: (_pos, html) => inserted.push(html) },
  getElementById: () => mk(),
  getElementsByClassName: () => [mk()],
  addEventListener() {},
  createElement: () => mk(),
  querySelector: () => mk(),
  querySelectorAll: () => [],
};
global.window = { addEventListener() {} };
global.bootstrap = {
  Modal: class { constructor(el) { this.el = el; } show() {} hide() {} dispose() {} },
  Toast: class { constructor(el) { this.el = el; } show() {} },
};

let failures = 0;
const ok = (cond, what) => {
  if (!cond) { failures++; console.log("  FAIL " + what); }
  else { console.log("  PASS " + what); }
};

eval(fs.readFileSync(APP_JS, "utf8"));

// 1. The delete modal is small and carries its message.
inserted.length = 0;
showConfirm({
  size: "sm", title: "Delete QA Widget?",
  message: "This permanently removes the product from the catalog.",
  confirmText: "Delete", confirmClass: "btn-danger",
});
let html = inserted[0] || "";
ok(html.length > 0, "showConfirm inserts a modal into the DOM");
ok(html.includes("modal-sm"), "size:'sm' produces the modal-sm class");
ok(html.includes("Delete QA Widget?"), "the title is rendered");
ok(html.includes("permanently removes"), "the message is rendered");
ok(html.includes("btn-danger"), "the confirm button keeps its class");

// 2. Existing callers are unaffected: no size means today's width.
inserted.length = 0;
showConfirm({ title: "Confirm", message: "Are you sure?" });
html = inserted[0] || "";
const dialogClass = (html.match(/class="modal-dialog[^"]*"/) || [""])[0];
ok(!dialogClass.includes("modal-sm"), "no size option leaves the width unchanged");
ok(!/\bsm\b\s/.test(dialogClass), "no stray size class is added by default");

// 3. ARIA references must resolve to real ids in the same markup.
const t = /aria-labelledby="([^"]+)"/.exec(html);
const d = /aria-describedby="([^"]+)"/.exec(html);
ok(!!t && html.includes(`id="${t[1]}"`), "aria-labelledby points at a real element");
ok(!!d && html.includes(`id="${d[1]}"`), "aria-describedby points at a real element");

// 4. `size` is allow-listed: it reaches a class attribute, so it must not be
//    able to break out of one.
inserted.length = 0;
showConfirm({ size: 'x" onload="alert(1)', title: "T", message: "M" });
html = inserted[0] || "";
ok(!/onload=/.test(html), "a hostile size value cannot inject an attribute");

// 5. The bug that started all this.
let parseFailed = false;
try { new Function('var CSRF = &quot;x&quot;;'); } catch (e) { parseFailed = true; }
ok(parseFailed, "js() inside a <script> block is a parse error, as documented");

// ── showToast: geometry contract ───────────────────────────────────────────
// The close button is positioned by CSS, not by the markup, so the assertions
// that matter are that the CSS exists and that the markup no longer fights it.
//
// Comments are stripped first. The replacement block explains what the old
// values were, and asserting against the raw text means the rule passes or
// fails on its own documentation — the recurring trap in this repo, now the
// fifth time it has come up.
const cssRaw = fs.readFileSync(path.join(__dirname, "..", "..", "assets", "css", "main.css"), "utf8");
const css = cssRaw.replace(/\/\*[\s\S]*?\*\//g, "");

ok(/#toastContainer\s*\{[^}]*width:\s*320px/s.test(css), "toast container has a fixed 320px width");
ok(/#toastContainer\s*\{[^}]*max-width/s.test(css), "toast container is clamped on narrow viewports");
ok(
  /#toastContainer\s+\.btn-close\s*\{[^}]*position:\s*absolute[^}]*top:\s*\.5rem[^}]*right:\s*\.5rem/s.test(css),
  "the close button is pinned to the top-right corner"
);
ok(
  /#toastContainer\s+\.toast\s*\{[^}]*position:\s*relative/s.test(css),
  "the toast is the positioning context for that button"
);
ok(
  /#toastContainer\s+\.toast-body\s*\{[^}]*padding-right/s.test(css),
  "the message body reserves room so text does not run under the button"
);
ok(!/min-width:\s*280px/.test(css), "the old unbounded min-width is gone");

inserted.length = 0;
showToast("Insufficient stock. Requested 3, only 2 available.", "error");
html = inserted[0] || "";
ok(html.length > 0, "showToast inserts a toast");

// Look at the BUTTON TAG only. A whole-string search matched the HTML comment
// that explains why the spacing utility was removed — the assertion was
// reading the explanation instead of the code.
const closeTag = (html.match(/<button[^>]*btn-close[^>]*>/) || [""])[0];
ok(closeTag.length > 0, "the toast has a close button");
ok(
  !/\bm-[0-9]/.test((closeTag.match(/class="([^"]*)"/) || ["", ""])[1]),
  "the close button carries no spacing utility",
  closeTag
);
ok(/class="btn-close btn-close-white flex-shrink-0"/.test(html), "the close button keeps its base classes");
ok(html.includes("Insufficient stock."), "the message text is present");
ok(!html.includes("<script"), "the message is escaped");

// ── the admin product page script ─────────────────────────────────────────
// Its handler is an inline <script> in the view, so it is checked here by
// source rather than executed: it depends on fetch and on the CSRF token the
// page renders, neither of which exist in this stub. The parts that decide
// behaviour are the guards and the copy, and those are greppable.
const view = fs.readFileSync(
  path.join(__dirname, "..", "..", "views", "admin", "shop_products.php"),
  "utf8"
);
ok(!/js-delete-product-form/.test(view), "the delete-only handler name is fully retired");
ok(/\.js-product-action-form/.test(view), "the delete form handler is present");
ok(
  /if \(action === 'delete' && form\.dataset\.blocked === '1'\)/.test(view),
  "the blocked short-circuit applies to delete"
);
ok(!/data-action="status"/.test(view), "the row-level Disable/Enable button and its status handler are gone");
ok(!/function applyStatus\(/.test(view), "no status-flip updater is left behind");
ok(
  /var CSRF = '<\?= e\(csrf_token\(\)\) \?>'/.test(view),
  "the CSRF token is emitted with e() inside a script block, not js()"
);

console.log(failures === 0 ? "\nJS OK" : `\nJS FAILED (${failures})`);
process.exit(failures === 0 ? 0 : 1);
