<?php
// admin/user_profile.php — Evaluation-focused profile with optional training-module summary
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
admin_require_login();

/* ---------- helpers ---------- */
function tbl_exists(PDO $pdo, string $t): bool {
  $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name=?");
  $q->execute([$t]); return (int)$q->fetchColumn()>0;
}
function col_exists(PDO $pdo,string $t,string $c): bool {
  $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name=? AND column_name=?");
  $q->execute([$t,$c]); return (int)$q->fetchColumn()>0;
}
function pct(int|float $a, int|float $b): int { return $b>0 ? (int)round($a*100/$b) : 0; }
function fmt_secs(int $s): string {
  if($s<=0) return '—';
  $m = intdiv($s,60); $s = $s % 60;
  if ($m >= 60) { $h = intdiv($m,60); $m = $m % 60; return sprintf('%dh %dm %ds', $h, $m, $s); }
  return sprintf('%dm %ds', $m, $s);
}
function level_from_xp(int $xp): array {
  if($xp>=10000)return['level'=>6,'name'=>'Master'];
  if($xp>=5000)return['level'=>5,'name'=>'Diamond'];
  if($xp>=2500)return['level'=>4,'name'=>'Platinum'];
  if($xp>=1000)return['level'=>3,'name'=>'Gold'];
  if($xp>=250)return['level'=>2,'name'=>'Silver'];
  return['level'=>1,'name'=>'Bronze'];
}

/* ---------- assessment helpers (supports test_attempts + older schemas) ---------- */
function latest_assessment(PDO $pdo,int $uid,string $kind):?array{
  $k = (strtolower($kind)==='post' ? 'post' : 'pre');

  // 0) New: test_attempts
  if (tbl_exists($pdo,'test_attempts')) {
    $col=null; $val=null; $boolPre=null; $boolPost=null;
    foreach (['kind','phase','test_type'] as $c) if (col_exists($pdo,'test_attempts',$c)) $col=$c;
    if ($col!==null) { $val=$k; }
    if ($col===null && col_exists($pdo,'test_attempts','is_pre'))  $boolPre=true;
    if ($col===null && col_exists($pdo,'test_attempts','is_post')) $boolPost=true;

    $scoreCol = col_exists($pdo,'test_attempts','score') ? 'score' : (col_exists($pdo,'test_attempts','correct') ? 'correct' : null);
    $totalCol = col_exists($pdo,'test_attempts','total') ? 'total' : (col_exists($pdo,'test_attempts','questions') ? 'questions' : null);
    $dateCol  = col_exists($pdo,'test_attempts','submitted_at') ? 'submitted_at' : (col_exists($pdo,'test_attempts','created_at') ? 'created_at' : null);

    if ($scoreCol && $totalCol) {
      // ignore zero totals / null dates
      $where="user_id=? AND {$totalCol} > 0".($dateCol ? " AND {$dateCol} IS NOT NULL" : "");
      $bind=[$uid];
      if ($col!==null) { $where.=" AND {$col}=?"; $bind[]=$val; }
      elseif ($boolPre || $boolPost) {
        $flag = ($k==='pre' ? 'is_pre' : 'is_post'); $where.=" AND {$flag}=1";
      }
      $dt = $dateCol ?: 'id';
      $sql = "SELECT {$scoreCol} AS s, {$totalCol} AS t, ".($dateCol ? "{$dateCol} AS d" : "'' AS d")
           . " FROM test_attempts WHERE {$where} ORDER BY {$dt} DESC LIMIT 1";
      $st = $pdo->prepare($sql); $st->execute($bind);
      if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $s=(int)$r['s']; $t=(int)$r['t'];
        return ['score'=>$s,'total'=>$t,'pct'=>$t?round($s*100.0/$t,1):0.0,'submitted_at'=>$r['d']];
      }
    }
  }

  // 1) user_assessments (fallback) — ignore zero-total
  if (tbl_exists($pdo,'user_assessments')) {
    $st=$pdo->prepare("SELECT score,total,submitted_at FROM user_assessments
                       WHERE user_id=? AND kind=? AND total>0
                       ORDER BY submitted_at DESC LIMIT 1");
    $st->execute([$uid,$k]);
    if($r=$st->fetch(PDO::FETCH_ASSOC)){
      $s=(int)$r['score'];$t=(int)$r['total'];
      return ['score'=>$s,'total'=>$t,'pct'=>$t?round($s*100.0/$t,1):0.0,'submitted_at'=>$r['submitted_at']??''];
    }
  }

  // 2) very old schemas — ignore zero-total
  $map=$k==='pre'?['user_pretest','pretest_results']:['user_posttest','posttest_results'];
  foreach($map as $t){ if(!tbl_exists($pdo,$t)) continue;
    $st=$pdo->prepare("SELECT score,total,submitted_at FROM {$t}
                       WHERE user_id=? AND total>0
                       ORDER BY submitted_at DESC LIMIT 1");
    $st->execute([$uid]);
    if($r=$st->fetch(PDO::FETCH_ASSOC)){
      $s=(int)$r['score'];$tt=(int)$r['total'];
      return ['score'=>$s,'total'=>$tt,'pct'=>$tt?round($s*100.0/$tt,1):0.0,'submitted_at'=>$r['submitted_at']??''];
    }
    break;
  }
  return null;
}

/* ---------- input + user ---------- */
$uid=(int)($_GET['uid']??0); if($uid<=0){ http_response_code(400); exit('Missing uid'); }
$st=$pdo->prepare("SELECT id,username,email,role,level,level_name, DATE_FORMAT(created_at,'%Y-%m-%d %H:%i') created_at FROM users WHERE id=?");
$st->execute([$uid]); $user=$st->fetch(PDO::FETCH_ASSOC); if(!$user){ http_response_code(404); exit('User not found'); }

/* ---------- XP & level ---------- */
$totalXP=0; if(tbl_exists($pdo,'user_xp')){ $x=$pdo->prepare("SELECT COALESCE(SUM(points),0) FROM user_xp WHERE user_id=?"); $x->execute([$uid]); $totalXP=(int)$x->fetchColumn(); }
$levelInfo=['level'=>(int)($user['level']??0),'name'=>(string)($user['level_name']??'')];
if($levelInfo['level']<=0||$levelInfo['name']===''){ $levelInfo=level_from_xp($totalXP); }

/* ---------- sessions: accuracy + speed + channel ---------- */
$overallAttempts=0; $overallCorrect=0; $overallAvgSec=null;
$channelsAcc=['email'=>null,'sms'=>null,'web'=>null];
$speedBuckets=['fast'=>0,'avg'=>0,'slow'=>0];
$lastSeen=null;

if (tbl_exists($pdo,'user_spot_sessions')) {
  $q=$pdo->prepare("SELECT DATE_FORMAT(MAX(COALESCE(submitted_at,started_at)),'%Y-%m-%d %H:%i') FROM user_spot_sessions WHERE user_id=?");
  $q->execute([$uid]); $lastSeen=$q->fetchColumn();

  $q=$pdo->prepare("
    SELECT COUNT(*) a,
           SUM(is_correct=1) c,
           AVG(TIMESTAMPDIFF(SECOND,started_at,COALESCE(submitted_at,started_at))) t
    FROM user_spot_sessions
    WHERE user_id=? AND submitted_at IS NOT NULL
  "); $q->execute([$uid]); if($r=$q->fetch(PDO::FETCH_ASSOC)){
    $overallAttempts=(int)$r['a']; $overallCorrect=(int)$r['c'];
    $overallAvgSec = $r['t']!==null ? (int)round((float)$r['t']) : null;
  }

  if (col_exists($pdo,'user_spot_sessions','channel')) {
    $q=$pdo->prepare("
      SELECT LOWER(channel) ch, COUNT(*) a, SUM(is_correct=1) c
      FROM user_spot_sessions
      WHERE user_id=? AND submitted_at IS NOT NULL
      GROUP BY LOWER(channel)
    "); $q->execute([$uid]);
    foreach($q as $r){
      $ch=$r['ch']; if(isset($channelsAcc[$ch])){
        $channelsAcc[$ch] = pct((int)$r['c'], (int)$r['a']);
      }
    }
  }

  $hasDecision = col_exists($pdo,'user_spot_sessions','decision_ms');
  if ($hasDecision) {
    $bq=$pdo->prepare("
      SELECT
        SUM(CASE WHEN decision_ms IS NOT NULL AND decision_ms <  5000 THEN 1 ELSE 0 END) AS fast,
        SUM(CASE WHEN decision_ms BETWEEN 5000 AND 15000 THEN 1 ELSE 0 END) AS avg,
        SUM(CASE WHEN decision_ms > 15000 THEN 1 ELSE 0 END) AS slow
      FROM user_spot_sessions
      WHERE user_id=? AND submitted_at IS NOT NULL
    "); $bq->execute([$uid]);
    if($br=$bq->fetch(PDO::FETCH_ASSOC)){
      $speedBuckets=['fast'=>(int)$br['fast'],'avg'=>(int)$br['avg'],'slow'=>(int)$br['slow']];
    }
  }
}

/* ---------- Pre/Post ---------- */
$preLast = latest_assessment($pdo,$uid,'pre');
$postLast= latest_assessment($pdo,$uid,'post');
$improveAbs=null; $prePct=$preLast['pct']??0; $postPct=$postLast['pct']??0;
if($preLast&&$postLast){ $improveAbs=round($postPct-$prePct,1); }
$overallAcc = $overallAttempts? round($overallCorrect*100/$overallAttempts):0;

/* ---------- Assessment list (compact) ---------- */
$attemptRows=[];
if (tbl_exists($pdo,'test_attempts')) {
  $scoreCol = col_exists($pdo,'test_attempts','score') ? 'score' : (col_exists($pdo,'test_attempts','correct') ? 'correct' : null);
  $totalCol = col_exists($pdo,'test_attempts','total') ? 'total' : (col_exists($pdo,'test_attempts','questions') ? 'questions' : null);
  $dateCol  = col_exists($pdo,'test_attempts','submitted_at') ? 'submitted_at' : (col_exists($pdo,'test_attempts','created_at') ? 'created_at' : 'id');
  $kindCol = null; foreach (['kind','phase','test_type'] as $c) if (col_exists($pdo,'test_attempts',$c)) $kindCol=$c;
  if ($scoreCol && $totalCol) {
    $sql = "SELECT ".($kindCol? "{$kindCol} AS k," : "'' AS k,")." {$scoreCol} s, {$totalCol} t, {$dateCol} d
            FROM test_attempts
            WHERE user_id=? AND {$totalCol} > 0 ".($dateCol ? "AND {$dateCol} IS NOT NULL" : "")."
            ORDER BY {$dateCol} DESC
            LIMIT 8";
    $st=$pdo->prepare($sql); $st->execute([$uid]);
    foreach($st as $r){
      $lab = $r['k']!==''? strtoupper(substr($r['k'],0,1)).substr($r['k'],1) : 'Test';
      $s=(int)$r['s']; $t=(int)$r['t']; $p=$t?round($s*100.0/$t,1):0;
      $attemptRows[]=['kind'=>$lab,'score'=>"$s/$t",'pct'=>"$p%",'date'=>substr((string)$r['d'],0,16)];
    }
  }
}

/* ---------- OPTIONAL: training module summary (uses user_training_progress) ---------- */
$tm = [
  'by_total' => ['email'=>0,'sms'=>0,'web'=>0],
  'by_done'  => ['email'=>0,'sms'=>0,'web'=>0],
  'total'    => 0,
  'done'     => 0,
  'last'     => null,
];
if (tbl_exists($pdo,'training_modules') && tbl_exists($pdo,'user_training_progress')) {
  foreach ($pdo->query("SELECT channel, COUNT(*) c FROM training_modules GROUP BY channel") as $r) {
    $ch = strtolower($r['channel']);
    if (isset($tm['by_total'][$ch])) $tm['by_total'][$ch] = (int)$r['c'];
  }
  $st = $pdo->prepare("
    SELECT tm.channel, COUNT(*) c
    FROM training_modules tm
    JOIN user_training_progress up
      ON up.module_id = tm.id
     AND up.user_id   = ?
     AND up.status    = 'Completed'
    GROUP BY tm.channel
  ");
  $st->execute([$uid]);
  foreach ($st as $r) {
    $ch = strtolower($r['channel']);
    if (isset($tm['by_done'][$ch])) $tm['by_done'][$ch] = (int)$r['c'];
  }
  $tm['total'] = array_sum($tm['by_total']);
  $tm['done']  = array_sum($tm['by_done']);
  $st = $pdo->prepare("SELECT DATE_FORMAT(MAX(completed_at),'%Y-%m-%d %H:%i') FROM user_training_progress WHERE user_id=? AND status='Completed'");
  $st->execute([$uid]); $tm['last'] = $st->fetchColumn() ?: null;
}

/* ---------- CSV Export ---------- */
if (isset($_GET['export']) && $_GET['export']==='csv') {
  $filename = 'user_eval_'.($user['username'] ?? 'user').'_'.$uid.'.csv';
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="'.$filename.'"');
  $out = fopen('php://output', 'w');

  $write = function(array $row) use ($out){ fputcsv($out, $row); };

  $write(['User', $user['username']??'', 'Email', $user['email']??'']);
  $write(['Role', $user['role']??'user', 'Level', 'L'.$levelInfo['level'].' · '.$levelInfo['name']]);
  $write(['Joined', $user['created_at']??'', 'Last seen', $lastSeen??'—']);
  $write([]);
  $write(['=== Summary ===']);
  $write(['Overall Accuracy (%)', $overallAcc]);
  $write(['Attempts', $overallAttempts]);
  $write(['Correct', $overallCorrect]);
  $write(['Avg Decision Time', $overallAvgSec!==null? fmt_secs($overallAvgSec):'—']);
  $write([]);

  $write(['=== Pre vs Post ===']);
  $write(['Pre %', $prePct]);
  $write(['Post %', $postPct]);
  $write(['Improvement (pts)', $improveAbs!==null? $improveAbs : '—']);
  $write([]);

  $write(['=== Accuracy by Channel (%) ===']);
  foreach (['Email'=>'email','SMS & Social'=>'sms','Web/Sandbox'=>'web'] as $label=>$key) {
    $write([$label, (int)($channelsAcc[$key]??0)]);
  }
  $write([]);

  $write(['=== Decision Speed (counts) ===']);
  $write(['Fast (<5s)', $speedBuckets['fast']??0]);
  $write(['Average (5–15s)', $speedBuckets['avg']??0]);
  $write(['Slow (>15s)', $speedBuckets['slow']??0]);
  $write([]);

  if ($tm['total']>0){
    $write(['=== Training Modules ===']);
    $write(['Total', $tm['done'].'/'.$tm['total']]);
    $write(['Email', $tm['by_done']['email'].'/'.$tm['by_total']['email']]);
    $write(['SMS & Social', $tm['by_done']['sms'].'/'.$tm['by_total']['sms']]);
    $write(['Web/Sandbox', $tm['by_done']['web'].'/'.$tm['by_total']['web']]);
    if ($tm['last']) $write(['Last completed at', $tm['last']]);
    $write([]);
  }

  if ($attemptRows) {
    $write(['=== Latest Assessment Attempts ===']);
    $write(['Kind','Score','%','Date']);
    foreach ($attemptRows as $r) $write([$r['kind'],$r['score'],$r['pct'],$r['date']]);
  }

  fclose($out);
  exit;
}

/* ---------- data for JS ---------- */
$js = [
  'overallAcc'=>$overallAcc,
  'prePct'=>$prePct, 'postPct'=>$postPct,
  'improveAbs'=>$improveAbs,
  'channelsAcc'=>[
    'Email'=> (int)($channelsAcc['email'] ?? 0),
    'SMS & Social'=> (int)($channelsAcc['sms'] ?? 0),
    'Web/Sandbox'=> (int)($channelsAcc['web'] ?? 0),
  ],
  'speed'=>$speedBuckets
];

$adminBase = admin_base();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>User · <?= htmlspecialchars($user['username']??'') ?> · PhishGuard Admin</title>
<link rel="icon" type="image/svg+xml"
        href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='12' fill='%236d28d9'/%3E%3Cpath d='M18 38l14-20 14 20-14 8z' fill='white'/%3E%3C/svg%3E">

<meta name="viewport" content="width=device-width, initial-scale=1">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
:root{
  --bg:#f8fafc; --panel:#ffffff; --ink:#0f172a; --muted:#6b7280; --line:#e5e7eb;
  --brand:#3b82f6; --ok:#10b981; --warn:#f59e0b; --bad:#ef4444;
}
*{box-sizing:border-box}
html,body{margin:0;background:var(--bg);color:var(--ink);font:13.5px/1.5 Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial}
a{color:inherit;text-decoration:none}
.wrap{display:grid;grid-template-columns:220px 1fr;min-height:100vh}
.sidebar{background:#0f172a;color:#cbd5e1;padding:14px;border-right:1px solid rgba(0,0,0,.12)}
.sidebar .brand{font-weight:700;margin-bottom:12px}
.sidebar a{display:block;padding:8px 10px;border-radius:8px;margin:2px 0;color:#cbd5e1}
.sidebar a.active,.sidebar a:hover{background:rgba(255,255,255,.06)}

.header{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;background:#fff;border-bottom:1px solid var(--line)}
.hero{display:flex;align-items:center;gap:10px}
.avatar{width:36px;height:36px;border-radius:50%;display:grid;place-items:center;font-weight:700;background:linear-gradient(135deg,#3b82f6,#22c55e);color:#051026}
.btn{border:1px solid var(--line);background:#fff;border-radius:8px;padding:6px 10px;font-weight:600;cursor:pointer}
.btn-row{display:flex;gap:8px;align-items:center}

.main{padding:16px;max-width:1200px;margin:0 auto}
.grid{display:grid;gap:12px}
.grid-4{grid-template-columns:repeat(4,1fr)}
.grid-2{grid-template-columns:1.4fr .9fr}
@media(max-width:1100px){.wrap{grid-template-columns:1fr}.sidebar{display:none}.grid-4{grid-template-columns:repeat(2,1fr)}.grid-2{grid-template-columns:1fr}}

.card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:14px;box-shadow:0 1px 2px rgba(0,0,0,.03)}
.card h3{margin:0 0 8px;font-size:14px;font-weight:600}
.kpi{display:flex;align-items:center;gap:10px}
.kpi .icon{width:28px;height:28px;border-radius:8px;display:grid;place-items:center;background:#f3f4f6;color:#1f2937;border:1px solid var(--line)}
.kpi .label{color:var(--muted);font-size:12px}
.kpi .val{font-size:16px;font-weight:600}

.tiles{display:grid;grid-template-columns:repeat(5,1fr);gap:10px}
.tile{border:1px solid var(--line);border-radius:10px;padding:10px;background:#fff}
.tile .k{color:var(--muted);font-size:12px}
.tile .v{font-weight:600}

.table{width:100%;border-collapse:collapse}
.table th,.table td{padding:8px;border-bottom:1px solid var(--line);text-align:left}
.table th{color:var(--muted);font-weight:600;background:#f9fafb}

.help{color:var(--muted);font-size:12px}
canvas.small{max-height:200px;width:100%}
canvas.medium{max-height:220px;width:100%}

@media print {
  .sidebar, .btn-row, .btn, .header a { display:none !important; }
  .wrap{grid-template-columns:1fr}
  .card{page-break-inside:avoid}
}
</style>
</head>
<body>
<div class="wrap">
  <aside class="sidebar">
    <div class="brand">PhishGuard</div>
    <a href="<?= htmlspecialchars($adminBase) ?>/index.php">Dashboard</a>
    <a href="<?= htmlspecialchars($adminBase) ?>/user.php" class="active">Users</a>
    <a href="<?= htmlspecialchars($adminBase) ?>/campaigns.php">Campaigns</a>
    <a href="<?= htmlspecialchars($adminBase) ?>/reports.php">Reports</a>
  </aside>

  <section>
    <div class="header">
      <div class="hero">
        <div class="avatar"><?= htmlspecialchars(strtoupper(substr($user['username']??'?',0,1))) ?></div>
        <div>
          <div style="font-weight:600">User · <?= htmlspecialchars($user['username']??'') ?></div>
          <div class="help"><?= htmlspecialchars($user['email']??'') ?></div>
        </div>
      </div>
      <div class="btn-row">
        <a class="btn" href="<?= htmlspecialchars($adminBase) ?>users.php">Back</a>
        <a class="btn" href="?uid=<?= (int)$uid ?>&export=csv">Export CSV</a>
        <button class="btn" onclick="window.print()">Print / Save as PDF</button>
      </div>
    </div>

    <div class="main">
      <!-- KPI row -->
      <div class="grid grid-4">
        <div class="card kpi"><div class="icon">👤</div><div><div class="label">Role</div><div class="val"><?= htmlspecialchars($user['role']??'user') ?></div></div></div>
        <div class="card kpi"><div class="icon">🏅</div><div><div class="label">Level</div><div class="val">L<?= (int)$levelInfo['level'] ?> · <?= htmlspecialchars($levelInfo['name']) ?></div></div></div>
        <div class="card kpi"><div class="icon">🕒</div><div><div class="label">Joined</div><div class="val"><?= htmlspecialchars($user['created_at']??'') ?></div></div></div>
        <div class="card kpi"><div class="icon">⭐</div><div><div class="label">Total XP</div><div class="val"><?= (int)$totalXP ?></div></div></div>

        <?php if ($tm['total'] > 0): ?>
        <div class="card kpi">
          <div class="icon">📚</div>
          <div>
            <div class="label">Training Modules</div>
            <div class="val"><?= $tm['done'] ?>/<?= $tm['total'] ?></div>
            <div class="help">
              Email <?= $tm['by_done']['email'] ?>/<?= $tm['by_total']['email'] ?> ·
              SMS <?= $tm['by_done']['sms'] ?>/<?= $tm['by_total']['sms'] ?> ·
              Web <?= $tm['by_done']['web'] ?>/<?= $tm['by_total']['web'] ?>
              <?= $tm['last'] ? ' · last '.$tm['last'] : '' ?>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <div class="grid grid-2" style="margin-top:12px">
        <div class="card">
          <div class="tiles">
            <div class="tile"><div class="k">Last seen</div><div class="v"><?= $lastSeen? htmlspecialchars($lastSeen) : '—' ?></div></div>
            <div class="tile"><div class="k">Overall Accuracy</div><div class="v"><?= $overallAttempts? ($overallAcc.'%'):'—' ?></div></div>
            <div class="tile"><div class="k">Avg Decision Time</div><div class="v"><?= $overallAvgSec!==null? fmt_secs($overallAvgSec):'—' ?></div></div>
            <div class="tile"><div class="k">Attempts</div><div class="v"><?= (int)$overallAttempts ?></div></div>
            <div class="tile"><div class="k">Correct</div><div class="v"><?= (int)$overallCorrect ?></div></div>
          </div>
          <div class="help" style="margin-top:8px">Accuracy = correct ÷ attempts × 100. Decision time = average seconds from open to submit.</div>
        </div>

        <div class="card">
          <h3>Overall Accuracy</h3>
          <canvas id="chOverall" class="small"></canvas>
          <div class="help" style="margin-top:6px"><?= (int)$overallAttempts ?> attempts · <?= (int)$overallCorrect ?> correct</div>
        </div>
      </div>

      <div class="grid grid-2" style="margin-top:12px">
        <div class="card">
          <h3>Learning Impact — Pre vs Post</h3>
          <canvas id="chPrePost" class="medium"></canvas>
          <div class="help" style="margin-top:6px">
            Latest attempts only. % = score ÷ total × 100.
            <?php if($improveAbs!==null): ?>
              Improvement: <b><?= ($improveAbs>=0?'+':'').number_format($improveAbs,1) ?> pts</b>
            <?php endif; ?>
          </div>
        </div>

        <div class="card">
          <h3>Assessment Attempts (latest)</h3>
          <table class="table">
            <thead><tr><th>Kind</th><th>Score</th><th>%</th><th>Date</th></tr></thead>
            <tbody>
              <?php if (!$attemptRows): ?>
                <tr><td colspan="4" class="help">No attempts found</td></tr>
              <?php else: foreach ($attemptRows as $r): ?>
                <tr><td><?= htmlspecialchars($r['kind']) ?></td><td><?= htmlspecialchars($r['score']) ?></td><td><?= htmlspecialchars($r['pct']) ?></td><td><?= htmlspecialchars($r['date']) ?></td></tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="grid grid-2" style="margin-top:12px">
        <div class="card">
          <h3>Accuracy by Channel</h3>
          <canvas id="chChannel" class="medium"></canvas>
          <div class="help" style="margin-top:6px">Computed from spotting sessions: Email / SMS & Social / Web.</div>
        </div>

        <div class="card">
          <h3>Decision Speed Buckets</h3>
          <canvas id="chSpeed" class="medium"></canvas>
          <div class="help" style="margin-top:6px">Fast &lt; 5s · Average 5–15s · Slow &gt; 15s.</div>
        </div>
      </div>

      <div class="card" style="margin-top:12px">
        <h3>How this profile evaluates the learner</h3>
        <ul class="help" style="margin:6px 0 0 18px;line-height:1.55">
          <li><b>Overall Accuracy</b>: headline skill indicator across all spotting sessions.</li>
          <li><b>Pre → Post</b>: learning impact; we expect the Post % to be higher. The delta (pts) is used in reporting.</li>
          <li><b>Accuracy by Channel</b>: where the user is strong/weak (Email vs SMS vs Web).</li>
          <li><b>Decision Speed</b>: behavioral metric; too slow ⇒ uncertainty, too fast with errors ⇒ guessing.</li>
        </ul>
      </div>
    </div>
  </section>
</div>

<script>
const DATA = <?= json_encode($js, JSON_UNESCAPED_SLASHES) ?>;
Chart.defaults.color = '#374151';
Chart.defaults.borderColor = '#e5e7eb';

// Overall accuracy donut
(() => {
  const acc = DATA.overallAcc||0;
  new Chart(document.getElementById('chOverall').getContext('2d'), {
    type:'doughnut',
    data:{labels:['Correct','Missed'],datasets:[{data:[acc, 100-acc], backgroundColor:['#10b981','rgba(148,163,184,.28)'], borderWidth:0}]},
    options:{responsive:true, maintainAspectRatio:false, cutout:'70%', plugins:{legend:{display:false}, tooltip:{callbacks:{label:(ctx)=> ctx.label+': '+ctx.parsed+'%'}}}}
  });
})();

// Pre vs Post bar
(() => {
  const pre = DATA.prePct||0, post = DATA.postPct||0;
  new Chart(document.getElementById('chPrePost').getContext('2d'),{
    type:'bar',
    data:{labels:['Pre','Post'],
      datasets:[{data:[pre,post], backgroundColor:['#7c4dff','#22d3ee'], borderWidth:0, borderRadius:8}]
    },
    options:{
      responsive:true, maintainAspectRatio:false,
      plugins:{legend:{display:false}},
      scales:{y:{beginAtZero:true, max:100, ticks:{callback:v=>v+'%'}}}
    }
  });
})();

// Channel accuracy bar (with "all zero" hint)
(() => {
  const labs = Object.keys(DATA.channelsAcc||{});
  const vals = labs.map(k=> DATA.channelsAcc[k]||0);
  const allZero = vals.every(v => v === 0);

  new Chart(document.getElementById('chChannel').getContext('2d'),{
    type:'bar',
    data:{labels:labs, datasets:[{data:vals, backgroundColor:'#3b82f6', borderWidth:0, borderRadius:6, maxBarThickness:44}]},
    options:{
      responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}},
      scales:{y:{beginAtZero:true, max:100, ticks:{callback:v=>v+'%'}}, x:{ticks:{font:{size:12}}, grid:{display:false}}}
    }
  });

  if (allZero) {
    document.querySelector('#chChannel').insertAdjacentHTML(
      'afterend',
      '<div class="help" style="margin-top:6px;color:#6b7280">No correct identifications yet for this user, so channel accuracy is 0% across Email, SMS &amp; Social, and Web.</div>'
    );
  }
})();

// Speed buckets
(() => {
  const b = DATA.speed || {fast:0,avg:0,slow:0};
  new Chart(document.getElementById('chSpeed').getContext('2d'),{
    type:'bar',
    data:{labels:['Fast (<5s)','Average (5–15s)','Slow (>15s)'],
      datasets:[{data:[b.fast||0,b.avg||0,b.slow||0], backgroundColor:['#10b981','#f59e0b','#ef4444'], borderWidth:0, borderRadius:8}]},
    options:{responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true, ticks:{precision:0}}}}
  });
})();
</script>
</body>
</html>
