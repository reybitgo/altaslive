<?php

/**
 * @file   views/partials/rows_per_page.php
 * @brief  Rows-per-page dropdown for paginated tables
 */
$current = per_page();
$options = [5, 10, 25, 50, 100];
?>
<form method="GET" action="<?= APP_URL ?>/" class="d-flex align-items-center gap-2">
  <?php foreach ($_GET as $k => $v): ?>
    <?php if (!is_scalar($v) || in_array($k, ['per_page', 'pg'], true)) continue; ?>
    <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>">
  <?php endforeach; ?>
  <label for="rowsPerPage" class="text-muted small mb-0" style="font-size:.78rem;">Show</label>
  <select name="per_page" id="rowsPerPage" class="form-select form-select-sm" style="width:auto;min-width:66px;" onchange="this.form.submit()">
    <?php foreach ($options as $opt): ?>
      <option value="<?= $opt ?>" <?= $current == $opt ? 'selected' : '' ?>><?= $opt ?></option>
    <?php endforeach; ?>
  </select>
  <label for="rowsPerPage" class="text-muted small mb-0" style="font-size:.78rem;">rows</label>
</form>
