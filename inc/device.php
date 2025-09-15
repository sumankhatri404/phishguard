<?php
// inc/device.php — stable device token + helpers (one-device-one-user)
declare(strict_types=1);

// -------------- config --------------
const PG_DEVICE_COOKIE = 'pg_dev';
const PG_DEVICE_YEARS  = 2; // persist for 2 years
// !!! CHANGE THIS to a long random string unique to your deployment:
const PG_DEVICE_SALT   = 'CHANGE-ME-1c9a6f3c-2d44-4711-98f4-4b9a0f2c7c65';

/** Set cookie with mobile-friendly defaults */
function pg_same_site_cookie(array $opts): void {
  setcookie(
    PG_DEVICE_COOKIE,
    $opts['value'],
    [
      'expires'  => $opts['expires'],
      'path'     => '/',
      'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
      'httponly' => true,
      'samesite' => 'Lax',
    ]
  );
}

/** Returns a stable opaque device token; creates and persists if missing. */
function pg_get_client_device_token(): string {
  $tok = $_COOKIE[PG_DEVICE_COOKIE] ?? '';
  if (!is_string($tok) || strlen($tok) < 24) {
    $raw = random_bytes(32); // 32 bytes → 43 base64url chars
    $tok = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    pg_same_site_cookie([
      'value'   => $tok,
      'expires' => time() + (PG_DEVICE_YEARS * 365 * 24 * 60 * 60),
    ]);
  }
  return $tok;
}

/** Hash token with server-side salt (DB never stores raw token). */
function pg_hash_device_token(string $tok): string {
  return hash('sha256', PG_DEVICE_SALT . '|' . $tok);
}

/** Ensure devices row exists; update last_seen idempotently. */
function pg_touch_device(PDO $pdo, string $hash): void {
  $sql = "INSERT INTO devices (device_hash, first_seen, last_seen)
          VALUES (?, NOW(), NOW())
          ON DUPLICATE KEY UPDATE last_seen = NOW()";
  $st = $pdo->prepare($sql);
  $st->execute([$hash]);
}

/** If this device is already bound, return user_id; else null. */
function pg_device_registered_user(PDO $pdo, string $hash): ?int {
  $st = $pdo->prepare("SELECT registered_user_id
                       FROM devices
                       WHERE device_hash = ?
                         AND registered_user_id IS NOT NULL
                       LIMIT 1");
  $st->execute([$hash]);
  $uid = $st->fetchColumn();
  return $uid !== false ? (int)$uid : null;
}

/** Bind device to user once. Returns true only if we bound it now. */
function pg_mark_device_registered(PDO $pdo, string $hash, int $userId): bool {
  $st = $pdo->prepare("UPDATE devices
                       SET registered_user_id = ?, registered_at = NOW()
                       WHERE device_hash = ?
                         AND registered_user_id IS NULL");
  $st->execute([$userId, $hash]);
  return $st->rowCount() === 1;
}
