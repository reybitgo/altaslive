<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Binary MLM Simulator v6</title>
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
      .num-input.sm {
        width: 68px;
      }
      .num-input.mix-num {
        width: 62px;
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

      /* ─── PLAN EDITOR ─── */
      .plan-card {
        background: linear-gradient(135deg, var(--surface-1) 0%, #161d30 100%);
        border: 1px solid var(--border-col);
        border-radius: 14px;
        padding: 1rem 1.125rem;
        margin-bottom: 1rem;
        position: relative;
      }
      .plan-card.plan-a {
        border-top: 3px solid var(--accent);
      }
      .plan-card.plan-b {
        border-top: 3px solid var(--purple);
      }
      .plan-card.plan-c {
        border-top: 3px solid var(--pink);
      }
      .plan-card-head {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem 1rem;
        align-items: center;
        margin-bottom: 0.875rem;
        padding-right: 2.25rem;
      }
      .plan-flags {
        display: flex;
        flex-wrap: wrap;
        gap: 0.375rem 1rem;
        align-items: center;
        flex: 1 1 100%;
        order: 30;
      }
      .plan-name {
        flex: 1;
        min-width: 130px;
        background: var(--surface-3);
        border: 1px solid var(--border-col);
        border-radius: 7px;
        color: #fff;
        font-family: var(--font-display);
        font-size: 13px;
        font-weight: 600;
        padding: 6px 10px;
        outline: none;
      }
      .plan-name:focus {
        border-color: var(--accent-border);
      }
      .plan-mix {
        display: flex;
        flex-direction: column;
        gap: 4px;
        min-width: 170px;
      }
      .plan-switch {
        margin-bottom: 0 !important;
        font-size: 11.5px;
        color: #94a3b8;
        white-space: nowrap;
        user-select: none;
      }
      .plan-switch .form-check-input {
        width: 2.1em;
        height: 1.05em;
        margin-top: 0.05em;
        cursor: pointer;
        background-color: var(--surface-3);
        border-color: var(--border-bright);
      }
      .plan-switch .form-check-input:checked {
        background-color: var(--accent);
        border-color: var(--accent);
      }
      .plan-switch.indirect .form-check-input:checked {
        background-color: var(--purple);
        border-color: var(--purple);
      }
      .plan-switch.dfi .form-check-input:checked {
        background-color: var(--pink);
        border-color: var(--pink);
      }
      .auto-rule {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 8px;
        font-size: 11px;
        color: var(--muted);
      }
      .auto-rule-opt {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        cursor: pointer;
        user-select: none;
      }
      .auto-rule-opt input {
        accent-color: var(--accent);
        cursor: pointer;
        margin: 0;
      }
      .btn-rm {
        position: absolute;
        top: 0.7rem;
        right: 0.7rem;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        border: 1px solid var(--border-col);
        background: var(--surface-3);
        color: #94a3b8;
        font-size: 12px;
        line-height: 1;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background 0.15s, color 0.15s, border-color 0.15s;
        z-index: 2;
      }
      .btn-rm:hover {
        background: var(--danger-dim);
        color: var(--danger);
        border-color: rgba(239, 68, 68, 0.4);
      }
      .plan-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
        gap: 0.7rem 1rem;
        align-items: end;
      }
      .plan-grid .si-label {
        font-size: 10.5px;
      }
      .plan-grid .num-input {
        width: 100%;
      }
      .hidden-field {
        display: none !important;
      }
      .plv-row {
        margin-top: 0.875rem;
        padding-top: 0.75rem;
        border-top: 1px solid var(--border-col);
        display: flex;
        flex-direction: column;
        gap: 8px;
      }
      .plv-grid {
        display: grid;
        grid-template-columns: repeat(5, minmax(76px, 1fr));
        gap: 0.55rem;
        max-width: 460px;
      }
      .plv-grid .plv {
        display: flex;
        flex-direction: column;
        gap: 3px;
        font-size: 10px;
        color: var(--muted);
        font-family: var(--font-mono);
      }
      .plv-grid .num-input {
        width: 100%;
      }
      .plv-label {
        font-size: 10.5px;
        color: var(--muted);
      }
      .plan-mix-total {
        font-size: 11px;
        font-family: var(--font-mono);
        color: var(--muted);
      }
      .plan-mix-total.ok {
        color: var(--success);
      }
      .plan-mix-total.bad {
        color: var(--danger);
      }
      .btn-plan-add {
        width: 100%;
        padding: 9px;
        background: var(--surface-2);
        border: 1px dashed var(--border-bright);
        border-radius: 10px;
        color: #94a3b8;
        font-size: 12px;
        font-weight: 600;
        font-family: var(--font-display);
        cursor: pointer;
        transition: all 0.15s;
      }
      .btn-plan-add:hover {
        border-color: var(--accent-border);
        color: var(--accent);
        background: var(--accent-dim);
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
      .flow-caption {
        font-size: 11.5px;
        color: var(--muted);
        font-family: var(--font-mono);
        margin-top: 0.5rem;
        line-height: 1.6;
      }

      .flow-caption-grid {
        display: flex;
        align-items: stretch;
        gap: 10px;
        overflow-x: auto;
        margin-top: 0.5rem;
        padding-bottom: 4px;
        scrollbar-width: thin;
        scrollbar-color: var(--surface-3) transparent;
      }
      .flow-caption-grid::-webkit-scrollbar {
        height: 4px;
      }
      .flow-caption-grid::-webkit-scrollbar-thumb {
        background: var(--surface-3);
        border-radius: 2px;
      }
      .fc-chip {
        flex: 0 0 240px;
        background: var(--surface-2);
        border: 1px solid var(--border-col);
        border-radius: 10px;
        padding: 0.7rem 0.8rem;
      }
      .fc-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 6px;
      }
      .fc-name {
        font-weight: 700;
        font-size: 13px;
        color: #fff;
      }
      .fc-mix {
        font-size: 10px;
        font-weight: 600;
        color: var(--muted);
        background: var(--surface-3);
        border: 1px solid var(--border-col);
        padding: 2px 7px;
        border-radius: 999px;
        white-space: nowrap;
      }
      .fc-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
        font-size: 11.5px;
        line-height: 2;
      }
      .fc-row span {
        color: var(--muted);
      }
      .fc-row b {
        font-family: var(--font-mono);
        font-weight: 600;
        color: #e2e8f0;
        text-align: right;
      }
      .fc-flags {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
        margin-top: 6px;
        padding-top: 7px;
        border-top: 1px dashed var(--border-col);
      }
      .fc-flag {
        font-size: 10px;
        font-weight: 600;
        padding: 2px 7px;
        border-radius: 999px;
        white-space: nowrap;
      }
      .fc-flag.on {
        background: rgba(16, 185, 129, 0.14);
        color: var(--success);
        border: 1px solid rgba(16, 185, 129, 0.35);
      }
      .fc-flag.off {
        background: var(--surface-3);
        color: var(--muted);
        border: 1px solid var(--border-col);
      }

      .hero-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 10px;
      }
      .hs-item {
        background: var(--surface-1);
        border: 1px solid var(--border-col);
        border-radius: 12px;
        padding: 0.95rem 1rem;
        text-align: center;
      }
      .hs-lbl {
        font-size: 10px;
        font-weight: 600;
        letter-spacing: 0.8px;
        text-transform: uppercase;
        color: var(--muted);
        margin-bottom: 7px;
      }
      .hs-val {
        font-size: clamp(20px, 3vw, 28px);
        font-weight: 700;
        font-family: var(--font-mono);
        line-height: 1;
      }
      .hs-val.white { color: #fff; }
      .hs-val.green { color: var(--success); }
      .hs-val.amber { color: var(--accent); }
      .hs-val.info  { color: var(--info); }
      .hs-val.slate { color: #94a3b8; }
      .hs-sub {
        font-size: 10.5px;
        color: var(--muted);
        margin-top: 6px;
        line-height: 1.4;
      }

      .total-row td {
        border-top: 2px solid var(--border-bright);
        font-weight: 700;
        color: #fff;
        background: rgba(245, 158, 11, 0.07);
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
      table.compact {
        min-width: 860px;
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
      tbody td.left-td {
        text-align: left;
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
        .plv-grid {
          grid-template-columns: repeat(2, minmax(76px, 1fr));
          max-width: 100%;
        }
      }
    </style>
  </head>
  <body>
    <!-- ═══ HEADER ═══ -->
    <div class="app-header">
      <div class="container">
        <div class="app-badge">
          <i class="bi bi-diagram-3-fill"></i> Binary MLM Simulator v6
        </div>
        <div class="app-title">Company Profit Calculator</div>
        <div class="app-subtitle">
          <span class="tag">Multi-plan join mix</span>
          <span class="tag">Volume-based pairing</span>
          <span class="tag">Per-plan toggles (binary · indirect · DFI)</span>
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
            <div class="comp-name">1 · Membership Entry + Plan Mix</div>
            <div class="comp-desc">
              Every new member buys one of the configured
              <strong style="color: #e2e8f0">plans</strong>. Each day's new
              members are split across plans by the
              <strong style="color: #e2e8f0">join mix %</strong>. A portion of
              the entry fee covers the
              <strong style="color: #e2e8f0">cost of goods</strong> (global
              product-cost %); the remainder is the company's
              <strong style="color: #e2e8f0">cash-in</strong> — the working
              capital from which all bonuses are paid. Every member is placed
              into the binary tree using
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
            <div class="comp-name">2 · Binary Pairing (Volume-Based)</div>
            <div class="comp-desc">
              Each member has a
              <strong style="color: #e2e8f0">left leg</strong> and a
              <strong style="color: #e2e8f0">right leg</strong>. Every
              <strong style="color: #c4b5fd">paid</strong> member contributes
              their plan's <strong style="color: #e2e8f0">pair volume</strong>
              (₱) into the matching leg of every upline ancestor. An ancestor
              earns the
              <strong style="color: #e2e8f0"
                >minimum of its two legs' accumulated volume</strong
              >, settled incrementally — so an unbalanced incoming volume
              carries forward and matches in a later placement. A
              <strong style="color: #e2e8f0">daily pair cap</strong> limits
              payable volume per member per day
              <em>(cap × own pair volume, in pesos)</em>; excess is
              <strong style="color: #fca5a5">flushed (permanently lost)</strong
              >. Inactive and capped members are skipped and earn nothing (their
              leg volume still accrues).
            </div>
            <span class="comp-badge amber"
              >Matched volume min(L,R) · Daily cap = cap × pair volume ₱</span
            >
          </div>
        </div>
        <div class="comp-row">
          <div class="comp-icon purple">
            <i class="bi bi-toggle-on"></i>
          </div>
          <div class="comp-body">
            <div class="comp-name">3 · Per-Plan Compensation Toggles</div>
            <div class="comp-desc">
              Every plan carries the same switches as a production package:
              <strong style="color: #e2e8f0">Binary</strong>
              (<code>pairing_enabled</code>) ·
              <strong style="color: #e2e8f0">Indirect</strong>
              (<code>indirect_referral_enabled</code>) ·
              <strong style="color: #e2e8f0">DFI</strong>
              (<code>dfi_enabled</code>), plus a global Direct-referral switch.
              Disabling a toggle turns that income stream off for the plan
              exactly as it does in the live system.
            </div>
            <span class="comp-badge amber"
              >Binary · Indirect · DFI — mirrored from packages</span
            >
          </div>
        </div>
        <div class="comp-row">
          <div class="comp-icon purple">
            <i class="bi bi-bar-chart-steps"></i>
          </div>
          <div class="comp-body">
            <div class="comp-name">4 · Income Cap &amp; Reactivation</div>
            <div class="comp-desc">
              Each member's
              <strong style="color: #e2e8f0">maximum total income cap</strong>
              is their plan's
              <strong style="color: #c4b5fd"
                >entry fee × lifetime cap multiplier</strong
              >. The cap covers
              <strong style="color: #e2e8f0">all income types combined</strong>
              — pairing, daily fixed income, direct referral and unilevel. Once
              cumulative earnings reach this limit, the account becomes
              <strong style="color: #fca5a5">inactive</strong> and earns nothing
              until reactivation. To resume, the member pays the plan's
              <strong style="color: #e2e8f0">Reactivation Fee</strong>; income
              counters reset and only post-reactivation earnings count toward
              the new cycle. Missing the
              <strong style="color: #e2e8f0">Reactivation Window</strong> makes
              the account
              <strong style="color: #fca5a5">permanently inactive</strong>.
            </div>
            <span class="comp-badge purple"
              >Cap = Entry ₱ × Multiplier · Covers All Income Streams</span
            >
          </div>
        </div>
        <div class="comp-row">
          <div class="comp-icon pink">
            <i class="bi bi-calendar-check-fill"></i>
          </div>
          <div class="comp-body">
            <div class="comp-name">5 · Daily Fixed Income</div>
            <div class="comp-desc">
              Every <strong style="color: #e2e8f0">active</strong> member on a
              DFI-enabled plan earns a
              <strong style="color: #e2e8f0">fixed daily amount</strong> for up
              to the plan's set number of days. DFI also counts toward the
              income cap — it stops at either the duration limit
              <em>or</em> the cap, whichever comes first. Days while inactive do
              <strong>not</strong> count; the clock pauses and resumes on
              reactivation (with a fresh daily cycle).
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
            <div class="comp-name">6 · Direct Referral Bonus</div>
            <div class="comp-desc">
              When a member directly recruits a new member, the sponsor
              immediately receives the
              <strong style="color: #e2e8f0">Direct Referral Bonus</strong> —
              a one-time cash payment per recruit, the amount coming from the
              <strong style="color: #e2e8f0">new member's plan</strong>. Paid at
              registration only while the sponsor is active and under the income
              cap; once capped, further referral bonuses are
              <strong style="color: #fca5a5">blocked</strong>.
            </div>
            <span class="comp-badge green"
              >Paid Once Per Direct Recruit · Blocked When Capped</span
            >
          </div>
        </div>
        <div class="comp-row">
          <div class="comp-icon blue"><i class="bi bi-layers-fill"></i></div>
          <div class="comp-body">
            <div class="comp-name">7 · Unilevel Bonus (Up to 10 Levels)</div>
            <div class="comp-desc">
              When a new member on an indirect-enabled plan joins, a
              <strong style="color: #e2e8f0">unilevel bonus</strong> flows up
              the <strong style="color: #e2e8f0">sponsor chain</strong>. L1
              (direct sponsor) gets the most; higher levels get less. The
              per-level amounts come from the
              <strong style="color: #e2e8f0">new member's plan</strong> (L1–L10
              config), and the
              <strong style="color: #e2e8f0">Avg Sponsor Depth</strong> setting
              stands in for the average real chain length. Each upline receives
              their share only while active and under the income cap.
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
            <div class="comp-name">8 · Flush-Out Protection (Daily Cap)</div>
            <div class="comp-desc">
              If matched volume on a given day exceeds the daily pair cap, the
              excess is
              <strong style="color: #fca5a5">flushed</strong> — not paid, not
              carried forward. This controls runaway payouts on high-growth days
              and protects company cash flow.
            </div>
            <span class="comp-badge red"
              >Excess Volume = Lost Permanently · No Carry-Forward</span
            >
          </div>
        </div>
      </div>

      <!-- ══════════ NOTE BOX ══════════ -->
      <div class="note-box mb-4">
        <i class="bi bi-info-circle me-2" style="color: var(--accent)"></i>
        <strong style="color: #e2e8f0">Simulator mechanics:</strong>
        Binary tree uses BFS placement; the sponsor chain uses a
        <strong>linear, enrollment-order proxy</strong> (member i is sponsored
        by member i−1) — an aggregate stand-in for a real branching sponsorship
        graph, with <strong>Avg Sponsor Depth</strong> modeling the average
        chain length. Pairing is
        <strong style="color: #c4b5fd">volume-based</strong>: each paid member
        injects their plan's pair volume into every ancestor's leg, and an
        ancestor earns matched volume up to a daily cap of
        <em>plan daily pair cap × plan pair volume</em> pesos; daily overflow is
        <strong style="color: #fca5a5"
          >flushed permanently (no carry-forward)</strong
        >. The income cap is per-plan:
        <code>entry fee × lifetime cap multiplier</code>, covering all income
        types combined; on cap the member earns nothing until reactivation
        (counters reset), and missing the window makes the account permanently
        inactive. New members on a plan with the <em>Binary</em> toggle off
        contribute no pair volume and trigger no pairing payouts; with
        <em>Indirect</em> off no unilevel is paid for their join; with
        <em>DFI</em> off they earn no daily fixed income. Leg counts always
        increment. VIP bypass columns (<code>capping_bypass</code> /
        <code>daily_cap_bypass</code>) are not modeled. The
        <strong style="color: #e2e8f0">CD split</strong>
        (<code>user_cd_status</code>) is intentionally not modeled.
        <strong style="color: #e2e8f0">Tip:</strong> plan fields are number
        inputs; join mix uses slider + number (auto-synced). Join mix is
        auto-normalized if it does not total 100% — or turn on
        <strong style="color: #e2e8f0">Auto join mix</strong> to compute it
        from plan count / entry fees automatically.
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
          <i class="bi bi-people me-1"></i>Referral-Only
        </button>
        <button class="preset-btn" onclick="applyPreset('fixedheavy', this)">
          <i class="bi bi-calendar-week me-1"></i>Fixed-Heavy
        </button>
      </div>

      <!-- ══════════ CARD 1: PLAN CONFIGURATION ══════════ -->
      <div class="sim-card mb-3">
        <div class="card-heading">
          <i class="bi bi-cpu"></i> Plan Configuration
          <span class="plan-mix-total ms-auto" id="mix-total"></span>
        </div>
        <div id="plans"></div>
        <button class="btn-plan-add" onclick="addPlan()" id="btn-add-plan">
          <i class="bi bi-plus-lg me-1"></i>Add Plan
        </button>
      </div>

      <!-- ══════════ CARD 2: GLOBAL SETTINGS ══════════ -->
      <div class="sim-card mb-3">
        <div class="card-heading">
          <i class="bi bi-toggles"></i> Global Settings
        </div>
        <div class="row g-3">
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
              <div class="si-hint">
                % of entry fee covering real goods cost (all plans)
              </div>
            </div>
          </div>
          <div class="col-12 col-sm-6 col-lg-3">
            <div class="si">
              <div class="si-label">Reactivation rate %</div>
              <div class="ctrl-row">
                <input
                  type="range"
                  id="s-reactrate"
                  min="0"
                  max="100"
                  step="5"
                  value="100"
                  oninput="syncFromSlider('reactrate')"
                />
                <input
                  type="number"
                  class="num-input"
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
          <div class="col-12 col-sm-6 col-lg-3">
            <div class="si">
              <div class="si-label" style="margin-bottom: 8px">
                Direct referral (global)
              </div>
              <label class="form-check form-switch plan-switch" id="sw-direct-wrap">
                <input
                  class="form-check-input"
                  type="checkbox"
                  id="sw-direct"
                  checked
                />
                <span class="form-check-label"
                  >Pay direct referral on joins</span
                >
              </label>
              <div class="si-hint">
                master switch — plan amounts still apply per join
              </div>
            </div>
          </div>
          <div class="col-12 col-sm-6 col-lg-3">
            <div class="si">
              <div class="si-label" style="margin-bottom: 8px">
                Auto join mix
              </div>
              <label class="form-check form-switch plan-switch" id="sw-automix-wrap">
                <input
                  class="form-check-input"
                  type="checkbox"
                  id="sw-automix"
                  checked
                />
                <span class="form-check-label">Auto-compute</span>
              </label>
              <div class="auto-rule">
                <label class="auto-rule-opt"
                  ><input type="radio" name="automix-rule" value="equal" checked />
                  Equal split</label
                >
                <label class="auto-rule-opt"
                  ><input type="radio" name="automix-rule" value="entry" />
                  Inverse entry</label
                >
              </div>
              <div class="si-hint">
                Computes each plan's join % automatically — equal split, or
                weighted by inverse entry fee so cheaper plans take more joins.
                Auto-normalized to 100%.
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ══════════ CARD 3: GROWTH PARAMETERS ══════════ -->
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
                  id="s-maxm"
                  min="100"
                  max="50000"
                  step="100"
                  value="1000"
                  oninput="syncFromSlider('maxm')"
                />
                <input
                  type="number"
                  class="num-input"
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
                  id="s-npd"
                  min="1"
                  max="2000"
                  step="1"
                  value="50"
                  oninput="syncFromSlider('npd')"
                />
                <input
                  type="number"
                  class="num-input"
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
                  id="s-depth"
                  min="1"
                  max="10"
                  step="1"
                  value="4"
                  oninput="syncFromSlider('depth')"
                />
                <input
                  type="number"
                  class="num-input"
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

        <div class="hero-stats mb-4">
          <div class="hs-item">
            <div class="hs-lbl"><i class="bi bi-people me-1"></i>Accounts encoded</div>
            <div class="hs-val white" id="r-members">0</div>
            <div class="hs-sub">total members, all plans</div>
          </div>
          <div class="hs-item">
            <div class="hs-lbl"><i class="bi bi-person-check me-1"></i>Active now</div>
            <div class="hs-val green" id="r-active">0</div>
            <div class="hs-sub">currently earning</div>
          </div>
          <div class="hs-item">
            <div class="hs-lbl"><i class="bi bi-pause-circle me-1"></i>Capped now</div>
            <div class="hs-val amber" id="r-capped-now">0</div>
            <div class="hs-sub" id="r-capped-now-s">in reactivation window</div>
          </div>
          <div class="hs-item">
            <div class="hs-lbl"><i class="bi bi-person-x me-1"></i>Permanently inactive</div>
            <div class="hs-val slate" id="r-perm-now">0</div>
            <div class="hs-sub">missed reactivation window</div>
          </div>
          <div class="hs-item">
            <div class="hs-lbl"><i class="bi bi-calendar3 me-1"></i>Days</div>
            <div class="hs-val info" id="r-days-hero">0</div>
            <div class="hs-sub">simulation duration</div>
          </div>
        </div>

        <div class="sec-label mb-2">
          Money Flow Per Member Registration (mix-weighted avg)
        </div>
        <div class="flow-strip mb-1" id="flow-cards"></div>
        <div class="flow-caption-grid mb-4" id="flow-caption"></div>

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
                <i class="bi bi-check2-circle me-1"></i>Pair settlements
              </div>
              <div class="mv" id="r-pairs-n">0</div>
              <div class="ms">ancestor matched-volume payments</div>
            </div>
          </div>
          <div class="col-6 col-lg-3">
            <div class="mc d">
              <div class="ml">
                <i class="bi bi-x-circle me-1"></i>Flush events
              </div>
              <div class="mv" id="r-flushed">0</div>
              <div class="ms" id="r-flushed-s"></div>
            </div>
          </div>
          <div class="col-6 col-lg-3">
            <div class="mc a">
              <div class="ml">
                <i class="bi bi-shield-check me-1"></i>Flush saved company
              </div>
              <div class="mv" id="r-saved">₱0</div>
              <div class="ms">daily-cap flush value in pesos</div>
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
          <i class="bi bi-table me-2"></i>Plan Breakdown
        </div>
        <div class="sim-card p-0 mb-4" style="overflow: hidden">
          <div class="tbl-wrap">
            <table class="compact">
              <thead>
                <tr>
                  <th>Plan</th>
                  <th>Mix %</th>
                  <th>Members</th>
                  <th>% pop</th>
                  <th>Entry (₱)</th>
                  <th>Goods (₱)</th>
                  <th>Direct (₱)</th>
                  <th>Unilevel (₱)</th>
                  <th>Pairing (₱)</th>
                  <th>DFI (₱)</th>
                  <th>Total out (₱)</th>
                </tr>
              </thead>
              <tbody id="plan-tbl-body"></tbody>
            </table>
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
      //  Each preset is a set of plans (with join mix) + global controls.
      //  Default mirrors tmp/pckgs/pckgs.json — the seeded live package
      //  lineup (Basic → Platinum, entry ₱5k–₱1M, per-plan binary/indirect/
      //  DFI toggles, unilevel L1–L4, lifetime cap 3×–5× entry).
      // ══════════════════════════════════════════
      const PRESETS = {
        default: {
          pcost: 30,
          maxm: 2000,
          npd: 50,
          depth: 4,
          reactrate: 100,
          directOn: true,
          plans: [
            {
              name: "Basic",
              mix: 100,
              entry: 5000,
              pairBonus: 0,
              pairCap: 0,
              directRef: 500,
              capMult: 5,
              reactFee: 5000,
              reactWin: 15,
              dfi: 20,
              dfiDays: 1250,
              binary: false,
              indirect: true,
              dfiOn: true,
              levels: [0, 200, 100, 50, 10, 0, 0, 0, 0, 0, 0],
            },
            {
              name: "Starter",
              mix: 100,
              entry: 10000,
              pairBonus: 1500,
              pairCap: 5,
              directRef: 1000,
              capMult: 3,
              reactFee: 10000,
              reactWin: 15,
              dfi: 33,
              dfiDays: 909,
              binary: true,
              indirect: false,
              dfiOn: true,
              levels: [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            },
            {
              name: "Pro",
              mix: 100,
              entry: 10000,
              pairBonus: 0,
              pairCap: 0,
              directRef: 1500,
              capMult: 5,
              reactFee: 10000,
              reactWin: 15,
              dfi: 50,
              dfiDays: 1000,
              binary: false,
              indirect: true,
              dfiOn: true,
              levels: [0, 500, 300, 200, 100, 0, 0, 0, 0, 0, 0],
            },
            {
              name: "Elite",
              mix: 100,
              entry: 20000,
              pairBonus: 0,
              pairCap: 0,
              directRef: 2500,
              capMult: 5,
              reactFee: 20000,
              reactWin: 15,
              dfi: 100,
              dfiDays: 1000,
              binary: false,
              indirect: true,
              dfiOn: true,
              levels: [0, 1000, 500, 300, 100, 0, 0, 0, 0, 0, 0],
            },
            {
              name: "Silver",
              mix: 100,
              entry: 50000,
              pairBonus: 0,
              pairCap: 0,
              directRef: 6500,
              capMult: 5,
              reactFee: 50000,
              reactWin: 15,
              dfi: 250,
              dfiDays: 1000,
              binary: false,
              indirect: true,
              dfiOn: true,
              levels: [0, 2500, 1000, 500, 200, 0, 0, 0, 0, 0, 0],
            },
            {
              name: "Gold",
              mix: 100,
              entry: 100000,
              pairBonus: 0,
              pairCap: 0,
              directRef: 12000,
              capMult: 5,
              reactFee: 100000,
              reactWin: 15,
              dfi: 500,
              dfiDays: 1000,
              binary: false,
              indirect: true,
              dfiOn: true,
              levels: [0, 5000, 2000, 1000, 500, 0, 0, 0, 0, 0, 0],
            },
            {
              name: "Diamond",
              mix: 100,
              entry: 500000,
              pairBonus: 0,
              pairCap: 0,
              directRef: 60000,
              capMult: 5,
              reactFee: 500000,
              reactWin: 15,
              dfi: 2500,
              dfiDays: 1000,
              binary: false,
              indirect: true,
              dfiOn: true,
              levels: [0, 20000, 10000, 5000, 2000, 0, 0, 0, 0, 0, 0],
            },
            {
              name: "Platinum",
              mix: 100,
              entry: 1000000,
              pairBonus: 0,
              pairCap: 0,
              directRef: 120000,
              capMult: 5,
              reactFee: 1000000,
              reactWin: 15,
              dfi: 5000,
              dfiDays: 1000,
              binary: false,
              indirect: true,
              dfiOn: true,
              levels: [0, 40000, 20000, 10000, 5000, 0, 0, 0, 0, 0, 0],
            },
          ],
        },
        aggressive: {
          pcost: 40,
          maxm: 3000,
          npd: 150,
          depth: 6,
          reactrate: 70,
          directOn: true,
          plans: [
            {
              name: "Starter",
              mix: 70,
              entry: 10000,
              pairBonus: 2000,
              pairCap: 3,
              directRef: 500,
              capMult: 3.0,
              reactFee: 10000,
              reactWin: 15,
              dfi: 100,
              dfiDays: 90,
              binary: true,
              indirect: true,
              dfiOn: true,
              levels: [0, 300, 200, 150, 100, 100, 50, 50, 50, 50, 50],
            },
            {
              name: "Power",
              mix: 30,
              entry: 50000,
              pairBonus: 8000,
              pairCap: 8,
              directRef: 4000,
              capMult: 4.0,
              reactFee: 15000,
              reactWin: 30,
              dfi: 500,
              dfiDays: 120,
              binary: true,
              indirect: true,
              dfiOn: true,
              levels: [0, 800, 600, 450, 300, 300, 150, 150, 150, 150, 150],
            },
          ],
        },
        lean: {
          pcost: 30,
          maxm: 1000,
          npd: 50,
          depth: 4,
          reactrate: 60,
          directOn: true,
          plans: [
            {
              name: "Lite",
              mix: 100,
              entry: 5000,
              pairBonus: 800,
              pairCap: 2,
              directRef: 400,
              capMult: 2.0,
              reactFee: 1000,
              reactWin: 20,
              dfi: 25,
              dfiDays: 60,
              binary: true,
              indirect: true,
              dfiOn: true,
              levels: [0, 150, 100, 75, 50, 50, 25, 25, 25, 25, 25],
            },
          ],
        },
        highcap: {
          pcost: 50,
          maxm: 2000,
          npd: 80,
          depth: 5,
          reactrate: 50,
          directOn: true,
          plans: [
            {
              name: "Elite",
              mix: 100,
              entry: 20000,
              pairBonus: 2500,
              pairCap: 3,
              directRef: 1500,
              capMult: 10.0,
              reactFee: 8000,
              reactWin: 60,
              dfi: 200,
              dfiDays: 180,
              binary: true,
              indirect: true,
              dfiOn: true,
              levels: [0, 400, 300, 200, 150, 150, 100, 100, 100, 100, 100],
            },
          ],
        },
        highref: {
          pcost: 30,
          maxm: 1000,
          npd: 50,
          depth: 6,
          reactrate: 55,
          directOn: true,
          plans: [
            {
              name: "Referral",
              mix: 100,
              entry: 10000,
              pairBonus: 0,
              pairCap: 1,
              directRef: 3000,
              capMult: 5.0,
              reactFee: 5000,
              reactWin: 20,
              dfi: 150,
              dfiDays: 90,
              binary: false,
              indirect: true,
              dfiOn: true,
              levels: [0, 800, 600, 450, 300, 300, 150, 150, 150, 150, 150],
            },
          ],
        },
        fixedheavy: {
          pcost: 55,
          maxm: 1000,
          npd: 50,
          depth: 4,
          reactrate: 65,
          directOn: false,
          plans: [
            {
              name: "Fixed",
              mix: 100,
              entry: 10000,
              pairBonus: 1000,
              pairCap: 2,
              directRef: 0,
              capMult: 2.0,
              reactFee: 2000,
              reactWin: 30,
              dfi: 500,
              dfiDays: 180,
              binary: true,
              indirect: true,
              dfiOn: true,
              levels: [0, 100, 75, 50, 25, 25, 0, 0, 0, 0, 0],
            },
          ],
        },
      };

      const MAX_PLANS = 8;
      const DEFAULT_PLAN = {
        name: "Basic",
        mix: 100,
        entry: 5000,
        pairBonus: 0,
        pairCap: 0,
        directRef: 500,
        capMult: 5,
        reactFee: 5000,
        reactWin: 15,
        dfi: 20,
        dfiDays: 1250,
        binary: false,
        indirect: true,
        dfiOn: true,
        levels: [0, 200, 100, 50, 10, 0, 0, 0, 0, 0, 0],
      };

      const fmtN = (n) => Math.round(n).toLocaleString();
      const fmtP = (n) => "₱" + fmtN(n);

      function clamp(v, min, max) {
        return Math.min(max, Math.max(min, v));
      }

      function syncFromSlider(k) {
        const sl = document.getElementById("s-" + k);
        const ni = document.getElementById("n-" + k);
        if (!sl || !ni) return;
        ni.value = parseFloat(sl.value);
        ni.classList.remove("err");
      }

      function syncFromNum(k) {
        const sl = document.getElementById("s-" + k);
        const ni = document.getElementById("n-" + k);
        if (!sl || !ni) return;
        let v = parseFloat(ni.value);
        const min = parseFloat(sl.min),
          max = parseFloat(sl.max);
        if (isNaN(v)) return;
        v = clamp(v, min, max);
        ni.value = v;
        sl.value = v;
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

      // ══════════ PLAN ROW RENDERING ══════════
      function planRowHTML(i) {
        const tone = i % 3 === 0 ? "plan-a" : i % 3 === 1 ? "plan-b" : "plan-c";
        let levelsHtml = "";
        for (let l = 1; l <= 10; l++) {
          levelsHtml += `
        <label class="plv"><span>L${l}</span>
          <input type="number" class="num-input" data-lv="${l}" value="0" min="0" step="1" />
        </label>`;
        }
        return `
      <div class="plan-card ${tone}" data-pi="${i}">
        <div class="plan-card-head">
          <input type="text" class="plan-name" placeholder="Plan name" value="Plan ${i + 1}" />
          <div class="plan-flags">
          <label class="form-check form-switch plan-switch" title="Binary pairing enabled (pairing_enabled)">
            <input class="form-check-input" type="checkbox" data-flag="binary" checked />
            <span class="form-check-label">Binary</span>
          </label>
          <label class="form-check form-switch plan-switch indirect" title="Indirect (unilevel) enabled (indirect_referral_enabled)">
            <input class="form-check-input" type="checkbox" data-flag="indirect" checked />
            <span class="form-check-label">Indirect</span>
          </label>
          <label class="form-check form-switch plan-switch dfi" title="Daily fixed income enabled (dfi_enabled)">
            <input class="form-check-input" type="checkbox" data-flag="dfi" checked />
            <span class="form-check-label">DFI</span>
          </label>
          </div>
          <div class="plan-mix">
            <div class="si-label">Join mix %</div>
            <div class="ctrl-row">
              <input type="range" class="mix-slider" min="0" max="100" step="1" value="100" />
              <input type="number" class="num-input mix-num" value="100" min="0" max="100" step="1" />
            </div>
          </div>
          <button class="btn-rm" title="Remove plan">✕</button>
        </div>
        <div class="plan-grid">
          <div class="si"><div class="si-label">Entry fee (₱)</div><input type="number" class="num-input" data-f="entry" value="10000" min="1" step="500" /></div>
          <div class="si" data-stream="pair"><div class="si-label">Pair volume (₱)</div><input type="number" class="num-input" data-f="pair" value="2000" min="0" step="100" /></div>
          <div class="si" data-stream="pair"><div class="si-label">Daily pair cap</div><input type="number" class="num-input" data-f="cap" value="3" min="0" step="1" /></div>
          <div class="si"><div class="si-label">Direct ref (₱)</div><input type="number" class="num-input" data-f="direct" value="500" min="0" step="50" /></div>
          <div class="si"><div class="si-label">Cap mult ×</div><input type="number" class="num-input" data-f="mult" value="3" min="1" step="0.25" /></div>
          <div class="si"><div class="si-label">React. fee (₱)</div><input type="number" class="num-input" data-f="reactfee" value="10000" min="0" step="500" /></div>
          <div class="si"><div class="si-label">React. window (d)</div><input type="number" class="num-input" data-f="reactwin" value="15" min="1" step="1" /></div>
          <div class="si" data-stream="dfi"><div class="si-label">Daily fixed (₱/day)</div><input type="number" class="num-input" data-f="dfi" value="100" min="0" step="1" /></div>
          <div class="si" data-stream="dfi"><div class="si-label">Max income days</div><input type="number" class="num-input" data-f="dfidays" value="90" min="1" step="1" /></div>
        </div>
        <div class="plv-row">
          <div class="plv-label">Unilevel L1–L10 (₱) — from this plan, paid on its joins</div>
          <div class="plv-grid">${levelsHtml}</div>
        </div>
      </div>`;
      }

      function rowOf(el) {
        return el.closest(".plan-card");
      }

      function fieldValues(row, keys) {
        return keys.map((k) => {
          const i = row.querySelector(`input[data-f="${k}"]`);
          if (!i) return 0;
          const v = parseFloat(i.value);
          return isFinite(v) ? v : 0;
        });
      }
      function setFieldValues(row, keys, vals) {
        keys.forEach((k, idx) => {
          const i = row.querySelector(`input[data-f="${k}"]`);
          if (i && vals[idx] !== undefined) i.value = vals[idx];
        });
      }
      function levelValues(row) {
        const arr = [];
        for (let l = 1; l <= 10; l++) {
          const i = row.querySelector(`input[data-lv="${l}"]`);
          const v = i ? parseFloat(i.value) : NaN;
          arr.push(isFinite(v) ? v : 0);
        }
        return arr;
      }
      function setLevelValues(row, vals) {
        for (let l = 1; l <= 10; l++) {
          const i = row.querySelector(`input[data-lv="${l}"]`);
          if (i && vals[l - 1] !== undefined) i.value = vals[l - 1];
        }
      }
      function toggleStreamFields(row, stream, on) {
        const grid = row.querySelector(".plan-grid");
        grid.querySelectorAll(`.si[data-stream="${stream}"]`).forEach((el) => {
          el.classList.toggle("hidden-field", !on);
        });
      }
      function setFlagClass(row) {
        row._prev = row._prev || {};
        const checked = (f) => row.querySelector(`[data-flag="${f}"]`).checked;
        const streams = {
          pair: { on: checked("binary"), f: ["pair", "cap"] },
          dfi: { on: checked("dfi"), f: ["dfi", "dfidays"] },
        };
        for (const [key, st] of Object.entries(streams)) {
          if (!st.on) {
            if (row._prev[key] === undefined)
              row._prev[key] = fieldValues(row, st.f);
            setFieldValues(row, st.f, st.f.map(() => 0));
          } else if (row._prev[key] !== undefined) {
            setFieldValues(row, st.f, row._prev[key]);
            delete row._prev[key];
          }
          toggleStreamFields(row, key, st.on);
        }
        const indirect = checked("indirect");
        if (!indirect) {
          if (row._prev.indirect === undefined)
            row._prev.indirect = levelValues(row);
          setLevelValues(row, new Array(10).fill(0));
        } else if (row._prev.indirect !== undefined) {
          setLevelValues(row, row._prev.indirect);
          delete row._prev.indirect;
        }
        row.querySelector(".plv-row").classList.toggle("hidden-field", !indirect);
      }

      function syncMixSlider(row) {
        const sl = row.querySelector(".mix-slider");
        const ni = row.querySelector(".mix-num");
        if (!sl || !ni) return;
        ni.value = sl.value;
      }
      function syncMixNum(row) {
        const sl = row.querySelector(".mix-slider");
        const ni = row.querySelector(".mix-num");
        if (!sl || !ni) return;
        let v = parseFloat(ni.value);
        if (isNaN(v)) return;
        v = clamp(v, 0, 100);
        ni.value = v;
        sl.value = v;
      }

      const plansWrap = document.getElementById("plans");
      plansWrap.addEventListener("change", (e) => {
        const t = e.target;
        const row = t.closest(".plan-card");
        if (!row) return;
        if (t.matches(".mix-slider")) syncMixSlider(row);
        if (t.matches('[data-flag]')) setFlagClass(row);
        if (autoMixEnabled() && t.matches('[data-f="entry"]'))
          applyAutoMixToDom();
        updateMixTotal();
      });
      plansWrap.addEventListener("input", (e) => {
        const t = e.target;
        const row = t.closest(".plan-card");
        if (!row) return;
        if (t.matches(".mix-num")) syncMixNum(row);
        if (t.matches(".mix-slider")) syncMixSlider(row);
        if (autoMixEnabled() && t.matches('[data-f="entry"]'))
          applyAutoMixToDom();
        updateMixTotal();
      });
      plansWrap.addEventListener("click", (e) => {
        const t = e.target;
        if (t.matches(".btn-rm")) removePlan(t.closest(".plan-card"));
      });

      const swAuto = document.getElementById("sw-automix");
      if (swAuto) swAuto.addEventListener("change", applyAutoMixToDom);
      document
        .querySelectorAll('input[name="automix-rule"]')
        .forEach((el) => el.addEventListener("change", applyAutoMixToDom));

      function autoMixEnabled() {
        const sw = document.getElementById("sw-automix");
        return !!(sw && sw.checked);
      }
      function autoMixRule() {
        const sel = document.querySelector(
          'input[name="automix-rule"]:checked'
        );
        return sel && sel.value === "entry" ? "entry" : "equal";
      }
      function autoMixPlans(plans) {
        if (autoMixRule() === "entry") {
          const inv = plans.map((p) => 1 / Math.max(1, p.entry));
          const sum = inv.reduce((a, b) => a + b, 0);
          return plans.map((p, i) => (inv[i] / sum) * 100);
        }
        return plans.map(() => 100 / plans.length);
      }
      function niceMixes(mixes) {
        const r = mixes.map((m) => Math.floor(m * 10) / 10);
        const tenths = Math.round((100 - r.reduce((a, b) => a + b, 0)) * 10);
        const idx = mixes
          .map((m, i) => [i, m * 10 - Math.floor(m * 10)])
          .sort((a, b) => b[1] - a[1]);
        for (let k = 0; k < tenths; k++) r[idx[k % idx.length][0]] += 0.1;
        return r;
      }
      function applyAutoMixToDom() {
        const rows = plansWrap.querySelectorAll(".plan-card");
        const on = autoMixEnabled();
        document
          .querySelectorAll('input[name="automix-rule"]')
          .forEach((r) => (r.disabled = !on));
        rows.forEach((r) => {
          r.querySelector(".mix-slider").disabled = on;
          r.querySelector(".mix-num").disabled = on;
        });
        if (on && rows.length) {
          const plans = Array.from(rows).map(planFromRow);
          const mixes = niceMixes(autoMixPlans(plans));
          rows.forEach((r, i) => {
            r.querySelector(".mix-slider").value = mixes[i];
            r.querySelector(".mix-num").value = mixes[i];
          });
        }
        updateMixTotal();
      }

      function updateMixTotal() {
        const el = document.getElementById("mix-total");
        if (!el) return;
        if (autoMixEnabled()) {
          el.textContent = "Mix: auto (100%)";
          el.classList.add("ok");
          el.classList.remove("bad");
          return;
        }
        const rows = plansWrap.querySelectorAll(".plan-card");
        let sum = 0;
        rows.forEach((r) => {
          sum += parseFloat(r.querySelector(".mix-num").value) || 0;
        });
        el.textContent = "Mix total: " + Math.round(sum) + "%";
        el.classList.toggle("ok", Math.abs(sum - 100) < 0.5);
        el.classList.toggle("bad", Math.abs(sum - 100) >= 0.5);
      }

      function planFromRow(row) {
        const numV = (sel) => {
          const v = parseFloat(row.querySelector(sel)?.value);
          return isFinite(v) ? v : 0;
        };
        const name = (row.querySelector(".plan-name").value || "").trim();
        const levels = [0];
        for (let l = 1; l <= 10; l++)
          levels.push(numV(`input[data-lv="${l}"]`));
        return {
          name: name || "Plan",
          mix: clamp(Math.round(numV(".mix-num")), 0, 100),
          entry: Math.max(1, numV('input[data-f="entry"]')),
          pairBonus: Math.max(0, numV('input[data-f="pair"]')),
          pairCap: Math.max(0, Math.round(numV('input[data-f="cap"]'))),
          directRef: Math.max(0, numV('input[data-f="direct"]')),
          capMult: Math.max(1, numV('input[data-f="mult"]')),
          reactFee: Math.max(0, numV('input[data-f="reactfee"]')),
          reactWin: Math.max(1, Math.round(numV('input[data-f="reactwin"]'))),
          dfi: Math.max(0, numV('input[data-f="dfi"]')),
          dfiDays: Math.max(1, Math.round(numV('input[data-f="dfidays"]'))),
          binary: row.querySelector('[data-flag="binary"]').checked,
          indirect: row.querySelector('[data-flag="indirect"]').checked,
          dfiOn: row.querySelector('[data-flag="dfi"]').checked,
          levels,
        };
      }

      function readPlans() {
        const rows = plansWrap.querySelectorAll(".plan-card");
        const plans = Array.from(rows).map(planFromRow);
        if (autoMixEnabled()) {
          const mixes = autoMixPlans(plans);
          plans.forEach((p, i) => (p.mix = mixes[i]));
          return plans;
        }
        const totalMix = plans.reduce((s, p) => s + p.mix, 0);
        if (totalMix <= 0) {
          plans.forEach((p) => (p.mix = 100 / plans.length));
        } else if (Math.abs(totalMix - 100) >= 0.5) {
          plans.forEach((p) => (p.mix = (p.mix / totalMix) * 100));
        }
        return plans;
      }

      function setPlanValues(cfg, row) {
        row.querySelector(".plan-name").value = cfg.name || "";
        row.querySelector('[data-flag="binary"]').checked = !!cfg.binary;
        row.querySelector('[data-flag="indirect"]').checked = !!cfg.indirect;
        row.querySelector('[data-flag="dfi"]').checked = !!cfg.dfiOn;
        const sl = row.querySelector(".mix-slider");
        const ni = row.querySelector(".mix-num");
        sl.value = cfg.mix;
        ni.value = cfg.mix;
        const fMap = {
          entry: cfg.entry,
          pair: cfg.pairBonus,
          cap: cfg.pairCap,
          direct: cfg.directRef,
          mult: cfg.capMult,
          reactfee: cfg.reactFee,
          reactwin: cfg.reactWin,
          dfi: cfg.dfi,
          dfidays: cfg.dfiDays,
        };
        Object.entries(fMap).forEach(([k, v]) => {
          const input = row.querySelector(`input[data-f="${k}"]`);
          if (input) input.value = v;
        });
        for (let l = 1; l <= 10; l++) {
          const input = row.querySelector(`input[data-lv="${l}"]`);
          if (input) input.value = cfg.levels[l] || 0;
        }
        setFlagClass(row);
      }

      function renderPlans(configs) {
        plansWrap.innerHTML = configs.map((_, i) => planRowHTML(i)).join("");
        configs.forEach((cfg, i) => {
          setPlanValues(cfg, plansWrap.querySelectorAll(".plan-card")[i]);
        });
        applyAutoMixToDom();
        updateAddBtn();
      }

      function addPlan() {
        const count = plansWrap.querySelectorAll(".plan-card").length;
        if (count >= MAX_PLANS) return;
        const row = document.createElement("div");
        row.innerHTML = planRowHTML(count).trim();
        plansWrap.appendChild(row.firstChild);
        setPlanValues({ ...DEFAULT_PLAN, name: "Plan " + (count + 1) }, plansWrap.querySelectorAll(".plan-card")[count]);
        applyAutoMixToDom();
        updateAddBtn();
      }

      function removePlan(row) {
        if (!row) return;
        if (plansWrap.querySelectorAll(".plan-card").length <= 1) return;
        row.remove();
        applyAutoMixToDom();
        updateAddBtn();
      }

      function updateAddBtn() {
        const btn = document.getElementById("btn-add-plan");
        if (btn) btn.style.display =
          plansWrap.querySelectorAll(".plan-card").length >= MAX_PLANS
            ? "none"
            : "block";
      }

      function applyPreset(key, btn) {
        document
          .querySelectorAll(".preset-btn")
          .forEach((b) => b.classList.remove("on"));
        if (btn) btn.classList.add("on");
        const p = PRESETS[key] || PRESETS.default;
        const setField = (k, v) => {
          const sl = document.getElementById("s-" + k);
          const ni = document.getElementById("n-" + k);
          if (sl) sl.value = v;
          if (ni) ni.value = v;
        };
        setField("pcost", p.pcost);
        setField("maxm", p.maxm);
        setField("npd", p.npd);
        setField("depth", p.depth);
        setField("reactrate", p.reactrate);
        document.getElementById("sw-direct").checked = !!p.directOn;
        renderPlans(p.plans);
      }

      // ══════════════════════════════════════════
      //  GET PARAMS
      // ══════════════════════════════════════════
      function getParams() {
        return {
          plans: readPlans(),
          pcostPct: clamp(getVal("pcost"), 0, 95) / 100,
          maxm: Math.round(clamp(getVal("maxm"), 1, 50000)),
          npd: Math.round(clamp(getVal("npd"), 1, 2000)),
          depth: Math.round(clamp(getVal("depth"), 1, 10)),
          reactrate: clamp(getVal("reactrate"), 0, 100) / 100,
          directOn: document.getElementById("sw-direct").checked,
        };
      }

      // ══════════════════════════════════════════
      //  CORE SIMULATION
      //  Mirrors core/Commission.php, core/DailyFixedIncome.php and
      //  core/CapEngine.php:
      //   · binary = volume-based incremental matching (min(L,R), daily cap
      //     in pesos = daily_pair_cap × own pair volume, overflow flushed)
      //   · cap per member = own plan entry_fee × lifetime_cap_multiplier,
      //     all income streams combined
      //   · direct + unilevel amounts from the NEW member's plan; unilevel
      //     walks the linear sponsor chain (enrollment-order proxy)
      //   · plan toggles gate binary/indirect participation and DFI
      // ══════════════════════════════════════════
      function simulate(p) {
        const {
          plans,
          pcostPct,
          maxm,
          npd,
          depth,
          reactrate,
          directOn,
          resetDfiOnReact = false,
        } = p;

        if (!plans.length) return null;

        // Per-member state
        const planOf = []; // plan index per member
        const leftCount = [],
          rightCount = [];
        const leftVol = [],
          rightVol = []; // accumulated leg volume (pesos)
        const volMatched = []; // volume already settled (paid + flushed)
        const volToday = []; // volume settled today (for daily cap)
        const memberEarned = []; // lifetime earned this cycle
        const memberStatus = []; // active | capped | perminact
        const inactDay = [];
        const memberDfiDays = [];
        const memberDfiDone = [];

        const planStats = plans.map(() => ({
          members: 0,
          gross: 0,
          goods: 0,
          direct: 0,
          uni: 0,
          pair: 0,
          dfi: 0,
        }));

        let totalMembers = 0,
          totalGross = 0,
          totalGoods = 0,
          totalReactRev = 0;
        let totalPair = 0,
          totalDirect = 0,
          totalUni = 0,
          totalDfi = 0;
        let pairEvents = 0,
          flushEvents = 0,
          flushPesos = 0;
        let capSaved = 0,
          pairCapSaved = 0,
          directSaved = 0,
          uniSaved = 0,
          dfiCapSaved = 0;
        let totalCappedEver = 0,
          totalReacts = 0,
          totalPermInact = 0;
        const log = [];
        let day = 0;

        const capOf = (i) =>
          plans[planOf[i]].entry * plans[planOf[i]].capMult;

        function checkCap(i) {
          if (memberStatus[i] === "active" && memberEarned[i] >= capOf(i)) {
            memberStatus[i] = "capped";
            inactDay[i] = day;
            totalCappedEver++;
            return true;
          }
          return false;
        }

        // Credit one payment to an earner, aware of the lifetime cap.
        // kind: 'pair' | 'direct' | 'uni'; acc is the day accumulator.
        function creditEarner(i, amount, kind, acc) {
          if (amount <= 0) return;
          if (memberStatus[i] !== "active") {
            const b = amount;
            if (kind === "pair") pairCapSaved += b;
            else if (kind === "direct") directSaved += b;
            else uniSaved += b;
            capSaved += b;
            return;
          }
          const room = Math.max(0, capOf(i) - memberEarned[i]);
          const pay = Math.min(amount, room);
          memberEarned[i] += pay;
          if (kind === "pair") {
            totalPair += pay;
            acc.pair += pay;
            planStats[planOf[i]].pair += pay;
          } else if (kind === "direct") {
            totalDirect += pay;
            acc.direct += pay;
            planStats[planOf[i]].direct += pay;
          } else {
            totalUni += pay;
            acc.uni += pay;
            planStats[planOf[i]].uni += pay;
          }
          const blocked = amount - pay;
          if (blocked > 0) {
            if (kind === "pair") pairCapSaved += blocked;
            else if (kind === "direct") directSaved += blocked;
            else uniSaved += blocked;
            capSaved += blocked;
          }
          checkCap(i);
        }

        // Settle newly matched volume for ancestor a (mirrors the incremental
        // available − processed matching in Commission::processBinaryPlacement).
        function settlePairs(a, acc) {
          if (memberStatus[a] !== "active") return;
          const ap = plans[planOf[a]];
          if (!ap.binary || ap.pairBonus <= 0) return;
          const available = Math.min(leftVol[a], rightVol[a]);
          const newSettle = available - volMatched[a];
          if (newSettle <= 0) return;
          const capPesos = ap.pairCap * ap.pairBonus;
          const capRemaining = Math.max(0, capPesos - volToday[a]);
          const payNow = Math.min(newSettle, capRemaining);
          const flushNow = newSettle - payNow;
          volMatched[a] += newSettle;
          volToday[a] += payNow;
          if (flushNow > 0) {
            flushEvents++;
            flushPesos += flushNow;
          }
          if (payNow > 0) {
            pairEvents++;
            creditEarner(a, payNow, "pair", acc);
          }
        }

        // Direct + unilevel flow up the linear sponsor chain (idx-1, idx-2…).
        function creditRefChain(idx, acc) {
          const pl = plans[planOf[idx]]; // NEW member's plan drives amounts
          if (directOn && pl.directRef > 0) {
            creditEarner(idx - 1, pl.directRef, "direct", acc);
          }
          if (pl.indirect) {
            const levels = Math.min(idx, depth, 10);
            for (let lv = 1; lv <= levels; lv++) {
              const b = pl.levels[lv];
              if (b > 0) creditEarner(idx - lv, b, "uni", acc);
            }
          }
        }

        // Add one member. planIdx = which plan the join bought.
        function addMember(planIdx, acc) {
          const idx = totalMembers;
          const pl = plans[planIdx];
          planOf.push(planIdx);
          leftCount.push(0);
          rightCount.push(0);
          leftVol.push(0);
          rightVol.push(0);
          volMatched.push(0);
          volToday.push(0);
          memberEarned.push(0);
          memberStatus.push("active");
          inactDay.push(-1);
          memberDfiDays.push(0);
          memberDfiDone.push(false);
          totalMembers++;
          totalGross += pl.entry;
          totalGoods += pl.entry * pcostPct;
          planStats[planIdx].members++;
          planStats[planIdx].gross += pl.entry;
          planStats[planIdx].goods += pl.entry * pcostPct;

          // Binary: propagate volume up BFS parents + settle incrementally.
          // Leg counts always increment; volume only for binary-enabled paid
          // members. Disabled plans contribute no volume and no payouts.
          const newVol = pl.binary && pl.pairBonus > 0 ? pl.pairBonus : 0;
          let cur = idx;
          while (cur > 0) {
            const parent = (cur - 1) >> 1;
            const isLeft = cur === 2 * parent + 1;
            if (isLeft) leftCount[parent]++;
            else rightCount[parent]++;
            if (newVol > 0) {
              if (isLeft) leftVol[parent] += newVol;
              else rightVol[parent] += newVol;
              settlePairs(parent, acc);
            }
            cur = parent;
          }

          if (idx > 0) creditRefChain(idx, acc);
        }

        // Spread N joins across plans proportionally to join mix.
        function buildDaySeq(plansArr, n) {
          const totalW = plansArr.reduce((s, x) => s + x.mix, 0) || 1;
          const exact = plansArr.map((x) => (x.mix / totalW) * n);
          const counts = exact.map(Math.floor);
          let rem = n - counts.reduce((a, b) => a + b, 0);
          while (rem > 0) {
            let best = -1,
              bestFrac = -1;
            for (let k = 0; k < plansArr.length; k++) {
              const frac = exact[k] - counts[k];
              if (frac > bestFrac + 1e-9) {
                best = k;
                bestFrac = frac;
              }
            }
            counts[best]++;
            rem--;
          }
          const seq = [];
          const idxPos = counts.map(() => 0);
          const nextScore = plansArr.map((_, k) =>
            counts[k] > 0 ? 0 : Infinity
          );
          for (let j = 0; j < n; j++) {
            let best = -1,
              bestScore = Infinity;
            for (let k = 0; k < plansArr.length; k++) {
              if (nextScore[k] < bestScore) {
                bestScore = nextScore[k];
                best = k;
              }
            }
            seq.push(best);
            idxPos[best]++;
            nextScore[best] =
              idxPos[best] >= counts[best] ? Infinity : idxPos[best] / counts[best];
          }
          return seq;
        }

        while (totalMembers < maxm) {
          day++;

          // 1. Expire reactivation windows
          for (let i = 0; i < totalMembers; i++) {
            if (
              memberStatus[i] === "capped" &&
              day - inactDay[i] > plans[planOf[i]].reactWin
            ) {
              memberStatus[i] = "perminact";
              totalPermInact++;
            }
          }

          // 2. Process reactivations (day after capping)
          let dayReactRev = 0,
            dayReacts = 0;
          for (let i = 0; i < totalMembers; i++) {
            if (memberStatus[i] === "capped" && day === inactDay[i] + 1) {
              if (Math.random() < reactrate) {
                memberStatus[i] = "active";
                memberEarned[i] = 0; // full reset — new earning cycle
                // DFI entitlement is NOT restarted by default: a member can
                // never re-earn their full fixed-income allocation a second
                // time, so repeated reactivation cannot compound DFI.
                if (resetDfiOnReact) {
                  memberDfiDays[i] = 0;
                  memberDfiDone[i] = false;
                }
                // pair volume counters are NOT reset — the member resumes
                // matching the volume that accrued while capped
                totalReactRev += plans[planOf[i]].reactFee;
                dayReactRev += plans[planOf[i]].reactFee;
                dayReacts++;
                totalReacts++;
              }
            }
          }

          // 3. Reset daily pair-volume counters
          for (let i = 0; i < totalMembers; i++) volToday[i] = 0;

          // 4. New members
          const toAdd = Math.min(npd, maxm - totalMembers);
          const prevGross = totalGross;
          const prevGoods = totalGoods;
          const acc = {
            direct: 0,
            uni: 0,
            pair: 0,
            dfi: 0,
            directSaved: 0,
            uniSaved: 0,
          };
          const seq = buildDaySeq(plans, toAdd);
          for (let j = 0; j < toAdd; j++) addMember(seq[j], acc);
          const newMembers = toAdd;
          const entryToday = totalGross - prevGross;
          const goodsToday = totalGoods - prevGoods;
          const cashInToday = entryToday - goodsToday + dayReactRev;
          const directPaidToday = acc.direct;
          const uniToday = acc.uni;
          const pairToday = acc.pair;

          // 5. Daily fixed income (also subject to combined cap)
          let dfiToday = 0;
          for (let i = 0; i < totalMembers; i++) {
            const pln = plans[planOf[i]];
            if (!pln.dfiOn || pln.dfi <= 0) continue;
            if (memberStatus[i] !== "active") continue;
            if (memberDfiDone[i]) continue;
            const room = Math.max(0, capOf(i) - memberEarned[i]);
            if (room <= 0) {
              dfiCapSaved += pln.dfi;
              capSaved += pln.dfi;
              checkCap(i);
              continue;
            }
            const amt = Math.min(pln.dfi, room);
            memberDfiDays[i]++;
            memberEarned[i] += amt;
            totalDfi += amt;
            dfiToday += amt;
            planStats[planOf[i]].dfi += amt;
            if (amt < pln.dfi) {
              dfiCapSaved += pln.dfi - amt;
              capSaved += pln.dfi - amt;
            }
            if (memberEarned[i] >= capOf(i)) {
              memberDfiDone[i] = true;
              checkCap(i);
            } else if (memberDfiDays[i] >= pln.dfiDays) {
              memberDfiDone[i] = true;
            }
          }

          const totalOutToday =
            pairToday + dfiToday + directPaidToday + uniToday;
          const netToday = cashInToday - totalOutToday;
          const cumulProfit =
            totalGross -
            totalGoods +
            totalReactRev -
            totalPair -
            totalDirect -
            totalUni -
            totalDfi;
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
          });
        }

        const totalAllBonuses = totalPair + totalDirect + totalUni + totalDfi;
        const netProfit =
          totalGross - totalGoods + totalReactRev - totalAllBonuses;
        const cashRevenue = totalGross - totalGoods + totalReactRev;
        const weighted = { entry: 0, direct: 0, uni: 0, dfi: 0 };
        const tw = plans.reduce((s, x) => s + x.mix, 0) || 1;
        plans.forEach((pl) => {
          const f = (pl.mix || 0) / tw;
          weighted.entry += f * pl.entry;
          weighted.direct += f * (directOn ? pl.directRef : 0);
          let u = 0;
          for (let lv = 1; lv <= Math.min(depth, 10); lv++) u += pl.levels[lv];
          weighted.uni += f * u;
          weighted.dfi += f * (pl.dfiOn ? pl.dfi : 0);
        });

        return {
          log,
          totalGross,
          totalGoods,
          totalReactRev,
          totalPair,
          totalDirect,
          totalUni,
          totalDfi,
          pairEvents,
          flushEvents,
          flushPesos,
          capSaved,
          pairCapSaved,
          directSaved,
          uniSaved,
          dfiCapSaved,
          totalCappedEver,
          totalReacts,
          totalPermInact,
          totalMembers,
          activeNow: memberStatus.filter((s) => s === "active").length,
          cappedNow: memberStatus.filter((s) => s === "capped").length,
          permNow: memberStatus.filter((s) => s === "perminact").length,
          days: day,
          netProfit,
          cashRevenue,
          margin: cashRevenue > 0 ? (netProfit / cashRevenue) * 100 : 0,
          grossMargin: totalGross > 0 ? (netProfit / totalGross) * 100 : 0,
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
          weighted,
          plans,
          planStats,
          depth,
          directOn,
          pcostPct,
        };
      }

      // ══════════════════════════════════════════
      //  RUN
      // ══════════════════════════════════════════
      function runSim() {
        const progWrap = document.getElementById("prog");
        const progBar = document.getElementById("prog-bar");
        progWrap.style.display = "block";
        progBar.style.width = "10%";
        setTimeout(() => {
          const params = getParams();
          const r = simulate(params);
          progBar.style.width = "100%";
          setTimeout(() => {
            progWrap.style.display = "none";
            progBar.style.width = "0%";
            if (r) displayResults(r, params);
          }, 300);
        }, 40);
      }

      // ══════════════════════════════════════════
      //  DISPLAY RESULTS
      // ══════════════════════════════════════════
      function displayResults(r, p) {
        const { pcostPct } = p;
        const w = r.weighted;

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

        // ── flow strip (mix-weighted avg per join) ──
        const cashIn = w.entry - w.entry * pcostPct;
        const leftover =
          cashIn - w.direct - w.uni - (w.dfi > 0 ? w.dfi : 0);
        const flowData = [
          {
            label: "Entry fee (avg)",
            val: fmtP(w.entry),
            color: "var(--bs-body-color)",
          },
          {
            label: `− Goods cost (${Math.round(pcostPct * 100)}%)`,
            val: fmtP(w.entry * pcostPct),
            color: "var(--danger)",
          },
          {
            label: "= Cash in/member",
            val: fmtP(cashIn),
            color: "var(--info)",
          },
          {
            label: `− Direct referral`,
            val: fmtP(w.direct),
            color: r.directOn ? "var(--danger)" : "var(--muted)",
          },
          {
            label: `− Unilevel (${p.depth}L avg)`,
            val: fmtP(w.uni),
            color: "var(--danger)",
          },
          {
            label: `− Daily fixed (${w.dfi > 0 ? fmtP(w.dfi) + "/d" : "off"})`,
            val: w.dfi > 0 ? fmtP(w.dfi) : "₱0",
            color: w.dfi > 0 ? "var(--pink)" : "var(--muted)",
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
        const flagCls = (on) => (on ? "on" : "off");
        const flagTxt = (on) => (on ? "on" : "off");
        document.getElementById("flow-caption").innerHTML =
          '<div class="flow-caption-grid">' +
          r.plans
            .map((pl) => {
              const pairRow = pl.binary
                ? `<div class="fc-row"><span>Pair volume</span><b>${fmtP(pl.pairBonus)}/join · ${pl.pairCap}/day</b></div>`
                : `<div class="fc-row"><span>Pair volume</span><b>off</b></div>`;
              const dfiTxt = pl.dfiOn ? `${fmtP(pl.dfi)}/day` : "off";
              return `<div class="fc-chip">
      <div class="fc-head">
        <span class="fc-name">${pl.name}</span>
        <span class="fc-mix">${Math.round(pl.mix)}% mix</span>
      </div>
      <div class="fc-row"><span>Entry fee</span><b>${fmtP(pl.entry)}</b></div>
      ${pairRow}
      <div class="fc-flags">
        <span class="fc-flag ${flagCls(pl.binary)}">Binary ${flagTxt(pl.binary)}</span>
        <span class="fc-flag ${flagCls(pl.indirect)}">Indirect ${flagTxt(pl.indirect)}</span>
        <span class="fc-flag ${flagCls(pl.dfiOn)}">DFI ${dfiTxt}</span>
      </div>
    </div>`;
            })
            .join("") +
          "</div>";

        document.getElementById("r-members").textContent = fmtN(r.totalMembers);
        document.getElementById("r-active").textContent = fmtN(r.activeNow);
        document.getElementById("r-capped-now").textContent = fmtN(r.cappedNow);
        document.getElementById("r-capped-now-s").textContent =
          "reactivated " +
          fmtN(r.totalReacts) +
          " · ever capped " +
          fmtN(r.totalCappedEver);
        document.getElementById("r-perm-now").textContent = fmtN(r.permNow);
        document.getElementById("r-days-hero").textContent = fmtN(r.days);

        document.getElementById("r-gross").textContent = fmtP(r.totalGross);
        document.getElementById("r-gross-s").textContent =
          fmtN(r.totalMembers) +
          " members · avg " +
          fmtP(r.totalMembers ? r.totalGross / r.totalMembers : 0) +
          "/join";
        document.getElementById("r-pcost").textContent = fmtP(r.totalGoods);
        document.getElementById("r-pcost-s").textContent =
          Math.round(pcostPct * 100) +
          "% goods · cash in: " +
          fmtP(r.cashRevenue - r.totalReactRev);
        document.getElementById("r-reactrev").textContent = fmtP(
          r.totalReactRev,
        );
        document.getElementById("r-reactrev-s").textContent =
          fmtN(r.totalReacts) + " reactivations"; // fees vary per plan
        document.getElementById("r-cashin").textContent = fmtP(r.cashRevenue);
        document.getElementById("r-pair-paid").textContent = fmtP(r.totalPair);
        document.getElementById("r-pair-s").textContent =
          fmtN(r.pairEvents) +
          " settlements · " +
          fmtN(r.flushEvents) +
          " flush events · " +
          fmtP(r.pairCapSaved) +
          " cap-blocked";
        document.getElementById("r-direct-paid").textContent = fmtP(
          r.totalDirect,
        );
        document.getElementById("r-direct-s").textContent =
          fmtP(r.totalDirect) +
          " paid · " +
          fmtP(r.directSaved) +
          " cap-blocked";
        document.getElementById("r-uni-paid").textContent = fmtP(r.totalUni);
        const uniPaidAvg = r.totalMembers
          ? Math.round(r.totalUni / r.totalMembers)
          : 0;
        document.getElementById("r-uni-s").textContent =
          "avg " +
          fmtP(uniPaidAvg) +
          "/join paid · " +
          fmtP(r.uniSaved) +
          " cap-blocked · weighted " +
          fmtP(w.uni) +
          "/join";
        document.getElementById("r-dfi-paid").textContent = fmtP(r.totalDfi);
        document.getElementById("r-dfi-s").textContent =
          w.dfi > 0
            ? fmtP(w.dfi) + "/day weighted avg"
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
        document.getElementById("r-capsaved").textContent = fmtP(r.capSaved);
        document.getElementById("r-capsaved-s").textContent =
          "blocked: pair " +
          fmtP(r.pairCapSaved) +
          " + dfi " +
          fmtP(r.dfiCapSaved) +
          " + ref " +
          fmtP(r.directSaved + r.uniSaved);
        document.getElementById("r-pairs-n").textContent = fmtN(r.pairEvents);
        document.getElementById("r-flushed").textContent = fmtN(r.flushEvents);
        document.getElementById("r-flushed-s").textContent =
          fmtP(r.flushPesos) + " flushed volume";
        document.getElementById("r-saved").textContent = fmtP(r.flushPesos);
        document.getElementById("r-days").textContent = fmtN(r.days);
        document.getElementById("r-avg").textContent = fmtP(r.avgEarn);
        document.getElementById("r-peak").textContent = fmtP(r.maxDayPayout);
        document.getElementById("r-cashmargin").textContent =
          r.margin.toFixed(1) + "%";
        document.getElementById("r-comratio").textContent =
          r.comRatio.toFixed(1) + "%";

        renderPlanBreakdown(r);
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

      function renderPlanBreakdown(r) {
        const tbody = document.getElementById("plan-tbl-body");
        const planColors = ["var(--accent)", "var(--purple)", "var(--pink)"];
        const total = {
          members: 0,
          gross: 0,
          goods: 0,
          direct: 0,
          uni: 0,
          pair: 0,
          dfi: 0,
        };
        const rows = r.plans
          .map((pl, i) => {
            const s = r.planStats[i];
            total.members += s.members;
            total.gross += s.gross;
            total.goods += s.goods;
            total.direct += s.direct;
            total.uni += s.uni;
            total.pair += s.pair;
            total.dfi += s.dfi;
            const totalOut = s.direct + s.uni + s.pair + s.dfi;
            const pop =
              r.totalMembers > 0 ? ((s.members / r.totalMembers) * 100).toFixed(1) : "0.0";
            const flags = `${pl.binary ? "B" : "b"}·${pl.indirect ? "I" : "i"}·${pl.dfiOn ? "D" : "d"}`;
            return `
    <tr>
      <td style="color:${planColors[i % 3]}">${pl.name} <span class="ms" style="font-size:10px;color:var(--muted)">${flags}</span></td>
      <td>${Math.round(pl.mix)}%</td>
      <td>${fmtN(s.members)}</td>
      <td>${pop}%</td>
      <td class="ti">${fmtP(s.gross)}</td>
      <td class="tr">${fmtP(s.goods)}</td>
      <td>${fmtP(s.direct)}</td>
      <td>${fmtP(s.uni)}</td>
      <td class="tw">${fmtP(s.pair)}</td>
      <td class="tpk">${fmtP(s.dfi)}</td>
      <td class="${totalOut >= 0 ? "tg" : "tr"}">${fmtP(totalOut)}</td>
    </tr>`;
          })
          .join("");
        const tOut = total.direct + total.uni + total.pair + total.dfi;
        const mixSum = r.plans.reduce((s, pl) => s + (pl.mix || 0), 0);
        tbody.innerHTML =
          rows +
          `
    <tr class="total-row">
      <td>All plans</td>
      <td>${Math.round(mixSum)}%</td>
      <td>${fmtN(total.members)}</td>
      <td>100%</td>
      <td class="ti">${fmtP(total.gross)}</td>
      <td class="tr">${fmtP(total.goods)}</td>
      <td>${fmtP(total.direct)}</td>
      <td>${fmtP(total.uni)}</td>
      <td class="tw">${fmtP(total.pair)}</td>
      <td class="tpk">${fmtP(total.dfi)}</td>
      <td class="tg">${fmtP(tOut)}</td>
    </tr>`;
      }

      let simLog = [],
        curPage = 1;
      const PER_PAGE = 25;

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

      applyPreset("default", document.querySelector(".preset-btn"));
    </script>
  </body>
</html>