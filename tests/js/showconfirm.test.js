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

console.log(failures === 0 ? "\nJS OK" : `\nJS FAILED (${failures})`);
process.exit(failures === 0 ? 0 : 1);
