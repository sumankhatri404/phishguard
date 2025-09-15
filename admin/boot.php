<?php
// /admin/boot.php
declare(strict_types=1);

/* ---------- Path helper (critical) ---------- */
function admin_base(): string {
  $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin'), '/');
  if ($base === '' || $base === '\\' || $base === '.') $base = '/admin';
  return $base; // e.g. /phishguard/admin  or  /admin
}

/* ---------- Sessions (set cookie scope to /admin) ---------- */
if (session_status() === PHP_SESSION_NONE) {
  session_name('PG_ADMINSESS');

  // Scope cookie to the admin folder (works for /phishguard/admin or /admin)
  $cookiePath = admin_base();
  $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

  session_set_cookie_params([
    'httponly' => true,
    'secure'   => $isHttps,
    'samesite' => 'Lax',
    'path'     => $cookiePath,
  ]);

  session_start();
}

/* ---------- DB ---------- */
try {
 $pdo = new PDO(
    "mysql:host=sql305.infinityfree.com;dbname=if0_39778445_phishguard;charset=utf8mb4",
    "if0_39778445",
    "sumankhatri111"
);

  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  try { $pdo->exec("SET time_zone = '+00:00'"); } catch (Throwable $e) {}
} catch (PDOException $e) {
  http_response_code(500);
  exit('DB error');
}

/* (Optional) Cases-table helper if you’re using the dedicated-table feature */
require_once dirname(__DIR__) . '/inc/cases_table.php';

/* ---------- CSRF (admin namespace) ---------- */
function admin_csrf_token(): string {
  if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(16));
  }
  return $_SESSION['admin_csrf'];
}
function admin_verify_csrf(string $t): bool {
  return isset($_SESSION['admin_csrf']) && hash_equals($_SESSION['admin_csrf'], $t);
}

/* ---------- Auth helpers ---------- */
function admin_logged_in(): bool {
  return !empty($_SESSION['admin']) && !empty($_SESSION['admin']['id']);
}
function admin_require_login(): void {
  if (!admin_logged_in()) {
    $base = admin_base();
    $to = $base . '/login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? ($base . '/'));
    header('Location: ' . $to);
    exit;
  }
}

/* ---------- Login (username OR email) ---------- */
function admin_try_login(PDO $pdo, string $login, string $password): array {
  $st = $pdo->prepare("
    SELECT id, username, email, password_hash, role, is_locked
    FROM users
    WHERE (email = ? OR username = ?)
    LIMIT 1
  ");
  $st->execute([$login, $login]);
  $u = $st->fetch();

  if (!$u)                        return ['ok'=>false,'msg'=>'Invalid credentials'];
  if ($u['role'] !== 'admin')     return ['ok'=>false,'msg'=>'Not an admin account'];
  if ((int)$u['is_locked'] === 1) return ['ok'=>false,'msg'=>'Account locked'];
  if (!password_verify($password, $u['password_hash'])) {
    return ['ok'=>false,'msg'=>'Invalid credentials'];
  }

  session_regenerate_id(true);
  $_SESSION['admin'] = [
    'id'       => (int)$u['id'],
    'username' => $u['username'],
    'email'    => $u['email'],
  ];
  return ['ok'=>true];
}

function admin_logout(): void {
  $_SESSION['admin'] = null;
  session_regenerate_id(true);
  $base = admin_base();
  header('Location: ' . $base . '/login.php?msg=' . urlencode('Logged out'));
  exit;
}
