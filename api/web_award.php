declare(strict_types=1);
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';

if (empty($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['error'=>'Not signed in']);
  exit;
}

try {
  $in = json_decode(file_get_contents('php://input'), true) ?: [];
  $levelNo = (int)($in['level_no'] ?? 0);
  if ($levelNo <= 0) throw new RuntimeException('Invalid level');

  // Fetch reward from DB
  $st = $pdo->prepare("SELECT xp_reward FROM sim_levels WHERE enabled=1 AND level_no=? LIMIT 1");
  $st->execute([$levelNo]);
  $xpReward = (int)$st->fetchColumn();
  if ($xpReward <= 0) $xpReward = 50; // fallback

  $userId = (int)$_SESSION['user_id'];

  // Award XP (uses your pg_add_xp); module id: XP_MODULE_SPOT (or change if you prefer)
  $newTotal = pg_add_xp($pdo, $userId, XP_MODULE_SPOT, $xpReward);

  echo json_encode(['ok'=>true, 'xp_awarded'=>$xpReward, 'total_xp'=>$newTotal]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['error'=>$e->getMessage()]);
}
