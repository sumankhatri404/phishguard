<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';

if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'auth_required']); exit; }
$userId=(int)$_SESSION['user_id'];

/** read JSON or form */
$raw = file_get_contents('php://input');
$in  = $raw ? json_decode($raw,true) : $_POST;

$csrf = (string)($in['csrf'] ?? '');
if (!verify_csrf($csrf)) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'bad_csrf']); exit; }

$scenarioId = isset($in['scenario_id']) ? (int)$in['scenario_id'] : 0;
$levelNo    = isset($in['level_no'])    ? (int)$in['level_no']    : 0;
$clueKey    = isset($in['clue_key'])    ? trim((string)$in['clue_key']) : '';

if (!$scenarioId && $levelNo) {
  $st=$pdo->prepare("SELECT id
                     FROM sim_scenarios
                     WHERE level_no=? AND status='published'
                     ORDER BY version DESC, id DESC
                     LIMIT 1");
  $st->execute([$levelNo]);
  $scenarioId = (int)$st->fetchColumn();
}
if ($scenarioId<=0 || $clueKey==='') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing_params']); exit; }

/** validate the clue belongs to this scenario */
$chk=$pdo->prepare("SELECT COUNT(*) FROM sim_clues WHERE scenario_id=? AND clue_key=?");
$chk->execute([$scenarioId,$clueKey]);
if ((int)$chk->fetchColumn()===0) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'unknown_clue']); exit; }

/** insert progress (idempotent) */
$ins=$pdo->prepare("INSERT IGNORE INTO user_sim_progress (user_id,scenario_id,clue_key) VALUES (?,?,?)");
$ins->execute([$userId,$scenarioId,$clueKey]);
$newlyAdded = ($ins->rowCount()===1);

/** recompute completion */
$req=$pdo->prepare("SELECT clue_key FROM sim_clues WHERE scenario_id=? AND required=1");
$req->execute([$scenarioId]); $required=array_map(fn($r)=>$r['clue_key'],$req->fetchAll(PDO::FETCH_ASSOC));

$fd=$pdo->prepare("SELECT clue_key FROM user_sim_progress WHERE user_id=? AND scenario_id=?");
$fd->execute([$userId,$scenarioId]); $found=array_map(fn($r)=>$r['clue_key'],$fd->fetchAll(PDO::FETCH_ASSOC));

$complete = !array_diff($required,$found);

echo json_encode([
  'ok'=>true,
  'added'=>$newlyAdded,
  'complete'=>$complete,
  'counts'=>['found'=>count($found),'required'=>count($required)],
  'found'=>$found
]);
