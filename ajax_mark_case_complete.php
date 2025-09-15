<?php
// ajax_mark_case_complete.php
declare(strict_types=1);
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'not_logged_in']);
  exit;
}

$userId   = (int)$_SESSION['user_id'];
$moduleId = (int)($_POST['module_id'] ?? 0);
$caseId   = (int)($_POST['case_id'] ?? 0);

if ($moduleId <= 0 || $caseId <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'bad_args']);
  exit;
}

try {
  // Idempotent upsert
  $stmt = $pdo->prepare("
    INSERT INTO user_mail_progress (user_id, module_id, case_id, completed_at)
    VALUES (?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE completed_at = VALUES(completed_at)
  ");
  $stmt->execute([$userId, $moduleId, $caseId]);

  // Return fresh counts for this module
  $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM training_mail_cases WHERE module_id = ?");
  $totalStmt->execute([$moduleId]);
  $total = (int)$totalStmt->fetchColumn();

  $doneStmt = $pdo->prepare("SELECT COUNT(*) FROM user_mail_progress WHERE user_id = ? AND module_id = ?");
  $doneStmt->execute([$userId, $moduleId]);
  $done = (int)$doneStmt->fetchColumn();

  $percent = $total > 0 ? (int)round(($done / $total) * 100) : 0;

  echo json_encode([
    'ok'      => true,
    'done'    => $done,
    'total'   => $total,
    'percent' => $percent,
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'server_error']);
}
