<?php
// admin/reports.php — Reports (6-per-page recent & leaderboard, all-time drawer, responsive, drawer scroll FIX)
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
if (function_exists('admin_require_login')) admin_require_login();

$base = function_exists('admin_base') ? admin_base() : '/admin';

/* ---------------- helpers ---------------- */
function tbl_exists(PDO $pdo, string $t): bool {
  $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables
                    WHERE table_schema = DATABASE() AND table_name=?");
  $q->execute([$t]); return (int)$q->fetchColumn()>0;
}
function col_exists(PDO $pdo,string $t,string $c): bool {
  $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.columns
                    WHERE table_schema = DATABASE() AND table_name=? AND column_name=?");
  $q->execute([$t,$c]); return (int)$q->fetchColumn()>0;
}
function h($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
function pct($a,$b){ $a=(int)$a; $b=(int)$b; return $b>0? round($a*100/$b):0; }
function getv($k,$d=null){ return array_key_exists($k,$_GET)?$_GET[$k]:$d; }
function timeago(string $isoUtc): string {
  if (!$isoUtc) return '—';
  $ts = strtotime($isoUtc.' UTC');
  if ($ts===false) return '—';
  $diff = time() - $ts;
  if ($diff < 60) return $diff.'s ago';
  if ($diff < 3600) return floor($diff/60).'m ago';
  if ($diff < 86400) return floor($diff/3600).'h ago';
  return floor($diff/86400).'d ago';
}

/* ---------------- preflight ---------------- */
if (!tbl_exists($pdo,'spot_tasks') || !tbl_exists($pdo,'user_spot_sessions')) {
  http_response_code(200);
  echo "<h2 style='font-family:system-ui,sans-serif'>Reports</h2>";
  echo "<p>Required tables not found. Expected <code>spot_tasks</code> and <code>user_spot_sessions</code>.</p>";
  exit;
}

/* ---------------- filters (top of page) ---------------- */
$channels = ['all','email','sms','web'];
$truths   = ['all','phish','legit'];

$channel = in_array(getv('channel','all'), $channels, true) ? getv('channel','all') : 'all';
$truth   = in_array(getv('truth','all'),   $truths,   true) ? getv('truth','all')   : 'all';

$today = (new DateTimeImmutable('today', new DateTimeZone('UTC')));
$from  = getv('from', $today->modify('-29 days')->format('Y-m-d'));
$to    = getv('to',   $today->format('Y-m-d'));
try { new DateTimeImmutable($from); new DateTimeImmutable($to); }
catch(Exception $e){ $from=$today->modify('-29 days')->format('Y-m-d'); $to=$today->format('Y-m-d'); }

/* ---------------- WHERE (shared for top-of-page widgets) ---------------- */
$where = "s.submitted_at IS NOT NULL AND DATE(s.submitted_at) BETWEEN ? AND ?";
$bind  = [$from,$to];
if ($channel!=='all'){ $where.=" AND t.channel=?";   $bind[]=$channel; }
if ($truth!=='all'){   $where.=" AND t.is_phish=?";  $bind[] = ($truth==='phish'?1:0); }

/* ================== AJAX ENDPOINTS ================== */
/* recent list (search + pagination) */
if (getv('ajax')==='recent') {
  $q   = trim((string)(getv('q','')));
  $per = 6; // fixed 6 per page
  $page= max(1, (int)(getv('page',1)));
  $off = ($page-1)*$per;

  $where2 = $where;
  $bind2  = $bind;
  if ($q!==''){ $where2 .= " AND (t.title LIKE ?)"; $bind2[] = '%'.$q.'%'; }

  // total distinct tasks
  $sqlTot = "SELECT COUNT(*) FROM (
               SELECT t.id
               FROM user_spot_sessions s
               JOIN spot_tasks t ON t.id=s.task_id
               WHERE {$where2}
               GROUP BY t.id
             ) x";
  $st=$pdo->prepare($sqlTot); $st->execute($bind2);
  $total=(int)$st->fetchColumn();

  // page items
  $sql="SELECT t.id, t.title, t.channel,
               COUNT(*) reports, SUM(s.is_correct=1) correct,
               MAX(s.submitted_at) last_at
        FROM user_spot_sessions s
        JOIN spot_tasks t ON t.id=s.task_id
        WHERE {$where2}
        GROUP BY t.id
        ORDER BY last_at DESC
        LIMIT {$per} OFFSET {$off}";
  $st=$pdo->prepare($sql); $st->execute($bind2);
  $items=[];
  foreach($st as $r){
    $items[]=[
      'id'=>(int)$r['id'],
      'title'=>$r['title'] ?: ('[Task #'.$r['id'].']'),
      'type'=>strtoupper($r['channel']),
      'reports'=>(int)$r['reports'],
      'pct'=>pct((int)$r['correct'], (int)$r['reports']),
      'ago'=>timeago((string)$r['last_at'])
    ];
  }
  header('Content-Type: application/json');
  echo json_encode(['ok'=>true,'total'=>$total,'page'=>$page,'per'=>$per,'items'=>$items], JSON_UNESCAPED_UNICODE);
  exit;
}

/* item details drawer — ALL-TIME for that task (no page filters) */
if (getv('ajax')==='item') {
  $id = (int)getv('id',0);
  if ($id<=0){ http_response_code(400); echo json_encode(['ok'=>false,'msg'=>'Bad id']); exit; }

  // meta (all-time)
  $sql="SELECT t.id,t.title,t.channel,t.is_phish,
               COUNT(*) attempts, SUM(s.is_correct=1) correct,
               MAX(s.submitted_at) last_at, MIN(s.submitted_at) first_at
        FROM user_spot_sessions s
        JOIN spot_tasks t ON t.id=s.task_id
        WHERE t.id=?
        GROUP BY t.id,t.title,t.channel,t.is_phish";
  $st=$pdo->prepare($sql); $st->execute([$id]);
  $meta=$st->fetch(PDO::FETCH_ASSOC);
  if(!$meta){ header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'Not found']); exit; }

  // choices breakdown (all-time, only real answers)
$sql = "SELECT LOWER(TRIM(s.choice)) AS choice,
               COUNT(*) n,
               SUM(s.is_correct=1) c
        FROM user_spot_sessions s
        WHERE s.task_id=? 
          AND LOWER(TRIM(s.choice)) IN ('phish','legit')
        GROUP BY LOWER(TRIM(s.choice))
        ORDER BY n DESC
        LIMIT 50";
$st = $pdo->prepare($sql);
$st->execute([$id]);
$choices = [];
foreach ($st as $r) {
  $choices[] = ['choice'=>$r['choice'],'n'=>(int)$r['n'],'c'=>(int)$r['c']];
}


  // per-day trend (all-time)
  $sql="SELECT DATE(s.submitted_at) d, COUNT(*) a, SUM(s.is_correct=1) c
        FROM user_spot_sessions s
        WHERE s.task_id=?
        GROUP BY DATE(s.submitted_at)
        ORDER BY DATE(s.submitted_at)";
  $st=$pdo->prepare($sql); $st->execute([$id]);
  $trend=[]; foreach($st as $r){ $trend[]=['d'=>$r['d'],'a'=>(int)$r['a'],'c'=>(int)$r['c']]; }

  // last 10 sessions (all-time)
  $hasUsers = tbl_exists($pdo,'users');
  $sql="SELECT s.id sid, s.submitted_at, s.is_correct, s.points_awarded".($hasUsers? ", u.username":"")."
        FROM user_spot_sessions s
        ".($hasUsers? "LEFT JOIN users u ON u.id=s.user_id":"")."
        WHERE s.task_id=?
        ORDER BY s.submitted_at DESC
        LIMIT 10";
  $st=$pdo->prepare($sql); $st->execute([$id]);
  $recent=[]; foreach($st as $r){
    $recent[]=[
      'sid'=>(int)$r['sid'],
      'at'=>$r['submitted_at'],
      'ago'=>timeago((string)$r['submitted_at']),
      'correct'=> (int)$r['is_correct']===1,
      'points'=> (int)$r['points_awarded'],
      'user'=> $hasUsers ? (string)($r['username']??'user') : 'user'
    ];
  }

  header('Content-Type: application/json');
  echo json_encode([
    'ok'=>true,
    'meta'=>[
      'id'=>(int)$meta['id'],
      'title'=>$meta['title'],
      'channel'=>strtoupper((string)$meta['channel']),
      'truth'=>((int)$meta['is_phish']===1?'PHISH':'LEGIT'),
      'attempts'=>(int)$meta['attempts'],
      'correct'=>(int)$meta['correct'],
      'pct'=>pct((int)$meta['correct'], (int)$meta['attempts']),
      'last'=> (string)$meta['last_at'],
      'first'=> (string)$meta['first_at'],
      'ago'=>timeago((string)$meta['last_at'])
    ],
    'choices'=>$choices,
    'trend'=>$trend,
    'recent'=>$recent
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ---------------- CSV Export ---------------- */
if (getv('export')==='csv') {
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename=phishguard_reports_'.date('Ymd_His').'.csv');
  $out=fopen('php://output','w');
  fputcsv($out, ['session_id','date','user_id','task_id','title','channel','truth','choice','is_correct','points']);
  $sql="SELECT s.id sid, s.submitted_at, s.user_id, t.id task_id, t.title, t.channel,
               t.is_phish, s.choice, s.is_correct, s.points_awarded
        FROM user_spot_sessions s
        JOIN spot_tasks t ON t.id = s.task_id
        WHERE {$where}
        ORDER BY s.submitted_at DESC
        LIMIT 50000";
  $st=$pdo->prepare($sql); $st->execute($bind);
  while($r=$st->fetch(PDO::FETCH_ASSOC)){
    fputcsv($out, [
      $r['sid'],$r['submitted_at'],$r['user_id'],$r['task_id'],$r['title'],$r['channel'],
      ((int)$r['is_phish']===1?'phish':'legit'),$r['choice'],(int)$r['is_correct'],$r['points_awarded']
    ]);
  }
  fclose($out); exit;
}

/* ---------------- KPIs ---------------- */
$kpi=['attempts'=>0,'correct'=>0,'users'=>0,'assess_pre'=>0,'assess_post'=>0];

$st=$pdo->prepare("SELECT COUNT(*) a, SUM(s.is_correct=1) c
                   FROM user_spot_sessions s
                   JOIN spot_tasks t ON t.id=s.task_id
                   WHERE {$where}");
$st->execute($bind);
if($r=$st->fetch(PDO::FETCH_ASSOC)){ $kpi['attempts']=(int)$r['a']; $kpi['correct']=(int)$r['c']; }

$st=$pdo->prepare("SELECT COUNT(DISTINCT s.user_id)
                   FROM user_spot_sessions s
                   JOIN spot_tasks t ON t.id=s.task_id
                   WHERE {$where}");
$st->execute($bind); $kpi['users']=(int)$st->fetchColumn();

if (tbl_exists($pdo,'test_attempts') && col_exists($pdo,'test_attempts','kind') && col_exists($pdo,'test_attempts','submitted_at')){
  $st=$pdo->prepare("SELECT kind, COUNT(*) n
                     FROM test_attempts
                     WHERE DATE(submitted_at) BETWEEN ? AND ?
                     GROUP BY kind");
  $st->execute([$from,$to]);
  foreach($st as $r){
    $k = strtolower((string)$r['kind']);
    if ($k==='pre')  $kpi['assess_pre']  = (int)$r['n'];
    if ($k==='post') $kpi['assess_post'] = (int)$r['n'];
  }
}

/* ---------------- Activity trend (overall, filtered) ---------------- */
$trend=[];
$st=$pdo->prepare("SELECT DATE(s.submitted_at) d, COUNT(*) a, SUM(s.is_correct=1) c
                   FROM user_spot_sessions s
                   JOIN spot_tasks t ON t.id=s.task_id
                   WHERE {$where}
                   GROUP BY DATE(s.submitted_at)
                   ORDER BY DATE(s.submitted_at)");
$st->execute($bind);
foreach($st as $row){ $trend[]=['d'=>$row['d'],'a'=>(int)$row['a'],'c'=>(int)$row['c']]; }

/* ---------------- Channel accuracy (overall, filtered) ---------------- */
$chan=['email'=>['a'=>0,'c'=>0],'sms'=>['a'=>0,'c'=>0],'web'=>['a'=>0,'c'=>0]];
$st=$pdo->prepare("SELECT t.channel ch, COUNT(*) a, SUM(s.is_correct=1) c
                   FROM user_spot_sessions s
                   JOIN spot_tasks t ON t.id=s.task_id
                   WHERE {$where}
                   GROUP BY t.channel");
$st->execute($bind);
foreach($st as $r){ $ch=$r['ch']; if(isset($chan[$ch])){ $chan[$ch]['a']=(int)$r['a']; $chan[$ch]['c']=(int)$r['c']; }}

/* ---------------- Initial recent seed (first page, 6 items) ---------------- */
$recent=[];
$st=$pdo->prepare("SELECT t.id, t.title, t.channel,
                          COUNT(*) reports, SUM(s.is_correct=1) correct,
                          MAX(s.submitted_at) last_at
                   FROM user_spot_sessions s
                   JOIN spot_tasks t ON t.id=s.task_id
                   WHERE {$where}
                   GROUP BY t.id
                   ORDER BY last_at DESC
                   LIMIT 6");
$st->execute($bind);
foreach($st as $r){
  $recent[]=[
    'id'=>(int)$r['id'],
    'title'=>$r['title'] ?: ('[Task #'.$r['id'].']'),
    'type'=>strtoupper($r['channel']),
    'reports'=>(int)$r['reports'],
    'pct'=>pct((int)$r['correct'], (int)$r['reports']),
    'ago'=>timeago((string)$r['last_at'])
  ];
}

/* ---------------- Leaderboard (full list, client 6/page) ---------------- */
$board=[];
if (tbl_exists($pdo,'users')){
  $sql="SELECT u.id, u.username, COUNT(*) a, SUM(s.is_correct=1) c
        FROM user_spot_sessions s
        JOIN spot_tasks t ON t.id=s.task_id
        JOIN users u ON u.id=s.user_id
        WHERE {$where}
        GROUP BY u.id, u.username
        HAVING a>=1
        ORDER BY (SUM(s.is_correct=1)/COUNT(*)) DESC, COUNT(*) DESC, u.username ASC
        LIMIT 300";
  $st=$pdo->prepare($sql); $st->execute($bind);
  foreach($st as $r){
    $a=(int)$r['a']; $c=(int)$r['c'];
    $board[]=['name'=>$r['username'],'a'=>$a,'c'=>$c,'pct'=>($a>0? round(100*$c/$a) : 0)];
  }
}

$ADMIN = htmlspecialchars($_SESSION['admin']['username'] ?? 'admin', ENT_QUOTES,'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>PhishGuard · Reports</title>
<link rel="icon" type="image/svg+xml"
        href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='12' fill='%236d28d9'/%3E%3Cpath d='M18 38l14-20 14 20-14 8z' fill='white'/%3E%3C/svg%3E">

<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{
    --bg:#f6f7fb; --text:#111827; --muted:#6b7280;
    --card:#ffffff; --line:#e5e7eb; --brand:#3b82f6; --brand-2:#0ea5e9;
    --good:#10b981; --warn:#f59e0b; --bad:#ef4444; --chip:#f1f5f9;
  }
  *{box-sizing:border-box}
  html, body { overflow-x:hidden; height:100%; }
  body{margin:0;background:var(--bg);color:var(--text);font:14px/1.45 system-ui,Segoe UI,Roboto,Arial}
  a{color:inherit;text-decoration:none}
  .app{display:grid;grid-template-columns:240px 1fr;min-height:100vh}
  .side{background:#0f172a;color:#e2e8f0;display:flex;flex-direction:column}
  .brand{display:flex;align-items:center;gap:12px;padding:16px 18px;border-bottom:1px solid rgba(255,255,255,.08);font-weight:600}
  .brand .logo{width:28px;height:28px;border-radius:8px;background:linear-gradient(135deg,#2563eb,#06b6d4)}
  .nav{padding:10px}
  .nav a{display:block;padding:10px 12px;border-radius:8px;color:#e2e8f0;margin:4px 0}
  .nav a.active, .nav a:hover{background:rgba(255,255,255,.08)}
  header{display:flex;align-items:center;gap:12px;padding:16px 20px;border-bottom:1px solid var(--line);background:var(--card)}
  header .spacer{flex:1}
  .chip{background:var(--chip);border:1px solid var(--line);padding:6px 10px;border-radius:999px;white-space:nowrap}
  header form, header a.btn{white-space:nowrap}
  main{padding:20px;display:grid;gap:16px;min-width:0}
  .card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:14px;overflow:hidden}
  .cards{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:16px}
  .muted{color:var(--muted)}
  .btn{padding:8px 12px;border:1px solid var(--line);border-radius:8px;background:#fff;cursor:pointer}
  .btn.primary{background:var(--brand);border-color:var(--brand);color:#fff}
  .btn:disabled{opacity:.5;cursor:not-allowed}
  .grid2{display:grid;grid-template-columns:2fr 1fr;gap:16px}
  .row{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--line)}
  .dot{width:36px;height:36px;border-radius:10px;display:grid;place-items:center;background:#eef2f7;font-size:18px}
  .pill{padding:2px 10px;border-radius:999px;font-size:12px;border:1px solid var(--line);background:#fff;white-space:nowrap}
  .pill.ok{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
  .pill.warn{background:#fffbeb;border-color:#fde68a;color:#92400e}
  .pill.bad{background:#fef2f2;border-color:#fecaca;color:#991b1b}
  .kpi .val{font-size:22px;font-weight:800}
  .field{display:flex;flex-direction:column;gap:6px}
  .field input,.field select{padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:#fff}
  table{width:100%;border-collapse:collapse}
  th,td{padding:8px 6px;border-bottom:1px solid var(--line);text-align:left}
  thead th{font-weight:600}
  .h3{font-weight:700;margin:0 0 8px}

  /* recent controls */
  .recentbar{display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap}
  .recentbar input[type="search"]{flex:1 1 240px;min-width:200px;padding:8px 10px;border:1px solid var(--line);border-radius:8px}

  /* Drawer */
  .drawer{position:fixed;inset:0;display:none;z-index:1000}
  .drawer.show{display:block}
  .drawer .mask{position:fixed;inset:0;background:rgba(0,0,0,.42)}
  .drawer .panel{
    position:fixed;top:0;right:0;height:100vh;
    width:clamp(360px,46vw,600px);
    max-width:100vw;background:#fff;border-left:1px solid var(--line);
    display:flex;flex-direction:column;box-shadow:-24px 0 48px rgba(0,0,0,.22);
    overscroll-behavior:contain;
  }
  .drawer header{display:flex;align-items:center;gap:12px;padding:14px 16px;border-bottom:1px solid var(--line);flex:0 0 auto}

  /* ⭐ Fixed: make the drawer body a scrollable flex column */
  .drawer .content{
    flex:1 1 auto;
    display:flex;
    flex-direction:column;
    gap:12px;
    padding:14px 16px;
    overflow-y:auto; overflow-x:hidden;
    min-height:0;          /* prevents flex overflow bug */
    -webkit-overflow-scrolling:touch;
    touch-action:pan-y;
  }
  .drawer .content > .card{flex:0 0 auto;}

  /* Tables scroll inside their card (no overlap with chart below) */
  .table-wrap{max-height:240px;overflow:auto;-webkit-overflow-scrolling:touch}

  /* Chart sizes (slightly smaller so bottom sections stay visible) */
  .chart-box{position:relative;width:100%;height:320px}
  .chart-box-sm{position:relative;width:100%;height:200px}
  canvas{max-width:100% !important;display:block}

  /* prevent background scroll when drawer open */
  body.modal-open{overflow:hidden}

  /* responsive breakpoints */
  @media (max-width:1400px){ .cards{grid-template-columns:repeat(4,minmax(0,1fr));} }
  @media (max-width:1200px){ .cards{grid-template-columns:repeat(3,minmax(0,1fr));} .grid2{grid-template-columns:1fr;} }
  @media (max-width:900px){ .cards{grid-template-columns:repeat(2,minmax(0,1fr));} }
  @media (max-width:720px){
    .drawer .panel{width:100%}
  }
  @media (max-width:600px){ .cards{grid-template-columns:1fr;} }
  /* Drawer top metrics — compact, no giant empty space */
.kv{
  display:grid;
  gap:8px;
  align-items:start;
  grid-auto-rows:minmax(0,auto);
}
/* 3-up on wide drawers, 2-up otherwise */
@media (min-width:1024px){ .kv{ grid-template-columns:repeat(3,minmax(0,1fr)); } }
@media (max-width:1023.98px){ .kv{ grid-template-columns:repeat(2,minmax(0,1fr)); } }

/* Make each metric tile tight */
.kv .card{
  padding:8px 10px;          /* smaller padding */
  border-radius:10px;
  min-height:auto;           /* no forced height */
  display:grid;              /* label + value stack */
  grid-template-rows:auto auto;
  row-gap:4px;
}

/* Smaller label, crisp value */
.kv .card .muted{ font-size:12px; line-height:1; color:var(--muted); }
.kv .card .val{ font-weight:700; font-size:16px; line-height:1.2; }
/* drawer top metric values */
.kv .card .val{font-size:16px;line-height:1.2;}
.kv .card .val.strong{font-weight:700;}
.kv .card .val.dim{font-weight:400;color:var(--muted);} /* <- dim, not bold */



</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

</head>
<body>
<div class="app">
  <aside class="side">
    <div class="brand"><div class="logo"></div> PhishGuard</div>
    <nav class="nav">
      <a href="<?= h($base) ?>/index.php">Dashboard</a>
      <a href="<?= h($base) ?>/user.php">Users</a>
      <a href="<?= h($base) ?>/campaigns.php">Modules</a>
      <a href="<?= h($base) ?>/reports.php" class="active">Reports</a>
      <a href="<?= h($base) ?>/paired.php">Paired Evaluation</a>
    </nav>
  </aside>

  <section>
    <header>
      <h2 style="margin:0">Reports</h2>
      <div class="spacer"></div>
      <div class="chip">Signed in as <?= $ADMIN ?></div>

      <!-- Export keeps current filters -->
      <form method="get" style="margin:0">
        <input type="hidden" name="from" value="<?= h($from) ?>">
        <input type="hidden" name="to" value="<?= h($to) ?>">
        <input type="hidden" name="channel" value="<?= h($channel) ?>">
        <input type="hidden" name="truth" value="<?= h($truth) ?>">
        <input type="hidden" name="export" value="csv">
        <button class="btn">Export CSV</button>
      </form>

      <a class="btn" href="<?= h($base) ?>/logout.php">Logout</a>
    </header>

    <main>
      <!-- Filters -->
      <div class="card">
        <form method="get" style="display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;align-items:end">
          <div class="field">
            <label class="muted">From</label>
            <input type="date" name="from" value="<?= h($from) ?>">
          </div>
          <div class="field">
            <label class="muted">To</label>
            <input type="date" name="to" value="<?= h($to) ?>">
          </div>
          <div class="field">
            <label class="muted">Channel</label>
            <select name="channel">
              <?php foreach($channels as $c): ?>
                <option value="<?= h($c) ?>" <?= $c===$channel?'selected':''?>><?= strtoupper($c) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label class="muted">Truth</label>
            <select name="truth">
              <option value="all"   <?= $truth==='all'?'selected':''?>>All</option>
              <option value="phish" <?= $truth==='phish'?'selected':''?>>Phish</option>
              <option value="legit" <?= $truth==='legit'?'selected':''?>>Legit</option>
            </select>
          </div>
          <div class="field">
            <label class="muted">&nbsp;</label>
            <button class="btn primary" style="width:100%">Apply</button>
          </div>
        </form>
        <div style="margin-top:8px" class="muted">
          Showing data for <b><?= h($channel) ?></b>, truth <b><?= h($truth) ?></b>, from <b><?= h($from) ?></b> to <b><?= h($to) ?></b>.
        </div>
      </div>

      <!-- KPIs -->
      <div class="cards">
        <div class="card kpi"><div class="muted">Attempts</div><div class="val"><?= (int)$kpi['attempts'] ?></div></div>
        <div class="card kpi"><div class="muted">Overall Accuracy</div><div class="val"><?= pct($kpi['correct'],$kpi['attempts']) ?>%</div></div>
        <div class="card kpi"><div class="muted">Participants</div><div class="val"><?= (int)$kpi['users'] ?></div></div>
        <div class="card kpi"><div class="muted">Avg Attempts / User</div><div class="val"><?= ($kpi['users']? round($kpi['attempts']/$kpi['users'],1):0) ?></div></div>
        <div class="card kpi"><div class="muted">Assessments (Pre/Post)</div><div class="val"><?= (int)$kpi['assess_pre'] ?>/<?= (int)$kpi['assess_post'] ?></div></div>
      </div>

      <!-- Trend + Channel accuracy -->
      <div class="grid2">
        <div class="card">
          <div class="h3">Activity — attempts &amp; correct</div>
          <div class="chart-box"><canvas id="chTrend"></canvas></div>
        </div>
        <div class="card">
          <div class="h3">Channel accuracy</div>
          <div class="chart-box"><canvas id="chChan"></canvas></div>
        </div>
      </div>

      <!-- Recent + Leaderboard (both have 6/page with Prev/Next) -->
      <div class="grid2">
        <div class="card">
          <div class="h3">Recent Phishing Reports</div>
          <div class="recentbar">
            <input id="recentQ" type="search" placeholder="Search title…" />
            <div class="spacer"></div>
            <div class="muted" id="recentMeta">Page 1</div>
            <button id="btnPrev" class="btn">Prev</button>
            <button id="btnNext" class="btn">Next</button>
          </div>
          <div id="recentBox" class="muted">Loading…</div>
        </div>

        <div class="card">
          <div style="display:flex;align-items:center;gap:8px;justify-content:space-between">
            <div class="h3">Leaderboard (User Accuracy)</div>
            <div class="muted" id="lbMeta">Page 1</div>
          </div>
          <table>
            <thead class="muted">
              <tr><th style="width:56px">Rank</th><th>User</th><th>Attempts</th><th>Correct</th><th>Accuracy</th></tr>
            </thead>
            <tbody id="tblBoard"></tbody>
          </table>
          <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:8px">
            <button id="lbPrev" class="btn">Prev</button>
            <button id="lbNext" class="btn">Next</button>
          </div>
        </div>
      </div>
    </main>
  </section>
</div>

<!-- Drawer -->
<div class="drawer" id="drawer">
  <div class="mask" id="drawerMask"></div>
  <div class="panel">
    <header>
      <button class="btn" id="drawerClose">Close</button>
      <div class="spacer"></div>
      <div id="drawerHeader" class="muted">Loading…</div>
    </header>
    <div class="content" id="drawerContent">
      <div id="drawerTop" class="kv"></div>

      <div class="card">
        <div class="h3">Choices breakdown</div>
        <div class="table-wrap">
          <table id="drawerChoices">
            <thead class="muted"><tr><th>Choice</th><th>Count</th><th>Correct</th><th>% Correct</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <div class="h3">Daily trend</div>
        <div class="chart-box-sm"><canvas id="drawerTrend"></canvas></div>
      </div>

      <div class="card">
        <div class="h3">Last 10 submissions</div>
        <div class="table-wrap">
          <table id="drawerRecent">
            <thead class="muted"><tr><th>User</th><th>When</th><th>Result</th><th>Points</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
// ---------- Helpers ----------
function escapeHtml(s){ return (s??'').toString().replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m])); }
function pillClass(p){ return p>=70?'ok':(p>=50?'warn':'bad'); }
function iconFor(type){ return type==='SMS'?'💬':(type==='WEB'?'🌐':'📨'); }

// Seed data from PHP
const DATA = {
  trend: <?= json_encode($trend) ?>,
  chan : <?= json_encode($chan) ?>,
  recent: <?= json_encode($recent) ?>,
  board: <?= json_encode($board) ?>
};

// ------- Overall Trend chart
(() => {
  const labels   = (DATA.trend||[]).map(r=>r.d);
  const attempts = (DATA.trend||[]).map(r=>r.a);
  const correct  = (DATA.trend||[]).map(r=>r.c);
  new Chart(document.getElementById('chTrend'),{
    type:'line',
    data:{labels,datasets:[
      {label:'Started', data:attempts, borderColor:'#3b82f6', backgroundColor:'rgba(59,130,246,.18)', tension:.3, fill:true, spanGaps:true, pointRadius:2},
      {label:'Correct', data:correct,  borderColor:'#10b981', backgroundColor:'rgba(16,185,129,.18)', tension:.3, fill:true, spanGaps:true, pointRadius:2}
    ]},
    options:{maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}, scales:{y:{beginAtZero:true,ticks:{precision:0}}}}
  });
})();

// ------- Channel accuracy
(() => {
  const labels=['EMAIL','SMS','WEB'], keys=['email','sms','web'];
  const attempts=keys.map(k=>Number((DATA.chan?.[k]?.a)||0));
  const corrects=keys.map(k=>Number((DATA.chan?.[k]?.c)||0));
  const values=keys.map((k,i)=>attempts[i]?Math.round(100*corrects[i]/attempts[i]):0);
  const TARGET=70;

  const valueLabels={id:'valueLabels',afterDatasetsDraw(c){const {ctx}=c, m=c.getDatasetMeta(0);ctx.save();ctx.font='600 12px system-ui';ctx.textAlign='center';ctx.textBaseline='bottom';ctx.fillStyle='#111827';m.data.forEach((b,i)=>ctx.fillText(c.data.datasets[0].data[i]+'%',b.x,b.y-6));ctx.restore();}};
  const targetLine={id:'targetLine',afterDraw(c){const {ctx,chartArea,scales}=c;if(!chartArea)return;const y=scales.y.getPixelForValue(TARGET);ctx.save();ctx.strokeStyle='rgba(16,185,129,.6)';ctx.setLineDash([4,4]);ctx.beginPath();ctx.moveTo(chartArea.left,y);ctx.lineTo(chartArea.right,y);ctx.stroke();ctx.setLineDash([]);ctx.font='600 11px system-ui';ctx.fillStyle='rgba(16,185,129,.9)';ctx.fillText(`Target ${TARGET}%`,chartArea.right-60,y-6);ctx.restore();}};
  new Chart(document.getElementById('chChan'),{
    type:'bar',
    data:{labels,datasets:[{label:'Accuracy %',data:values,backgroundColor:'#3b82f6',borderRadius:10,barThickness:26,maxBarThickness:28}]},
    options:{
      maintainAspectRatio:false,
      plugins:{legend:{display:false},tooltip:{callbacks:{label:(ctx)=>` ${values[ctx.dataIndex]}%  •  ${corrects[ctx.dataIndex]}/${attempts[ctx.dataIndex]} correct`}}},
      scales:{x:{grid:{display:false},ticks:{font:{weight:600}}},y:{beginAtZero:true,max:100,ticks:{callback:v=>v+'%'}}}
    },
    plugins:[valueLabels,targetLine]
  });
})();

/* ---------- Leaderboard (6/page) ---------- */
const BOARD = DATA.board||[];
const lb = { page:1, per:6, total:BOARD.length,
  tb:document.getElementById('tblBoard'),
  meta:document.getElementById('lbMeta'),
  prev:document.getElementById('lbPrev'),
  next:document.getElementById('lbNext')
};
function renderLB(){
  const start=(lb.page-1)*lb.per, end=Math.min(lb.total,start+lb.per);
  const slice=BOARD.slice(start,end);
  lb.tb.innerHTML='';
  slice.forEach((r,i)=>{
    const rankGlobal=start+i+1;
    const rank = rankGlobal===1?'🥇 1':rankGlobal===2?'🥈 2':rankGlobal===3?'🥉 3':String(rankGlobal);
    const tr=document.createElement('tr');
    tr.innerHTML=`<td>${rank}</td><td>${escapeHtml(r.name||'—')}</td><td>${r.a}</td><td>${r.c}</td><td><span class="pill ${pillClass(r.pct)}">${r.pct}%</span></td>`;
    lb.tb.appendChild(tr);
  });
  lb.meta.textContent = lb.total ? `Page ${lb.page} · ${start+1}-${end}/${lb.total}` : 'Page 1';
  lb.prev.disabled = lb.page<=1;
  lb.next.disabled = end>=lb.total;
}
lb.prev.addEventListener('click',()=>{ if(lb.page>1){ lb.page--; renderLB(); }});
lb.next.addEventListener('click',()=>{ if(lb.page*lb.per<lb.total){ lb.page++; renderLB(); }});
renderLB();

/* ---------- Recent (6/page, search) ---------- */
const recentState = { page:1, per:6, q:'', total:0, items: DATA.recent || [] };
const recentBox  = document.getElementById('recentBox');
const recentQ    = document.getElementById('recentQ');
const btnPrev    = document.getElementById('btnPrev');
const btnNext    = document.getElementById('btnNext');
const recentMeta = document.getElementById('recentMeta');

function rowHTML(r){
  return `
    <div class="row" style="cursor:pointer">
      <div class="dot">${iconFor(r.type)}</div>
      <div style="flex:1">
        <div style="font-weight:600">${escapeHtml(r.title)}</div>
        <div class="muted" style="font-size:13px">${escapeHtml(r.type)} · ${r.reports} reports</div>
      </div>
      <span class="pill ${pillClass(r.pct)}">${r.pct}% detected</span>
      <span class="muted" style="min-width:56px;text-align:right">${escapeHtml(r.ago)}</span>
    </div>`;
}
function bindRowClicks(container, items){
  [...container.querySelectorAll('.row')].forEach((el,i)=> el.addEventListener('click', ()=> openDrawer(items[i].id)));
}
function renderRecent(){
  recentBox.innerHTML = (recentState.items||[]).map(rowHTML).join('') || 'No data';
  bindRowClicks(recentBox, recentState.items);
  const start = (recentState.total? ((recentState.page-1)*recentState.per)+1 : 0);
  const end   = Math.min(recentState.total, recentState.page*recentState.per);
  recentMeta.textContent = recentState.total ? `Page ${recentState.page} · ${start}-${end}/${recentState.total}` : `Page ${recentState.page}`;
  btnPrev.disabled = recentState.page<=1;
  btnNext.disabled = (recentState.page*recentState.per)>=recentState.total;
}
async function fetchRecent(){
  const params = new URLSearchParams({
    ajax:'recent',
    from:'<?= h($from) ?>',
    to:'<?= h($to) ?>',
    channel:'<?= h($channel) ?>',
    truth:'<?= h($truth) ?>',
    page: String(recentState.page),
    q: recentState.q
  });
  recentBox.textContent='Loading…';
  const res=await fetch('reports.php?'+params.toString(),{credentials:'same-origin'});
  const j=await res.json();
  if(j?.ok){ recentState.items=j.items; recentState.total=j.total; renderRecent(); }
  else { recentBox.textContent='Failed to load'; }
}
let qTimer=null;
recentQ.addEventListener('input', ()=>{ clearTimeout(qTimer); qTimer=setTimeout(()=>{ recentState.q=recentQ.value.trim(); recentState.page=1; fetchRecent(); }, 300); });
btnPrev.addEventListener('click', ()=>{ if(recentState.page>1){ recentState.page--; fetchRecent(); }});
btnNext.addEventListener('click', ()=>{ recentState.page++; fetchRecent(); });
renderRecent(); // seed
fetchRecent();  // sync totals

/* ---------- Drawer logic (all-time, scrollable) ---------- */
const drawer = document.getElementById('drawer');
const drawerClose = document.getElementById('drawerClose');
const drawerMask = document.getElementById('drawerMask');
const drawerHeader = document.getElementById('drawerHeader');
const drawerTop = document.getElementById('drawerTop');
const drawerChoices = document.getElementById('drawerChoices').querySelector('tbody');
const drawerRecent = document.getElementById('drawerRecent').querySelector('tbody');
let drawerChart=null;

// open/close helpers (also prevent background scroll)
function openOverlay(){ document.body.classList.add('modal-open'); drawer.classList.add('show'); }
function closeOverlay(){ drawer.classList.remove('show'); document.body.classList.remove('modal-open'); }

drawerClose.addEventListener('click', closeOverlay);
drawerMask.addEventListener('click', closeOverlay);
window.addEventListener('keydown', (e)=>{ if(e.key==='Escape' && drawer.classList.contains('show')) closeOverlay(); });

function prettifyChoice(v){ return (v==='(none)' || v==='') ? 'No selection' : escapeHtml(v); }

async function openDrawer(id){
  openOverlay();
  drawerHeader.textContent = 'Loading…';
  drawerTop.innerHTML = '';
  drawerChoices.innerHTML = '';
  drawerRecent.innerHTML = '';
  if(drawerChart){ drawerChart.destroy(); drawerChart=null; }
  const params = new URLSearchParams({ ajax:'item', id:String(id) });
  const res=await fetch('reports.php?'+params.toString(),{credentials:'same-origin'});
  const j=await res.json();
  if(!j?.ok){ drawerHeader.textContent='Not found'; return; }

  const m=j.meta;
  const pill = (v)=>`<span class="pill">${escapeHtml(v)}</span>`;
  drawerHeader.innerHTML = `<b>${escapeHtml(m.title||('[Task #'+m.id+']'))}</b> &nbsp; ${pill(m.channel)} ${pill(m.truth)} <span class="pill ${pillClass(m.pct)}">${m.pct}% detected</span> <span class="muted">· ${escapeHtml(m.ago)}</span>`;

  drawerTop.innerHTML = `
  <div class="card"><div class="muted">Attempts</div><div class="val strong">${m.attempts}</div></div>
  <div class="card"><div class="muted">Correct</div><div class="val strong">${m.correct}</div></div>
  <div class="card"><div class="muted">Accuracy</div><div class="val strong">${m.pct}%</div></div>
  <div class="card"><div class="muted">First seen</div><div class="val dim">${escapeHtml(m.first||'—')}</div></div>
  <div class="card"><div class="muted">Last seen</div><div class="val dim">${escapeHtml(m.last||'—')}</div></div>
`;


  // choices (only real stored values; nothing auto-inserted)
  (j.choices||[]).forEach(row=>{
    const p = row.n? Math.round(100*(row.c/row.n)) : 0;
    const tr=document.createElement('tr');
    tr.innerHTML = `<td>${prettifyChoice(row.choice)}</td><td>${row.n}</td><td>${row.c}</td><td>${p}%</td>`;
    drawerChoices.appendChild(tr);
  });
  if (!(j.choices||[]).length){
    const tr=document.createElement('tr');
    tr.innerHTML = `<td colspan="4" class="muted">No responses yet</td>`;
    drawerChoices.appendChild(tr);
  }

  // trend
  const labs=(j.trend||[]).map(r=>r.d);
  const attempts=(j.trend||[]).map(r=>r.a);
  const correct=(j.trend||[]).map(r=>r.c);
  drawerChart=new Chart(document.getElementById('drawerTrend'),{
    type:'line',
    data:{labels:labs,datasets:[
      {label:'Attempts',data:attempts,borderColor:'#3b82f6',backgroundColor:'rgba(59,130,246,.18)', tension:.3, fill:true, spanGaps:true, pointRadius:2},
      {label:'Correct',data:correct,borderColor:'#10b981',backgroundColor:'rgba(16,185,129,.18)', tension:.3, fill:true, spanGaps:true, pointRadius:2}
    ]},
    options:{maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}, scales:{y:{beginAtZero:true,ticks:{precision:0}}}}
  });

  // last 10
  (j.recent||[]).forEach(r=>{
    const tr=document.createElement('tr');
    tr.innerHTML = `<td>${escapeHtml(r.user)}</td>
                    <td>${escapeHtml(r.ago)}</td>
                    <td>${r.correct?'<span class="pill ok">Correct</span>':'<span class="pill bad">Wrong</span>'}</td>
                    <td>${r.points}</td>`;
    drawerRecent.appendChild(tr);
  });
  if (!(j.recent||[]).length){
    const tr=document.createElement('tr');
    tr.innerHTML = `<td colspan="4" class="muted">No recent submissions</td>`;
    drawerRecent.appendChild(tr);
  }
}

// Auto-open drawer when navigated with ?open=ID or #open=ID
(function(){
  try {
    const url = new URL(window.location.href);
    const id = url.searchParams.get('open') || (location.hash && location.hash.indexOf('#open=')===0 ? location.hash.substring(6) : null);
    if (id) { setTimeout(()=> openDrawer(id), 0); }
  } catch(e){}
})();
</script>
</body>
</html>
