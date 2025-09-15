<?php
// ajax_spot_open.php — session open for Spot-the-Phish (uses deadline_at)
declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';

if (empty($_SESSION['user_id'])) { echo json_encode(['ok'=>false,'message'=>'Not logged in']); exit; }

$userId = (int)$_SESSION['user_id'];
$taskId = (int)($_GET['id'] ?? 0);
if ($taskId <= 0) { echo json_encode(['ok'=>false,'message'=>'Missing id']); exit; }

try { $pdo->exec("SET time_zone = '+00:00'"); } catch (Throwable $e) {}

// Load task (to get channel + time limit)
$task = null;
try {
  $st = $pdo->prepare("
    SELECT id, channel, title, from_line, meta_line, body_html,
           COALESCE(time_limit_sec,30) AS time_limit_sec
    FROM spot_tasks WHERE id=? LIMIT 1
  ");
  $st->execute([$taskId]);
  $task = $st->fetch();
  if (!$task) { echo json_encode(['ok'=>false,'message'=>'Task not found']); exit; }
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'message'=>'Load failed']); exit;
}

$limit = max(10, (int)$task['time_limit_sec']);
$now   = (int)$pdo->query("SELECT UNIX_TIMESTAMP(NOW())")->fetchColumn();

// Reuse if already open
try {
  $q = $pdo->prepare("
    SELECT id, UNIX_TIMESTAMP(deadline_at) AS exp
    FROM user_spot_sessions
    WHERE user_id=? AND task_id=? AND submitted_at IS NULL AND deadline_at > NOW()
    ORDER BY deadline_at DESC
    LIMIT 1
  ");
  $q->execute([$userId,$taskId]);
  $row = $q->fetch();
} catch (Throwable $e) {
  $row = false;
}

$sessionId = null; $exp = null;

if ($row) {
  // Reset timer on re-open: keep same session id but refresh countdown
  try {
    $upd = $pdo->prepare("UPDATE user_spot_sessions SET started_at = NOW(), deadline_at = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id=? LIMIT 1");
    $upd->execute([$limit, (int)$row['id']]);
    $sessionId = (string)$row['id'];
    $exp = (int)$pdo->query("SELECT UNIX_TIMESTAMP(deadline_at) FROM user_spot_sessions WHERE id=".(int)$row['id'])->fetchColumn();
  } catch (Throwable $e) {
    // Fallback to previous expiry if update fails
    $sessionId = (string)$row['id'];
    $exp = (int)$row['exp'];
  }
} else {
  // Create fresh
  try {
    $ins = $pdo->prepare("
      INSERT INTO user_spot_sessions (user_id, task_id, channel, started_at, deadline_at)
      VALUES (?,?,?, NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND))
    ");
    $ins->execute([$userId, $taskId, (string)$task['channel'], $limit]);
    $sessionId = (string)$pdo->lastInsertId();
    $exp = (int)$pdo->query("SELECT UNIX_TIMESTAMP(deadline_at) FROM user_spot_sessions WHERE id=".$pdo->lastInsertId())->fetchColumn();
  } catch (Throwable $e) {
    echo json_encode(['ok'=>false,'message'=>'Could not create session']); exit;
  }
}

$chan = strtolower((string)$task['channel']);
$icon = $chan==='sms' ? '📱' : ($chan==='web' ? '🌐' : '✉');

echo json_encode([
  'ok'=>true,
  'task'=>[
    'id'            => (int)$task['id'],
    'session_id'    => $sessionId,
    'now'           => $now,
    'expires_at'    => $exp,
    'icon'          => $icon,
    'channel_label' => ucfirst($chan ?: 'email'),
    'from_line'     => (string)$task['from_line'],
    'meta_line'     => (string)$task['meta_line'],
    'title'         => (string)$task['title'],
    'body_html'     => (string)$task['body_html'],
  ],
]);
