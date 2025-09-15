<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) { echo json_encode(['ok'=>false,'error'=>'not_logged_in']); exit; }

/* Ensure user_level_completions table exists (safe no-op if present) */
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS user_level_completions (
    user_id  INT NOT NULL,
    level_no INT NOT NULL,
    completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, level_no),
    KEY idx_user (user_id),
    KEY idx_level (level_no)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

try {
  // Latest published scenario per level
  $levels = $pdo->query("SELECT level_no, prerequisite_level_no, min_xp_required
                         FROM sim_levels WHERE enabled=1 ORDER BY order_index, level_no")
                ->fetchAll(PDO::FETCH_ASSOC);

  // Completed levels
  $st = $pdo->prepare("SELECT level_no FROM user_level_completions WHERE user_id=?");
  $st->execute([$userId]);
  $completed = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);

  // XP for unlock rules (if you use min_xp_required)
  $xp = pg_total_xp($pdo, $userId);

  // Determine unlocked per level (L1 unlocked by default)
  $completedSet = array_flip($completed);
  $unlocked = [];
  foreach ($levels as $L) {
    $lvl = (int)$L['level_no'];
    $pre = $L['prerequisite_level_no'] !== null ? (int)$L['prerequisite_level_no'] : null;
    $minXp = (int)$L['min_xp_required'];
    $okXp = ($xp >= $minXp);
    $okPre = ($pre === null) ? true : isset($completedSet[$pre]);
    $unlocked[$lvl] = ($lvl === 1) ? true : ($okPre && $okXp);
  }

  // Found clues: get latest published scenario ids first
  $scnStmt = $pdo->query("SELECT s.id, s.level_no
                          FROM sim_scenarios s
                          INNER JOIN (
                            SELECT level_no, MAX(CONCAT(LPAD(version,10,'0'),'-',LPAD(id,10,'0'))) as v
                            FROM sim_scenarios WHERE status='published'
                            GROUP BY level_no
                          ) t ON t.level_no=s.level_no
                          WHERE s.status='published'");
  $lvlToScn = [];
  foreach ($scnStmt?->fetchAll(PDO::FETCH_ASSOC) ?? [] as $r) {
    $lvlToScn[(int)$r['level_no']] = (int)$r['id'];
  }
  $scnIds = array_values($lvlToScn);
  $found = [];
  if ($scnIds) {
    $in = implode(',', array_fill(0, count($scnIds), '?'));
    $st = $pdo->prepare("SELECT scenario_id, clue_key FROM user_sim_progress
                         WHERE user_id=? AND scenario_id IN ($in)");
    $st->execute(array_merge([$userId], $scnIds));
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $sid = (int)$row['scenario_id'];
      $ck  = (string)$row['clue_key'];
      $found[$sid][$ck] = true;
    }
  }

  echo json_encode([
    'ok'        => true,
    'completed' => $completed,
    'unlocked'  => $unlocked,
    'found'     => $found,
    'xp'        => $xp
  ]);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'error'=>'server_error']);
}
