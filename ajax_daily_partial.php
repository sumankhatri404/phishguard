<?php
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

header('Content-Type: text/html; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo '<div style="color:#fca5a5;">Please sign in again.</div>';
  exit;
}

$userId = (int)$_SESSION['user_id'];

function chLabel($c) { return $c==='sms'?'Sms':($c==='email'?'Email':'Web'); }

// Pick 3 deterministic daily tasks for this user/date
$sql = "
  SELECT t.id, t.channel, t.title, t.from_line, t.meta_line
       , IFNULL(ud.lock_until, NULL) AS lock_until
  FROM spot_tasks t
  LEFT JOIN (
    SELECT task_id, MAX(lock_until) AS lock_until
    FROM user_spot_daily
    WHERE user_id = ?
    GROUP BY task_id
  ) ud ON ud.task_id = t.id
  ORDER BY SHA2(CONCAT(CURDATE(), '-', ?, '-', t.id), 256)
  LIMIT 3
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$userId, $userId]);
$dailyTasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// How many answered today? If >=3 we lock the whole section
$answeredToday = 0;
try {
  $q = $pdo->prepare("SELECT COUNT(DISTINCT task_id) FROM user_spot_daily WHERE user_id=? AND day_key = CURDATE()");
  $q->execute([$userId]);
  $answeredToday = (int)$q->fetchColumn();
} catch (Throwable $e) { $answeredToday = 0; }
$allLockedToday = ($answeredToday >= 3);
$nextResetEpoch = (new DateTimeImmutable('tomorrow 00:00:00'))->getTimestamp();

?>
<div id="dailyWrap" class="daily-wrap <?= $allLockedToday ? 'locked-all' : '' ?>" data-reset="<?= (int)$nextResetEpoch ?>">
  <?php if ($allLockedToday): ?>
    <div class="daily-overlay">
      <div class="do-panel">
        <span class="do-chip">Daily</span>
        <div><div><strong>All tasks completed.</strong> <span class="do-sub">New set unlocks in <span id="dailyResetTimer" class="do-timer">--:--:--</span></span></div></div>
      </div>
    </div>
  <?php endif; ?>

  <div class="spot-grid">
    <?php foreach ($dailyTasks as $t):
      $locked = ($t['lock_until'] && strtotime($t['lock_until']) > time()) || $allLockedToday;
    ?>
      <article class="daily-spot" data-task-id="<?= (int)$t['id'] ?>" data-locked="<?= $locked?'1':'0' ?>">
        <header class="ds-head">
          <div class="ds-icon"><?= $t['channel']==='sms'?'📱':($t['channel']==='email'?'📧':'🌐') ?></div>
          <div>
            <div class="ds-title"><?= htmlspecialchars($t['from_line']) ?></div>
            <div class="ds-sub"><?= htmlspecialchars($t['meta_line']) ?></div>
          </div>
          <div class="ds-chip"><?= chLabel($t['channel']); ?></div>
        </header>

        <div class="ds-body">
          <div class="ds-preview">
            <strong><?= htmlspecialchars($t['title']); ?></strong><br>
            <span class="muted">Click “Open” to view the full message and answer.</span>
          </div>
        </div>

        <footer class="ds-foot">
          <?php if ($locked): ?>
            <button class="btn-locked" disabled>🔒 Locked — come back later</button>
          <?php else: ?>
            <button class="btn-open js-open-spot">Open</button>
          <?php endif; ?>
        </footer>
      </article>
    <?php endforeach; ?>
  </div>
</div>
