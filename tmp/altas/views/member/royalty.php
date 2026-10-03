<?php $pageTitle = 'Royalty Bonus'; ?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_member.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <!-- Current Rank -->
    <div class="card mb-4" style="border:2px solid var(--royalty-border, #fbbf24);">
      <div class="card-body text-center py-4">
        <div style="font-size:3rem;"><?= $rankStyle['icon'] ?></div>
        <h3 class="fw-bold mt-2 mb-1"><?= e($rankLabel) ?></h3>
        <span class="badge <?= $rankStyle['badge'] ?> fs-6 px-3 py-2"><?= e($rank ?: 'Not ranked yet') ?></span>
        <?php if (setting('royalty_enabled', '0') !== '1'): ?>
          <div class="text-muted mt-3" style="font-size:.8rem;">Royalty bonus is currently disabled by admin.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Requirements Progress -->
    <div class="row g-3 mb-4">
      <div class="col-12">
        <h5 class="fw-600 mb-3">Rank Requirements</h5>
      </div>

      <?php
      $qaDirects   = (int)setting('royalty_qa_directs', '3');
      $qaPersonal  = (float)setting('royalty_qa_personal_pv', '200');
      $qaGroup     = (float)setting('royalty_qa_group_pv', '1000');
      $userPv      = (float)$user['personal_pv'];
      $groupPv     = (float)$user['group_pv'];
      ?>

      <!-- QA Card -->
      <div class="col-12 col-md-6 col-lg-3">
        <div class="card h-100">
          <div class="card-body">
            <h6 class="fw-700 mb-2">🟡 Qualified Associate</h6>
            <div class="mb-2">
              <div class="d-flex justify-content-between text-muted" style="font-size:.78rem;">
                <span>Directs: <?= $directCount ?> / <?= $qaDirects ?></span>
                <span><?= $directCount >= $qaDirects ? '✓' : '✗' ?></span>
              </div>
              <div class="d-flex justify-content-between text-muted" style="font-size:.78rem;">
                <span>Personal PV: <?= number_format($userPv, 2) ?> / <?= number_format($qaPersonal, 2) ?></span>
                <span><?= $userPv >= $qaPersonal ? '✓' : '✗' ?></span>
              </div>
              <div class="d-flex justify-content-between text-muted" style="font-size:.78rem;">
                <span>Group PV: <?= number_format($groupPv, 2) ?> / <?= number_format($qaGroup, 2) ?></span>
                <span><?= $groupPv >= $qaGroup ? '✓' : '✗' ?></span>
              </div>
            </div>
            <div class="text-muted" style="font-size:.72rem;">OR gate: Personal PV OR Group PV</div>
          </div>
        </div>
      </div>

      <!-- Supervisor Card -->
      <div class="col-12 col-md-6 col-lg-3">
        <div class="card h-100">
          <div class="card-body">
            <h6 class="fw-700 mb-2">🥉 Supervisor</h6>
            <div class="mb-2">
              <div class="d-flex justify-content-between text-muted" style="font-size:.78rem;">
                <span>Directs: <?= $directCount ?> / 10</span>
                <span><?= $directCount >= 10 ? '✓' : '✗' ?></span>
              </div>
              <div class="d-flex justify-content-between text-muted" style="font-size:.78rem;">
                <span>QA Legs: <?= $qaLegs ?> / 5</span>
                <span><?= $qaLegs >= 5 ? '✓' : '✗' ?></span>
              </div>
            </div>
            <div class="text-muted" style="font-size:.72rem;">
              Group: <?= setting('royalty_supervisor_group_pct', '3') ?>% · Repeat: <?= setting('royalty_supervisor_repeat_pct', '5') ?>%
            </div>
          </div>
        </div>
      </div>

      <!-- Manager Card -->
      <div class="col-12 col-md-6 col-lg-3">
        <div class="card h-100">
          <div class="card-body">
            <h6 class="fw-700 mb-2">🥈 Manager</h6>
            <div class="mb-2">
              <div class="d-flex justify-content-between text-muted" style="font-size:.78rem;">
                <span>3 Supervisor Legs</span>
              </div>
            </div>
            <div class="text-muted" style="font-size:.72rem;">
              Group: <?= setting('royalty_manager_group_pct', '5') ?>% · Repeat: <?= setting('royalty_manager_repeat_pct', '10') ?>%
            </div>
          </div>
        </div>
      </div>

      <!-- Director Card -->
      <div class="col-12 col-md-6 col-lg-3">
        <div class="card h-100">
          <div class="card-body">
            <h6 class="fw-700 mb-2">🥇 Director</h6>
            <div class="mb-2">
              <div class="d-flex justify-content-between text-muted" style="font-size:.78rem;">
                <span>3 Manager Legs</span>
              </div>
            </div>
            <div class="text-muted" style="font-size:.72rem;">
              Group: <?= setting('royalty_director_group_pct', '10') ?>% · Repeat: <?= setting('royalty_director_repeat_pct', '15') ?>%
            </div>
          </div>
        </div>
      </div>

      <!-- Chairman Card -->
      <div class="col-12 col-md-6 col-lg-3">
        <div class="card h-100">
          <div class="card-body">
            <h6 class="fw-700 mb-2">👑 Chairman</h6>
            <div class="mb-2">
              <div class="d-flex justify-content-between text-muted" style="font-size:.78rem;">
                <span>3 Director Legs</span>
              </div>
            </div>
            <div class="text-muted" style="font-size:.72rem;">
              Group: <?= setting('royalty_chairman_group_pct', '12') ?>% · Repeat: <?= setting('royalty_chairman_repeat_pct', '20') ?>%
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Total Earned -->
    <div class="card mb-4">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center">
          <span class="card-title mb-0">Total Royalty Earned</span>
          <strong class="fs-4" style="color:var(--royalty, #fbbf24);"><?= fmt_money($totalRoyalty) ?></strong>
        </div>
      </div>
    </div>

    <!-- History -->
    <div class="card">
      <div class="card-header"><span class="card-title">Royalty Commission History</span></div>
      <div class="card-body py-0 px-3">
        <?php if (empty($royaltyHistory)): ?>
          <div class="text-center py-5 text-muted">
            <div style="font-size:2rem;">📭</div>
            <p class="mt-2 mb-0">No royalty commissions yet.</p>
          </div>
        <?php else: foreach ($royaltyHistory as $item): ?>
          <div class="activity-item">
            <div class="activity-dot" style="background:#fef3c7;color:#d4a017;">⭐</div>
            <div class="flex-grow-1 min-w-0">
              <div class="activity-desc"><?= e($item['description']) ?></div>
              <div class="activity-meta"><?= fmt_datetime($item['created_at']) ?></div>
            </div>
            <div class="activity-amount" style="color:var(--success);">
              +<?= fmt_money($item['amount']) ?>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

  </div>
</div>
<?php require 'views/partials/footer.php'; ?>
