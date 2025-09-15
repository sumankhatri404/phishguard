<?php
// /admin/login.php
declare(strict_types=1);
require_once __DIR__ . '/boot.php';

$base = admin_base(); // e.g. /phishguard/admin

// Already logged in? Go to admin home (index.php)
if (admin_logged_in()) {
  header('Location: ' . $base . '/');
  exit;
}

$msg = isset($_GET['msg']) ? (string)$_GET['msg'] : '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!admin_verify_csrf($_POST['csrf'] ?? '')) {
    $err = 'Security check failed.';
  } else {
    // Username OR Email
    $login = trim((string)($_POST['login'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');
    if ($login === '' || $pass === '') {
      $err = 'Login and password are required.';
    } else {
      $res = admin_try_login($pdo, $login, $pass);
      if (!empty($res['ok'])) {
        $next = $_GET['next'] ?? ($base . '/');
        header('Location: ' . $next);
        exit;
      }
      $err = $res['msg'] ?? 'Invalid credentials.';
    }
  }
}

$csrf = admin_csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Admin Login · PhishGuard</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body{margin:0;background:#0a0f1f;color:#e5e7eb;font:14px/1.45 system-ui,Segoe UI,Roboto,Arial;display:grid;place-items:center;height:100vh}
  .card{width:360px;background:#0d1630;border:1px solid #1f2a44;border-radius:14px;padding:18px}
  input,button{width:100%;padding:10px;border-radius:8px;border:1px solid #1f2a44;background:#0b1327;color:#e5e7eb}
  button{cursor:pointer;margin-top:8px}
  .muted{color:#94a3b8}
  .msg{margin:8px 0;padding:8px 10px;border-radius:8px;background:#0b1327;border:1px solid #1f2a44}
  .err{border-color:#ef4444;color:#ef9a9a}
</style>
</head>
<body>
  <div class="card">
    <h2 style="margin:0 0 8px">PhishGuard Admin</h2>

    <?php if ($msg): ?><div class="msg"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="msg err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <form method="post" action="">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
      <label class="muted">Username or Email</label>
      <input type="text" name="login" autocomplete="username" required>
      <label class="muted" style="margin-top:8px;display:block">Password</label>
      <input type="password" name="password" autocomplete="current-password" required>
      <button type="submit">Sign in</button>
    </form>

    <p class="muted" style="margin-top:10px">Admin area only · Separate from user login</p>
  </div>
</body>
</html>
