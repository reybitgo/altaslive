// ── Mobile menu toggle ─────────────────────────────────────
function toggleMobileMenu() {
  const menu = document.getElementById("mobileMenu");
  const isOpen = menu.classList.contains("open");
  if (isOpen) {
    menu.classList.remove("open");
    menu.setAttribute("aria-hidden", "true");
    document.body.style.overflow = "";
  } else {
    menu.classList.add("open");
    menu.setAttribute("aria-hidden", "false");
    document.body.style.overflow = "hidden";
  }
}
document.addEventListener("click", function (e) {
  const menu = document.getElementById("mobileMenu");
  const toggle = document.querySelector(".nav-mobile-toggle");
  if (
    menu.classList.contains("open") &&
    !menu.contains(e.target) &&
    !toggle.contains(e.target)
  ) {
    toggleMobileMenu();
  }
});

// ── Scroll-triggered fade-up ───────────────────────────────
const observer = new IntersectionObserver(
  (entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        entry.target.classList.add("visible");
        observer.unobserve(entry.target);
      }
    });
  },
  { threshold: 0.1, rootMargin: "0px 0px -50px 0px" },
);
document.querySelectorAll(".fade-up").forEach((el) => observer.observe(el));

// ── Nav background/shadow on scroll ───────────────────────
const nav = document.querySelector("nav");
window.addEventListener("scroll", () => {
  if (window.scrollY > 10) nav.style.boxShadow = "0 4px 20px rgba(0,0,0,.3)";
  else nav.style.boxShadow = "none";
});

// ── Smooth anchor offset for fixed nav ────────────────────
document.querySelectorAll('a[href^="#"]').forEach((a) => {
  a.addEventListener("click", (e) => {
    const href = a.getAttribute("href");
    if (href === "#" || href === "#hero") return;
    const target = document.querySelector(href);
    if (!target) return;
    e.preventDefault();
    const offset = target.getBoundingClientRect().top + window.scrollY - 80;
    window.scrollTo({ top: offset, behavior: "smooth" });
  });
});

// ── Back to Top Logic ───────────────────────────────────────
const backToTopBtn = document.getElementById("backToTop");
window.addEventListener("scroll", () => {
  if (window.scrollY > 500) {
    backToTopBtn.classList.add("show");
  } else {
    backToTopBtn.classList.remove("show");
  }
});
backToTopBtn.addEventListener("click", () => {
  window.scrollTo({ top: 0, behavior: "smooth" });
});

// ── PWA SERVICE WORKER REGISTRATION ────────────────────────
if ("serviceWorker" in navigator) {
  const swCode = `
    const CACHE_NAME = 'altas-farm-v3';
    const urlsToCache = ['.', 'index.html', 'https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;900&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap'];
    self.addEventListener('install', event => {
      event.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(urlsToCache)));
      self.skipWaiting();
    });
    self.addEventListener('fetch', event => {
      if (event.request.mode === 'navigate') {
        event.respondWith(
          fetch(event.request).then(res => {
            const copy = res.clone();
            caches.open(CACHE_NAME).then(cache => cache.put(event.request, copy));
            return res;
          }).catch(() => caches.match(event.request))
        );
      } else {
        event.respondWith(caches.match(event.request).then(response => response || fetch(event.request)));
      }
    });
    self.addEventListener('activate', event => {
      const cacheWhitelist = [CACHE_NAME];
      event.waitUntil(caches.keys().then(cacheNames => Promise.all(cacheNames.map(cacheName => {
        if (cacheWhitelist.indexOf(cacheName) === -1) return caches.delete(cacheName);
      }))));
      self.clients.claim();
    });
  `;
  const blob = new Blob([swCode], { type: "application/javascript" });
  const swUrl = URL.createObjectURL(blob);
  navigator.serviceWorker
    .register(swUrl)
    .then(() => console.log("SW Registered"))
    .catch((err) => console.log("SW Failed", err));
}

/* ── Modal open / close ─────────────────────────────────── */
function openModal(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.add("open");
  document.body.style.overflow = "hidden";
  // focus the close button for accessibility
  setTimeout(() => {
    const btn = el.querySelector(".af-modal-close");
    if (btn) btn.focus();
  }, 50);
}
function closeModal(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.remove("open");
  document.body.style.overflow = "";
}
function closeModalOnBackdrop(e, id) {
  if (e.target === document.getElementById(id)) closeModal(id);
}
// Close on Escape key
document.addEventListener("keydown", function (e) {
  if (e.key === "Escape") {
    document.querySelectorAll(".af-modal-backdrop.open").forEach(function (m) {
      m.classList.remove("open");
    });
    document.body.style.overflow = "";
  }
});

/* ── Package Details modal ─────────────────────────────── */
function fmtMoney(v) {
  return (
    "₱" +
    Number(v).toLocaleString("en-PH", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    })
  );
}

// Build + open the package-details modal for a given package id.
function openPkgDetails(id) {
  const pkg = (window.PKG_DETAILS || {})[id];
  if (!pkg) return;
  const G = window.PKG_GLOBALS || {};

  // ── Unilevel referral breakdown (primary content) ──
  let levelsHtml;
  const levelRows = Object.entries(pkg.levels || {})
    .filter(([, amt]) => Number(amt) > 0)
    .sort((a, b) => Number(a[0]) - Number(b[0]));
  if (pkg.indirect && levelRows.length) {
    const rows = levelRows
      .map(
        ([lv, amt]) =>
          "<tr>" +
          "<td><strong>Level " +
          lv +
          "</strong></td>" +
          '<td class="pkg-dl-amt">' +
          fmtMoney(amt) +
          "</td>" +
          "</tr>",
      )
      .join("");
    levelsHtml =
      "<h3>👥 Unilevel Referral Breakdown</h3>" +
      "<p>Generational bonuses paid through your sponsor chain, on top of your direct referral bonus. Levels with a carry value are shown below.</p>" +
      '<table class="pkg-details-table pkg-dl-breakdown"><thead>' +
      "<tr><th>Level</th><th>Bonus / head</th></tr>" +
      "</thead><tbody>" +
      rows +
      "</tbody></table>";
  } else {
    levelsHtml =
      '<div class="pkg-dl-empty">This package does not include unilevel referral bonuses.</div>';
  }

  // ── Earning settings ──
  const earn = [];
  if (pkg.binary) {
    earn.push([
      "⚖️ Binary Pairing Bonus",
      fmtMoney(pkg.pair_amount) +
        " per left-right pair &middot; capped at " +
        fmtMoney(pkg.pair_cap_amount) +
        "/day (" +
        Number(pkg.pair_cap) +
        " pairs)",
    ]);
  }
  if (Number(pkg.direct_ref) > 0) {
    earn.push([
      "🤝 Direct Referral Bonus",
      fmtMoney(pkg.direct_ref) + " per recruit",
    ]);
  }
  if (pkg.dfi) {
    earn.push([
      "📅 Loyalty Reward",
      fmtMoney(pkg.dfi_amount) + "/day for " + Number(pkg.dfi_days) + " days",
    ]);
  }
  earn.push([
    "🔒 Lifetime Income Cap",
    fmtMoney(pkg.cap_amount) +
      " total &middot; " +
      Number(pkg.cap_mult) +
      "&times; your entry fee",
  ]);
  const earnHtml =
    "<h3>Earning Settings</h3>" +
    '<ul class="pkg-dl-list">' +
    earn
      .map(function (r) {
        return (
          '<li><span class="pkg-dl-lbl">' +
          r[0] +
          '</span><span class="pkg-dl-val">' +
          r[1] +
          "</span></li>"
        );
      })
      .join("") +
    "</ul>";

  // ── Entry & payment settings ──
  const entryRows = [
    ["Entry fee", fmtMoney(pkg.entry) + " <small>(one-time)</small>"],
    ["Minimum payout", G.min_payout != null ? fmtMoney(G.min_payout) : "—"],
    [
      "Reactivation fee",
      Number(pkg.react_fee) > 0 ? fmtMoney(pkg.react_fee) : "—",
    ],
    [
      "Reactivation window",
      Number(pkg.react_days) > 0
        ? Number(pkg.react_days) + " days after capping"
        : "—",
    ],
  ];
  const settleHtml =
    "<h3>Entry &amp; Payment Settings</h3>" +
    '<table class="pkg-details-table pkg-dl-settings"><tbody>' +
    entryRows
      .map(function (r) {
        return (
          "<tr><td>" +
          r[0] +
          '</td><td class="pkg-dl-amt">' +
          r[1] +
          "</td></tr>"
        );
      })
      .join("") +
    "</tbody></table>" +
    '<p class="meta-line">' +
    (G.site_name || "Our") +
    " service fees and caps are reviewed periodically and may be updated by the platform.</p>";

  const sub = document.getElementById("pkg-details-sub");
  if (sub)
    sub.textContent = 'Full breakdown for the "' + pkg.name + '" package';
  const body = document.getElementById("pkgDetailsBody");
  if (body) body.innerHTML = levelsHtml + earnHtml + settleHtml;
  openModal("modal-pkg-details");
}

/* ── FAQ accordion ──────────────────────────────────────── */
function toggleFaq(btn) {
  const item = btn.closest(".faq-item");
  const isOpen = item.classList.contains("open");
  // close all
  document.querySelectorAll(".faq-item.open").forEach(function (i) {
    i.classList.remove("open");
  });
  if (!isOpen) item.classList.add("open");
}

/* ── Live seat counter ──────────────────────────────────── */
(function () {
  const fill = document.getElementById("seatFill");
  const label = document.getElementById("seatCount");
  if (!fill || !label) return;
  const TOTAL = 1000;
  function render(n) {
    fill.style.width = Math.min((n / TOTAL) * 100, 100).toFixed(1) + "%";
    label.textContent = n.toLocaleString() + " / " + TOTAL.toLocaleString();
  }
  fetch("/?api=member_count")
    .then(function (r) {
      return r.ok ? r.json() : Promise.reject();
    })
    .then(function (d) {
      if (typeof d.count === "number") render(d.count);
    })
    .catch(function () {});
})();

/* ── Legalities image viewer ───────────────────────────── */
function openLegalImg(imgEl) {
  if (!imgEl) return;
  var title = document.getElementById("legal-img-title");
  if (title) title.textContent = imgEl.alt || "Certificate";
  var full = document.getElementById("legalImgFull");
  if (full) full.src = imgEl.src;
  openModal("modal-legal-img");
}
document.addEventListener("click", function (e) {
  var box = e.target.closest(".legal-img");
  if (!box) return;
  var img = box.querySelector("img");
  if (img) openLegalImg(img);
});
document.addEventListener("keydown", function (e) {
  if (e.key !== "Enter" && e.key !== " ") return;
  var t = e.target;
  if (t.classList && t.classList.contains("legal-img")) {
    e.preventDefault();
    var img = t.querySelector("img");
    if (img) openLegalImg(img);
  }
});
