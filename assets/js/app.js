/**
 * @file   assets/js/app.js
 * @brief  Global JavaScript utilities for the application
 */

/**
 * app.js — Global JS utilities
 * Replaces all native alert() / confirm() with Bootstrap 5 modals/toasts
 */

// ── Toast ────────────────────────────────────────────────────
function showToast(message, type = "success") {
  const icons = { success: "✓", danger: "✕", warning: "⚠", info: "ℹ" };
  const id = "toast_" + Date.now();
  const html = `
    <div id="${id}" class="toast align-items-center text-bg-${type} border-0 mb-2" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="d-flex">
        <div class="toast-body fw-semibold" style="font-size:.875rem;">
          ${icons[type] || "•"} ${message}
        </div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
      </div>
    </div>`;
  let container = document.getElementById("toastContainer");
  if (!container) {
    container = document.createElement("div");
    container.id = "toastContainer";
    document.body.appendChild(container);
  }
  container.insertAdjacentHTML("beforeend", html);
  const el = document.getElementById(id);
  const toast = new bootstrap.Toast(el, { delay: 3500 });
  toast.show();
  el.addEventListener("hidden.bs.toast", () => el.remove());
}

// ── Confirm Modal ────────────────────────────────────────────
// Usage: showConfirm({ title, message, confirmText, confirmClass, onConfirm })
function showConfirm(opts) {
  const id = "confirmModal_" + Date.now();
  const {
    title = "Confirm",
    message = "Are you sure?",
    confirmText = "Confirm",
    confirmClass = "btn-primary",
    onConfirm = null,
    formId = null,
  } = opts;

  const html = `
    <div class="modal fade" id="${id}" tabindex="-1" aria-modal="true" role="dialog">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">${title}</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body" style="font-size:.9rem;line-height:1.65;">${message}</div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn ${confirmClass}" id="${id}_confirm">${confirmText}</button>
          </div>
        </div>
      </div>
    </div>`;

  document.body.insertAdjacentHTML("beforeend", html);
  const el = document.getElementById(id);
  const modal = new bootstrap.Modal(el);
  const btnConf = document.getElementById(id + "_confirm");

  btnConf.addEventListener("click", () => {
    modal.hide();
    if (onConfirm) onConfirm();
    if (formId) {
      const f = document.getElementById(formId);
      if (f) f.submit();
    }
  });

  el.addEventListener("hidden.bs.modal", () => el.remove());
  modal.show();
}

// ── Inline form submit with confirm ──────────────────────────
// Usage: <button onclick="confirmSubmit(this, {title,message,...})">
function confirmSubmit(btn, opts) {
  const form = btn.closest("form");
  showConfirm({ ...opts, onConfirm: () => form.submit() });
}

// ── Sidebar swipe (mobile) ───────────────────────────────────
(function () {
  let startX = 0;
  document.addEventListener(
    "touchstart",
    (e) => {
      startX = e.touches[0].clientX;
    },
    { passive: true },
  );
  document.addEventListener(
    "touchend",
    (e) => {
      const dx = e.changedTouches[0].clientX - startX;
      const offcanvasEl = document.getElementById("mobileSidebar");
      if (!offcanvasEl) return;
      const instance = bootstrap.Offcanvas.getOrCreateInstance(offcanvasEl);
      if (dx > 60 && startX < 50) instance.show();
      if (dx < -60) instance.hide();
    },
    { passive: true },
  );
})();

// ── Copy to clipboard with toast ─────────────────────────────
function copyText(text) {
  navigator.clipboard.writeText(text).then(() => {
    showToast("Copied: " + text, "info");
  });
}
