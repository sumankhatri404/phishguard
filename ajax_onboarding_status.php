<?php
// ajax_onboarding_status.php — report flags + heal from logs
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

header('Content-Type: application/json; charset=UTF-8');

if (empty($_SESSION['user_id'])) {
  echo json_encode(['ok'=>false,'message'=>'Not logged in']); exit;
}
$userId = (int)$_SESSION['user_id'];

try {
  // Ensure columns/tables (idempotent)
  try { $pdo->exec("ALTER TABLE users ADD COLUMN has_consent TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE users ADD COLUMN has_pretest TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS consent_logs (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      age INT,
      consented TINYINT(1) NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS test_attempts (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      kind ENUM('pre','post') NOT NULL,
      started_at DATETIME NULL,
      submitted_at DATETIME NULL,
      ms_elapsed INT NOT NULL DEFAULT 0,
      score INT NOT NULL DEFAULT 0,
      total INT NOT NULL DEFAULT 0,
      accuracy_pct DECIMAL(6,2) NOT NULL DEFAULT 0.00,
      UNIQUE KEY uniq_user_kind_once (user_id, kind)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  // Read current flags
  $st = $pdo->prepare("SELECT has_consent, has_pretest FROM users WHERE id=? LIMIT 1");
  $st->execute([$userId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) { echo json_encode(['ok'=>false,'message'=>'User not found']); exit; }

  $hasConsent = (int)($row['has_consent'] ?? 0);
  $hasPretest = (int)($row['has_pretest'] ?? 0);

  // Heal consent from logs
  if ($hasConsent !== 1) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM consent_logs WHERE user_id=? AND consented=1");
    $q->execute([$userId]);
    if ((int)$q->fetchColumn() > 0) {
      $pdo->prepare("UPDATE users SET has_consent=1 WHERE id=?")->execute([$userId]);
      $hasConsent = 1;
    }
  }

  // Heal pretest from attempts
  if ($hasPretest !== 1) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM test_attempts WHERE user_id=? AND kind='pre' AND submitted_at IS NOT NULL");
    $q->execute([$userId]);
    if ((int)$q->fetchColumn() > 0) {
      $pdo->prepare("UPDATE users SET has_pretest=1 WHERE id=?")->execute([$userId]);
      $hasPretest = 1;
    }
  }

  echo json_encode([
    'ok' => true,
    'has_consent' => ($hasConsent === 1),
    'has_pretest' => ($hasPretest === 1)
  ]);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'message'=>'DB error','debug'=>$e->getMessage()]);
}
