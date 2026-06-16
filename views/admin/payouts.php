<?php

/**
 * @file   views/admin/payouts.php
 * @brief  Payout management UI
 */
?>
<?php $pageTitle = 'Payout Requests'; ?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_admin.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <ul class="nav nav-pills mb-3">
      <?php foreach (['pending' => '⏳ Pending', 'approved' => '✅ Approved', 'completed' => '💚 Completed', 'rejected' => '❌ Rejected', '' => '📋 All'] as $s => $label): ?>
        <li class="nav-item">
          <a class="nav-link <?= $status === $s ? 'active' : '' ?>" href="<?= APP_URL ?>/?page=admin_payouts&status=<?= $s ?>">
            <?= $label ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>

    <div class="row g-3 mb-3">
      <div class="col-12 col-md-6">
        <div class="card stat-card">
          <div class="stat-accent stat-accent-warning"></div>
          <div class="card-body pt-4">
            <div class="stat-label">Pending Amount</div>
            <div class="stat-value text-warning"><?= fmt_money(Payout::pendingTotal()) ?></div>
          </div>
        </div>
      </div>
      <div class="col-12 col-md-6">
        <div class="card stat-card">
          <div class="stat-accent stat-accent-success"></div>
          <div class="card-body pt-4">
            <div class="stat-label">Total Paid Out</div>
            <div class="stat-value text-success"><?= fmt_money(Payout::totalPaid()) ?></div>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="card-title">💸 Payout Requests</span>
        <span class="badge bg-secondary-subtle text-secondary"><?= $result['total'] ?> records</span>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th>#</th>
              <th>Member</th>
              <th>Requested (₱)</th>
              <th>Net / USDT</th>
              <th>Method</th>
              <th>Account</th>
              <th>Requested</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($result['data'])): ?>
              <tr>
                <td colspan="9" class="text-center py-5 text-muted">No payout requests found.</td>
              </tr>
              <?php else: foreach ($result['data'] as $pr):
                $method  = $pr['payout_method']  ?: 'gcash';
                $account = $pr['payout_account'];
                $methodLabel = match ($method) {
                  'maya' => 'Maya',
                  'usdt' => 'USDT TRC20',
                  default => 'GCash'
                };
                $methodColor = match ($method) {
                  'maya' => '#48b0db',
                  'usdt' => '#26a17b',
                  default => '#0070d8'
                };
                $sendVerb    = match ($method) {
                  'usdt' => 'Transfer USDT to',
                  default => 'Send via ' . $methodLabel . ' to'
                };
                $netPhp      = $pr['amount'] - ($pr['service_fee_amount'] ?? 0);
                $hasUsdt     = $method === 'usdt' && ($pr['usdt_amount'] ?? 0) > 0;
                $sendAmount  = $hasUsdt
                  ? number_format($pr['usdt_amount'], 4) . ' USDT'
                  : fmt_money($netPhp);
              ?>
                <tr>
                  <td class="td-muted" style="font-size:.72rem;"><?= $pr['id'] ?></td>
                  <td>
                    <a href="<?= APP_URL ?>/?page=admin_user_view&id=<?= $pr['user_id'] ?>"
                      class="fw-bold text-decoration-none">@<?= e($pr['username']) ?></a>
                    <div class="text-muted" style="font-size:.72rem;"><?= e($pr['full_name'] ?? '') ?></div>
                  </td>
                  <td class="font-mono fw-bold td-green" style="font-size:.95rem;">
                    <?= fmt_money($pr['amount']) ?>
                  </td>
                  <td>
                    <?php if ($hasUsdt): ?>
                      <div class="fw-bold font-mono text-success"><?= number_format($pr['usdt_amount'], 4) ?> USDT</div>
                      <div class="text-muted" style="font-size:.68rem;">@ ₱<?= number_format($pr['usdt_rate'], 2) ?></div>
                      <?php if (($pr['service_fee_amount'] ?? 0) > 0): ?>
                        <div class="text-muted" style="font-size:.65rem;">Fee: <?= fmt_money($pr['service_fee_amount']) ?> + <?= number_format($pr['usdt_gas_fee'], 2) ?> USDT gas</div>
                      <?php endif; ?>
                    <?php elseif (($pr['service_fee_amount'] ?? 0) > 0): ?>
                      <div class="fw-bold font-mono"><?= fmt_money($netPhp) ?></div>
                      <div class="text-muted" style="font-size:.68rem;">Fee: <?= fmt_money($pr['service_fee_amount']) ?> (<?= number_format($pr['service_fee_pct'], 1) ?>%)</div>
                    <?php else: ?>
                      <span class="font-mono fw-bold"><?= fmt_money($pr['amount']) ?></span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="badge" style="background:<?= $methodColor ?>20;color:<?= $methodColor ?>;border:1px solid <?= $methodColor ?>40;font-size:.72rem;">
                      <?= $methodLabel ?>
                    </span>
                  </td>
                  <td>
                    <span class="font-mono fw-bold text-primary" style="font-size:.8rem;"><?= $account ? e($account) : '<span class="text-muted fst-italic">—</span>' ?></span>
                    <?php if ($account): ?>
                      <button class="btn btn-sm btn-link p-0 ms-1"
                        onclick="copyText('<?= e($account) ?>')" title="Copy">📋</button>
                    <?php endif; ?>
                  </td>
                  <td class="td-muted" style="font-size:.75rem;"><?= fmt_datetime($pr['requested_at']) ?></td>
                  <td>
                    <?php
                    $b = match ($pr['status']) {
                      'pending'   => 'bg-warning-subtle text-warning',
                      'approved'  => 'bg-info-subtle text-info',
                      'completed' => 'bg-success-subtle text-success',
                      'rejected'  => 'bg-danger-subtle text-danger',
                      default     => 'bg-secondary-subtle text-secondary'
                    };
                    ?>
                    <span class="badge <?= $b ?>"><?= ucfirst($pr['status']) ?></span>
                    <?php if ($pr['admin_note']): ?>
                      <div class="text-muted" style="font-size:.68rem;max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= e($pr['admin_note']) ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php
                    $hasUsdt    = $method === 'usdt' && ($pr['usdt_amount'] ?? 0) > 0;
                    $netPhp     = $pr['amount'] - ($pr['service_fee_amount'] ?? 0);
                    $sendAmount = $hasUsdt ? number_format($pr['usdt_amount'], 4) . ' USDT' : fmt_money($netPhp);
                    ?>
                    <?php if ($pr['status'] === 'pending'): ?>
                      <div class="d-flex gap-1 flex-wrap">
                        <button class="btn btn-sm btn-success"
                          onclick="payoutAction('approve',<?= $pr['id'] ?>,'<?= e($pr['username']) ?>','<?= fmt_money($pr['amount']) ?>','<?= e($account) ?>','<?= $methodLabel ?>')">
                          ✓ Approve
                        </button>
                        <button class="btn btn-sm btn-danger"
                          onclick="payoutAction('reject',<?= $pr['id'] ?>,'<?= e($pr['username']) ?>','<?= fmt_money($pr['amount']) ?>','<?= e($account) ?>','<?= $methodLabel ?>')">
                          ✕ Reject
                        </button>
                      </div>
                    <?php elseif ($pr['status'] === 'approved'): ?>
                      <div class="mb-1 p-2 rounded" style="background:<?= $methodColor ?>12;border:1px solid <?= $methodColor ?>30;font-size:.72rem;">
                        <div class="fw-bold mb-1" style="color:<?= $methodColor ?>;"><?= $sendVerb ?></div>
                        <div class="font-mono mb-1" style="color:#374151;word-break:break-all;font-size:.8rem;"><?= e($account) ?></div>
                        <?php if ($hasUsdt): ?>
                          <div class="fw-bold font-mono" style="color:<?= $methodColor ?>;font-size:1rem;">
                            <?= number_format($pr['usdt_amount'], 4) ?> USDT
                          </div>
                          <div style="color:#6b7a99;font-size:.65rem;">
                            From <?= fmt_money($pr['amount']) ?> &middot; Fee <?= fmt_money($pr['service_fee_amount'] ?? 0) ?> &middot; Gas <?= number_format($pr['usdt_gas_fee'] ?? 0, 2) ?> USDT
                          </div>
                        <?php else: ?>
                          <div class="fw-bold" style="color:<?= $methodColor ?>;"><?= fmt_money($netPhp) ?></div>
                          <?php if (($pr['service_fee_amount'] ?? 0) > 0): ?>
                            <div style="color:#6b7a99;font-size:.65rem;">From <?= fmt_money($pr['amount']) ?> &middot; Fee <?= fmt_money($pr['service_fee_amount']) ?></div>
                          <?php endif; ?>
                        <?php endif; ?>
                      </div>
                      <button class="btn btn-sm btn-primary w-100"
                        onclick="payoutAction('complete',<?= $pr['id'] ?>,'<?= e($pr['username']) ?>','<?= e($sendAmount) ?>','<?= e($account) ?>','<?= $methodLabel ?>')">
                        ✅ Mark Complete
                      </button>
                    <?php else: ?>
                      <span class="td-muted" style="font-size:.75rem;">
                        <?= $pr['processed_at'] ? fmt_date($pr['processed_at']) : '—' ?>
                      </span>
                    <?php endif; ?>
                  </td>
                </tr>
            <?php endforeach;
            endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($result && $result['total_pages'] > 1): ?>
        <div class="card-footer">
          <?= pagination_links($result, APP_URL . '/?page=admin_payouts&status=' . urlencode($status)) ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Payout Action Modal -->
<div class="modal fade" id="payoutModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="payoutModalTitle">Confirm</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p id="payoutModalDesc" style="font-size:.9rem;line-height:1.65;"></p>
        <div class="mb-0" id="payoutNoteGroup">
          <label class="form-label" id="payoutNoteLabel">Note</label>
          <textarea id="payoutModalNote" class="form-control" rows="2" placeholder="Optional…"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <form method="POST" action="<?= APP_URL ?>/?page=admin_payout_action"
          id="payoutActionForm" class="m-0">
          <?= csrf_field() ?>
          <input type="hidden" name="action" id="payoutActionInput">
          <input type="hidden" name="id" id="payoutIdInput">
          <input type="hidden" name="note" id="payoutNoteInput">
          <button type="submit" class="btn" id="payoutModalBtn">Confirm</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
  function payoutAction(action, id, user, amount, account, method) {
    const configs = {
      approve: {
        title: '✓ Approve Payout',
        desc: `Approve <strong>${amount}</strong> for <strong>@${user}</strong>?<br>
                  Send funds via <strong>${method}</strong> to <code>${account}</code> before marking complete.`,
        btnClass: 'btn-success',
        btnText: '✓ Approve',
        noteLabel: 'Note (optional)',
      },
      reject: {
        title: '✕ Reject Payout',
        desc: `Reject <strong>${amount}</strong> for <strong>@${user}</strong>? Balance will NOT be deducted.`,
        btnClass: 'btn-danger',
        btnText: '✕ Reject',
        noteLabel: 'Rejection reason (shown to member)',
      },
      complete: {
        title: '✅ Mark as Completed',
        desc: `Confirm you've sent <strong>${amount}</strong> to <strong>@${user}</strong>
                  via <strong>${method}</strong>:<br><code>${account}</code><br>
                  This will deduct the member's e-wallet.`,
        btnClass: 'btn-primary',
        btnText: '✅ Mark Complete',
        noteLabel: 'Note (optional)',
      },
    };

    const cfg = configs[action];
    document.getElementById('payoutModalTitle').textContent = cfg.title;
    document.getElementById('payoutModalDesc').innerHTML = cfg.desc;
    document.getElementById('payoutModalBtn').className = 'btn ' + cfg.btnClass;
    document.getElementById('payoutModalBtn').textContent = cfg.btnText;
    document.getElementById('payoutNoteLabel').textContent = cfg.noteLabel;
    document.getElementById('payoutModalNote').value = '';
    document.getElementById('payoutActionInput').value = action;
    document.getElementById('payoutIdInput').value = id;

    document.getElementById('payoutActionForm').onsubmit = () => {
      document.getElementById('payoutNoteInput').value =
        document.getElementById('payoutModalNote').value;
    };

    new bootstrap.Modal(document.getElementById('payoutModal')).show();
  }
</script>
<?php require 'views/partials/footer.php'; ?>