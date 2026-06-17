<?php

/**
 * @file   views/admin/users.php
 * @brief  User management UI
 */
?>
<?php $pageTitle = 'Members'; ?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_admin.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>
    <?php $counts = User::counts(); ?>
    <div class="row g-3 mb-3">
      <?php foreach ([['Total', (int)$counts['total'], 'primary'], ['Active', (int)$counts['active'], 'success'], ['Pending', (int)($counts['pending'] ?? 0), 'warning'], ['Suspended', (int)$counts['suspended'], 'danger'], ['Joined Today', (int)$counts['joined_today'], 'info']] as [$l, $v, $c]): ?>
        <div class="col-6 col-xl-3">
          <div class="card stat-card">
            <div class="stat-accent stat-accent-<?= $c ?>"></div>
            <div class="card-body pt-4">
              <div class="stat-label"><?= $l ?></div>
              <div class="stat-value text-<?= $c ?>"><?= number_format($v) ?></div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <div class="card-header">
        <form method="GET" action="<?= APP_URL ?>/" class="d-flex flex-wrap align-items-end justify-content-between gap-2">
          <input type="hidden" name="page" value="admin_users">

          <!-- Filters -->
          <div class="d-flex flex-wrap align-items-end gap-2">
            <input type="text" name="q" value="<?= e($search) ?>" class="form-control form-control-sm" style="min-width:220px;" placeholder="🔍 Search username, name, email…">
            <select name="status" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
              <option value="">All Statuses</option>
              <?php foreach (['active', 'suspended', 'pending'] as $s): ?><option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?>
            </select>
            <select name="pkg" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
              <option value="">All Packages</option>
              <?php foreach ($packages as $pkg): ?><option value="<?= $pkg['id'] ?>" <?= $pkgId === (int)$pkg['id'] ? 'selected' : '' ?>><?= e($pkg['name']) ?></option><?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">Search</button>
            <?php if ($search || $status || $pkgId): ?><a href="<?= APP_URL ?>/?page=admin_users" class="btn btn-outline-secondary btn-sm">✕ Clear</a><?php endif; ?>
          </div>

          <!-- Rows per page -->
          <div class="d-flex align-items-center gap-2">
            <label for="perPageSelect" class="form-label mb-0 text-muted" style="font-size:.78rem;white-space:nowrap;">Rows per page</label>
            <select id="perPageSelect" name="per_page" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
              <?php foreach ([5, 10, 25, 50, 100] as $n): ?>
                <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </form>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th>#</th>
              <th>Username</th>
              <th>Full Name</th>
              <th>Package</th>
              <th>Balance</th>
              <th>Pairs</th>
              <th>Joined</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($result['data'])): ?>
              <tr>
                <td colspan="9" class="text-center py-5 text-muted">No members found.</td>
              </tr>
              <?php else: foreach ($result['data'] as $i => $m): ?>
                <tr>
                  <td class="td-muted" style="font-size:.7rem;"><?= ($result['page'] - 1) * $result['per_page'] + $i + 1 ?></td>
                  <td><a href="<?= APP_URL ?>/?page=admin_user_view&id=<?= $m['id'] ?>" class="fw-bold text-decoration-none">@<?= e($m['username']) ?></a></td>
                  <td style="font-size:.825rem;"><?= e($m['full_name'] ?? '—') ?></td>
                  <td><span class="badge bg-primary-subtle text-primary"><?= e($m['package_name'] ?? '—') ?></span></td>
                  <td class="td-green font-mono fw-bold"><?= fmt_money($m['ewallet_balance']) ?></td>
                  <td class="td-muted font-mono"><?= number_format($m['pairs_paid']) ?></td>
                  <td class="td-muted" style="font-size:.75rem;"><?= fmt_date($m['joined_at']) ?></td>
                  <td>
                    <?php $b = $m['status'] === 'active' ? 'bg-success-subtle text-success' : ($m['status'] === 'suspended' ? 'bg-danger-subtle text-danger' : 'bg-warning-subtle text-warning'); ?>
                    <span class="badge <?= $b ?>"><?= ucfirst($m['status']) ?></span>
                    <?php if (!empty($m['cd_active'])): ?>
                      <span class="badge bg-warning-subtle text-warning" style="font-size:.65rem;">⏳ CD</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="d-flex gap-1">
                      <a href="<?= APP_URL ?>/?page=admin_user_view&id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                      <?php if ($m['status'] !== 'pending'): ?>
                      <form method="POST" action="<?= APP_URL ?>/?page=admin_toggle_user" class="m-0" id="toggleForm<?= $m['id'] ?>">
                        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $m['id'] ?>">
                        <?php $isSuspend = $m['status'] === 'active'; ?>
                        <button type="button" class="btn btn-sm <?= $isSuspend ? 'btn-outline-danger' : 'btn-outline-success' ?>"
                          onclick="showConfirm({
            title: '<?= $isSuspend ? 'Suspend' : 'Unsuspend' ?> Member',
            message: 'Are you sure you want to <?= $isSuspend ? 'suspend' : 'unsuspend' ?> <strong>@<?= e($m['username']) ?></strong>?',
            confirmText: '<?= $isSuspend ? 'Suspend' : 'Unsuspend' ?>',
            confirmClass: '<?= $isSuspend ? 'btn-danger' : 'btn-success' ?>',
            onConfirm: () => this.closest('form').submit()
        })">
                          <?= $isSuspend ? '🔒 Suspend' : '🔓 Unsuspend' ?>
                        </button>
                      </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
            <?php endforeach;
            endif; ?>
          </tbody>
        </table>
      </div>
      <?php if (!empty($result['total_pages']) && $result['total_pages'] > 1): ?>
        <div class="card-footer"><?= pagination_links($result, APP_URL . '/?page=admin_users&q=' . urlencode($search) . '&status=' . $status . '&pkg=' . $pkgId . '&per_page=' . $perPage) ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require 'views/partials/footer.php'; ?>