<?php
// module_web.php — Website Impersonation module
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

if (!isset($_SESSION['user_id'])) {
  header('Location: index.php?msg=' . urlencode('Please login first.'));
  exit;
}

$userId   = (int) $_SESSION['user_id'];
$moduleId = (int) ($_GET['id'] ?? 0);

// ✅ resolve the actual cases table for this module (dedicated or default)
require_once __DIR__ . '/inc/cases_table.php';
$caseTbl = getCasesTableForModule($pdo, $moduleId, 'web'); // <- use $moduleId (not $module_id)

// Load module meta
$m = $pdo->prepare("SELECT id, title, description, duration_minutes FROM training_modules WHERE id=? LIMIT 1");
$m->execute([$moduleId]);
$module = $m->fetch(PDO::FETCH_ASSOC);
if (!$module) die('Module not found.');

$moduleTitle = htmlspecialchars($module['title'] ?? 'Website Impersonation');
$moduleDesc  = htmlspecialchars($module['description'] ?? '');
$durationMin = (int)($module['duration_minutes'] ?? 0);

/* ---------------- Load cases (dynamic table, with sort_order fallback) ---------------- */
$hasSort = false;
try {
  $chk = $pdo->prepare("
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'sort_order'
  ");
  $chk->execute([$caseTbl]);
  $hasSort = ((int)$chk->fetchColumn() > 0);
} catch (Throwable $e) { /* ignore */ }

$sql = "SELECT * FROM `$caseTbl` WHERE module_id = ? AND is_active = 1 ORDER BY " . ($hasSort ? "sort_order, id" : "id");
$st = $pdo->prepare($sql);
$st->execute([$moduleId]);
$cases = $st->fetchAll(PDO::FETCH_ASSOC);

/* ---------------- Progress map (only for THIS module's cases) ---------------- */
$progStmt = $pdo->prepare("
  SELECT p.case_id, p.status, p.points_awarded
  FROM training_web_progress p
  JOIN `$caseTbl` c ON c.id = p.case_id
  WHERE p.user_id = ? AND c.module_id = ?
");
$progStmt->execute([$userId, $moduleId]);
$progRows = $progStmt->fetchAll(PDO::FETCH_ASSOC);
$prog = [];
foreach ($progRows as $r) $prog[(int)$r['case_id']] = $r;

/* ---------------- Counts ---------------- */
$total = count($cases);
$completed = 0;
foreach ($cases as $c) {
  $cid = (int)$c['id'];
  if (!empty($prog[$cid]) && ($prog[$cid]['status'] ?? '') === 'submitted') $completed++;
}
$pct = $total ? (int)round($completed * 100 / $total) : 0;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title><?= $moduleTitle ?> · PhishGuard</title>
<style>
:root{
  --bg:#070d1c; --panel:#0c1631; --panel2:#101d40;
  --ring:rgba(148,163,184,.16); --ring-strong:rgba(148,163,184,.28);
  --text:#e9efff; --muted:#a6b4d8; --accent:#7c4dff; --ok:#22c55e;
}
body{margin:0;background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;}
/* compact header */
header{background:linear-gradient(90deg,#5b4dff, #7c4dff 45%, #5cabff); border-bottom:1px solid rgba(255,255,255,.06);}
.header-wrap{max-width:1180px; margin:0 auto; padding:8px 16px; display:flex; justify-content:space-between; align-items:center;}
.header-wrap .btn{background:#1e2a4f;color:#fff;padding:6px 12px;border-radius:6px;text-decoration:none;font-weight:600;}
.header-wrap .btn:hover{background:#27345f;}
.wrap{max-width:1180px;margin:16px auto;padding:0 16px;}
.head{display:flex;justify-content:space-between;gap:14px;background:var(--panel);border:1px solid var(--ring);border-radius:12px;padding:12px 16px;box-shadow:0 6px 24px rgba(0,0,0,.3)}
.head .t{font-weight:800;font-size:1.1rem}
.head .d{color:var(--muted);max-width:820px;line-height:1.45;font-size:.9rem}
.badge{background:rgba(255,255,255,.06);border:1px solid var(--ring);padding:4px 8px;border-radius:999px;font-weight:700;font-size:.85rem}
.progress{margin:12px 0 4px}
.muted{color:var(--muted)}
.bar{height:10px;background:rgba(255,255,255,.06);border:1px solid var(--ring);border-radius:999px;overflow:hidden}
.fill{height:100%;width:<?= $pct ?>%;background:linear-gradient(90deg,#22c55e,#10b981)}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px;margin-top:14px}
.card{background:var(--panel2);border:1px solid var(--ring);border-radius:12px;padding:12px;box-shadow:0 6px 18px rgba(0,0,0,.28)}
.card h4{margin:0 0 6px 0;font-size:1rem}
.fake-url{font-family:ui-monospace,Menlo,Consolas,monospace;color:#a5d8ff}
.row{display:flex;justify-content:space-between;align-items:center;gap:8px}
.btn{display:inline-block;background:#334155;color:#e5edff;border:0;border-radius:8px;padding:8px 12px;font-weight:700;text-decoration:none}
.btn:hover{box-shadow:0 6px 14px rgba(0,0,0,.25);transform:translateY(-1px)}
.btn.primary{background:#22c55e;color:#062413}
.status{font-size:.8rem;border:1px solid var(--ring);padding:2px 8px;border-radius:999px}
.status.done{background:linear-gradient(90deg,#22c55e,#10b981);color:#062413;border:0}
.points{font-size:.85rem;color:#c8e6c9}
</style>
</head>
<body>
<header>
  <div class="header-wrap">
    <div><b>PhishGuard</b> · <?= $moduleTitle ?></div>
    <a class="btn" href="dashboard.php">← Back</a>
  </div>
</header>

<div class="wrap">
  <div class="head">
    <div>
      <div class="t"><?= $moduleTitle ?></div>
      <div class="d"><?= $moduleDesc ?></div>
    </div>
    <div class="badge"><?= $durationMin ?> min</div>
  </div>

  <div class="progress">
    <div class="row muted" style="margin-bottom:6px;">
      <div><?= $completed ?> / <?= $total ?> completed</div>
      <div><?= $pct ?>%</div>
    </div>
    <div class="bar"><div class="fill" id="pfill"></div></div>
  </div>

  <?php if (!$cases): ?>
    <p class="muted">No cases yet. Add rows to <code><?= htmlspecialchars($caseTbl) ?></code> for module <?= (int)$moduleId ?>.</p>
  <?php endif; ?>

  <div class="grid" id="caseGrid">
    <?php foreach ($cases as $c):
      $cid    = (int)$c['id'];
      $status = $prog[$cid]['status'] ?? 'not_started';
      $points = (int)($prog[$cid]['points_awarded'] ?? 0);
      $done   = ($status === 'submitted');
      $fakeUrl = (string)($c['fake_url'] ?? '');
    ?>
      <div class="card" data-id="<?= $cid ?>">
        <h4><?= htmlspecialchars($c['title'] ?? ('Case #'.$cid)) ?></h4>
        <div class="fake-url"><?= htmlspecialchars($fakeUrl) ?></div>
        <div class="row" style="margin-top:8px;">
          <div>
            <span class="status <?= $done ? 'done' : '' ?>">
              <?= $done ? 'Done' : ucfirst(str_replace('_',' ',$status)) ?>
            </span>
            <?php if ($points>0): ?>
              <span class="points"> · +<?= $points ?> XP</span>
            <?php endif; ?>
          </div>
          <div class="actions">
            <a class="btn" href="website_Impersonation.php?case_id=<?= $cid ?>&url=<?= urlencode($fakeUrl) ?>&module_id=<?= (int)$moduleId ?>">Open (Sandbox)</a>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
// nothing needed here yet — progress updates happen when the learner returns from sandbox
</script>
</body>
</html>
