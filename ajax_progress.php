<?php
// ajax_progress.php — saves a completed case and auto-awards badges.
// POST: case_id (int), points (optional)

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';
header('Content-Type: application/json');

session_start();
if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'Not logged in']);
  exit;
}

$userId  = (int)$_SESSION['user_id'];
$caseId  = (int)($_POST['case_id'] ?? 0);
$points  = (int)($_POST['points'] ?? 0);

if ($caseId <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Missing case_id']);
  exit;
}

// Ensure user_badges exists (safe to run)
try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS user_badges (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      type VARCHAR(50) NOT NULL,
      title VARCHAR(120) NOT NULL,
      description VARCHAR(255) NOT NULL,
      variant VARCHAR(20) NOT NULL,
      icon VARCHAR(30) NOT NULL,
      awarded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_user_type (user_id, type),
      KEY idx_user_awarded (user_id, awarded_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
} catch (Throwable $e) {}

function award_badge_if_needed(PDO $pdo, int $userId, string $type, string $title, string $desc, string $variant, string $icon) {
  try {
    $stmt = $pdo->prepare("INSERT IGNORE INTO user_badges (user_id, type, title, description, variant, icon) VALUES (?,?,?,?,?,?)");
    $stmt->execute([$userId, $type, $title, $desc, $variant, $icon]);
  } catch (Throwable $e) {}
}

// 1) Upsert completion (idempotent)
try {
  $check = $pdo->prepare("SELECT 1 FROM training_mail_progress WHERE user_id = ? AND case_id = ? LIMIT 1");
  $check->execute([$userId, $caseId]);
  $exists = (bool)$check->fetchColumn();

  if (!$exists) {
    $ins = $pdo->prepare("INSERT INTO training_mail_progress (user_id, case_id, points, completed_at) VALUES (?,?,?, NOW())");
    $ins->execute([$userId, $caseId, $points]);
  }
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Save failed']);
  exit;
}

// 2) Module info
$moduleId = 0; $moduleTitle = '';
try {
  $q = $pdo->prepare("
    SELECT c.module_id, m.title
    FROM training_mail_cases c
    LEFT JOIN training_modules m ON m.id = c.module_id
    WHERE c.id = ?
    LIMIT 1
  ");
  $q->execute([$caseId]);
  if ($row = $q->fetch(PDO::FETCH_ASSOC)) {
    $moduleId    = (int)$row['module_id'];
    $moduleTitle = (string)$row['title'];
  }
} catch (Throwable $e) {}

// 3) Recompute percent for this module/user
$percent = 0;
try {
  $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM training_mail_cases WHERE module_id = ?");
  $stmtDone  = $pdo->prepare("
    SELECT COUNT(DISTINCT p.case_id)
    FROM training_mail_progress p
    INNER JOIN training_mail_cases c ON c.id = p.case_id
    WHERE p.user_id = ? AND c.module_id = ?
  ");
  $stmtTotal->execute([$moduleId]);
  $totalCases = (int)$stmtTotal->fetchColumn();

  $stmtDone->execute([$userId, $moduleId]);
  $doneCases = (int)$stmtDone->fetchColumn();

  $percent = ($totalCases > 0) ? (int)round(($doneCases / $totalCases) * 100) : 0;
} catch (Throwable $e) {}

// 4) Award badges

// 4a) First completion
try {
  $cnt = $pdo->prepare("SELECT COUNT(*) FROM training_mail_progress WHERE user_id = ?");
  $cnt->execute([$userId]);
  if ((int)$cnt->fetchColumn() === 1) {
    award_badge_if_needed($pdo, $userId, 'phish_spotter', 'Phish Spotter', 'Identified a phishing email', 'azure', 'shield');
  }
} catch (Throwable $e) {}

// 4b) Module mastery (100%)
if ($moduleId > 0 && $percent >= 100) {
  $type = 'module_mastery_' . $moduleId;
  $desc = $moduleTitle ? ('Completed ' . $moduleTitle) : 'Completed a training module';
  award_badge_if_needed($pdo, $userId, $type, 'Module Mastery', $desc, 'gold', 'medal');
}

// 4c) Streak badges (3/7 days)
function compute_streak_from_db(PDO $pdo, int $userId): int {
  $dates = $pdo->prepare("
    SELECT DISTINCT DATE(completed_at) AS d
    FROM training_mail_progress
    WHERE user_id = ?
    ORDER BY d DESC
  ");
  $dates->execute([$userId]);
  $days = $dates->fetchAll(PDO::FETCH_COLUMN);
  if (!$days) return 0;

  $today = new DateTime('today');
  $streak = 0;

  foreach ($days as $d) {
    $expected = (clone $today)->modify("-{$streak} day")->format('Y-m-d');
    if ($d === $expected) { $streak++; continue; }
    if ($streak === 0 && $d === (new DateTime('yesterday'))->format('Y-m-d')) { $streak = 1; $today = new DateTime('yesterday'); continue; }
    break;
  }
  return $streak;
}

try {
  $streak = compute_streak_from_db($pdo, $userId);
  if ($streak >= 7) {
    award_badge_if_needed($pdo, $userId, 'streak_7', '7-Day Streak', 'Showed up daily for a week', 'silver', 'fire');
  } elseif ($streak >= 3) {
    award_badge_if_needed($pdo, $userId, 'streak_3', '3-Day Streak', 'On your way — keep it up!', 'violet', 'fire');
  }
} catch (Throwable $e) {}

// 5) Done
echo json_encode([
  'ok'       => true,
  'moduleId' => $moduleId,
  'percent'  => $percent
  , 'completed_all' => (function($pdo,$userId){
      try{
        $c=0; $st=$pdo->prepare("SELECT COUNT(DISTINCT case_id) FROM training_mail_progress WHERE user_id=?"); $st->execute([$userId]); $c += (int)$st->fetchColumn();
        $st=$pdo->prepare("SELECT COUNT(DISTINCT case_id) FROM training_sms_progress WHERE user_id=?");  $st->execute([$userId]); $c += (int)$st->fetchColumn();
        $st=$pdo->prepare("SELECT COUNT(DISTINCT case_id) FROM training_web_progress WHERE user_id=?");  $st->execute([$userId]); $c += (int)$st->fetchColumn();
        return $c;
      }catch(Throwable $e){ return 0; }
    })($pdo,$userId)
]);
