<?php
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/cases_table.php';

header('Content-Type: application/json');

// --- inputs ---
$id       = (int)($_GET['id'] ?? $_GET['case_id'] ?? 0);
$moduleId = (int)($_GET['module_id'] ?? $_GET['moduleId'] ?? 0);
if ($id <= 0)       { echo json_encode(['ok'=>false,'message'=>'Missing id']); exit; }
if ($moduleId <= 0) { echo json_encode(['ok'=>false,'message'=>'Missing module_id']); exit; }

// --- ensure PDO (in case inc/db.php didn’t set it) ---
if (!isset($pdo) || !($pdo instanceof PDO)) {
  try {
    $pdo = new PDO(
      'mysql:host=127.0.0.1;dbname=phishguard;charset=utf8mb4',
      'root', '', // adjust if your DB auth differs
      [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
      ]
    );
  } catch (Throwable $e) {
    echo json_encode(['ok'=>false,'message'=>'DB not connected: '.$e->getMessage()]);
    exit;
  }
}

try {
  // Which table holds the cases for this module?
  $caseTbl = getCasesTableForModule($pdo, $moduleId, 'sms');

  // Make sure the table exists
  $t = $pdo->prepare("
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = ?
  ");
  $t->execute([$caseTbl]);
  if ((int)$t->fetchColumn() === 0) {
    echo json_encode(['ok'=>false,'message'=>'Cases table not found','table'=>$caseTbl]); exit;
  }

  // Find which body column(s) actually exist in this table (in priority order)
  $wanted = ['sms_html','body_html','body','requester_email_html','message_html','content_html'];
  $q = $pdo->prepare("
    SELECT column_name
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = ?
      AND column_name IN ('sms_html','body_html','body','requester_email_html','message_html','content_html')
  ");
  $q->execute([$caseTbl]);
  $have = array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN));

  $pick = null;
  foreach ($wanted as $w) {
    if (in_array($w, $have, true)) { $pick = $w; break; }
  }
  if ($pick === null) {
    echo json_encode([
      'ok'=>false,
      'message'=>'No body column found',
      'table'=>$caseTbl,
      'have'=>$have
    ]);
    exit;
  }

  // Fetch the body for this case scoped to this module
  $sql = "SELECT `$pick` AS body_html
          FROM `$caseTbl`
          WHERE id = ? AND module_id = ? AND (is_active IS NULL OR is_active = 1)
          LIMIT 1";
  $s = $pdo->prepare($sql);
  $s->execute([$id, $moduleId]);
  $row = $s->fetch();

  if (!$row) {
    echo json_encode([
      'ok'=>false,
      'message'=>'Case not found for this module',
      'table'=>$caseTbl,
      'case_id'=>$id,
      'module_id'=>$moduleId
    ]);
    exit;
  }

  $body = (string)$row['body_html'];
  if ($body === '') {
    echo json_encode([
      'ok'=>false,
      'message'=>"Empty body in `$pick`",
      'table'=>$caseTbl,
      'case_id'=>$id
    ]);
    exit;
  }

  echo json_encode(['ok'=>true,'body_html'=>$body]);

} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'message'=>'Exception: '.$e->getMessage()]);
}
