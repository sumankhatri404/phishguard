<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../inc/db.php';         // exposes $pdo (PDO)
require_once __DIR__ . '/../inc/functions.php';  // csrf + xp helpers

if (empty($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'auth_required']); exit;
}
$userId = (int)$_SESSION['user_id'];

/** Resolve latest published scenario id for a level */
function web_latest_scenario_id(PDO $pdo, int $levelNo): ?int {
  $st=$pdo->prepare("SELECT id
                     FROM sim_scenarios
                     WHERE level_no=? AND status='published'
                     ORDER BY version DESC, id DESC
                     LIMIT 1");
  $st->execute([$levelNo]);
  $id = $st->fetchColumn();
  return $id ? (int)$id : null;
}

$scenarioId = isset($_GET['scenario_id']) ? (int)$_GET['scenario_id'] : null;
$levelNo    = isset($_GET['level_no'])    ? (int)$_GET['level_no']    : null;
if (!$scenarioId && $levelNo) $scenarioId = web_latest_scenario_id($pdo, $levelNo);

if (!$scenarioId) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing_scenario']); exit; }

/** required clues for this scenario */
$req = $pdo->prepare("SELECT clue_key
                      FROM sim_clues
                      WHERE scenario_id=? AND required=1
                      ORDER BY sort_order, id");
$req->execute([$scenarioId]);
$required = array_map(fn($r)=>$r['clue_key'],$req->fetchAll(PDO::FETCH_ASSOC));

/** found clues by this user */
$fd = $pdo->prepare("SELECT clue_key
                     FROM user_sim_progress
                     WHERE user_id=? AND scenario_id=?");
$fd->execute([$userId,$scenarioId]);
$found = array_map(fn($r)=>$r['clue_key'],$fd->fetchAll(PDO::FETCH_ASSOC));

$complete = !array_diff($required,$found);

/** include some display metadata */
$metaSt = $pdo->prepare("
  SELECT l.level_no,l.title,l.difficulty,l.xp_reward,
         s.url_in_bar,s.show_padlock,s.show_not_secure,s.countdown_seconds
  FROM sim_scenarios s
  JOIN sim_levels l ON l.level_no=s.level_no
  WHERE s.id=? LIMIT 1
");
$metaSt->execute([$scenarioId]);
$meta = $metaSt->fetch(PDO::FETCH_ASSOC) ?: [];

echo json_encode([
  'ok'=>true,
  'scenario_id'=>$scenarioId,
  'required'=>$required,
  'found'=>$found,
  'complete'=>$complete,
  'meta'=>$meta
]);
