<?php
// training_module.php — single-module page with progress & completion
declare(strict_types=1);

require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php'; // provides $pdo

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php?msg=" . urlencode("Please login first."));
    exit;
}

$user_id = (int) $_SESSION['user_id'];

/* -----------------------------------------------------------
   Utilities
----------------------------------------------------------- */

function ensure_progress_table(PDO $pdo): void {
    // Create user_training_progress if it doesn't exist yet.
    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `user_training_progress` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `module_id` INT UNSIGNED NOT NULL,
  `progress_percent` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `status` ENUM('Not started','In progress','Completed') NOT NULL DEFAULT 'Not started',
  `started_at` DATETIME NULL,
  `completed_at` DATETIME NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_module` (`user_id`,`module_id`),
  KEY `idx_module` (`module_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
    $pdo->exec($sql);
}

/** Return module by id or null */
function get_module(PDO $pdo, int $module_id): ?array {
    $st = $pdo->prepare("SELECT * FROM training_modules WHERE id=? LIMIT 1");
    $st->execute([$module_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Get progress row; if missing, create 'Not started'. */
function get_or_seed_progress(PDO $pdo, int $user_id, int $module_id): array {
    $st = $pdo->prepare("SELECT * FROM user_training_progress WHERE user_id=? AND module_id=?");
    $st->execute([$user_id, $module_id]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if ($p) return $p;

    $ins = $pdo->prepare("
        INSERT INTO user_training_progress (user_id, module_id, progress_percent, status)
        VALUES (?, ?, 0, 'Not started')
    ");
    $ins->execute([$user_id, $module_id]);

    $st->execute([$user_id, $module_id]);
    return (array)$st->fetch(PDO::FETCH_ASSOC);
}

/** First real visit => move Not started -> In progress */
function bump_to_in_progress(PDO $pdo, array $progress): void {
    if (($progress['status'] ?? '') === 'Not started') {
        $u = $pdo->prepare("
            UPDATE user_training_progress
            SET status='In progress', started_at=NOW()
            WHERE user_id=? AND module_id=? AND status='Not started'
        ");
        $u->execute([(int)$progress['user_id'], (int)$progress['module_id']]);
    }
}

/* -----------------------------------------------------------
   Input
----------------------------------------------------------- */

$module_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$module_id) {
    $module_id = filter_input(INPUT_POST, 'module_id', FILTER_VALIDATE_INT);
}
if (!$module_id || $module_id < 1) {
    http_response_code(400);
    exit('Module ID missing or invalid.');
}

/* -----------------------------------------------------------
   Data
----------------------------------------------------------- */

ensure_progress_table($pdo);

$module = get_module($pdo, (int)$module_id);
if (!$module) {
    http_response_code(404);
    exit('Module not found.');
}

$progress = get_or_seed_progress($pdo, $user_id, (int)$module_id);

/* -----------------------------------------------------------
   Actions
----------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf'] ?? '')) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'complete') {
        $st = $pdo->prepare("
            UPDATE user_training_progress
            SET progress_percent=100, status='Completed', completed_at=NOW()
            WHERE user_id=? AND module_id=?
        ");
        $st->execute([$user_id, (int)$module_id]);
        redirect_msg("dashboard.php", "Module completed!");
    }

    if ($action === 'reset') {
        $st = $pdo->prepare("
            UPDATE user_training_progress
            SET progress_percent=0,
                status='Not started',
                started_at=NULL,
                completed_at=NULL
            WHERE user_id=? AND module_id=?
        ");
        $st->execute([$user_id, (int)$module_id]);
        redirect_msg("training_module.php?id=".$module_id, "Progress reset.");
    }
}

// After any POST redirect, for a GET: ensure we reflect "In progress".
$progress = get_or_seed_progress($pdo, $user_id, (int)$module_id);
bump_to_in_progress($pdo, $progress);

// Refresh local copy after any possible update above
$st = $pdo->prepare("SELECT * FROM user_training_progress WHERE user_id=? AND module_id=?");
$st->execute([$user_id, (int)$module_id]);
$progress = (array)$st->fetch(PDO::FETCH_ASSOC);

/* Convenience */
$level  = htmlspecialchars((string)($module['level'] ?? ''));
$title  = htmlspecialchars((string)($module['title'] ?? 'Training Module'));
$desc   = nl2br(htmlspecialchars((string)($module['description'] ?? '')));
$durMin = (int)($module['duration_minutes'] ?? 0);
$status = (string)($progress['status'] ?? 'Not started');
$pct    = (int)($progress['progress_percent'] ?? 0);
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title><?= $title ?></title>
<link rel="stylesheet" href="assets/css/pg.css">
<style>
.page{max-width:900px;margin:40px auto;background:rgba(16,26,49,.96);padding:22px;border-radius:14px;color:#fff}
h1{margin:0 0 6px}
.meta{color:#cdd5f5;margin:0 0 14px}
.badge{display:inline-block;border:1px solid rgba(255,255,255,.18);padding:2px 8px;border-radius:999px;margin-left:6px;font-size:12px}
.row{display:flex;gap:12px;flex-wrap:wrap;margin-top:16px}
.btn{display:inline-grid;place-items:center;height:46px;padding:0 14px;border:1px solid rgba(255,255,255,.16);border-radius:10px;background:#1e293b;color:#fff;cursor:pointer;text-decoration:none}
.btn:hover{background:#243447}
.note{margin-top:10px;background:rgba(124,77,255,.16);border:1px solid rgba(124,77,255,.35);padding:10px 12px;border-radius:10px}
.kv{margin-top:10px;color:#c9d4ff}
.progress{height:8px;background:rgba(255,255,255,.15);border-radius:999px;overflow:hidden;margin-top:8px}
.progress > span{display:block;height:100%;background:#22d3ee;width:<?= max(0,min(100,$pct)) ?>%}
</style>
</head>
<body class="pg-page">
<div class="page">
  <h1><?= $title ?></h1>
  <p class="meta">
    <strong>Level:</strong> <?= $level ?>
    <span class="badge">Status: <?= htmlspecialchars($status) ?></span>
    <span class="badge">Progress: <?= $pct ?>%</span>
  </p>
  <?php if ($durMin): ?>
    <p class="meta"><strong>Duration:</strong> <?= $durMin ?> min</p>
  <?php endif; ?>
  <p><?= $desc ?></p>

  <div class="kv">Your progress</div>
  <div class="progress"><span></span></div>

  <?php if (!empty($_GET['msg'])): ?>
    <div class="note"><?= htmlspecialchars((string)$_GET['msg']) ?></div>
  <?php endif; ?>

  <div class="row">
    <!-- Mark Complete -->
    <form method="post" action="training_module.php?id=<?= (int)$module_id ?>">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="module_id" value="<?= (int)$module_id ?>">
      <input type="hidden" name="action" value="complete">
      <button type="submit" class="btn">Mark as Complete</button>
    </form>

    <!-- Reset Progress -->
    <form method="post" action="training_module.php?id=<?= (int)$module_id ?>"
          onsubmit="return confirm('Reset progress for this module?');">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="module_id" value="<?= (int)$module_id ?>">
      <input type="hidden" name="action" value="reset">
      <button type="submit" class="btn">Reset Progress</button>
    </form>

    <a href="dashboard.php" class="btn">Back</a>
  </div>
</div>
</body>
</html>
