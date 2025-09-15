<?php
// ajax_save_consent.php — save consent + log it (idempotent)
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

header('Content-Type: application/json; charset=UTF-8');

if (empty($_SESSION['user_id'])) {
  echo json_encode(['ok'=>false,'message'=>'Not logged in']); exit;
}

$userId = (int)$_SESSION['user_id'];
$age     = (int)($_POST['age'] ?? 0);
$consent = (int)($_POST['consent'] ?? 0);
$read    = (int)($_POST['read_info'] ?? 0);

if ($age < 18) {
  echo json_encode(['ok'=>false,'message'=>'You must be 18 or older.']); exit;
}
if ($consent !== 1 || $read !== 1) {
  echo json_encode(['ok'=>false,'message'=>'Consent and info required.']); exit;
}

try {
  // Ensure column/table exist (no-op if already there)
  try { $pdo->exec("ALTER TABLE users ADD COLUMN has_consent TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
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

  // Flip flag + log
  $pdo->beginTransaction();
  $pdo->prepare("UPDATE users SET has_consent=1 WHERE id=?")->execute([$userId]);
  $pdo->prepare("INSERT INTO consent_logs (user_id, age, consented) VALUES (?,?,1)")
      ->execute([$userId, $age]);
  $pdo->commit();

  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  echo json_encode(['ok'=>false,'message'=>'DB error','debug'=>$e->getMessage()]);
}
