<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) { echo json_encode(['ok'=>false,'error'=>'not_logged_in']); exit; }

$in = json_decode(file_get_contents('php://input') ?: '[]', true);
$levelNo = (int)($in['level_no'] ?? 0);
if ($levelNo <= 0) { echo json_encode(['ok'=>false,'error'=>'bad_level']); exit; }

/* Ensure completion table (safe) */
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS user_level_completions (
    user_id  INT NOT NULL,
    level_no INT NOT NULL,
    completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, level_no)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

try {
  // Scenario id for this level (latest published)
  $scn = $pdo->prepare("SELECT id FROM sim_scenarios
                        WHERE level_no=? AND status='published'
                        ORDER BY version DESC, id DESC LIMIT 1");
  $scn->execute([$levelNo]);
  $scenarioId = (int)$scn->fetchColumn();
  if ($scenarioId <= 0) { echo json_encode(['ok'=>false,'error'=>'no_scenario']); exit; }

  // Required clues count
  $reqSt = $pdo->prepare("SELECT COUNT(*) FROM sim_clues WHERE scenario_id=? AND required=1");
  $reqSt->execute([$scenarioId]);
  $required = (int)$reqSt->fetchColumn();

  // Found by user
  $foundSt = $pdo->prepare("SELECT COUNT(*) FROM user_sim_progress WHERE user_id=? AND scenario_id=?");
  $foundSt->execute([$userId, $scenarioId]);
  $found = (int)$foundSt->fetchColumn();

  if ($required > 0 && $found < $required) {
    echo json_encode(['ok'=>false,'error'=>'missing_clues','required'=>$required,'found'=>$found]);
    exit;
  }

  // XP reward for level
  $xps = $pdo->prepare("SELECT xp_reward FROM sim_levels WHERE enabled=1 AND level_no=? LIMIT 1");
  $xps->execute([$levelNo]);
  $xpReward = (int)$xps->fetchColumn();
  if ($xpReward <= 0) { echo json_encode(['ok'=>false,'error'=>'no_xp_for_level']); exit; }

  // Insert completion if not exists
  $pdo->prepare("INSERT IGNORE INTO user_level_completions (user_id, level_no) VALUES (?,?)")
      ->execute([$userId, $levelNo]);

  // Award XP
  $moduleId  = defined('XP_MODULE_SPOT') ? (int)XP_MODULE_SPOT : 0;
  $totalAfter= pg_add_xp($pdo, $userId, $moduleId, $xpReward);
  $L         = pg_update_user_level($pdo, $userId);

  echo json_encode([
    'ok'            => true,
    'xp_awarded'    => $xpReward,
    'total_xp'      => $totalAfter,
    'user_level'    => $L['level'] ?? null,
    'level_name'    => $L['name'] ?? null,
    'level_changed' => (bool)($L['changed'] ?? false),
  ]);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'error'=>'server_error']);
}
