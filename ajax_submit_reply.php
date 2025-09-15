<?php
// ajax_submit_reply.php — award XP, bump streak, recompute level

declare(strict_types=1);
require_once __DIR__ . '/inc/functions.php';  // must contain pg_update_user_level()
require_once __DIR__ . '/inc/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
  exit;
}

$userId   = (int) $_SESSION['user_id'];
$moduleId = (int) ($_POST['module_id'] ?? 0);

// Points from client (clamp to 0…10)
$earned = isset($_POST['points']) ? (int) $_POST['points'] : 0;
if ($earned < 0) $earned = 0;
if ($earned > 10) $earned = 10;

try {
  // 1) Award XP (and bump streak inside pg_add_xp)
  $totalXp = pg_add_xp($pdo, $userId, $moduleId, $earned);

  // 2) Recompute and store the user's level from total XP (THIS is the key line)
  $lev = pg_update_user_level($pdo, $userId); // returns ['xp','level','name','changed']

  // 3) Fetch current streak for UI
  $st = $pdo->prepare("SELECT streak_current, streak_best FROM user_streaks WHERE user_id=?");
  $st->execute([$userId]);
  $sr = $st->fetch(PDO::FETCH_ASSOC) ?: ['streak_current'=>0,'streak_best'=>0];

  echo json_encode([
    'ok'          => true,
    'earned'      => $earned,
    'total_xp'    => $totalXp,
    'level'       => (int)$lev['level'],     // L1..L6
    'level_name'  => (string)$lev['name'],   // Bronze/Silver/… (or your custom label)
    'streak'      => (int)$sr['streak_current'],
    'best_streak' => (int)$sr['streak_best']
  ]);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'ok'    => false,
    'error' => 'DB error',
    'debug' => $e->getMessage()
  ]);
}
