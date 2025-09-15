<?php
// auth/register.php — one-device-one-user, race-safe, rate-limited
declare(strict_types=1);

require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/device.php';
require_once __DIR__ . '/../inc/rate_limit.php';

if (!verify_csrf($_POST['csrf'] ?? '')) {
  redirect_msg('../index.php', 'Invalid CSRF token.');
  exit;
}

/* Light throttle: max 3 sign-up attempts per hour per IP+UA+path */
if (!pg_rate_limit($pdo, pg_default_reg_throttle_key(), 3, 3600)) {
  redirect_msg('../index.php', 'Too many sign-up attempts. Please try again later.');
  exit;
}

/* Inputs */
$first    = trim($_POST['first_name']  ?? '');
$last     = trim($_POST['last_name']   ?? '');
$username = trim($_POST['username']    ?? '');
$emailRaw = trim($_POST['email']       ?? '');
$pass     = (string)($_POST['password'] ?? '');

/* Basic validation */
if (
  !$first || !$last || !$username ||
  strlen($username) < 3 || strlen($username) > 32 ||
  !preg_match('/^[A-Za-z0-9_.-]+$/', $username) ||
  strlen($pass) < 8
) {
  redirect_msg('../index.php', 'Check details (username 3–32 ok chars, password ≥ 8).');
  exit;
}

/* Email (optional) */
$email = $emailRaw !== '' ? (filter_var($emailRaw, FILTER_VALIDATE_EMAIL) ?: null) : null;

/* One-device-one-registration */
$rawTok = pg_get_client_device_token();   // cookie/localStorage stabilized
$hash   = pg_hash_device_token($rawTok);
pg_touch_device($pdo, $hash);

if (pg_device_registered_user($pdo, $hash) !== null) {
  redirect_msg('../index.php', 'This device has already been used to create an account. Please log in.');
  exit;
}

/* Uniqueness: username */
$exists = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$exists->execute([$username]);
if ($exists->fetch()) {
  redirect_msg('../index.php', 'Username already taken.');
  exit;
}

/* Create + bind atomically */
try {
  $pdo->beginTransaction();

  $hashPw = password_hash($pass, PASSWORD_DEFAULT);

  $ins = $pdo->prepare('
    INSERT INTO users (first_name, last_name, username, email, password_hash)
    VALUES (?,?,?,?,?)
  ');
  $ins->execute([$first, $last, $username, $email, $hashPw]);

  $newUserId = (int)$pdo->lastInsertId();

  // race-safe device bind: succeeds only if not already bound
  if (!pg_mark_device_registered($pdo, $hash, $newUserId)) {
    $pdo->rollBack();
    redirect_msg('../index.php', 'This device has already been used to create an account. Please log in.');
    exit;
  }

  $pdo->commit();

  // ✅ rotate CSRF after successful action
  rotate_csrf();

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  redirect_msg('../index.php', 'Could not create account right now. Please try again.');
  exit;
}

redirect_msg('../index.php', 'Account created! You can login now.');
exit;
