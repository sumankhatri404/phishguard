<?php
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'msg'=>'unauthorized']);
  exit;
}

if (!verify_csrf($_POST['csrf'] ?? '')) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'msg'=>'bad token']);
  exit;
}

$module_id   = filter_input(INPUT_POST,'module_id',FILTER_VALIDATE_INT);
$stepIndex   = filter_input(INPUT_POST,'step_index',FILTER_VALIDATE_INT);
$totalSteps  = filter_input(INPUT_POST,'total_steps',FILTER_VALIDATE_INT);

if(!$module_id || !$stepIndex || !$totalSteps){
  http_response_code(400);
  echo json_encode(['ok'=>false,'msg'=>'bad params']);
  exit;
}

$percent = (int) floor((max(1,$stepIndex) / $totalSteps) * 100);
$percent = min(99, max(1,$percent)); // only lessons, keep <100

$user_id = (int)$_SESSION['user_id'];

$pdo->prepare(
  "UPDATE user_training_progress
   SET progress_percent = GREATEST(progress_percent, ?), status='In progress'
   WHERE user_id = ? AND module_id = ?"
)->execute([$percent, $user_id, $module_id]);

echo json_encode(['ok'=>true,'percent'=>$percent]);
