<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Binary MLM Simulator v5</title>
    <link
      href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
      rel="stylesheet"
    />
    <link
      href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
      rel="stylesheet"
    />
    <link
      href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=Syne:wght@400;500;600;700;800&display=swap"
      rel="stylesheet"
    />
    <style>
      :root {
        --bs-body-bg: #0d0f14;
        --bs-body-color: #e2e8f0;
        --surface-1: #131720;
        --surface-2: #1a2030;
        --surface-3: #202840;
        --border-col: rgba(255, 255, 255, 0.07);
        --border-bright: rgba(255, 255, 255, 0.14);
        --accent: #f59e0b;
        --accent-dim: rgba(245, 158, 11, 0.12);
        --accent-border: rgba(245, 158, 11, 0.35);
        --success: #10b981;
        --success-dim: rgba(16, 185, 129, 0.12);
        --success-border: rgba(16, 185, 129, 0.35);
        --danger: #ef4444;
        --danger-dim: rgba(239, 68, 68, 0.12);
        --info: #38bdf8;
        --info-dim: rgba(56, 189, 248, 0.12);
        --purple: #a78bfa;
        --purple-dim: rgba(167, 139, 250, 0.12);
        --purple-border: rgba(167, 139, 250, 0.35);
        --pink: #f472b6;
        --pink-dim: rgba(244, 114, 182, 0.12);
        --pink-border: rgba(244, 114, 182, 0.35);
        --muted: #64748b;
        --font-display: "Syne", sans-serif;
        --font-mono: "IBM Plex Mono", monospace;
      }
      * {
        box-sizing: border-box;
      }
      body {
        background: var(--bs-body-bg);
        color: var(--bs-body-color);
        font-family: var(--font-display);
        min-height: 100vh;
      }

      /* ─── HEADER ─── */
      .app-header {
        background: linear-gradient(135deg, #0d0f14 0%, #131a2e 100%);
        border-bottom: 1px solid var(--border-col);
        padding: 1.5rem 0 1.25rem;
        position: relative;
        overflow: hidden;
      }
      .app-header::before {
        content: "";
        position: absolute;
        inset: 0;
        background: radial-gradient(
          ellipse at 60% 0%,
          rgba(245, 158, 11, 0.08) 0%,
          transparent 70%
        );
        pointer-events: none;
      }
      .app-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: var(--accent-dim);
        border: 1px solid var(--accent-border);
        color: var(--accent);
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 1px;
        text-transform: uppercase;
        padding: 4px 12px;
        border-radius: 20px;
        margin-bottom: 0.75rem;
      }
      .app-title {
        font-size: clamp(1.4rem, 4vw, 2rem);
        font-weight: 800;
        line-height: 1.1;
        margin-bottom: 0.4rem;
        background: linear-gradient(135deg, #fff 0%, #94a3b8 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
      }
      .app-subtitle {
        font-size: 13px;
        color: var(--muted);
        line-height: 1.7;
      }
      .app-subtitle .tag {
        display: inline-block;
        background: var(--surface-2);
        border: 1px solid var(--border-col);
        border-radius: 4px;
        padding: 1px 7px;
        font-size: 11px;
        margin: 2px 1px;
        color: #94a3b8;
      }

      /* ─── COMP PLAN ─── */
      .comp-plan-card {
        background: linear-gradient(135deg, var(--surface-1) 0%, #161d30 100%);
        border: 1px solid var(--border-bright);
        border-top: 3px solid var(--purple);
        border-radius: 14px;
        padding: 1.5rem;
        position: relative;
        overflow: hidden;
      }
      .comp-plan-card::before {
        content: "";
        position: absolute;
        inset: 0;
        background: radial-gradient(
          ellipse at 0% 0%,
          rgba(167, 139, 250, 0.06) 0%,
          transparent 60%
        );
        pointer-events: none;
      }
      .comp-plan-title {
        font-size: 14px;
        font-weight: 700;
        color: var(--purple);
        letter-spacing: 0.5px;
        text-transform: uppercase;
        margin-bottom: 1.25rem;
        display: flex;
        align-items: center;
        gap: 8px;
      }
      .comp-row {
        display: flex;
        gap: 0.75rem;
        align-items: flex-start;
        padding: 0.7rem 0;
        border-bottom: 1px solid var(--border-col);
      }
      .comp-row:last-child {
        border-bottom: none;
        padding-bottom: 0;
      }
      .comp-icon {
        flex: 0 0 32px;
        height: 32px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
        margin-top: 1px;
      }
      .comp-icon.green {
        background: var(--success-dim);
        color: var(--success);
      }
      .comp-icon.amber {
        background: var(--accent-dim);
        color: var(--accent);
      }
      .comp-icon.blue {
        background: var(--info-dim);
        color: var(--info);
      }
      .comp-icon.purple {
        background: var(--purple-dim);
        color: var(--purple);
      }
      .comp-icon.pink {
        background: var(--pink-dim);
        color: var(--pink);
      }
      .comp-icon.red {
        background: var(--danger-dim);
        color: var(--danger);
      }
      .comp-body {
        flex: 1;
      }
      .comp-name {
        font-size: 13px;
        font-weight: 700;
        color: #e2e8f0;
        margin-bottom: 3px;
      }
      .comp-desc {
        font-size: 12px;
        color: var(--muted);
        line-height: 1.65;
      }
      .comp-badge {
        display: inline-block;
        font-size: 10px;
        font-weight: 600;
        padding: 2px 8px;
        border-radius: 4px;
        margin-top: 4px;
        font-family: var(--font-mono);
      }
      .comp-badge.green {
        background: var(--success-dim);
        color: var(--success);
        border: 1px solid var(--success-border);
      }
      .comp-badge.amber {
        background: var(--accent-dim);
        color: var(--accent);
        border: 1px solid var(--accent-border);
      }
      .comp-badge.purple {
        background: var(--purple-dim);
        color: var(--purple);
        border: 1px solid var(--purple-border);
      }
      .comp-badge.red {
        background: var(--danger-dim);
        color: var(--danger);
        border: 1px solid rgba(239, 68, 68, 0.35);
      }

      /* ─── NOTE BOX ─── */
      .note-box {
        background: var(--surface-1);
        border: 1px solid var(--border-col);
        border-left: 3px solid var(--accent);
        border-radius: 10px;
        padding: 0.875rem 1.125rem;
        font-size: 12.5px;
        color: var(--muted);
        line-height: 1.7;
      }

      /* ─── SECTION LABEL ─── */
      .sec-label {
        font-size: 10px;
        font-weight: 700;
        color: var(--muted);
        letter-spacing: 1.5px;
        text-transform: uppercase;
        margin-bottom: 0.6rem;
      }

      /* ─── PRESET BUTTONS ─── */
      .preset-row {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
      }
      .preset-btn {
        background: var(--surface-2);
        border: 1px solid var(--border-col);
        border-radius: 8px;
        color: #94a3b8;
        font-size: 12px;
        font-family: var(--font-display);
        font-weight: 500;
        padding: 6px 14px;
        cursor: pointer;
        transition: all 0.15s;
        white-space: nowrap;
      }
      .preset-btn:hover {
        border-color: var(--accent-border);
        color: var(--accent);
        background: var(--accent-dim);
      }
      .preset-btn.on {
        border-color: var(--accent);
        color: var(--accent);
        background: var(--accent-dim);
        box-shadow: 0 0 0 1px rgba(245, 158, 11, 0.2);
      }

      /* ─── SIM CARDS ─── */
      .sim-card {
        background: var(--surface-1);
        border: 1px solid var(--border-col);
        border-radius: 14px;
        padding: 1.25rem 1.25rem 1rem;
      }
      .card-heading {
        font-size: 12px;
        font-weight: 600;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 1.1rem;
        display: flex;
        align-items: center;
        gap: 8px;
      }
      .card-heading i {
        font-size: 14px;
        color: var(--accent);
      }
      .card-heading i.purple {
        color: var(--purple);
      }
      .card-heading i.pink {
        color: var(--pink);
      }
      .card-heading i.info {
        color: var(--info);
      }

      /* ─── SLIDER + INPUT CONTROL ─── */
      .si {
        display: flex;
        flex-direction: column;
        gap: 4px;
      }
      .si-label {
        font-size: 11.5px;
        color: var(--muted);
        margin-bottom: 2px;
      }
      .si-hint {
        font-size: 11px;
        color: #475569;
        line-height: 1.5;
        margin-top: 2px;
      }

      /* The combined slider + input row */
      .ctrl-row {
        display: flex;
        align-items: center;
        gap: 8px;
      }
      .ctrl-row input[type="range"] {
        flex: 1;
        min-width: 0;
      }

      /* Number input */
      .num-input {
        width: 86px;
        flex-shrink: 0;
        background: var(--surface-3);
        border: 1px solid var(--border-col);
        border-radius: 7px;
        color: #fff;
        font-family: var(--font-mono);
        font-size: 12px;
        font-weight: 500;
        padding: 5px 8px;
        text-align: right;
        outline: none;
        transition: border-color 0.15s;
        -moz-appearance: textfield;
      }
      .num-input::-webkit-outer-spin-button,
      .num-input::-webkit-inner-spin-button {
        -webkit-appearance: none;
        margin: 0;
      }
      .num-input:focus {
        border-color: var(--accent-border);
        background: var(--surface-2);
      }
      .num-input.purple:focus {
        border-color: var(--purple-border);
      }
      .num-input.pink:focus {
        border-color: var(--pink-border);
      }
      .num-input.info-c:focus {
        border-color: rgba(56, 189, 248, 0.4);
      }
      .num-input.err {
        border-color: rgba(239, 68, 68, 0.6) !important;
        background: var(--danger-dim) !important;
      }

      /* Range inputs */
      input[type="range"] {
        -webkit-appearance: none;
        appearance: none;
        width: 100%;
        height: 4px;
        background: var(--surface-3);
        border-radius: 2px;
        outline: none;
        cursor: pointer;
      }
      input[type="range"]::-webkit-slider-thumb {
        -webkit-appearance: none;
        appearance: none;
        width: 16px;
        height: 16px;
        border-radius: 50%;
        background: var(--accent);
        box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.2);
        transition: box-shadow 0.15s;
      }
      input[type="range"]:hover::-webkit-slider-thumb {
        box-shadow: 0 0 0 5px rgba(245, 158, 11, 0.25);
      }
      input[type="range"]::-moz-range-thumb {
        width: 16px;
        height: 16px;
        border: none;
        border-radius: 50%;
        background: var(--accent);
        box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.2);
      }
      input[type="range"].purple-thumb::-webkit-slider-thumb {
        background: var(--purple);
        box-shadow: 0 0 0 3px rgba(167, 139, 250, 0.2);
      }
      input[type="range"].purple-thumb::-moz-range-thumb {
        background: var(--purple);
      }
      input[type="range"].pink-thumb::-webkit-slider-thumb {
        background: var(--pink);
        box-shadow: 0 0 0 3px rgba(244, 114, 182, 0.2);
      }
      input[type="range"].pink-thumb::-moz-range-thumb {
        background: var(--pink);
      }
      input[type="range"].info-thumb::-webkit-slider-thumb {
        background: var(--info);
        box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2);
      }
      input[type="range"].info-thumb::-moz-range-thumb {
        background: var(--info);
      }

      /* ─── RUN BUTTON ─── */
      .btn-run {
        width: 100%;
        padding: 14px;
        background: var(--accent);
        color: #000;
        font-size: 14px;
        font-weight: 700;
        font-family: var(--font-display);
        letter-spacing: 0.5px;
        border: none;
        border-radius: 12px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        transition: all 0.2s;
        box-shadow: 0 4px 20px rgba(245, 158, 11, 0.25);
      }
      .btn-run:hover {
        background: #fbbf24;
        box-shadow: 0 6px 28px rgba(245, 158, 11, 0.4);
        transform: translateY(-1px);
      }
      .btn-run:active {
        transform: translateY(0);
      }

      /* ─── PROGRESS ─── */
      .prog {
        height: 3px;
        background: var(--surface-3);
        border-radius: 2px;
        overflow: hidden;
        display: none;
      }
      .prog-bar {
        height: 100%;
        width: 0;
        background: linear-gradient(90deg, var(--accent), #fbbf24);
        border-radius: 2px;
        transition: width 0.3s ease;
      }

      /* ─── RESULTS ─── */
      #results {
        display: none;
      }
      .hero-card {
        background: linear-gradient(
          135deg,
          var(--surface-1) 0%,
          var(--surface-2) 100%
        );
        border: 1px solid var(--border-bright);
        border-top: 3px solid var(--success);
        border-radius: 16px;
        padding: 2rem 1.5rem;
        text-align: center;
        position: relative;
        overflow: hidden;
      }
      .hero-card::before {
        content: "";
        position: absolute;
        inset: 0;
        background: radial-gradient(
          ellipse at 50% 0%,
          rgba(16, 185, 129, 0.07) 0%,
          transparent 70%
        );
        pointer-events: none;
      }
      .hero-label {
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 1.5px;
        text-transform: uppercase;
        color: var(--muted);
        margin-bottom: 0.75rem;
      }
      .hero-val {
        font-size: clamp(2rem, 6vw, 3rem);
        font-weight: 700;
        font-family: var(--font-mono);
        line-height: 1;
        margin-bottom: 0.6rem;
      }
      .hero-sub {
        font-size: 12px;
        color: var(--muted);
        line-height: 1.7;
      }

      .alert-sim {
        border-radius: 12px;
        padding: 1rem 1.125rem;
        border: 1px solid;
        display: none;
      }
      .alert-sim.danger {
        background: var(--danger-dim);
        border-color: rgba(239, 68, 68, 0.3);
      }
      .alert-sim.warn {
        background: var(--accent-dim);
        border-color: var(--accent-border);
      }
      .alert-sim .alert-icon {
        font-size: 18px;
      }
      .alert-sim .alert-msg {
        font-size: 13px;
        font-weight: 500;
        line-height: 1.5;
      }
      .alert-sim.danger .alert-icon,
      .alert-sim.danger .alert-msg {
        color: #fca5a5;
      }
      .alert-sim.warn .alert-icon,
      .alert-sim.warn .alert-msg {
        color: var(--accent);
      }

      .mc {
        background: var(--surface-1);
        border: 1px solid var(--border-col);
        border-radius: 12px;
        padding: 1rem;
        position: relative;
        overflow: hidden;
        transition: border-color 0.15s;
      }
      .mc:hover {
        border-color: var(--border-bright);
      }
      .mc::after {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 2px;
        border-radius: 2px 2px 0 0;
      }
      .mc.a::after {
        background: var(--success);
      }
      .mc.b::after {
        background: var(--danger);
      }
      .mc.c::after {
        background: var(--info);
      }
      .mc.d::after {
        background: var(--accent);
      }
      .mc.e::after {
        background: var(--muted);
      }
      .mc.p::after {
        background: var(--purple);
      }
      .mc.pk::after {
        background: var(--pink);
      }
      .mc .ml {
        font-size: 11px;
        color: var(--muted);
        margin-bottom: 6px;
        font-weight: 500;
      }
      .mc .mv {
        font-size: clamp(16px, 3vw, 20px);
        font-weight: 700;
        font-family: var(--font-mono);
        line-height: 1;
      }
      .mc .ms {
        font-size: 11px;
        color: var(--muted);
        margin-top: 4px;
        line-height: 1.4;
      }
      .mc.a .mv {
        color: var(--success);
      }
      .mc.b .mv {
        color: var(--danger);
      }
      .mc.c .mv {
        color: var(--info);
      }
      .mc.d .mv {
        color: var(--accent);
      }
      .mc.p .mv {
        color: var(--purple);
      }
      .mc.pk .mv {
        color: var(--pink);
      }

      .flow-strip {
        display: flex;
        gap: 0;
        overflow-x: auto;
        padding-bottom: 4px;
        scrollbar-width: thin;
        scrollbar-color: var(--surface-3) transparent;
      }
      .flow-strip::-webkit-scrollbar {
        height: 4px;
      }
      .flow-strip::-webkit-scrollbar-thumb {
        background: var(--surface-3);
        border-radius: 2px;
      }
      .flow-item {
        flex: 0 0 auto;
        min-width: 120px;
        background: var(--surface-2);
        border: 1px solid var(--border-col);
        border-radius: 10px;
        padding: 0.75rem;
        text-align: center;
        position: relative;
        margin-right: 4px;
      }
      .flow-item + .flow-item::before {
        content: "→";
        position: absolute;
        left: -12px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--muted);
        font-size: 11px;
      }
      .flow-label {
        font-size: 10px;
        color: var(--muted);
        line-height: 1.4;
        margin-bottom: 4px;
      }
      .flow-val {
        font-size: 13px;
        font-weight: 700;
        font-family: var(--font-mono);
      }

      .sim-divider {
        height: 1px;
        background: var(--border-col);
        margin: 1.5rem 0;
      }

      .tbl-wrap {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
      }
      .tbl-wrap::-webkit-scrollbar {
        height: 4px;
      }
      .tbl-wrap::-webkit-scrollbar-thumb {
        background: var(--surface-3);
        border-radius: 2px;
      }
      table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
        min-width: 1050px;
        font-family: var(--font-mono);
      }
      thead th {
        background: var(--surface-2);
        padding: 10px 10px;
        text-align: right;
        font-size: 9.5px;
        font-weight: 700;
        font-family: var(--font-display);
        color: var(--muted);
        letter-spacing: 0.4px;
        text-transform: uppercase;
        border-bottom: 1px solid var(--border-col);
        white-space: nowrap;
      }
      thead th:first-child {
        text-align: center;
      }
      tbody td {
        padding: 8px 10px;
        text-align: right;
        border-bottom: 1px solid var(--border-col);
        white-space: nowrap;
        color: #cbd5e1;
        transition: background 0.1s;
      }
      tbody td:first-child {
        text-align: center;
        color: var(--muted);
        font-size: 11px;
      }
      tbody tr:last-child td {
        border-bottom: none;
      }
      tbody tr:hover td {
        background: var(--surface-2);
      }
      .tg {
        color: var(--success) !important;
      }
      .tr {
        color: var(--danger) !important;
      }
      .tw {
        color: var(--accent) !important;
      }
      .ti {
        color: var(--info) !important;
      }
      .tp {
        color: var(--purple) !important;
      }
      .tpk {
        color: var(--pink) !important;
      }

      .pager {
        display: flex;
        gap: 8px;
        align-items: center;
        padding: 0.875rem 1rem;
        border-top: 1px solid var(--border-col);
        background: var(--surface-2);
        border-radius: 0 0 14px 14px;
      }
      .pager button {
        background: var(--surface-1);
        border: 1px solid var(--border-col);
        border-radius: 8px;
        color: #94a3b8;
        font-size: 13px;
        padding: 6px 14px;
        cursor: pointer;
        transition: all 0.15s;
        font-family: var(--font-display);
      }
      .pager button:hover {
        border-color: var(--accent-border);
        color: var(--accent);
      }
      .pager span {
        font-size: 12px;
        color: var(--muted);
        flex: 1;
        text-align: center;
        font-family: var(--font-mono);
      }

      .run-sticky {
        position: sticky;
        bottom: 0;
        z-index: 50;
        background: linear-gradient(to top, var(--bs-body-bg) 80%, transparent);
        padding: 1rem 0 0.5rem;
      }

      .section-divider {
        display: flex;
        align-items: center;
        gap: 12px;
        margin: 2rem 0 1.25rem;
      }
      .section-divider .sd-line {
        flex: 1;
        height: 1px;
        background: var(--border-col);
      }
      .section-divider .sd-label {
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 1.5px;
        text-transform: uppercase;
        color: var(--muted);
        white-space: nowrap;
      }

      @media (max-width: 576px) {
        .app-header {
          padding: 1.25rem 0 1rem;
        }
        .sim-card {
          padding: 1rem;
        }
        .hero-card {
          padding: 1.5rem 1rem;
        }
        .comp-plan-card {
          padding: 1rem;
        }
        .num-input {
          width: 72px;
          font-size: 11px;
        }
      }
    </style>
  </head>
  <body>
    <!-- ═══ HEADER ═══ -->
    <div class="app-header">
      <div class="container">
        <div class="app-badge">
          <i class="bi bi-diagram-3-fill"></i> Binary MLM Simulator v5
        </div>
        <div class="app-title">Company Profit Calculator</div>
        <div class="app-subtitle">
          <span class="tag">Binary pairing</span>
          <span class="tag">Income capping &amp; reactivation</span>
          <span class="tag">Daily fixed income</span>
          <span class="tag">Direct referral</span>
          <span class="tag">10-level unilevel</span>
          <span class="tag">Flush-out mechanics</span>
          <span class="tag">Product cost accounting</span>
        </div>
      </div>
    </div>

    <div class="container py-4">
      <!-- ══════════ COMPENSATION PLAN ══════════ -->
      <div class="comp-plan-card mb-4">
        <div class="comp-plan-title">
          <i class="bi bi-journal-richtext"></i> System Compensation Plan — How
          It Works
        </div>
        <div class="comp-row">
          <div class="comp-icon green">
            <i class="bi bi-person-plus-fill"></i>
          </div>
          <div class="comp-body">
            <div class="comp-name">1 · Membership Entry</div>
            <div class="comp-desc">
              A new member pays the
              <strong style="color: #e2e8f0">Entry Fee</strong> to join. A
              portion covers the
              <strong style="color: #e2e8f0">cost of goods</strong> (actual
              product delivered). The remainder is the company's
              <strong style="color: #e2e8f0">cash-in</strong> — working capital
              from which all bonuses are paid. Every member is placed into the
              binary tree using
              <strong style="color: #e2e8f0"
                >Breadth-First (BFS) placement</strong
              >
              — filling level by level, left before right.
            </div>
            <span class="comp-badge amber"
              >Entry Fee − Goods Cost = Cash In Per Member</span
            >
          </div>
        </div>
        <div class="comp-row">
          <div class="comp-icon amber">
            <i class="bi bi-diagram-3-fill"></i>
          </div>
          <div class="comp-body">
            <div class="comp-name">2 · Binary Pairing Bonus</div>
            <div class="comp-desc">
              Each member has a
              <strong style="color: #e2e8f0">left leg</strong> and a
              <strong style="color: #e2e8f0">right leg</strong>. Every time a
              member gets one new recruit on each side, a
              <strong style="color: #e2e8f0">pair</strong> is formed and earns
              the <strong style="color: #e2e8f0">Pairing Bonus</strong>. A
              <strong style="color: #e2e8f0">daily pair cap</strong> limits
              payable pairs per member per day — excess pairs are
              <strong style="color: #fca5a5">flushed (permanently lost)</strong
              >. Inactive members are skipped entirely.
            </div>
            <span class="comp-badge amber"
              >Pairs × Bonus (capped daily · active members only)</span
            >
          </div>
        </div>
        <div class="comp-row">
          <div class="comp-icon purple">
            <i class="bi bi-bar-chart-steps"></i>
          </div>
          <div class="comp-body">
            <div class="comp-name">3 · Income Cap &amp; Reactivation</div>
            <div class="comp-desc">
              Each member has a
              <strong style="color: #e2e8f0">maximum total income cap</strong>
              equal to
              <strong style="color: #c4b5fd">3× the entry fee</strong> per
              cycle. This cap applies to
              <strong style="color: #e2e8f0">all income types combined</strong>
              — binary pairing bonuses <em>and</em> daily fixed income both
              count toward the cap. Once cumulative earnings reach this limit,
              the account becomes
              <strong style="color: #fca5a5">inactive</strong> — no pairing
              bonuses, no daily fixed income, and the member is skipped in
              binary pair counting. To resume, the member pays the
              <strong style="color: #e2e8f0">Reactivation Fee</strong>. Only
              pairs and fixed income earned <em>after</em> reactivation count
              toward the new cycle. If the member does not reactivate within the
              <strong style="color: #e2e8f0">Reactivation Window</strong>, the
              account becomes
              <strong style="color: #fca5a5">permanently inactive</strong>.
              Reactivation resets all earnings counters from zero.
            </div>
            <span class="comp-badge purple"
              >Cap = 3× Entry Fee · Covers Pairing + Daily Fixed</span
            >
            <span class="comp-badge red" style="margin-left: 4px"
              >Missed Window → Permanently Inactive</span
            >
          </div>
        </div>
        <div class="comp-row">
          <div class="comp-icon pink">
            <i class="bi bi-calendar-check-fill"></i>
          </div>
          <div class="comp-body">
            <div class="comp-name">4 · Daily Fixed Income</div>
            <div class="comp-desc">
              Every <strong style="color: #e2e8f0">active</strong> member earns
              a <strong style="color: #e2e8f0">fixed daily amount</strong> for
              up to a set number of days (the
              <strong style="color: #e2e8f0">Daily Income Duration</strong>).
              The daily fixed income is also subject to the
              <strong style="color: #e2e8f0">total income cap</strong> — it
              stops permanently if either the duration limit is exhausted
              <em>or</em> the member's cumulative earnings hit the 3× entry cap,
              whichever comes first. Days while inactive do <em>not</em> count —
              the duration clock pauses and resumes on reactivation (but the cap
              resets on reactivation, so fresh DFI can be earned in the new
              cycle).
            </div>
            <span
              class="comp-badge"
              style="
                background: var(--pink-dim);
                color: var(--pink);
                border: 1px solid var(--pink-border);
              "
              >Fixed Amount/Day × Active Days (up to duration limit)</span
            >
          </div>
        </div>
        <div class="comp-row">
          <div class="comp-icon green">
            <i class="bi bi-person-check-fill"></i>
          </div>
          <div class="comp-body">
            <div class="comp-name">5 · Direct Referral Bonus</div>
            <div class="comp-desc">
              When a member directly recruits a new member, the sponsor
              immediately receives the
              <strong style="color: #e2e8f0">Direct Referral Bonus</strong> — a
              one-time cash payment per new recruit, paid at registration
              regardless of sponsor's cap or active status.
            </div>
            <span class="comp-badge green"
              >Paid Once Per Direct Recruit · Immediate</span
            >
          </div>
        </div>
        <div class="comp-row">
          <div class="comp-icon blue"><i class="bi bi-layers-fill"></i></div>
          <div class="comp-body">
            <div class="comp-name">6 · Unilevel Bonus (Up to 10 Levels)</div>
            <div class="comp-desc">
              When a new member joins, a
              <strong style="color: #e2e8f0">unilevel bonus</strong> flows up
              the <strong style="color: #e2e8f0">sponsor chain</strong>. L1
              (direct sponsor) gets the most; higher levels get less. Configured
              separately for L1, L2, L3, L4–5, L6–10. Limited by the
              <strong style="color: #e2e8f0">Avg Sponsor Depth</strong> setting.
            </div>
            <span
              class="comp-badge"
              style="
                background: var(--info-dim);
                color: var(--info);
                border: 1px solid rgba(56, 189, 248, 0.35);
              "
              >L1 → L10 Upline · Paid at Each New Registration</span
            >
          </div>
        </div>
        <div class="comp-row">
          <div class="comp-icon red">
            <i class="bi bi-shield-exclamation"></i>
          </div>
          <div class="comp-body">
            <div class="comp-name">7 · Flush-Out Protection (Daily Cap)</div>
            <div class="comp-desc">
              If pairs on a given day exceed the daily cap, excess pairs are
              <strong style="color: #fca5a5">flushed</strong> — not paid, not
              carried forward. This controls runaway payouts on high-growth days
              and protects company cash flow.
            </div>
            <span class="comp-badge red"
              >Excess Pairs = Lost Permanently · No Carry-Forward</span
            >
          </div>
        </div>
      </div>

      <!-- ══════════ NOTE BOX ══════════ -->
      <div class="note-box mb-4">
        <i class="bi bi-info-circle me-2" style="color: var(--accent)"></i>
        <strong style="color: #e2e8f0">Simulator mechanics:</strong>
        Binary tree uses BFS placement. Inactive members are
        <strong>skipped</strong> in pair counting — their sub-tree nodes exist
        but contribute no bonuses to that member. The
        <strong style="color: #c4b5fd">income cap = 3× entry fee</strong> and
        covers <strong>all earnings combined</strong>: binary pairing bonuses +
        daily fixed income. When the cap is reached, both income streams stop
        and the account becomes inactive. Reactivation is probabilistic: each
        capped member reactivates (based on rate %) on the day after capping;
        reactivation resets all earnings counters to zero for a fresh cycle.
        Daily fixed income stops at either the duration limit OR when the
        combined cap is reached — whichever comes first. Product cost is
        subtracted from gross entry to derive actual company cash. Daily pair
        cap: excess pairs are
        <strong style="color: #fca5a5">lost permanently</strong>.
        <strong style="color: #e2e8f0">Tip:</strong> Adjust via
        <strong>slider</strong> or type directly into the number field — both
        are fully synced.
      </div>

      <!-- ══════════ PRESETS ══════════ -->
      <div class="sec-label">Quick Presets</div>
      <div class="preset-row mb-4">
        <button class="preset-btn on" onclick="applyPreset('default', this)">
          <i class="bi bi-sliders me-1"></i>Default
        </button>
        <button class="preset-btn" onclick="applyPreset('aggressive', this)">
          <i class="bi bi-lightning-charge me-1"></i>Aggressive
        </button>
        <button class="preset-btn" onclick="applyPreset('lean', this)">
          <i class="bi bi-box me-1"></i>Lean Product
        </button>
        <button class="preset-btn" onclick="applyPreset('highcap', this)">
          <i class="bi bi-trophy me-1"></i>High Cap
        </button>
        <button class="preset-btn" onclick="applyPreset('highref', this)">
          <i class="bi bi-people me-1"></i>High Referral
        </button>
        <button class="preset-btn" onclick="applyPreset('fixedheavy', this)">
          <i class="bi bi-calendar-week me-1"></i>Fixed-Heavy
        </button>
      </div>

      <!-- ══════════ CARD 1: Entry & Pairing ══════════ -->
      <div class="sim-card mb-3">
        <div class="card-heading">
          <i class="bi bi-currency-exchange"></i> Entry &amp; Pairing
        </div>
        <div class="row g-3">
          <div class="col-12 col-sm-6 col-lg-3">
            <div class="si">
              <div class="si-label">Entry fee (₱)</div>
              <div class="ctrl-row">
                <input
                  type="range"
                  id="s-entry"
                  min="500"
                  max="100000"
                  step="500"
                  value="10000"
                  oninput="syncFromSlider('entry')"
                />
                <input
                  type="number"
                  class="num-input"
                  id="n-entry"
                  value="10000"
                  min="500"
                  max="100000"
                  step="500"
                  oninput="syncFromNum('entry')"
                />
              </div>
            </div>
          </div>
          <div class="col-12 col-sm-6 col-lg-3">
            <div class="si">
              <div class="si-label">Product cost %</div>
              <div class="ctrl-row">
                <input
                  type="range"
                  id="s-pcost"
                  min="0"
                  max="95"
                  step="1"
                  value="30"
                  oninput="syncFromSlider('pcost')"
                />
                <input
                  type="number"
                  class="num-input"
                  id="n-pcost"
                  value="30"
                  min="0"
                  max="95"
                  step="1"
                  oninput="syncFromNum('pcost')"
                />
              </div>
              <div class="si-hint">% of entry fee covering real goods cost</div>
            </div>
          </div>
          <div class="col-12 col-sm-6 col-lg-3">
            <div class="si">
              <div class="si-label">Pairing bonus (₱)</div>
              <div class="ctrl-row">
                <input
                  type="range"
                  id="s-bonus"
                  min="100"
                  max="20000"
                  step="100"
                  value="2000"
                  oninput="syncFromSlider('bonus')"
                />
                <input
                  type="number"
                  class="num-input"
                  id="n-bonus"
                  value="2000"
                  min="100"
                  max="20000"
                  step="100"
                  oninput="syncFromNum('bonus')"
                />
              </div>
            </div>
          </div>
          <div class="col-12 col-sm-6 col-lg-3">
            <div class="si">
              <div class="si-label">Daily pair cap</div>
              <div class="ctrl-row">
                <input
                  type="range"
                  id="s-cap"
                  min="1"
                  max="30"
                  step="1"
                  value="3"
                  oninput="syncFromSlider('cap')"
                />
                <input
                  type="number"
                  class="num-input"
                  id="n-cap"
                  value="3"
                  min="1"
                  max="30"
                  step="1"
                  oninput="syncFromNum('cap')"
                />
              </div>
              <div class="si-hint">max pairs/member/day — excess flushed</div>
            </div>
          </div>
        </div>
      </div>

      <!-- ══════════ CARD 2: Income Cap & Reactivation ══════════ -->
      <div class="sim-card mb-3" style="border-color: var(--purple-border)">
        <div class="card-heading">
          <i class="bi bi-shield-lock-fill purple"></i> Income Capping &amp;
          Reactivation
        </div>
        <div class="row g-3">
          <!-- Auto-computed cap display -->
          <div class="col-12 col-sm-6 col-lg-3">
            <div class="si">
              <div class="si-label" style="color: var(--purple)">
                Total income cap (auto)
              </div>
              <div
                style="
                  background: var(--surface-3);
                  border: 1px solid var(--purple-border);
                  border-radius: 9px;
                  padding: 8px 12px;
                  display: flex;
                  align-items: center;
                  justify-content: space-between;
                  gap: 8px;
                  margin-top: 2px;
                "
              >
                <div>
                  <div
                    id="cap-display"
                    style="
                      font-family: var(--font-mono);
                      font-size: 15px;
                      font-weight: 700;
                      color: var(--purple);
                    "
                  >
                    ₱30,000
                  </div>
                  <div
                    style="
                      font-size: 10px;
                      color: var(--muted);
                      margin-top: 2px;
                    "
                  >
                    = 3 × entry fee
                  </div>
                </div>
                <div
                  style="
                    background: var(--purple-dim);
                    border: 1px solid var(--purple-border);
                    border-radius: 6px;
                    padding: 3px 9px;
                    font-size: 11px;
                    font-weight: 700;
                    color: var(--purple);
                    white-space: nowrap;
                  "
                >
                  AUTO
                </div>
              </div>
              <div class="si-hint">
                applies to
                <strong style="color: #c4b5fd">all income types</strong>
                combined: pairing + daily fixed
              </div>
            </div>
          </div>
          <div class="col-12 col-sm-6 col-lg-3">
            <div class="si">
              <div class="si-label" style="color: var(--purple)">
                Reactivation fee (₱)
              </div>
              <div class="ctrl-row">
                <input
                  type="range"
                  class="purple-thumb"
                  id="s-reactfee"
                  min="0"
                  max="50000"
                  step="500"
                  value="10000"
                  oninput="syncFromSlider('reactfee')"
                />
                <input
                  type="number"
                  class="num-input purple"
                  id="n-reactfee"
                  value="10000"
                  min="0"
                  max="50000"
                  step="500"
                  oninput="syncFromNum('reactfee')"
                />
              </div>
              <div class="si-hint">
                fee paid by member to reactivate their account
              </div>
            </div>
          </div>
          <div class="col-12 col-sm-6 col-lg-3">
            <div class="si">
              <div class="si-label" style="color: var(--purple)">
                Reactivation window (days)
              </div>
              <div class="ctrl-row">
                <input
                  type="range"
                  class="purple-thumb"
                  id="s-reactwin"
                  min="1"
                  max="180"
                  step="1"
                  value="15"
                  oninput="syncFromSlider('reactwin')"
                />
                <input
                  type="number"
                  class="num-input purple"
                  id="n-reactwin"
                  value="15"
                  min="1"
                  max="180"
                  step="1"
                  oninput="syncFromNum('reactwin')"
                />
              </div>
              <div class="si-hint">
                days to reactivate before permanent deactivation
              </div>
            </div>
          </div>
          <div class="col-12 col-sm-6 col-lg-3">
            <div class="si">
              <div class="si-label" style="color: var(--purple)">
                Reactivation rate %
              </div>
              <div class="ctrl-row">
                <input
                  type="range"
                  class="purple-thumb"
                  id="s-reactrate"
                  min="0"
                  max="100"
                  step="5"
                  value="100"
                  oninput="syncFromSlider('reactrate')"
                />
                <input
                  type="number"
                  class="num-input purple"
                  id="n-reactrate"
                  value="100"
                  min="0"
                  max="100"
                  step="1"
                  oninput="syncFromNum('reactrate')"
                />
              </div>
              <div class="si-hint">
                % of capped members who choose to reactivate
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ══════════ CARD 3: Daily Fixed Income ══════════ -->
      <div class="sim-card mb-3" style="border-color: var(--pink-border)">
        <div class="card-heading">
          <i class="bi bi-calendar-week-fill pink"></i> Daily Fixed Income
        </div>
        <div class="row g-3">
          <div class="col-12 col-sm-6">
            <div class="si">
              <div class="si-label" style="color: var(--pink)">
                Daily fixed income (₱/day)
              </div>
              <div class="ctrl-row">
                <input
                  type="range"
                  class="pink-thumb"
                  id="s-dfi"
                  min="0"
                  max="5000"
                  step="50"
                  value="100"
                  oninput="syncFromSlider('dfi')"
                />
                <input
                  type="number"
                  class="num-input pink"
                  id="n-dfi"
                  value="100"
                  min="0"
                  max="5000"
                  step="1"
                  oninput="syncFromNum('dfi')"
                />
              </div>
              <div class="si-hint">
                paid to each active member per day · set 0 to disable
              </div>
            </div>
          </div>
          <div class="col-12 col-sm-6">
            <div class="si">
              <div class="si-label" style="color: var(--pink)">
                Max income days
              </div>
              <div class="ctrl-row">
                <input
                  type="range"
                  class="pink-thumb"
                  id="s-dfidays"
                  min="7"
                  max="730"
                  step="1"
                  value="90"
                  oninput="syncFromSlider('dfidays')"
                />
                <input
                  type="number"
                  class="num-input pink"
                  id="n-dfidays"
                  value="90"
                  min="7"
                  max="730"
                  step="1"
                  oninput="syncFromNum('dfidays')"
                />
              </div>
              <div class="si-hint">
                max days of fixed income per member · clock pauses while
                inactive
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ══════════ CARD 4: Referral Commissions ══════════ -->
      <div class="sim-card mb-3">
        <div class="card-heading">
          <i class="bi bi-person-plus"></i> Referral Commissions
        </div>
        <div class="row g-3">
          <!-- Direct Referral -->
          <div class="col-12 col-sm-6 col-lg-3">
            <div class="si">
              <div class="si-label">Direct referral (₱)</div>
              <div class="ctrl-row">
                <input
                  type="range"
                  id="s-direct"
                  min="0"
                  max="10000"
                  step="50"
                  value="500"
                  oninput="syncFromSlider('direct')"
                />
                <input
                  type="number"
                  class="num-input"
                  id="n-direct"
                  value="500"
                  min="0"
                  max="10000"
                  step="1"
                  oninput="syncFromNum('direct')"
                />
              </div>
              <div class="si-hint">paid to direct sponsor on each new join</div>
            </div>
          </div>

          <!-- L1 -->
          <div class="col-12 col-sm-6 col-lg-3">
            <div class="si">
              <div class="si-label">Unilevel L1 (₱)</div>
              <div class="ctrl-row">
                <input
                  type="range"
                  id="s-ul1"
                  min="0"
                  max="3000"
                  step="10"
                  value="300"
                  oninput="syncFromSlider('ul1')"
                />
                <input
                  type="number"
                  class="num-input"
                  id="n-ul1"
                  value="300"
                  min="0"
                  max="3000"
                  step="1"
                  oninput="syncFromNum('ul1')"
                />
              </div>
            </div>
          </div>

          <!-- L2 -->
          <div class="col-12 col-sm-6 col-lg-3">
            <div class="si">
              <div class="si-label">Unilevel L2 (₱)</div>
              <div class="ctrl-row">
                <input
                  type="range"
                  id="s-ul2"
                  min="0"
                  max="2000"
                  step="10"
                  value="200"
                  oninput="syncFromSlider('ul2')"
                />
                <input
                  type="number"
                  class="num-input"
                  id="n-ul2"
                  value="200"
                  min="0"
                  max="2000"
                  step="1"
                  oninput="syncFromNum('ul2')"
                />
              </div>
            </div>
          </div>

          <!-- L3 -->
          <div class="col-12 col-sm-6 col-lg-3">
            <div class="si">
              <div class="si-label">Unilevel L3 (₱)</div>
              <div class="ctrl-row">
                <input
                  type="range"
                  id="s-ul3"
                  min="0"
                  max="1000"
                  step="10"
                  value="150"
                  oninput="syncFromSlider('ul3')"
                />
                <input
                  type="number"
                  class="num-input"
                  id="n-ul3"
                  value="150"
                  min="0"
                  max="1000"
                  step="1"
                  oninput="syncFromNum('ul3')"
                />
              </div>
            </div>
          </div>

          <!-- L4 -->
          <div class="col-12 col-sm-6 col-lg-3">
            <div class="si">
              <div class="si-label">Unilevel L4 (₱)</div>
              <div class="ctrl-row">
                <input
                  type="range"
                  id="s-ul4"
                  min="0"
                  max="500"
                  step="10"
                  value="100"
                  oninput="syncFromSlider('ul4')"
                />
                <input
                  type="number"
                  class="num-input"
                  id="n-ul4"
                  value="100"
                  min="0"
                  max="500"
                  step="1"
                  oninput="syncFromNum('ul4')"
                />
              </div>
            </div>
          </div>

          <!-- L5 -->
          <div class="col-12 col-sm-6 col-lg-3">
            <div class="si">
              <div class="si-label">Unilevel L5 (₱)</div>
              <div class="ctrl-row">
                <input
                  type="range"
                  id="s-ul5"
                  min="0"
                  max="500"
                  step="10"
                  value="100"
                  oninput="syncFromSlider('ul5')"
                />
                <input
                  type="number"
                  class="num-input"
                  id="n-ul5"
                  value="100"
                  min="0"
                  max="500"
                  step="1"
                  oninput="syncFromNum('ul5')"
                />
              </div>
            </div>
          </div>

          <!-- L6 -->
          <div class="col-12 col-sm-6 col-lg-3">
            <div class="si">
              <div class="si-label">Unilevel L6 (₱)</div>
              <div class="ctrl-row">
                <input
                  type="range"
                  id="s-ul6"
                  min="0"
                  max="300"
                  step="10"
                  value="50"
                  oninput="syncFromSlider('ul6')"
                />
                <input
                  type="number"
                  class="num-input"
                  id="n-ul6"
                  value="50"
                  min="0"
                  max="300"
                  step="1"
                  oninput="syncFromNum('ul6')"
                />
              </div>
            </div>
          </div>

          <!-- L7 -->
          <div class="col-12 col-sm-6 col-lg-3">
            <div class="si">
              <div class="si-label">Unilevel L7 (₱)</div>
              <div class="ctrl-row">
                <input
                  type="range"
                  id="s-ul7"
                  min="0"
                  max="300"
                  step="10"
                  value="50"
                  oninput="syncFromSlider('ul7')"
                />
                <input
                  type="number"
                  class="num-input"
                  id="n-ul7"
                  value="50"
                  min="0"
                  max="300"
                  step="1"
                  oninput="syncFromNum('ul7')"
                />
              </div>
            </div>
          </div>

          <!-- L8 -->
          <div class="col-12 col-sm-6 col-lg-3">
            <div class="si">
              <div class="si-label">Unilevel L8 (₱)</div>
              <div class="ctrl-row">
                <input
                  type="range"
                  id="s-ul8"
                  min="0"
                  max="300"
                  step="10"
                  value="50"
                  oninput="syncFromSlider('ul8')"
                />
                <input
                  type="number"
                  class="num-input"
                  id="n-ul8"
                  value="50"
                  min="0"
                  max="300"
                  step="1"
                  oninput="syncFromNum('ul8')"
                />
              </div>
            </div>
          </div>

          <!-- L9 -->
          <div class="col-12 col-sm-6 col-lg-3">
            <div class="si">
              <div class="si-label">Unilevel L9 (₱)</div>
              <div class="ctrl-row">
                <input
                  type="range"
                  id="s-ul9"
                  min="0"
                  max="300"
                  step="10"
                  value="50"
                  oninput="syncFromSlider('ul9')"
                />
                <input
                  type="number"
                  class="num-input"
                  id="n-ul9"
                  value="50"
                  min="0"
                  max="300"
                  step="1"
                  oninput="syncFromNum('ul9')"
                />
              </div>
            </div>
          </div>

          <!-- L10 -->
          <div class="col-12 col-sm-6 col-lg-3">
            <div class="si">
              <div class="si-label">Unilevel L10 (₱)</div>
              <div class="ctrl-row">
                <input
                  type="range"
                  id="s-ul10"
                  min="0"
                  max="300"
                  step="10"
                  value="50"
                  oninput="syncFromSlider('ul10')"
                />
                <input
                  type="number"
                  class="num-input"
                  id="n-ul10"
                  value="50"
                  min="0"
                  max="300"
                  step="1"
                  oninput="syncFromNum('ul10')"
                />
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ══════════ CARD 5: Growth Parameters ══════════ -->
      <div class="sim-card mb-4">
        <div class="card-heading">
          <i class="bi bi-graph-up-arrow info"></i> Growth Parameters
        </div>
        <div class="row g-3">
          <div class="col-12 col-sm-4">
            <div class="si">
              <div class="si-label">Max members</div>
              <div class="ctrl-row">
                <input
                  type="range"
                  class="info-thumb"
                  id="s-maxm"
                  min="100"
                  max="50000"
                  step="100"
                  value="1000"
                  oninput="syncFromSlider('maxm')"
                />
                <input
                  type="number"
                  class="num-input info-c"
                  id="n-maxm"
                  value="1000"
                  min="100"
                  max="50000"
                  step="1"
                  oninput="syncFromNum('maxm')"
                />
              </div>
            </div>
          </div>
          <div class="col-12 col-sm-4">
            <div class="si">
              <div class="si-label">New members / day</div>
              <div class="ctrl-row">
                <input
                  type="range"
                  class="info-thumb"
                  id="s-npd"
                  min="1"
                  max="2000"
                  step="1"
                  value="50"
                  oninput="syncFromSlider('npd')"
                />
                <input
                  type="number"
                  class="num-input info-c"
                  id="n-npd"
                  value="50"
                  min="1"
                  max="2000"
                  step="1"
                  oninput="syncFromNum('npd')"
                />
              </div>
            </div>
          </div>
          <div class="col-12 col-sm-4">
            <div class="si">
              <div class="si-label">Avg sponsor depth</div>
              <div class="ctrl-row">
                <input
                  type="range"
                  class="info-thumb"
                  id="s-depth"
                  min="1"
                  max="10"
                  step="1"
                  value="4"
                  oninput="syncFromSlider('depth')"
                />
                <input
                  type="number"
                  class="num-input info-c"
                  id="n-depth"
                  value="4"
                  min="1"
                  max="10"
                  step="1"
                  oninput="syncFromNum('depth')"
                />
              </div>
              <div class="si-hint">
                avg upline levels for unilevel payout on new join
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Run button -->
      <div class="run-sticky">
        <button class="btn-run" onclick="runSim()">
          <i class="bi bi-play-circle-fill fs-5"></i>
          Run Simulation
        </button>
      </div>
      <div class="prog mt-2" id="prog">
        <div class="prog-bar" id="prog-bar"></div>
      </div>

      <!-- ═══ RESULTS ═══ -->
      <div id="results" class="mt-4">
        <div id="alert-loss" class="alert-sim danger mb-3">
          <div class="d-flex gap-3 align-items-start">
            <i class="bi bi-exclamation-triangle-fill alert-icon"></i>
            <div class="alert-msg" id="alert-loss-text"></div>
          </div>
        </div>
        <div id="alert-warn" class="alert-sim warn mb-3">
          <div class="d-flex gap-3 align-items-start">
            <i class="bi bi-exclamation-circle alert-icon"></i>
            <div class="alert-msg" id="alert-warn-text"></div>
          </div>
        </div>

        <div class="hero-card mb-4">
          <div class="hero-label">
            <i class="bi bi-building me-2"></i>Company Net Profit
          </div>
          <div class="hero-val" id="r-profit">₱0</div>
          <div class="hero-sub" id="r-margin">—</div>
        </div>

        <div class="sec-label mb-2">Money Flow Per Member Registration</div>
        <div class="flow-strip mb-4" id="flow-cards"></div>

        <div class="section-divider">
          <div class="sd-line"></div>
          <div class="sd-label">
            <i class="bi bi-cash-stack me-1"></i>Revenue &amp; Goods
          </div>
          <div class="sd-line"></div>
        </div>
        <div class="row g-3 mb-3">
          <div class="col-6 col-lg-3">
            <div class="mc a">
              <div class="ml">
                <i class="bi bi-arrow-down-circle me-1"></i>Gross entry
                collected
              </div>
              <div class="mv" id="r-gross">₱0</div>
              <div class="ms" id="r-gross-s"></div>
            </div>
          </div>
          <div class="col-6 col-lg-3">
            <div class="mc b">
              <div class="ml">
                <i class="bi bi-box-seam me-1"></i>Product cost (goods)
              </div>
              <div class="mv" id="r-pcost">₱0</div>
              <div class="ms" id="r-pcost-s"></div>
            </div>
          </div>
          <div class="col-6 col-lg-3">
            <div class="mc p">
              <div class="ml">
                <i class="bi bi-arrow-repeat me-1"></i>Reactivation revenue
              </div>
              <div class="mv" id="r-reactrev">₱0</div>
              <div class="ms" id="r-reactrev-s"></div>
            </div>
          </div>
          <div class="col-6 col-lg-3">
            <div class="mc a">
              <div class="ml">
                <i class="bi bi-wallet2 me-1"></i>Total cash in
              </div>
              <div class="mv" id="r-cashin">₱0</div>
              <div class="ms">entry cash + reactivations</div>
            </div>
          </div>
        </div>

        <div class="section-divider">
          <div class="sd-line"></div>
          <div class="sd-label">
            <i class="bi bi-arrow-up-circle me-1"></i>Payouts
          </div>
          <div class="sd-line"></div>
        </div>
        <div class="row g-3 mb-3">
          <div class="col-6 col-lg-3">
            <div class="mc b">
              <div class="ml">
                <i class="bi bi-diagram-3 me-1"></i>Pairing bonuses paid
              </div>
              <div class="mv" id="r-pair-paid">₱0</div>
              <div class="ms" id="r-pair-s"></div>
            </div>
          </div>
          <div class="col-6 col-lg-3">
            <div class="mc b">
              <div class="ml">
                <i class="bi bi-person-check me-1"></i>Direct referral paid
              </div>
              <div class="mv" id="r-direct-paid">₱0</div>
              <div class="ms" id="r-direct-s"></div>
            </div>
          </div>
          <div class="col-6 col-lg-3">
            <div class="mc b">
              <div class="ml">
                <i class="bi bi-layers me-1"></i>Unilevel paid
              </div>
              <div class="mv" id="r-uni-paid">₱0</div>
              <div class="ms" id="r-uni-s"></div>
            </div>
          </div>
          <div class="col-6 col-lg-3">
            <div class="mc pk">
              <div class="ml">
                <i class="bi bi-calendar-week me-1"></i>Daily fixed income paid
              </div>
              <div class="mv" id="r-dfi-paid">₱0</div>
              <div class="ms" id="r-dfi-s"></div>
            </div>
          </div>
        </div>

        <div class="section-divider">
          <div class="sd-line"></div>
          <div class="sd-label">
            <i class="bi bi-shield-lock me-1"></i>Cap &amp; Reactivation Stats
          </div>
          <div class="sd-line"></div>
        </div>
        <div class="row g-3 mb-3">
          <div class="col-6 col-lg-3">
            <div class="mc p">
              <div class="ml">
                <i class="bi bi-person-x me-1"></i>Members capped out
              </div>
              <div class="mv" id="r-capped">0</div>
              <div class="ms">reached income cap ≥ once</div>
            </div>
          </div>
          <div class="col-6 col-lg-3">
            <div class="mc a">
              <div class="ml">
                <i class="bi bi-arrow-repeat me-1"></i>Reactivations total
              </div>
              <div class="mv" id="r-reacts">0</div>
              <div class="ms" id="r-reacts-s"></div>
            </div>
          </div>
          <div class="col-6 col-lg-3">
            <div class="mc b">
              <div class="ml">
                <i class="bi bi-person-slash me-1"></i>Permanently inactive
              </div>
              <div class="mv" id="r-perminact">0</div>
              <div class="ms">missed reactivation window</div>
            </div>
          </div>
          <div class="col-6 col-lg-3">
            <div class="mc d">
              <div class="ml">
                <i class="bi bi-check2-all me-1"></i>Cap saved company
              </div>
              <div class="mv" id="r-capsaved">₱0</div>
              <div class="ms" id="r-capsaved-s">unpaid bonuses due to cap</div>
            </div>
          </div>
        </div>

        <div class="section-divider">
          <div class="sd-line"></div>
          <div class="sd-label">
            <i class="bi bi-activity me-1"></i>Pairing &amp; Performance
          </div>
          <div class="sd-line"></div>
        </div>
        <div class="row g-3 mb-4">
          <div class="col-6 col-lg-3">
            <div class="mc c">
              <div class="ml">
                <i class="bi bi-check2-circle me-1"></i>Pairs paid out
              </div>
              <div class="mv" id="r-pairs-n">0</div>
              <div class="ms">bonus-earning pairs</div>
            </div>
          </div>
          <div class="col-6 col-lg-3">
            <div class="mc d">
              <div class="ml">
                <i class="bi bi-x-circle me-1"></i>Pairs flushed
              </div>
              <div class="mv" id="r-flushed">0</div>
              <div class="ms">lost to daily cap</div>
            </div>
          </div>
          <div class="col-6 col-lg-3">
            <div class="mc a">
              <div class="ml">
                <i class="bi bi-shield-check me-1"></i>Flush saved company
              </div>
              <div class="mv" id="r-saved">₱0</div>
              <div class="ms">flush-out protection value</div>
            </div>
          </div>
          <div class="col-6 col-lg-3">
            <div class="mc e">
              <div class="ml">
                <i class="bi bi-calendar3 me-1"></i>Days to fill
              </div>
              <div class="mv" style="color: #94a3b8" id="r-days">0</div>
              <div class="ms">simulation duration</div>
            </div>
          </div>
          <div class="col-6 col-lg-3">
            <div class="mc c">
              <div class="ml">
                <i class="bi bi-person-lines-fill me-1"></i>Avg earned / member
              </div>
              <div class="mv" id="r-avg">₱0</div>
              <div class="ms">all bonus types combined</div>
            </div>
          </div>
          <div class="col-6 col-lg-3">
            <div class="mc d">
              <div class="ml">
                <i class="bi bi-bar-chart-line me-1"></i>Peak day payout
              </div>
              <div class="mv" id="r-peak">₱0</div>
              <div class="ms">highest single-day outflow</div>
            </div>
          </div>
          <div class="col-6 col-lg-3">
            <div class="mc a">
              <div class="ml">
                <i class="bi bi-percent me-1"></i>Cash margin
              </div>
              <div class="mv" id="r-cashmargin">0%</div>
              <div class="ms">after goods + all bonuses</div>
            </div>
          </div>
          <div class="col-6 col-lg-3">
            <div class="mc b">
              <div class="ml">
                <i class="bi bi-pie-chart me-1"></i>Total commission ratio
              </div>
              <div class="mv" id="r-comratio">0%</div>
              <div class="ms">of gross entry revenue</div>
            </div>
          </div>
        </div>

        <div class="sim-divider"></div>
        <div class="sec-label mb-2">
          <i class="bi bi-table me-2"></i>Day-by-Day Simulation Log
        </div>

        <div class="sim-card p-0 mb-5" style="overflow: hidden">
          <div class="tbl-wrap">
            <table>
              <thead>
                <tr>
                  <th>Day</th>
                  <th>Members</th>
                  <th>+New</th>
                  <th>Active</th>
                  <th>Capped</th>
                  <th>Perm.Inact.</th>
                  <th>Entry (₱)</th>
                  <th>React.Rev</th>
                  <th>Goods cost</th>
                  <th>Cash in</th>
                  <th>Pairing out</th>
                  <th>Fixed DI out</th>
                  <th>Direct ref</th>
                  <th>Unilevel</th>
                  <th>Day net</th>
                  <th>Cumul. profit</th>
                </tr>
              </thead>
              <tbody id="tbl-body"></tbody>
            </table>
          </div>
          <div class="pager">
            <button onclick="pg(-1)">
              <i class="bi bi-chevron-left"></i> Prev
            </button>
            <span id="pg-info">1 / 1</span>
            <button onclick="pg(1)">
              Next <i class="bi bi-chevron-right"></i>
            </button>
          </div>
        </div>
      </div>
      <!-- /results -->
    </div>
    <!-- /container -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
      // ══════════════════════════════════════════
      //  PRESETS
      //  Optimal rationale:
      //    Entry ₱10,000 · goods 60% → cash-in ₱4,000/member
      //    Pairing ₱1,500 · cap 2/day → max ₱3,000 payout risk
      //    Income cap ₱40,000 = ~26× pairing bonus (fair ceiling)
      //    Reactivation fee ₱2,000 (20% of entry, low barrier)
      //    Window 30 days (reasonable deadline)
      //    Reactrate 70% (most members choose to re-up)
      //    DFI ₱150/day × 120 days = ₱18,000 max fixed income per member
      //    Direct ₱500 + Unilevel L1 300/L2 200/L3 150/L4-5 100/L6-10 50
      //    Per-join commission load: 500+300+200+150+100+100+50+50+50+50 = ~1,550
      //    Gross cash-in ₱4,000 − ₱1,550 referral − ₱150 DFI = ₱2,300 pre-pairing buffer
      //    Sustained ~25–30% net margin over run (healthy company, decent member ROI)
      // ══════════════════════════════════════════
      const PRESETS = {
        default: {
          entry: 10000,
          pcost: 30,
          bonus: 2000,
          cap: 3,
          reactfee: 10000,
          reactwin: 15,
          reactrate: 100,
          dfi: 100,
          dfidays: 90,
          direct: 500,
          ul1: 300,
          ul2: 200,
          ul3: 150,
          ul4: 100,
          ul5: 100,
          ul6: 50,
          ul7: 50,
          ul8: 50,
          ul9: 50,
          ul10: 50,
          maxm: 1000,
          npd: 50,
          depth: 4,
        },
        aggressive: {
          entry: 10000,
          pcost: 70,
          bonus: 3000,
          cap: 5,
          reactfee: 4000,
          reactwin: 45,
          reactrate: 70,
          dfi: 300,
          dfidays: 120,
          direct: 800,
          ul1: 400,
          ul2: 250,
          ul3: 200,
          ul4: 150,
          ul5: 150,
          ul6: 100,
          ul7: 100,
          ul8: 100,
          ul9: 100,
          ul10: 100,
          maxm: 5000,
          npd: 200,
          depth: 5,
        },
        lean: {
          entry: 10000,
          pcost: 35,
          bonus: 2000,
          cap: 3,
          reactfee: 2000,
          reactwin: 30,
          reactrate: 60,
          dfi: 100,
          dfidays: 60,
          direct: 500,
          ul1: 300,
          ul2: 200,
          ul3: 150,
          ul4: 100,
          ul5: 100,
          ul6: 50,
          ul7: 50,
          ul8: 50,
          ul9: 50,
          ul10: 50,
          maxm: 1000,
          npd: 50,
          depth: 4,
        },
        highcap: {
          entry: 20000,
          pcost: 60,
          bonus: 2000,
          cap: 3,
          reactfee: 5000,
          reactwin: 60,
          reactrate: 50,
          dfi: 200,
          dfidays: 90,
          direct: 500,
          ul1: 300,
          ul2: 200,
          ul3: 150,
          ul4: 100,
          ul5: 100,
          ul6: 50,
          ul7: 50,
          ul8: 50,
          ul9: 50,
          ul10: 50,
          maxm: 2000,
          npd: 80,
          depth: 4,
        },
        highref: {
          entry: 10000,
          pcost: 60,
          bonus: 1000,
          cap: 3,
          reactfee: 2000,
          reactwin: 20,
          reactrate: 55,
          dfi: 150,
          dfidays: 60,
          direct: 1000,
          ul1: 600,
          ul2: 400,
          ul3: 300,
          ul4: 200,
          ul5: 200,
          ul6: 100,
          ul7: 100,
          ul8: 100,
          ul9: 100,
          ul10: 100,
          maxm: 1000,
          npd: 50,
          depth: 6,
        },
        fixedheavy: {
          entry: 10000,
          pcost: 55,
          bonus: 1000,
          cap: 2,
          reactfee: 3000,
          reactwin: 30,
          reactrate: 65,
          dfi: 500,
          dfidays: 180,
          direct: 400,
          ul1: 200,
          ul2: 150,
          ul3: 100,
          ul4: 75,
          ul5: 75,
          ul6: 25,
          ul7: 25,
          ul8: 25,
          ul9: 25,
          ul10: 25,
          maxm: 1000,
          npd: 50,
          depth: 4,
        },
      };

      // ══════════════════════════════════════════
      //  FIELD CONFIGS — min/max/step for clamping
      // ══════════════════════════════════════════
      const FIELD_CFG = {
        entry: { min: 500, max: 100000, step: 500 },
        pcost: { min: 0, max: 95, step: 1 },
        bonus: { min: 100, max: 20000, step: 100 },
        cap: { min: 1, max: 30, step: 1 },
        reactfee: { min: 0, max: 50000, step: 500 },
        reactwin: { min: 1, max: 180, step: 1 },
        reactrate: { min: 0, max: 100, step: 1 },
        dfi: { min: 0, max: 5000, step: 1 },
        dfidays: { min: 7, max: 730, step: 1 },
        direct: { min: 0, max: 10000, step: 1 },
        ul1: { min: 0, max: 3000, step: 1 },
        ul2: { min: 0, max: 2000, step: 1 },
        ul3: { min: 0, max: 1000, step: 1 },
        ul4: { min: 0, max: 500, step: 1 },
        ul5: { min: 0, max: 500, step: 1 },
        ul6: { min: 0, max: 300, step: 1 },
        ul7: { min: 0, max: 300, step: 1 },
        ul8: { min: 0, max: 300, step: 1 },
        ul9: { min: 0, max: 300, step: 1 },
        ul10: { min: 0, max: 300, step: 1 },
        maxm: { min: 100, max: 50000, step: 1 },
        npd: { min: 1, max: 2000, step: 1 },
        depth: { min: 1, max: 10, step: 1 },
      };

      const fmtN = (n) => Math.round(n).toLocaleString();
      const fmtP = (n) => "₱" + fmtN(n);

      function clamp(v, min, max) {
        return Math.min(max, Math.max(min, v));
      }

      function updateCapDisplay() {
        const entry = getVal("entry");
        const capVal = entry * 3;
        const el = document.getElementById("cap-display");
        if (el) el.textContent = "₱" + fmtN(capVal);
      }

      function syncFromSlider(k) {
        const sl = document.getElementById("s-" + k);
        const ni = document.getElementById("n-" + k);
        if (!sl || !ni) return;
        ni.value = parseFloat(sl.value);
        ni.classList.remove("err");
        if (k === "entry") updateCapDisplay();
      }

      function syncFromNum(k) {
        const sl = document.getElementById("s-" + k);
        const ni = document.getElementById("n-" + k);
        if (!sl || !ni) return;
        let v = parseFloat(ni.value);
        const cfg = FIELD_CFG[k] || {};
        if (isNaN(v)) {
          ni.classList.add("err");
          return;
        }
        ni.classList.remove("err");
        v = clamp(v, cfg.min ?? -Infinity, cfg.max ?? Infinity);
        const slMin = parseFloat(sl.min),
          slMax = parseFloat(sl.max);
        sl.value = clamp(v, slMin, slMax);
        if (k === "entry") updateCapDisplay();
      }

      function getVal(k) {
        const ni = document.getElementById("n-" + k);
        if (ni) {
          const v = parseFloat(ni.value);
          if (!isNaN(v)) return v;
        }
        const sl = document.getElementById("s-" + k);
        return sl ? parseFloat(sl.value) : 0;
      }

      function applyPreset(key, btn) {
        document
          .querySelectorAll(".preset-btn")
          .forEach((b) => b.classList.remove("on"));
        btn.classList.add("on");
        const p = PRESETS[key] || PRESETS.default;
        Object.entries(p).forEach(([k, v]) => {
          const sl = document.getElementById("s-" + k);
          const ni = document.getElementById("n-" + k);
          if (sl) sl.value = v;
          if (ni) {
            ni.value = v;
            ni.classList.remove("err");
          }
        });
        updateCapDisplay();
      }

      function getUniLevels() {
        return [
          0,
          getVal("ul1"),
          getVal("ul2"),
          getVal("ul3"),
          getVal("ul4"),
          getVal("ul5"),
          getVal("ul6"),
          getVal("ul7"),
          getVal("ul8"),
          getVal("ul9"),
          getVal("ul10"),
        ];
      }
      function uniPerJoin(levels, depth) {
        let t = 0;
        for (let i = 1; i <= Math.min(depth, 10); i++) t += levels[i];
        return t;
      }

      let simLog = [],
        curPage = 1;
      const PER_PAGE = 25;

      function runSim() {
        const progWrap = document.getElementById("prog");
        const progBar = document.getElementById("prog-bar");
        progWrap.style.display = "block";
        progBar.style.width = "10%";
        setTimeout(() => {
          const entryVal = getVal("entry");
          const params = {
            entry: entryVal,
            pcostPct: getVal("pcost") / 100,
            bonus: getVal("bonus"),
            cap: getVal("cap"),
            incap: entryVal * 3, // always 3× entry fee — all income types combined
            reactfee: getVal("reactfee"),
            reactwin: getVal("reactwin"),
            reactrate: getVal("reactrate") / 100,
            dfi: getVal("dfi"),
            dfidays: getVal("dfidays"),
            direct: getVal("direct"),
            maxM: getVal("maxm"),
            npd: getVal("npd"),
            depth: getVal("depth"),
            levels: getUniLevels(),
          };
          const r = simulate(params);
          progBar.style.width = "100%";
          setTimeout(() => {
            progWrap.style.display = "none";
            progBar.style.width = "0%";
            displayResults(r, params);
          }, 300);
        }, 40);
      }

      // ══════════════════════════════════════════
      //  CORE SIMULATION
      //  incap = 3 × entry · covers binary pairing + daily fixed income combined
      // ══════════════════════════════════════════
      function simulate(p) {
        const {
          entry,
          pcostPct,
          bonus,
          cap,
          incap,
          reactfee,
          reactwin,
          reactrate,
          dfi,
          dfidays,
          direct,
          maxM,
          npd,
          depth,
          levels,
        } = p;

        const leftCount = []; // left-leg recruit count
        const rightCount = []; // right-leg recruit count
        const paidPairs = []; // cumulative pairs whose pointer has been advanced
        const memberEarned = []; // total earned this cycle (pairing + DFI combined)
        const memberStatus = []; // 'active' | 'capped' | 'perminact'
        const inactDay = []; // day account became capped
        const memberDfiDays = []; // active days of fixed income used this cycle
        const memberDfiDone = []; // DFI duration limit reached (permanent per cycle, resets on reactivation)

        let totalMembers = 0,
          totalGross = 0,
          totalGoods = 0,
          totalBonusPair = 0,
          totalBonusDirect = 0,
          totalBonusUni = 0;
        let totalBonusDfi = 0,
          totalReactRev = 0;
        let totalPairsPaid = 0,
          totalFlushed = 0,
          totalCapSaved = 0,
          totalDfiCapSaved = 0;
        let totalCappedEver = 0,
          totalReacts = 0,
          totalPermInact = 0;
        const log = [];
        let day = 0;

        const uniPerMember = uniPerJoin(levels, depth);

        function addMember() {
          const idx = totalMembers;
          leftCount.push(0);
          rightCount.push(0);
          paidPairs.push(0);
          memberEarned.push(0);
          memberStatus.push("active");
          inactDay.push(-1);
          memberDfiDays.push(0);
          memberDfiDone.push(false);
          totalMembers++;
          totalGross += entry;
          totalGoods += entry * pcostPct;
          if (idx > 0) totalBonusDirect += direct;
          const myDepth = Math.min(idx, depth);
          for (let lv = 1; lv <= myDepth && lv <= 10; lv++)
            totalBonusUni += levels[lv];
          let cur = idx;
          while (cur > 0) {
            const parent = (cur - 1) >> 1;
            if (cur === 2 * parent + 1) leftCount[parent]++;
            else rightCount[parent]++;
            cur = parent;
          }
        }

        // Helper: check and apply cap — returns true if member just became capped
        function checkCap(i) {
          if (memberEarned[i] >= incap && memberStatus[i] === "active") {
            memberStatus[i] = "capped";
            inactDay[i] = day;
            totalCappedEver++;
            return true;
          }
          return false;
        }

        while (totalMembers < maxM) {
          day++;

          // ── 1. Expire reactivation windows ──
          for (let i = 0; i < totalMembers; i++) {
            if (memberStatus[i] === "capped" && day - inactDay[i] > reactwin) {
              memberStatus[i] = "perminact";
              totalPermInact++;
            }
          }

          // ── 2. Process reactivations (day after capping) ──
          let dayReactRev = 0,
            dayReacts = 0;
          for (let i = 0; i < totalMembers; i++) {
            if (memberStatus[i] === "capped" && day === inactDay[i] + 1) {
              if (Math.random() < reactrate) {
                memberStatus[i] = "active";
                memberEarned[i] = 0; // full reset — new earning cycle
                memberDfiDays[i] = 0; // DFI duration resets too
                memberDfiDone[i] = false; // can earn DFI again in new cycle
                paidPairs[i] = Math.min(leftCount[i], rightCount[i]); // skip accumulated pairs
                totalReactRev += reactfee;
                dayReactRev += reactfee;
                dayReacts++;
                totalReacts++;
              }
            }
          }

          // ── 3. New members ──
          const toAdd = Math.min(npd, maxM - totalMembers);
          const prevTotal = totalMembers;
          for (let i = 0; i < toAdd; i++) addMember();
          const newMembers = totalMembers - prevTotal;
          const entryToday = newMembers * entry;
          const goodsToday = newMembers * entry * pcostPct;
          const cashInToday = entryToday - goodsToday + dayReactRev;
          const directPaidToday =
            day === 1 && prevTotal === 0
              ? (newMembers - 1) * direct
              : newMembers * direct;
          const uniToday = newMembers * uniPerMember;

          // ── 4. Pairing bonuses (cap-aware, covers combined earnings) ──
          let pairToday = 0,
            paidToday = 0,
            flushedToday = 0,
            capSavedToday = 0;
          for (let i = 0; i < totalMembers; i++) {
            if (memberStatus[i] !== "active") continue;
            const maxPairs = Math.min(leftCount[i], rightCount[i]);
            const newPairs = maxPairs - paidPairs[i];
            if (newPairs <= 0) continue;

            // How much room is left under the combined cap?
            const capRoom = Math.max(0, incap - memberEarned[i]);
            const maxPayableByBonus =
              bonus > 0 ? Math.floor(capRoom / bonus) : 0;
            const pairsEligible = Math.min(newPairs, maxPayableByBonus);
            const capsaved = newPairs - pairsEligible; // blocked by cap
            const payNow = Math.min(pairsEligible, cap); // further limited by daily pair cap
            const flushed = pairsEligible - payNow; // excess over daily cap

            paidPairs[i] += newPairs;
            const earned = payNow * bonus;
            memberEarned[i] += earned;
            pairToday += earned;
            paidToday += payNow;
            flushedToday += flushed;
            capSavedToday += capsaved * bonus;
            checkCap(i);
          }
          totalBonusPair += pairToday;
          totalPairsPaid += paidToday;
          totalFlushed += flushedToday;
          totalCapSaved += capSavedToday;

          // ── 5. Daily fixed income (also subject to combined cap) ──
          let dfiToday = 0,
            dfiCapSavedToday = 0;
          if (dfi > 0) {
            for (let i = 0; i < totalMembers; i++) {
              if (memberStatus[i] !== "active") continue; // paused while inactive
              if (memberDfiDone[i]) continue; // duration exhausted this cycle

              // Check cap room
              const capRoom = Math.max(0, incap - memberEarned[i]);
              if (capRoom <= 0) {
                // cap already hit — DFI blocked
                dfiCapSavedToday += dfi;
                checkCap(i);
                continue;
              }
              const actualDfi = Math.min(dfi, capRoom); // partial if near cap
              memberDfiDays[i]++;
              memberEarned[i] += actualDfi;
              dfiToday += actualDfi;
              if (actualDfi < dfi) dfiCapSavedToday += dfi - actualDfi;

              // Stop DFI permanently for this cycle if cap now reached or duration maxed
              if (memberEarned[i] >= incap) {
                memberDfiDone[i] = true;
                checkCap(i);
              } else if (memberDfiDays[i] >= dfidays) {
                memberDfiDone[i] = true;
              }
            }
          }
          totalBonusDfi += dfiToday;
          totalDfiCapSaved += dfiCapSavedToday;
          totalCapSaved += dfiCapSavedToday;

          const totalOutToday =
            pairToday + dfiToday + directPaidToday + uniToday;
          const netToday = cashInToday - totalOutToday;
          const cumulProfit =
            totalGross -
            totalGoods +
            totalReactRev -
            totalBonusPair -
            totalBonusDirect -
            totalBonusUni -
            totalBonusDfi;
          const activeCount = memberStatus.filter((s) => s === "active").length;
          const cappedCount = memberStatus.filter((s) => s === "capped").length;
          const permCount = memberStatus.filter(
            (s) => s === "perminact",
          ).length;

          log.push({
            day,
            totalMembers,
            newMembers,
            activeCount,
            cappedCount,
            permCount,
            entryToday,
            dayReactRev,
            goodsToday,
            cashInToday,
            pairToday,
            dfiToday,
            directPaidToday,
            uniToday,
            netToday,
            cumulProfit,
            paidToday,
            flushedToday,
            capSavedToday,
          });
        }

        const totalAllBonuses =
          totalBonusPair + totalBonusDirect + totalBonusUni + totalBonusDfi;
        const netProfit =
          totalGross - totalGoods + totalReactRev - totalAllBonuses;
        const cashRevenue = totalGross - totalGoods + totalReactRev;
        return {
          log,
          totalGross,
          totalGoods,
          totalReactRev,
          totalBonusPair,
          totalBonusDirect,
          totalBonusUni,
          totalBonusDfi,
          totalPairsPaid,
          totalFlushed,
          totalCapSaved,
          totalDfiCapSaved,
          totalCappedEver,
          totalReacts,
          totalPermInact,
          totalMembers,
          days: day,
          netProfit,
          cashRevenue,
          incap, // pass through for display
          margin: cashRevenue > 0 ? (netProfit / cashRevenue) * 100 : 0,
          grossMargin: totalGross > 0 ? (netProfit / totalGross) * 100 : 0,
          flushSaved: totalFlushed * bonus,
          maxDayPayout: log.length
            ? Math.max(
                ...log.map(
                  (l) =>
                    l.pairToday + l.dfiToday + l.directPaidToday + l.uniToday,
                ),
              )
            : 0,
          avgEarn: totalMembers > 0 ? totalAllBonuses / totalMembers : 0,
          comRatio: totalGross > 0 ? (totalAllBonuses / totalGross) * 100 : 0,
          uniPerMember,
        };
      }

      // ══════════════════════════════════════════
      //  DISPLAY RESULTS
      // ══════════════════════════════════════════
      function displayResults(r, p) {
        const {
          entry,
          pcostPct,
          bonus,
          cap,
          direct,
          depth,
          dfi,
          dfidays,
          incap,
          reactfee,
          reactwin,
          reactrate,
          levels,
        } = p;

        const lossEl = document.getElementById("alert-loss");
        const warnEl = document.getElementById("alert-warn");
        if (r.netProfit < 0) {
          lossEl.style.display = "block";
          document.getElementById("alert-loss-text").textContent =
            "Company is operating at a LOSS of " +
            fmtP(Math.abs(r.netProfit)) +
            ". Total commissions + goods cost exceed all revenue. Reduce bonuses or increase entry fee.";
        } else lossEl.style.display = "none";
        if (r.grossMargin < 10 && r.netProfit >= 0) {
          warnEl.style.display = "block";
          document.getElementById("alert-warn-text").textContent =
            "Thin margin: only " +
            r.grossMargin.toFixed(1) +
            "% net margin on gross entry. A membership surge could push this negative.";
        } else warnEl.style.display = "none";

        const profEl = document.getElementById("r-profit");
        profEl.textContent = fmtP(r.netProfit);
        profEl.style.color =
          r.netProfit >= 0 ? "var(--success)" : "var(--danger)";
        document.getElementById("r-margin").textContent =
          r.grossMargin.toFixed(1) +
          "% of gross  ·  " +
          r.margin.toFixed(1) +
          "% of cash revenue  ·  " +
          r.comRatio.toFixed(1) +
          "% paid as commissions";

        const cashIn = Math.round(entry * (1 - pcostPct));
        const uniTotal = Math.round(r.uniPerMember);
        const leftover = cashIn - direct - uniTotal - (dfi > 0 ? dfi : 0);
        const flowData = [
          {
            label: "Entry fee",
            val: fmtP(entry),
            color: "var(--bs-body-color)",
          },
          {
            label: `− Goods cost (${Math.round(pcostPct * 100)}%)`,
            val: fmtP(Math.round(entry * pcostPct)),
            color: "var(--danger)",
          },
          {
            label: "= Cash in/member",
            val: fmtP(cashIn),
            color: "var(--info)",
          },
          {
            label: "− Direct referral",
            val: fmtP(direct),
            color: "var(--danger)",
          },
          {
            label: `− Unilevel (${depth}L)`,
            val: fmtP(uniTotal),
            color: "var(--danger)",
          },
          {
            label: `− Daily fixed (${dfi > 0 ? fmtP(dfi) + "/d" : "off"})`,
            val: dfi > 0 ? fmtP(dfi) : "₱0",
            color: dfi > 0 ? "var(--pink)" : "var(--muted)",
          },
          {
            label: "= Pre-pairing margin",
            val: fmtP(leftover),
            color: leftover >= 0 ? "var(--success)" : "var(--danger)",
          },
        ];
        document.getElementById("flow-cards").innerHTML = flowData
          .map(
            (f) => `
    <div class="flow-item">
      <div class="flow-label">${f.label}</div>
      <div class="flow-val" style="color:${f.color}">${f.val}</div>
    </div>`,
          )
          .join("");

        document.getElementById("r-gross").textContent = fmtP(r.totalGross);
        document.getElementById("r-gross-s").textContent =
          fmtN(r.totalMembers) + " members × " + fmtP(entry);
        document.getElementById("r-pcost").textContent = fmtP(r.totalGoods);
        document.getElementById("r-pcost-s").textContent =
          Math.round(pcostPct * 100) +
          "% goods · cash in: " +
          fmtP(r.cashRevenue - r.totalReactRev);
        document.getElementById("r-reactrev").textContent = fmtP(
          r.totalReactRev,
        );
        document.getElementById("r-reactrev-s").textContent =
          fmtN(r.totalReacts) + " reactivations × " + fmtP(reactfee);
        document.getElementById("r-cashin").textContent = fmtP(r.cashRevenue);
        document.getElementById("r-pair-paid").textContent = fmtP(
          r.totalBonusPair,
        );
        document.getElementById("r-pair-s").textContent =
          fmtN(r.totalPairsPaid) + " pairs × " + fmtP(bonus);
        document.getElementById("r-direct-paid").textContent = fmtP(
          r.totalBonusDirect,
        );
        document.getElementById("r-direct-s").textContent =
          "~" + fmtN(r.totalMembers) + " members × " + fmtP(direct);
        document.getElementById("r-uni-paid").textContent = fmtP(
          r.totalBonusUni,
        );
        const uniBreakdown = p.levels
          .slice(1, depth + 1)
          .map((v, i) => `L${i + 1}:${fmtP(v)}`)
          .join(" · ");
        document.getElementById("r-uni-s").textContent =
          "avg " + fmtP(r.uniPerMember) + "/join · " + uniBreakdown;
        document.getElementById("r-dfi-paid").textContent = fmtP(
          r.totalBonusDfi,
        );
        document.getElementById("r-dfi-s").textContent =
          dfi > 0
            ? fmtP(dfi) + "/day · max " + dfidays + " days/member"
            : "Disabled (₱0/day)";
        document.getElementById("r-capped").textContent = fmtN(
          r.totalCappedEver,
        );
        document.getElementById("r-reacts").textContent = fmtN(r.totalReacts);
        document.getElementById("r-reacts-s").textContent =
          fmtP(r.totalReactRev) + " total revenue";
        document.getElementById("r-perminact").textContent = fmtN(
          r.totalPermInact,
        );
        document.getElementById("r-capsaved").textContent = fmtP(
          r.totalCapSaved,
        );
        document.getElementById("r-capsaved-s").textContent =
          "cap = " + fmtP(r.incap) + " (3× entry) · pair+DFI blocked";
        document.getElementById("r-pairs-n").textContent = fmtN(
          r.totalPairsPaid,
        );
        document.getElementById("r-flushed").textContent = fmtN(r.totalFlushed);
        document.getElementById("r-saved").textContent = fmtP(r.flushSaved);
        document.getElementById("r-days").textContent = fmtN(r.days);
        document.getElementById("r-avg").textContent = fmtP(r.avgEarn);
        document.getElementById("r-peak").textContent = fmtP(r.maxDayPayout);
        document.getElementById("r-cashmargin").textContent =
          r.margin.toFixed(1) + "%";
        document.getElementById("r-comratio").textContent =
          r.comRatio.toFixed(1) + "%";

        simLog = r.log;
        curPage = 1;
        renderTable();
        document.getElementById("results").style.display = "block";
        setTimeout(
          () =>
            document
              .getElementById("results")
              .scrollIntoView({ behavior: "smooth", block: "start" }),
          120,
        );
      }

      function renderTable() {
        const total = Math.ceil(simLog.length / PER_PAGE);
        const start = (curPage - 1) * PER_PAGE;
        const slice = simLog.slice(start, start + PER_PAGE);
        document.getElementById("pg-info").textContent =
          "Page " + curPage + " of " + total;
        document.getElementById("tbl-body").innerHTML = slice
          .map(
            (r) => `
    <tr>
      <td>${r.day}</td>
      <td>${fmtN(r.totalMembers)}</td>
      <td class="tw">+${fmtN(r.newMembers)}</td>
      <td class="tg">${fmtN(r.activeCount)}</td>
      <td class="tp">${fmtN(r.cappedCount)}</td>
      <td class="tr">${fmtN(r.permCount)}</td>
      <td class="ti">${fmtP(r.entryToday)}</td>
      <td class="tp">${fmtP(r.dayReactRev)}</td>
      <td class="tr">${fmtP(r.goodsToday)}</td>
      <td class="ti">${fmtP(r.cashInToday)}</td>
      <td class="tr">${fmtP(r.pairToday)}</td>
      <td class="tpk">${fmtP(r.dfiToday)}</td>
      <td class="tr">${fmtP(r.directPaidToday)}</td>
      <td class="tr">${fmtP(r.uniToday)}</td>
      <td class="${r.netToday >= 0 ? "tg" : "tr"}">${fmtP(r.netToday)}</td>
      <td class="${r.cumulProfit >= 0 ? "tg" : "tr"}">${fmtP(r.cumulProfit)}</td>
    </tr>`,
          )
          .join("");
      }

      function pg(dir) {
        const total = Math.ceil(simLog.length / PER_PAGE);
        curPage = Math.max(1, Math.min(total, curPage + dir));
        renderTable();
        document
          .getElementById("tbl-body")
          .closest(".sim-card")
          .scrollIntoView({ behavior: "smooth", block: "start" });
      }

      // Initialise cap display on page load
      updateCapDisplay();
    </script>
  </body>
</html>
