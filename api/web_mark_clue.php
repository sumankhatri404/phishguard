<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../inc/db.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) { echo json_encode(['ok'=>false,'error'=>'not_logged_in']); exit; }

$in = json_decode(file_get_contents('php://input') ?: '[]', true);
$scenarioId = (int)($in['scenario_id'] ?? 0);
$clueKey    = (string)($in['clue_key'] ?? '');

if ($scenarioId <= 0 || $clueKey==='') { echo json_encode(['ok'=>false,'error'=>'bad_input']); exit; }

try {
  // Ensure table (safe)
  $pdo->exec("CREATE TABLE IF NOT EXISTS user_sim_progress (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    scenario_id INT NOT NULL,
    clue_key VARCHAR(64) NOT NULL,
    found_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_scn_clue (user_id, scenario_id, clue_key),
    INDEX idx_user (user_id),
    INDEX idx_scn (scenario_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // Insert-ignore
  $st = $pdo->prepare("INSERT IGNORE INTO user_sim_progress (user_id, scenario_id, clue_key) VALUES (?,?,?)");
  $st->execute([$userId, $scenarioId, $clueKey]);

  // Return current counts (optional)
  $reqSt = $pdo->prepare("SELECT COUNT(*) FROM sim_clues WHERE scenario_id=? AND required=1");
  $reqSt->execute([$scenarioId]);
  $required = (int)$reqSt->fetchColumn();

  $foundSt = $pdo->prepare("SELECT COUNT(*) FROM user_sim_progress WHERE user_id=? AND scenario_id=?");
  $foundSt->execute([$userId, $scenarioId]);
  $found = (int)$foundSt->fetchColumn();

  echo json_encode(['ok'=>true,'required'=>$required,'found'=>$found]);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'error'=>'server_error']);
}
