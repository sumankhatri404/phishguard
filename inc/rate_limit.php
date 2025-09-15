<?php
// inc/rate_limit.php — tiny DB-backed throttle
declare(strict_types=1);

function pg_rate_limit(PDO $pdo, string $key_str, int $limit, int $windowSeconds): bool {
  $key_hash = hash('sha256', $key_str);
  $now = new DateTimeImmutable('now');

  $stSel = $pdo->prepare("SELECT window_start, count FROM rate_limits WHERE key_hash = ?");
  $stSel->execute([$key_hash]);
  $row = $stSel->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    $stIns = $pdo->prepare("INSERT INTO rate_limits (key_hash, window_start, count) VALUES (?, ?, 1)");
    return $stIns->execute([$key_hash, $now->format('Y-m-d H:i:s')]);
  }

  $windowStart = new DateTimeImmutable($row['window_start']);
  $elapsed = $now->getTimestamp() - $windowStart->getTimestamp();

  if ($elapsed > $windowSeconds) {
    $stUpd = $pdo->prepare("UPDATE rate_limits SET window_start = ?, count = 1 WHERE key_hash = ?");
    return $stUpd->execute([$now->format('Y-m-d H:i:s'), $key_hash]);
  }

  if ((int)$row['count'] >= $limit) return false;

  $stInc = $pdo->prepare("UPDATE rate_limits SET count = count + 1 WHERE key_hash = ?");
  return $stInc->execute([$key_hash]);
}

function pg_default_reg_throttle_key(): string {
  $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
  $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
  $path = $_SERVER['REQUEST_URI'] ?? '/';
  return "reg|$ip|$ua|$path";
}
