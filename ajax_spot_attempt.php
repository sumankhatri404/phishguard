<?php
// ajax_spot_attempt.php
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
  echo json_encode(['ok'=>false,'message'=>'Please log in']); exit;
}

$userId  = (int)$_SESSION['user_id'];
$spotId  = (int)($_POST['spot_id'] ?? 0);
$choice  = $_POST['choice'] ?? '';

if (!$spotId || !in_array($choice,['phish','legit'],true)) {
  echo json_encode(['ok'=>false,'message'=>'Bad request']); exit;
}

// Has this user already attempted? Return prior result to avoid dupes.
$chk = $pdo->prepare("SELECT choice, is_correct, points FROM spot_attempts WHERE user_id=? AND spot_id=?");
$chk->execute([$userId, $spotId]);
if ($prev = $chk->fetch(PDO::FETCH_ASSOC)) {
  echo json_encode([
    'ok'=>true,
    'already'=>true,
    'correct'=> (bool)$prev['is_correct'],
    'points'=> (int)$prev['points'],
    'message'=>'Already recorded'
  ]); exit;
}

// Load the spot
$st = $pdo->prepare("SELECT id, is_phish FROM spot_items WHERE id=?");
$st->execute([$spotId]);
$spot = $st->fetch(PDO::FETCH_ASSOC);
if (!$spot) {
  echo json_encode(['ok'=>false,'message'=>'Not found']); exit;
}

$isCorrect = ((int)$spot['is_phish'] === 1 && $choice==='phish')
          || ((int)$spot['is_phish'] === 0 && $choice==='legit');

// Points rule: +5 XP if first attempt and correct, 0 otherwise.
$points = $isCorrect ? 5 : 0;

// Save attempt
$ins = $pdo->prepare("INSERT INTO spot_attempts (user_id, spot_id, choice, is_correct, points) VALUES (?,?,?,?,?)");
$ins->execute([$userId, $spotId, $choice, $isCorrect?1:0, $points]);

// Award XP if any
if ($points > 0) {
  $xp = $pdo->prepare("INSERT INTO user_xp (user_id, points, reason, created_at) VALUES (?,?,?,NOW())");
  $xp->execute([$userId, $points, 'Spot the Phish']);
}

echo json_encode([
  'ok'=>true,
  'correct'=>$isCorrect,
  'points'=>$points,
  'message'=>$isCorrect ? 'Nice catch!' : 'Review the clues to see what you missed.'
]);
