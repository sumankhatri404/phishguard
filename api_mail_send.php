<?php
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) { echo json_encode(['ok'=>false,'message'=>'Login required']); exit; }

$input = json_decode(file_get_contents('php://input'), true);
$user_id = (int)($_SESSION['user_id']);
$case_id = isset($input['case_id']) ? (int)$input['case_id'] : 0;

if (!$case_id) { echo json_encode(['ok'=>false,'message'=>'Missing case id']); exit; }

$stmt = $pdo->prepare("SELECT * FROM phish_expected_answers WHERE case_id = ?");
$stmt->execute([$case_id]);
$ans = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$ans) { echo json_encode(['ok'=>false,'message'=>'No answer configured']); exit; }

$ok = (
  (($input['verdict'] ?? '') === ($ans['verdict_phish'] ? 'is' : 'is_not')) &&
  (($input['r1l'] ?? '') === $ans['reason1_left']) &&
  (($input['r1r'] ?? '') === $ans['reason1_right']) &&
  (($input['joiner'] ?? '') === $ans['joiner']) &&
  (($input['r2l'] ?? '') === $ans['reason2_left']) &&
  (($input['r2r'] ?? '') === $ans['reason2_right'])
);

$points = $ok ? (int)$ans['points'] : 0;
$msg = $ok ? $ans['feedback_correct'] : $ans['feedback_wrong'];

$ins = $pdo->prepare("INSERT INTO phish_user_submissions (user_id, case_id, is_correct, earned_points) VALUES (?,?,?,?)");
$ins->execute([$user_id, $case_id, $ok ? 1 : 0, $points]);

echo json_encode(['ok'=>$ok,'points'=>$points,'message'=>$msg]);
