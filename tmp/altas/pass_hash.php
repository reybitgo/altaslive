<?php
$password = 'Admin@1234';
$hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Password Hash Generator</title>
  <style>
    body { font-family: monospace; background: #0f1728; color: #dde4f0; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
    .box { background: #151c2e; border: 1px solid #1e2a45; border-radius: 12px; padding: 32px 36px; max-width: 640px; width: 100%; }
    h2  { color: #f0b429; margin: 0 0 20px; font-size: 16px; letter-spacing: 1px; text-transform: uppercase; }
    .label { font-size: 11px; color: #5a6a88; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
    .value { background: #0e1220; border: 1px solid #1e2a45; border-radius: 8px; padding: 12px 14px; word-break: break-all; font-size: 13px; color: #10d07a; margin-bottom: 20px; }
    .sql  { color: #5b9cf6; }
    .btn  { display: inline-block; margin-top: 8px; background: #3b6ff0; color: #fff; border: none; border-radius: 8px; padding: 10px 20px; font-size: 13px; font-family: monospace; cursor: pointer; }
    .btn:hover { background: #2954cc; }
    .note { font-size: 11px; color: #f04040; margin-top: 16px; border-top: 1px solid #1e2a45; padding-top: 14px; }
  </style>
</head>
<body>
<div class="box">
  <h2>🔐 Password Hash Generator</h2>

  <div class="label">Password</div>
  <div class="value"><?= htmlspecialchars($password) ?></div>

  <div class="label">Generated Hash (bcrypt cost 12)</div>
  <div class="value" id="hash"><?= htmlspecialchars($hash) ?></div>

  <div class="label">SQL — paste this into HeidiSQL / phpMyAdmin</div>
  <div class="value sql">UPDATE users SET password_hash = '<?= htmlspecialchars($hash) ?>' WHERE username = 'admin';</div>

  <button class="btn" onclick="copyHash()">📋 Copy Hash</button>
  <button class="btn" onclick="copySQL()" style="background:#12a05c;margin-left:8px;">📋 Copy SQL</button>

  <div class="note">
    ⚠️ Delete this file from your server after use.<br>
    It is only needed once to fix the admin password hash.
  </div>
</div>
<script>
function copyHash() {
  navigator.clipboard.writeText(document.getElementById('hash').textContent.trim());
  alert('Hash copied!');
}
function copySQL() {
  const sql = `UPDATE users SET password_hash = '<?= addslashes($hash) ?>' WHERE username = 'admin';`;
  navigator.clipboard.writeText(sql);
  alert('SQL copied!');
}
</script>
</body>
</html>
