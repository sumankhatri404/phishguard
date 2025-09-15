<?php
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/db.php';

if (!isset($_SESSION['user_id'])) {
  http_response_code(401); echo json_encode(['error'=>'auth']); exit;
}

$module_id = (int)($_GET['module_id'] ?? 0);
$stmt = $pdo->prepare("SELECT id, from_name, from_avatar, subject, snippet, days_ago, red_dot
                       FROM help_requests WHERE module_id = ? ORDER BY id ASC");
$stmt->execute([$module_id]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
