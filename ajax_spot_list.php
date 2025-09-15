<?php
// ajax_spot_list.php
header('Content-Type: application/json');
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

if (empty($_SESSION['user_id'])) { echo json_encode(['ok'=>false,'message'=>'Not logged in']); exit; }
$userId = (int)$_SESSION['user_id'];

try {
  // Keep MySQL clock consistent with other endpoints
  try { $pdo->exec("SET time_zone = '+00:00'"); } catch (Throwable $e) {}

  // Pre-calc "tomorrow 00:00:00" epoch (UTC) for countdowns
  $todayEndTs = (int)$pdo->query("
    SELECT UNIX_TIMESTAMP(DATE_ADD(DATE(NOW()), INTERVAL 1 DAY))
  ")->fetchColumn();

  // Pick today's 3 deterministic tasks (unchanged), plus:
  //  - lock_until from user_spot_daily (your legacy lock)
  //  - submitted_today from user_spot_sessions (new, locks after submit)
  $sql = "
    SELECT
      t.id, t.channel, t.title, t.from_line, t.meta_line,

      (
        SELECT MAX(d.lock_until)
        FROM user_spot_daily d
        WHERE d.user_id = ? AND d.task_id = t.id AND d.day_key = CURDATE()
      ) AS lock_until,

      EXISTS(
        SELECT 1
        FROM user_spot_sessions s
        WHERE s.user_id = ? AND s.task_id = t.id
          AND s.submitted_at IS NOT NULL
          AND DATE(s.submitted_at) = CURDATE()
      ) AS submitted_today,

      EXISTS(
        SELECT 1
        FROM user_spot_sessions s2
        WHERE s2.user_id = ? AND s2.task_id = t.id
          AND s2.submitted_at IS NULL
          AND s2.deadline_at <= NOW()
          AND DATE(s2.started_at) = CURDATE()
      ) AS expired_today

    FROM spot_tasks t
    ORDER BY SHA2(CONCAT(CURDATE(), '-', ?, '-', t.id), 256)
    LIMIT 3
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$userId, $userId, $userId, $userId]);
  $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $now = time();
  $html = '';
  $allLocked = true;
  $maxFuture = 0; // latest unlock among the 3

  foreach ($tasks as $t) {
    $id    = (int)$t['id'];
    $ch    = $t['channel'];
    $from  = htmlspecialchars($t['from_line'] ?? '');
    $meta  = htmlspecialchars($t['meta_line'] ?? '');
    $title = htmlspecialchars($t['title'] ?? '');

    // Lock if:
    //  A) user submitted this task today (session recorded), OR
    //  B) legacy lock_until is in the future
    $submittedToday = !empty($t['submitted_today']) && (int)$t['submitted_today'] === 1;
    $expiredToday   = !empty($t['expired_today']) && (int)$t['expired_today'] === 1;

    $isLocked = false;

    // Legacy lock_until support
    $luTs = 0;
    if (!empty($t['lock_until'])) {
      $tmp = strtotime($t['lock_until']);
      if ($tmp && $tmp > $now) {
        $luTs = $tmp;
        $isLocked = true;
      }
    }

    // New rule: after submit OR time expired today, lock until end of day
    if ($submittedToday || $expiredToday) {
      $isLocked = true;
      // End-of-day is the unlock time for submitted-today
      $luTs = max($luTs, $todayEndTs);
    }

    if ($isLocked) {
      if ($luTs > $maxFuture) $maxFuture = $luTs;
    } else {
      $allLocked = false;
    }

    $chip = ($ch==='sms'?'Sms':($ch==='email'?'Email':'Web'));
    $icon = ($ch==='sms'?'📱':($ch==='email'?'📧':'🌐'));

    $btn  = $isLocked
      ? '<button class="btn-locked" disabled>🔒 Locked — come back later</button>'
      : '<button class="btn-open js-open-spot">Open</button>';

    $html .= '
<article class="daily-spot" data-task-id="'.$id.'" data-locked="'.($isLocked?'1':'0').'">
  <header class="ds-head">
    <div class="ds-icon">'.$icon.'</div>
    <div>
      <div class="ds-title">'.$from.'</div>
      <div class="ds-sub">'.$meta.'</div>
    </div>
    <div class="ds-chip">'.$chip.'</div>
  </header>
  <div class="ds-body">
    <strong>'.$title.'</strong><br>
    <span class="muted">Click “Open” to view the full message and answer.</span>
  </div>
  <footer class="ds-foot">'.$btn.'</footer>
</article>';
  }

  echo json_encode([
    'ok'                => true,
    'html'              => $html,
    'all_locked'        => $allLocked,
    'next_unlock_epoch' => ($allLocked && $maxFuture > 0) ? $maxFuture : 0
  ]);

} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'message'=>'List failed','debug'=>$e->getMessage()]);
}
