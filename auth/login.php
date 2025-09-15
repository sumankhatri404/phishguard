<?php
// /auth/login.php — hardened login (CSRF, fixation defense, device touch, optional throttle)
declare(strict_types=1);

require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/device.php';
require_once __DIR__ . '/../inc/rate_limit.php'; // optional (used below)

// --- CSRF ---
if (!verify_csrf($_POST['csrf'] ?? '')) {
  redirect_msg('../index.php', 'Invalid CSRF token.');
}

// --- Optional: light throttle (5 attempts / 10 minutes by IP+UA+path) ---
if (!pg_rate_limit($pdo, pg_default_reg_throttle_key(), 5, 600)) {
  redirect_msg('../index.php', 'Too many login attempts. Try again in a few minutes.');
}

$username = trim($_POST['username'] ?? '');
$pass     = (string)($_POST['password'] ?? '');

if ($username === '' || $pass === '') {
  redirect_msg('../index.php', 'Enter your username and password.');
}

// Fetch user
$stmt = $pdo->prepare('SELECT id, first_name, password_hash FROM users WHERE username = ? LIMIT 1');
$stmt->execute([$username]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);

// Verify password
if ($u && password_verify($pass, $u['password_hash'])) {

  // (Optional) upgrade hash if the algorithm/cost changed
  if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
    try {
      $newHash = password_hash($pass, PASSWORD_DEFAULT);
      $upd = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
      $upd->execute([$newHash, (int)$u['id']]);
    } catch (Throwable $e) { /* ignore if fails; login still succeeds */ }
  }

  // Fixation defense: new session ID after auth
  if (session_status() === PHP_SESSION_ACTIVE) {
    session_regenerate_id(true);
  }

  // Set session
  $_SESSION['user_id']   = (int)$u['id'];
  $_SESSION['user_name'] = (string)$u['first_name'];

  // Touch device row (no PII in devices)
  try {
    $rawTok = pg_get_client_device_token();  // ensures cookie exists
    $hash   = pg_hash_device_token($rawTok);
    pg_touch_device($pdo, $hash);
    $st = $pdo->prepare('UPDATE devices SET last_seen = CURRENT_TIMESTAMP WHERE device_hash = ?');
    $st->execute([$hash]);
  } catch (Throwable $e) { /* do not block login */ }

  // Rotate CSRF after successful state change
  rotate_csrf();

  header('Location: ../dashboard.php');
  exit;
}

redirect_msg('../index.php', 'Incorrect username or password.');
