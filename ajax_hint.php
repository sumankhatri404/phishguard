<?php
// ajax_hint.php
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
  echo json_encode(['ok'=>false,'message'=>'Please log in']); exit;
}
$userId = (int)$_SESSION['user_id'];
$spotId = (int)($_POST['spot_id'] ?? 0);
if (!$spotId) { echo json_encode(['ok'=>false,'message'=>'Bad request']); exit; }

/* ensure daily tokens (3/day) */
$sel = $pdo->prepare("SELECT tokens, last_reset_date FROM user_hint_tokens WHERE user_id=?");
$sel->execute([$userId]);
$row = $sel->fetch(PDO::FETCH_ASSOC);
$today = (new DateTimeImmutable('today'))->format('Y-m-d');

if (!$row) {
  $pdo->prepare("INSERT INTO user_hint_tokens (user_id, tokens, last_reset_date) VALUES (?,3,?)")
      ->execute([$userId, $today]);
  $tokens = 3;
} else {
  $tokens = (int)$row['tokens'];
  if ($row['last_reset_date'] !== $today) {
    $pdo->prepare("UPDATE user_hint_tokens SET tokens=3, last_reset_date=? WHERE user_id=?")
        ->execute([$today, $userId]);
    $tokens = 3;
  }
}

if ($tokens <= 0) {
  echo json_encode(['ok'=>false,'message'=>'No hint tokens left today']); exit;
}

/* pick a red-flag clue not yet shown (we just return any red for now) */
$st = $pdo->prepare("SELECT label, explanation FROM spot_clues WHERE spot_id=? AND is_red_flag=1 ORDER BY RAND() LIMIT 1");
$st->execute([$spotId]);
$clue = $st->fetch(PDO::FETCH_ASSOC);
if (!$clue) {
  echo json_encode(['ok'=>false,'message'=>'No hint available']); exit;
}

/* spend one token */
$pdo->prepare("UPDATE user_hint_tokens SET tokens=tokens-1 WHERE user_id=?")->execute([$userId]);

echo json_encode([
  'ok'=>true,
  'label'=>$clue['label'],
  'explanation'=>$clue['explanation'],
  'tokens_left'=>$tokens-1
]);
