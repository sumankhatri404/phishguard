<?php
// /leaderboard.php — Cooler DB-driven leaderboard (XP, Level, Badges)
declare(strict_types=1);

@session_start();
require_once __DIR__ . '/inc/db.php';
if (file_exists(__DIR__ . '/inc/functions.php')) require_once __DIR__ . '/inc/functions.php';

if (empty($_SESSION['user_id'])) { header('Location: auth/login.php'); exit; }

function tbl_exists(PDO $pdo, string $t): bool {
  $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name=?");
  $q->execute([$t]); return (int)$q->fetchColumn()>0;
}
function col_exists(PDO $pdo, string $t, string $c): bool {
  $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name=? AND column_name=?");
  $q->execute([$t,$c]); return (int)$q->fetchColumn()>0;
}
function bronze_master_level_from_xp(int $xp): array {
  if ($xp>=10000) return ['level'=>6,'name'=>'Master'];
  if ($xp>= 5000) return ['level'=>5,'name'=>'Diamond'];
  if ($xp>= 2500) return ['level'=>4,'name'=>'Platinum'];
  if ($xp>= 1000) return ['level'=>3,'name'=>'Gold'];
  if ($xp>=  250) return ['level'=>2,'name'=>'Silver'];
  return ['level'=>1,'name'=>'Bronze'];
}
function initials_from_name(string $name): string {
  $name = trim($name);
  if ($name === '') return '•';
  $parts = preg_split('/\s+/', $name);
  $f = mb_substr($parts[0],0,1);
  $s = isset($parts[1]) ? mb_substr($parts[1],0,1) : '';
  return mb_strtoupper($f . ($s ?: ''));
}
function safe(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$meName = $_SESSION['user']['username'] ?? $_SESSION['username'] ?? '';
if ($meName === '' && !empty($_SESSION['user_id']) && tbl_exists($pdo,'users')) {
  $st = $pdo->prepare("SELECT username FROM users WHERE id=?");
  $st->execute([(int)$_SESSION['user_id']]);
  $meName = (string)($st->fetchColumn() ?: 'User');
}
$first   = safe(ucfirst(explode(' ', $meName)[0] ?? 'User'));
$initial = safe(mb_strtoupper(mb_substr($meName,0,1)));

$rows = [];
$maxRows = 100;

if (tbl_exists($pdo,'users')) {
  if (tbl_exists($pdo,'user_xp')) {
    $xpJoin = "LEFT JOIN (SELECT user_id, COALESCE(SUM(points),0) AS xp FROM user_xp GROUP BY user_id) x ON x.user_id=u.id";
  } elseif (tbl_exists($pdo,'user_points')) {
    $xpJoin = "LEFT JOIN user_points x ON x.user_id=u.id";
  } else {
    $xpJoin = "LEFT JOIN (SELECT 0 user_id, 0 xp) x ON x.user_id=u.id";
  }

  $sql = "
    SELECT u.id, u.username, COALESCE(x.xp,0) AS xp, COALESCE(u.level,0) AS level, COALESCE(u.level_name,'') AS level_name
    FROM users u
    {$xpJoin}
    ORDER BY xp DESC, u.id ASC
    LIMIT {$maxRows}
  ";
  $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

$badgeSource = null; $badgeCol = null;
if (tbl_exists($pdo,'user_badges')) {
  if (col_exists($pdo,'user_badges','title')) { $badgeSource='user_badges'; $badgeCol='title'; }
  elseif (col_exists($pdo,'user_badges','type')) { $badgeSource='user_badges'; $badgeCol='type'; }
} elseif (tbl_exists($pdo,'achievements') && col_exists($pdo,'achievements','name') && col_exists($pdo,'achievements','user_id')) {
  $badgeSource='achievements'; $badgeCol='name';
}

$badgesByUser = [];
if ($badgeSource && $badgeCol && $rows) {
  $ids = array_map(fn($r)=>(int)$r['id'], $rows);
  $in  = implode(',', array_fill(0,count($ids),'?'));
  $st  = $pdo->prepare("SELECT user_id, {$badgeCol} AS badge FROM {$badgeSource} WHERE user_id IN ($in) ORDER BY id");
  $st->execute($ids);
  foreach ($st as $b) {
    $uid = (int)$b['user_id'];
    $badgesByUser[$uid] = $badgesByUser[$uid] ?? [];
    if (count($badgesByUser[$uid]) < 8) $badgesByUser[$uid][] = (string)$b['badge'];
  }
}

$maxXp=0;
foreach ($rows as &$r) {
  $r['xp']=(int)$r['xp'];
  $lvl=(int)$r['level']; $name=(string)$r['level_name'];
  if ($lvl<=0 || $name===''){ $calc=bronze_master_level_from_xp($r['xp']); $r['level']=$calc['level']; $r['level_name']=$calc['name']; }
  $r['badges'] = $badgesByUser[(int)$r['id']] ?? [];
  $maxXp = max($maxXp, $r['xp']);
}
unset($r);

function badges_html(array $badges): string {
  if (!$badges) return '<span class="chip muted">No badges yet</span>';
  $out=''; foreach ($badges as $b) $out .= '<span class="chip">'.safe($b).'</span>';
  return $out;
}
function pct(int $xp, int $max): int {
  if ($max<=0) return 0;
  return max(3, min(100, (int)round($xp*100/$max)));
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Leaderboard · PhishGuard</title>
<!-- Favicon -->
  <link rel="icon" type="image/svg+xml"
        href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='12' fill='%236d28d9'/%3E%3Cpath d='M18 38l14-20 14 20-14 8z' fill='white'/%3E%3C/svg%3E">

<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{
    --bg:#0b1020; --panel:#0f172a; --ink:#e8edff; --muted:#9fb0d7; --line:rgba(148,163,184,.18);
    --brand:#7c4dff; --brand2:#22d3ee;
  }
  *{box-sizing:border-box}
  html,body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.5 Inter,system-ui,Segoe UI,Roboto,Arial}

 /* Topbar container */
.pg-topbar{
  position:sticky; top:0; z-index:50;
  background:rgba(15,23,42,.92);
  backdrop-filter: blur(10px);
  border-bottom:1px solid rgba(148,163,184,.18);
}

/* Grid layout: brand | nav | user */
.pg-topbar .inner{
  max-width:1200px; margin:0 auto; padding:10px 12px;
  display:grid; grid-template-columns:auto 1fr auto; align-items:center; gap:10px;
}

/* brand */
.pg-brand{display:flex; align-items:center; gap:8px; color:#fff; text-decoration:none; font-weight:800;}
.pg-brand .logo{width:28px; height:28px; border-radius:8px; display:grid; place-items:center;
  background:linear-gradient(135deg,#7c4dff,#22d3ee);}

/* nav: allow shrinking + horizontal scroll on small */
.pg-nav{min-width:0; overflow-x:auto; white-space:nowrap; -webkit-overflow-scrolling:touch;}
.pg-nav::-webkit-scrollbar{display:none;}
.pg-nav a{
  display:inline-block; color:#e5e7eb; text-decoration:none;
  padding:6px 10px; margin:0 2px; border-radius:8px;
}
.pg-nav a:hover, .pg-nav a.active{ background:rgba(255,255,255,.08); }

/* user area */
.pg-user{display:flex; align-items:center; gap:8px; justify-self:end;}
.pg-avatar{width:28px; height:28px; border-radius:50%; display:grid; place-items:center; font-weight:800;
  background:linear-gradient(135deg,#7c4dff,#22d3ee); color:#051026;}
.pg-hello{color:#e5e7eb;}

/* dropdown */
.pg-dd{position:absolute; right:0; top:40px; background:#181f36; border-radius:10px;
  box-shadow:0 4px 24px rgba(31,38,135,.17); min-width:140px; overflow:hidden;}
.pg-dd a{display:block; padding:12px 16px; color:#fff; text-decoration:none; font-weight:600;}
.pg-dd a:hover{background:rgba(255,255,255,.06);}

/* ---------- Responsive tweaks ---------- */
/* Two-row header on narrow screens */
@media (max-width: 640px){
  .pg-topbar .inner{
    grid-template-columns: 1fr auto;       /* brand | user */
    grid-template-areas:
      "brand user"
      "nav   nav";
    row-gap: 8px;
  }
  .pg-brand{grid-area: brand;}
  .pg-nav{grid-area: nav;}
  .pg-user{grid-area: user;}
  .pg-hello{display:none;}                 /* hide welcome text; keep avatar */
  .pg-nav a{padding:6px 8px; margin:0 1px;}
}

  /* Page */
  .wrap{max-width:1200px;margin:24px auto;padding:0 18px}

  /* Header block */
  .hero{
    padding:18px;border-radius:18px;border:1px solid var(--line);
    background:
      radial-gradient(120% 120% at 12% 8%, rgba(124,77,255,.18), transparent 60%),
      radial-gradient(120% 120% at 88% 92%, rgba(34,211,238,.12), transparent 60%),
      linear-gradient(180deg, rgba(124,77,255,.08), rgba(34,211,238,.06)),
      var(--panel);
    box-shadow:0 18px 60px rgba(0,0,0,.35);margin-bottom:16px;
  }
  .hero h2{margin:0 0 6px;font-size:18px}
  .muted{color:var(--muted)}

  /* Podium (top 3) */
  .podium{display:grid;grid-template-columns:1.2fr 1.6fr 1.2fr;gap:14px;margin-bottom:16px}
  .pod{
    padding:16px;border-radius:16px;border:1px solid var(--line);
    background:rgba(255,255,255,.04);
    display:flex;flex-direction:column;gap:12px;align-items:center;justify-content:center;
    position:relative;overflow:hidden;
    transition:transform .15s ease, box-shadow .15s ease, border-color .15s ease;
  }
  .pod:hover{transform: translateY(-2px); box-shadow:0 12px 30px rgba(0,0,0,.35); border-color: rgba(124,77,255,.35)}
  .rankBubble{width:48px;height:48px;border-radius:14px;display:grid;place-items:center;font-weight:900;color:#051026;background:linear-gradient(135deg,#7c4dff,#22d3ee)}
  .pname{font-weight:900;font-size:16px}
  .pmeta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:center}
  .chip{font-size:12px;border:1px solid var(--line);border-radius:999px;padding:4px 10px;background:rgba(255,255,255,.04)}
  .pxp{font-weight:900}
  .pbar{width:100%;height:8px;border-radius:999px;background:#16213a;overflow:hidden}
  .pbar>span{display:block;height:100%;background:linear-gradient(90deg,#7c4dff,#22d3ee)}

  /* List rows */
  .rows{display:flex;flex-direction:column;gap:12px}
  .row{
    display:flex;align-items:center;gap:12px;padding:12px 14px;
    border:1px solid var(--line);border-radius:14px;background:rgba(255,255,255,.03);
    transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
  }
  .row:hover{transform: translateY(-1px); box-shadow:0 10px 24px rgba(0,0,0,.32); border-color: rgba(124,77,255,.28)}
  .rank{width:40px;height:40px;border-radius:12px;display:grid;place-items:center;font-weight:900;color:#051026;background:linear-gradient(135deg,#7c4dff,#22d3ee); flex:0 0 auto}
  .avatar{width:30px;height:30px;border-radius:999px;display:grid;place-items:center;font-weight:900;background:#121a33; flex:0 0 auto}
  .name{font-weight:800}
  .level{font-size:12px;border:1px solid var(--line);border-radius:999px;padding:4px 10px;color:#dbe2ff;background:rgba(255,255,255,.04)}
  .badges{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
  .badge{font-size:12px;border:1px solid var(--line);border-radius:999px;padding:4px 10px;background:rgba(255,255,255,.04)}

  .score{margin-left:auto; display:flex;align-items:center;gap:12px;min-width:240px}
  .xp{font-weight:900;white-space:nowrap}
  .bar{flex:1;height:8px;border-radius:999px;background:#16213a;overflow:hidden}
  .bar>span{display:block;height:100%;background:linear-gradient(90deg,#7c4dff,#22d3ee); transition:width .4s ease}

  .foot{padding:8px 4px;color:var(--muted);font-size:12px}

  /* ---------- RESPONSIVE FIXES ---------- */
  @media(max-width:980px){
    .podium{grid-template-columns:1fr}
  }
  @media(max-width:720px){
    .row{flex-wrap:wrap}                 /* allow items onto a new line */
    .level{margin-left:auto}             /* keep level near name on first line */
    .badges{width:100%; order:3}         /* badges go to their own wrapped line */
    .score{
      order:4; width:100%;               /* XP and bar full-width below badges */
      min-width:0; margin-left:0;
    }
    .bar{flex:1}
  }
  @media(max-width:420px){
    .pg-nav a{margin:0 6px; padding:6px 8px}
    .rank{width:36px;height:36px}
    .avatar{width:28px;height:28px}
    .name{font-size:13.5px}
  }
</style>
</head>
<body>

<?php
  // Set the active page for navigation highlighting in the topbar.
  $ACTIVE = 'leaderboard';
  // The BASE_URL variable should be defined in a central config, like db.php.
  // It's required by the topbar and footer.
  require_once __DIR__ . '/inc/app_topbar.php';
?>


<div class="wrap">

  <div class="hero">
    <h2>Leaderboard</h2>
    <div class="muted">Top learners by total XP , Levels and badges</div>
  </div>

  <?php
    $pod   = array_slice($rows, 0, 3);
    $rest  = array_slice($rows, 3);
  ?>

  <!-- Podium (Top 3) -->
  <?php if ($pod): ?>
  <div class="podium">
    <?php
      $order = [];
      if (isset($pod[1])) $order[] = [2,$pod[1]];
      if (isset($pod[0])) $order[] = [1,$pod[0]];
      if (isset($pod[2])) $order[] = [3,$pod[2]];
      foreach ($order as [$rank, $r]):
        $uname   = safe($r['username']);
        $lvlText = 'L'.$r['level'].' · '.safe($r['level_name']);
        $percent = pct((int)$r['xp'], $maxXp);
    ?>
    <div class="pod">
      <div class="rankBubble">#<?= $rank ?></div>
      <div class="pname"><?= $uname ?></div>
      <div class="pmeta">
        <span class="chip"><?= $lvlText ?></span>
        <?= badges_html($r['badges']) ?>
      </div>
      <div class="pxp"><?= (int)$r['xp'] ?> XP</div>
      <div class="pbar"><span style="width:<?= $percent ?>%"></span></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Rest of the leaderboard -->
  <div class="rows">
    <?php if (!$rows): ?>
      <div class="row">No learners yet.</div>
    <?php else:
      $rank = 4;
      foreach ($rest as $r):
        $uname   = safe($r['username']);
        $lvlText = 'L'.$r['level'].' · '.safe($r['level_name']);
        $percent = pct((int)$r['xp'], $maxXp);
        $init    = safe(initials_from_name($r['username']));
    ?>
      <div class="row">
        <div class="rank">#<?= $rank ?></div>
        <div class="avatar"><?= $init ?></div>
        <div class="name"><?= $uname ?></div>
        <span class="level"><?= $lvlText ?></span>
        <div class="badges">
          <?php if ($r['badges']): foreach ($r['badges'] as $b): ?>
            <span class="badge"><?= safe($b) ?></span>
          <?php endforeach; else: ?>
            <span class="badge muted">No badges yet</span>
          <?php endif; ?>
        </div>
        <div class="score">
          <div class="xp"><?= (int)$r['xp'] ?> XP</div>
          <div class="bar"><span style="width:<?= $percent ?>%"></span></div>
        </div>
      </div>
    <?php $rank++; endforeach; endif; ?>
  </div>

  <div class="foot">Showing top <?= count($rows) ?> users. Ties break by user id asc.</div>
</div>
