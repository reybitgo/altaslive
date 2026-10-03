// ── Mobile menu toggle ─────────────────────────────────────
function toggleMobileMenu() {
  const menu = document.getElementById('mobileMenu');
  const isOpen = menu.classList.contains('open');
  if (isOpen) {
    menu.classList.remove('open');
    menu.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  } else {
    menu.classList.add('open');
    menu.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }
}
document.addEventListener('click', function(e) {
  const menu = document.getElementById('mobileMenu');
  const toggle = document.querySelector('.nav-mobile-toggle');
  if (menu.classList.contains('open') && !menu.contains(e.target) && !toggle.contains(e.target)) {
    toggleMobileMenu();
  }
});

// ── Scroll-triggered fade-up ───────────────────────────────
const observer = new IntersectionObserver((entries) => {
  entries.forEach((entry) => {
    if (entry.isIntersecting) {
      entry.target.classList.add('visible');
      observer.unobserve(entry.target);
    }
  });
}, { threshold: 0.1, rootMargin: "0px 0px -50px 0px" });
document.querySelectorAll('.fade-up').forEach(el => observer.observe(el));

// ── Nav background/shadow on scroll ───────────────────────
const nav = document.querySelector('nav');
window.addEventListener('scroll', () => {
  if (window.scrollY > 10) nav.style.boxShadow = '0 4px 20px rgba(0,0,0,.3)';
  else nav.style.boxShadow = 'none';
});

// ── Smooth anchor offset for fixed nav ────────────────────
document.querySelectorAll('a[href^="#"]').forEach(a => {
  a.addEventListener('click', e => {
    const href = a.getAttribute('href');
    if (href === "#" || href === "#hero") return;
    const target = document.querySelector(href);
    if (!target) return;
    e.preventDefault();
    const offset = target.getBoundingClientRect().top + window.scrollY - 80;
    window.scrollTo({ top: offset, behavior: 'smooth' });
  });
});

// ── Back to Top Logic ───────────────────────────────────────
const backToTopBtn = document.getElementById('backToTop');
window.addEventListener('scroll', () => {
  if (window.scrollY > 500) {
    backToTopBtn.classList.add('show');
  } else {
    backToTopBtn.classList.remove('show');
  }
});
backToTopBtn.addEventListener('click', () => {
  window.scrollTo({ top: 0, behavior: 'smooth' });
});

// ── PWA SERVICE WORKER REGISTRATION ────────────────────────
if ('serviceWorker' in navigator) {
  const swCode = `
    const CACHE_NAME = 'altas-farm-v2';
    const urlsToCache = ['.', 'index.html', 'https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;900&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap'];
    self.addEventListener('install', event => {
      event.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(urlsToCache)));
      self.skipWaiting();
    });
    self.addEventListener('fetch', event => {
      event.respondWith(caches.match(event.request).then(response => response || fetch(event.request)));
    });
    self.addEventListener('activate', event => {
      const cacheWhitelist = [CACHE_NAME];
      event.waitUntil(caches.keys().then(cacheNames => Promise.all(cacheNames.map(cacheName => {
        if (cacheWhitelist.indexOf(cacheName) === -1) return caches.delete(cacheName);
      }))));
      self.clients.claim();
    });
  `;
  const blob = new Blob([swCode], { type: 'application/javascript' });
  const swUrl = URL.createObjectURL(blob);
  navigator.serviceWorker.register(swUrl)
    .then(() => console.log('SW Registered'))
    .catch(err => console.log('SW Failed', err));
}

/* ── Modal open / close ─────────────────────────────────── */
  function openModal(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.add('open');
    document.body.style.overflow = 'hidden';
    // focus the close button for accessibility
    setTimeout(() => { const btn = el.querySelector('.af-modal-close'); if (btn) btn.focus(); }, 50);
  }
  function closeModal(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.remove('open');
    document.body.style.overflow = '';
  }
  function closeModalOnBackdrop(e, id) {
    if (e.target === document.getElementById(id)) closeModal(id);
  }
  // Close on Escape key
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.af-modal-backdrop.open').forEach(function(m) {
        m.classList.remove('open');
      });
      document.body.style.overflow = '';
    }
  });

  /* ── FAQ accordion ──────────────────────────────────────── */
  function toggleFaq(btn) {
    const item = btn.closest('.faq-item');
    const isOpen = item.classList.contains('open');
    // close all
    document.querySelectorAll('.faq-item.open').forEach(function(i) { i.classList.remove('open'); });
    if (!isOpen) item.classList.add('open');
  }

  /* ── Live seat counter ──────────────────────────────────── */
  (function() {
    const fill  = document.getElementById('seatFill');
    const label = document.getElementById('seatCount');
    if (!fill || !label) return;
    const TOTAL = 1000;
    function render(n) {
      fill.style.width  = Math.min((n / TOTAL) * 100, 100).toFixed(1) + '%';
      label.textContent = n.toLocaleString() + ' / ' + TOTAL.toLocaleString();
    }
    fetch('/?api=member_count')
      .then(function(r) { return r.ok ? r.json() : Promise.reject(); })
      .then(function(d) { if (typeof d.count === 'number') render(d.count); })
      .catch(function() {});
  })();
