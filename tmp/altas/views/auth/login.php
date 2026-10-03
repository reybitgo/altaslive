<?php

/**
 * @file   views/auth/login.php
 * @brief  Login UI
 */
?>
<?php $pageTitle = 'Login — ' . setting('site_name', APP_NAME); ?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($pageTitle) ?></title>
  <meta name="robots" content="noindex,nofollow">
  <link rel="icon" href="<?= APP_URL ?>/assets/img/favicon.png" type="image/png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/auth.css">
</head>

<body>
  <div class="auth-page">
    <div class="auth-card">
      <div class="auth-header">
        <div class="auth-logo"><img src="<?= APP_URL ?>/assets/img/logo.png" alt="Logo"></div>
        <h1><?= e(setting('site_name', APP_NAME)) ?></h1>
        <p><?= e(setting('site_tagline', 'Build Your Network. Grow Your Income.')) ?></p>
      </div>
      <div class="auth-body">
        <?= render_flash() ?>
        <form method="POST" action="<?= APP_URL ?>/?page=do_login" id="loginForm">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label" for="username">Username</label>
            <div class="input-group">
              <input type="text" id="username" name="username" class="form-control"
                placeholder="Enter your username"
                value="<?= e($_POST['username'] ?? '') ?>"
                autocomplete="username" autofocus required>
              <span class="input-group-text">👤</span>
            </div>
          </div>
          <div class="mb-4">
            <label class="form-label" for="password">Password</label>
            <div class="input-group">
              <input type="password" id="password" name="password" class="form-control"
                placeholder="Enter your password" autocomplete="current-password" required>
              <button type="button" class="btn btn-outline-secondary" id="togglePw">👁</button>
            </div>
          </div>
          <button type="submit" class="btn btn-primary w-100 btn-lg fw-bold" id="loginBtn">
            Sign In
          </button>
        </form>
      </div>
      <div class="auth-footer">
        Have a registration code? <a href="<?= APP_URL ?>/?page=register">Register here →</a>
      </div>
      <div class="auth-footer" style="border-top:none;padding-top:0;">
        <a href="<?= APP_URL ?>/">← Back to Home</a>
      </div>
    </div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    document.getElementById('togglePw').addEventListener('click', function() {
      const pw = document.getElementById('password');
      pw.type = pw.type === 'password' ? 'text' : 'password';
      this.textContent = pw.type === 'password' ? '👁' : '🙈';
    });
    document.getElementById('loginForm').addEventListener('submit', function() {
      const btn = document.getElementById('loginBtn');
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Signing in…';
    });
  </script>
</body>

</html>