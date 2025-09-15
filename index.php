<?php
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

/* ---------- helpers ---------- */
function tbl_exists(PDO $pdo,string $t):bool{
  try{
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
    $q->execute([$t]); return (int)$q->fetchColumn()>0;
  }catch(Throwable $e){ return false; }
}
function col_exists(PDO $pdo,string $t,string $c):bool{
  try{
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
    $q->execute([$t,$c]); return (int)$q->fetchColumn()>0;
  }catch(Throwable $e){ return false; }
}
function scalar_or(PDO $pdo,string $sql,$fb){
  try{ $v=$pdo->query($sql)->fetchColumn(); return is_numeric($v)?(int)$v:(int)$fb; }
  catch(Throwable $e){ return (int)$fb; }
}

/* ---------- live metrics ---------- */
$totalUsers   = tbl_exists($pdo,'users')   ? scalar_or($pdo,"SELECT COUNT(*) FROM `users`",0) : 0;
$totalCourses = tbl_exists($pdo,'courses') ? scalar_or($pdo,"SELECT COUNT(*) FROM `courses`",3) : 3;
$totalBadges  = tbl_exists($pdo,'badges')  ? scalar_or($pdo,"SELECT COUNT(*) FROM `badges`",5) : 5;

/* ---------- Top XP (match leaderboard) ---------- */
$topXP = 0;
if (tbl_exists($pdo,'user_xp') && col_exists($pdo,'user_xp','points')) {
  $topXP = scalar_or($pdo,"
    SELECT COALESCE(MAX(xp),0) FROM (
      SELECT user_id, SUM(points) AS xp
      FROM `user_xp`
      GROUP BY user_id
    ) t
  ", 0);
} else if (tbl_exists($pdo,'user_points')) {
  if (col_exists($pdo,'user_points','xp')) {
    $topXP = scalar_or($pdo,"SELECT COALESCE(MAX(`xp`),0) FROM `user_points`",0);
  } else if (col_exists($pdo,'user_points','points')) {
    $topXP = scalar_or($pdo,"SELECT COALESCE(MAX(`points`),0) FROM `user_points`",0);
  }
} else if (tbl_exists($pdo,'users')) {
  foreach (['total_xp','xp','points','score'] as $col) {
    if (col_exists($pdo,'users',$col)) { $topXP = scalar_or($pdo,"SELECT COALESCE(MAX(`$col`),0) FROM `users`",0); break; }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>PhishGuard — Sign in</title>
<link rel="icon" type="image/svg+xml"
      href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='12' fill='%236d28d9'/%3E%3Cpath d='M18 38l14-20 14 20-14 8z' fill='white'/%3E%3C/svg%3E">
<link rel="stylesheet" href="assets/css/pg.css"/>

<style>
/* ===== Global guards ===== */
*{box-sizing:border-box}
html,body{
  margin:0;padding:0;
  height:auto!important;min-height:100%!important;
  width:100%;max-width:100%;
  overflow-x:hidden;               /* NO sideways scroll */
  overflow-y:auto!important; -webkit-overflow-scrolling:touch;
}
body.pg-page{
  background:
    radial-gradient(100% 60% at 15% 0%, #5b4dff22 0, transparent 40%),
    radial-gradient(100% 60% at 85% 100%, #5cabff22 0, transparent 40%),
    #070d1c;
  color:#e9efff;
  font:16px/1.45 system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial;
  padding-top:0;                   /* remove header gap */
}

/* ===== Layout ===== */
.pg{display:block!important;width:100%}
.wrap{max-width:1100px;width:100%;margin:0 auto;padding:0 12px}
.card{
  width:100%;
  display:grid!important;grid-template-columns:1fr 1fr!important;gap:0;
  background:#0b1429;border:1px solid rgba(148,163,184,.15);
  border-radius:22px;box-shadow:0 20px 60px rgba(0,0,0,.35);
  overflow:hidden!important;height:auto!important;min-height:0!important;
}
@media (max-width:880px){.card{grid-template-columns:1fr!important}}

/* ===== Left hero ===== */
.left{
  padding:22px;color:#f8faff;
  background:linear-gradient(180deg,#5b4dff 0%, #7c4dff 48%, #3ea0ff 100%);
  border-top-left-radius:22px;border-top-right-radius:22px;
}
.logo{width:44px;height:44px;display:grid;place-items:center;border-radius:12px;
      background:rgba(255,255,255,.18);margin-bottom:6px;font-size:24px;backdrop-filter:saturate(140%) blur(2px)}
.left h2{margin:6px 0 0;font-size:1.4rem}
.small{margin:2px 0 14px;opacity:.95;font-weight:600}

/* Users metric (glass, white text) */
.metric{
  background:linear-gradient(180deg,rgba(255,255,255,.18),rgba(255,255,255,.12));
  border:1px solid rgba(255,255,255,.28);
  border-radius:14px;padding:14px 14px 12px;display:grid;gap:6px;
  color:#fff; backdrop-filter:saturate(130%) blur(2px);
}
.metric .label{font-weight:800;letter-spacing:.2px;opacity:.95}
.metric .big{font-size:2.2rem;font-weight:900;line-height:1;letter-spacing:.5px}

/* Tiles */
.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:14px}
.stat{
  background:linear-gradient(180deg,rgba(255,255,255,.16),rgba(255,255,255,.10));
  border:1px solid rgba(255,255,255,.22);
  border-radius:14px;padding:10px;text-align:center;font-weight:800;color:#fff;
}
.stat .num{font-size:1.2rem}

/* ===== Right column ===== */
.right{
  padding:18px 18px 24px;
  position:relative; overflow:hidden;    /* clip any glows */
  height:auto!important; min-height:0!important;
}
.notice{background:#101d40;border:1px solid rgba(148,163,184,.20);color:#cfe1ff;padding:10px 12px;border-radius:12px;margin-bottom:10px}
.tabs{margin-bottom:6px;position:relative;max-width:100%;overflow:hidden}  /* contain moving underline */
.tab{display:inline-block;margin-right:16px;padding:8px 2px;cursor:pointer;color:#c8d8ff;font-weight:800}
.tab.active{color:#fff}
.underline{height:2px;background:#7c4dff;border-radius:999px;width:72px;transform:translateX(0);transition:transform .2s,width .2s}
.panes{margin-top:6px}
.pane{display:none;position:static!important;height:auto!important;min-height:0!important}
.pane.active{display:block}
h1{margin:4px 0 6px;font-size:1.3rem}
.muted{color:#a6b4d8;margin:0 0 12px}
label{display:block;margin:12px 0 6px;font-weight:700;color:#dbe6ff}
.input{width:100%;padding:12px;border-radius:12px;border:1px solid rgba(148,163,184,.2);background:#0c1631;color:#e9efff;box-shadow:inset 0 0 0 1px rgba(255,255,255,.02)}
.input:focus{outline:none;border-color:#7c4dff;box-shadow:0 0 0 3px #7c4dff33}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
@media (max-width:520px){.row2{grid-template-columns:1fr}}
.btn{
  display:block;width:100%;margin-top:14px;
  background:#4f46e5;color:#fff;border:none;border-radius:12px;padding:12px 14px;font-weight:900;cursor:pointer;
}

/* ===== Mobile-only clamps ===== */
@media (max-width:560px){
  .left{padding:14px 12px 12px}
  .logo{width:36px;height:36px;margin-bottom:6px;font-size:20px}
  .left h2{font-size:1.18rem}
  .small{margin:2px 0 10px}
  .metric{padding:12px;border-radius:12px}
  .metric .big{font-size:1.8rem}
  .metric .label{font-size:.9rem}
  .stats{gap:8px;margin-top:10px}
  .stat{padding:8px;border-radius:12px}
  .stat .num{font-size:1.05rem}

  .wrap,.card,.left,.right,.tabs,.panes,.pane,form{
    width:100%;max-width:100%;
    height:auto!important;min-height:0!important;
  }
  .right{padding:12px 12px 8px!important}
  #pg-login form{margin:0!important;padding:0!important}
  #pg-login .btn{
    margin:12px 0 0 0!important;
    box-shadow:none!important; filter:none!important;   /* nuke glow */
  }
  .right::before,.right::after,.btn::after,.card::after{content:none!important;display:none!important}
  .right .pane.active > *:last-child{margin-bottom:0!important}
}

/* Safety reset for any stray min-heights from global css */
@media (max-width:880px){
  html,body,.pg,.wrap,.card,.left,.right,.panes,.pane,form{
    height:auto!important;min-height:0!important;max-height:none!important;overflow:visible!important
  }
}
</style>
<style>
/* Strong mobile overrides for external pg.css */
@media (max-width: 1040px){
  /* Let the card grow and be scrollable */
  .pg .card{
    height:auto!important; max-height:none!important; overflow:visible!important;
    grid-template-columns:1fr!important; width:100%!important; max-width:100%!important;
    margin:16px auto!important;
  }
  html,body,.pg-page{height:auto!important; min-height:100%!important; overflow-y:auto!important}
  .pg{height:auto!important; min-height:0!important}
  .pg .wrap{height:auto!important; min-height:0!important; overflow:visible!important}
  /* Comfortable padding and bottom clearance */
  .pg .right{ padding:16px 14px 96px!important; overflow:visible!important; max-height:none!important }
  .pg .left{ padding:20px 16px!important }

  /* Make panes flow naturally; remove forced min-height */
  .pg .panes{ position:static!important; min-height:0!important; height:auto!important; max-height:none!important }
  .pg .pane{ position:static!important; opacity:1!important; transform:none!important; pointer-events:auto!important; height:auto!important }

  /* Reduce heavy divider spacing in tabs */
  .pg .tabs{ padding-bottom:6px!important; margin-bottom:10px!important; border-bottom:0!important }
  .pg .underline{ position:static!important; height:2px!important; margin-top:6px!important; box-shadow:none!important }
}
@media (max-width: 560px){
  /* Shrink hero and form to fit small phones */
  .pg .left{padding:10px 10px 8px!important}
  .pg .right{padding:10px 12px 16px!important}
  .pg .logo{width:34px!important;height:34px!important}
  .pg h2{font-size:20px!important}
  .pg p.small{font-size:12px!important;margin:0 0 8px!important}
  .pg h1{font-size:18px!important}
  .pg .input{height:42px!important; font-size:14px!important; padding:0 10px!important}
  .pg .btn{height:46px!important; margin-top:12px!important}
  .pg .underline{display:none!important}
  /* Compact hero parts to lift Register higher */
  .metric{padding:8px!important;border-radius:10px!important;margin-bottom:12px!important}
  .metric .big{font-size:1.2rem!important}
  .metric .label{font-size:.85rem!important}
  /* Show counters in compact grid with clear spacing */
  .stats{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:8px!important;margin-top:10px!important}
  .stat{padding:6px!important;border-radius:10px!important;min-height:60px!important}
.stat .num{font-size:1rem!important}
  .tabs{margin-bottom:6px!important}
  /* Keep first/last name side-by-side to save height */
  .row2{grid-template-columns:1fr 1fr!important; gap:10px!important}
  /* Trim vertical spacing inside Register form */
  #pg-register h1{margin:4px 0 4px!important}
  #pg-register .muted{margin:0 0 8px!important}
  #pg-register label{margin:8px 0 4px!important}
  #pg-register .btn{margin-top:12px!important}
  /* Make only the Register pane scrollable on phones */
  .pg .right{display:flex!important;flex-direction:column!important;min-height:0!important}
  .pg .right .panes{flex:1 1 auto!important;min-height:0!important}
  #pg-register.pane{overflow-y:auto!important; -webkit-overflow-scrolling:touch; max-height:75svh; max-height:75vh; padding-bottom:18px!important}
}

/* KPI tiles (Users, Courses, Badges, Top XP) as 2x2 grid for phones */
.kpis{display:none}
.kpi{background:linear-gradient(180deg,rgba(255,255,255,.16),rgba(255,255,255,.10));
     border:1px solid rgba(255,255,255,.22); border-radius:12px; padding:10px; text-align:center; color:#fff}
.kpi .num{font-weight:900; font-size:1.15rem; line-height:1}
.kpi .label{font-weight:700; margin-top:4px; opacity:.95}
@media (max-width:560px){
  .metric,.stats{display:none!important}
  .kpis{display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px; margin:10px 0 6px}
}

/* If the screen is a bit wider, keep three counters per row */
@media (min-width: 420px) and (max-width: 560px){
  .stats{grid-template-columns:repeat(3,minmax(0,1fr))!important}
}

/* Desktop sizing and spacing tune (looks less huge) */
@media (min-width: 1041px){
  .pg .card{
    max-width:900px!important; width:88vw!important;
    grid-template-columns:340px 1fr!important;
    height:auto!important; max-height:none!important; margin:24px auto!important;
  }
  .pg .left{padding:28px!important}
  .pg .right{padding:24px 26px!important}
  .pg .logo{width:50px!important;height:50px!important}
  .pg h2{font-size:26px!important}
  .pg h1{font-size:19px!important}
  .pg .input{height:44px!important}
  .pg .btn{height:50px!important}
  /* Switch to 2x2 KPI grid on desktop */
  .metric,.stats{display:none!important}
  .kpis{display:grid!important; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px!important; margin:10px 0 0!important}
  .kpi{padding:14px!important}
  .kpi .num{font-size:1.25rem!important}
  .kpi .label{font-size:0.95rem!important}
}
</style>
<style>
/* Hard always-on fixes to ensure Register pane is fully visible and page scrolls */
html,body,.pg-page{height:auto!important;min-height:100%!important;overflow-y:auto!important}
.pg{height:auto!important;min-height:0!important}
.pg .card{height:auto!important;max-height:none!important;overflow:visible!important}
.pg .right{overflow:visible!important;max-height:none!important}
.pg .panes{position:static!important;min-height:0!important;height:auto!important;max-height:none!important}
.pg .pane{position:static!important;opacity:1!important;transform:none!important;pointer-events:auto!important;height:auto!important}
#pg-register form{margin-bottom:16px!important}

/* Make Register pane itself scrollable on most phones */
@media (max-width: 700px){
  .pg .right.reg-scroll{display:flex!important;flex-direction:column!important;min-height:0!important;overflow:auto!important}
  .pg .right.reg-scroll .panes{flex:1 1 auto!important;min-height:0!important}
}
</style>
<style>
/* Responsive overrides: tighten layout and prevent overlap on small screens */
img,svg,video{max-width:100%;height:auto}

/* Forms and inputs should always fill the container width */
.right form{width:100%}
.input{width:100%;max-width:100%}
.btn{width:100%;max-width:100%}

/* Two-column rows collapse on mobile */
.row2{display:grid;grid-template-columns:1fr 1fr;gap:10px}

/* Tab bar resilience */
.tabs{display:grid;grid-template-columns:1fr 1fr;align-items:center;gap:0}
.tabs .tab{text-align:center}

/* Keep interactive content above any decorative glows */
.right .tabs,.right .panes,.right form{position:relative;z-index:1}
.right [class*="glow"],[class*="ring"],[class*="orb"]{pointer-events:none}

@media (max-width: 1024px){
  .wrap{padding-left:14px;padding-right:14px}
}

@media (max-width: 880px){
  /* Stack columns, keep hero first */
  .card{display:block!important}
  .left{border-radius:22px 22px 0 0}
  .right{border-radius:0 0 22px 22px; overflow:visible!important; padding:14px 14px 18px!important}
  .panes{margin-bottom:12px}
}

@media (max-width: 720px){
  .left{padding:18px}
  .right{padding:16px}
  .metric .big{font-size:1.8rem}
  .stats{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
  .row2{grid-template-columns:1fr}
  .tabs .tab{font-size:0.95rem;padding:8px 4px}
}

@media (max-width: 520px){
  .wrap{padding-left:10px;padding-right:10px}
  .left{padding:14px}
  .right{padding:14px}
  .left h2{font-size:1.15rem}
  .small{font-size:0.92rem}
  .metric{padding:12px}
  .metric .big{font-size:1.55rem}
  .stats{grid-template-columns:1fr}
  .stat .num{font-size:1.05rem}
  .pane h1{font-size:1.35rem}
  .pane .muted{font-size:0.95rem}
  label{font-size:0.95rem}
}

/* Extra narrow phones */
@media (max-width: 380px){
  .tabs .tab{font-size:0.9rem}
  .pane h1{font-size:1.2rem}
}
</style>
</head>
<body class="pg-page">

<div class="pg">
  <div class="wrap">
    <div class="card">
      <!-- LEFT -->
      <div class="left">
        <div class="logo">🛡️</div>
        <h2>PhishGuard</h2>
        <p class="small">Level up your security awareness</p>

        <!-- Users Metric (desktop/tablet) -->
        <div class="metric">
          <div class="label">Users</div>
          <div class="big" id="usersCount" data-target="<?= (int)$totalUsers ?>" data-start-extra="100">0</div>
        </div>

        <!-- Original 3 tiles (desktop/tablet) -->
        <div class="stats">
          <div class="stat">
            <div class="num" id="coursesCount" data-target="<?= (int)$totalCourses ?>" data-start-extra="100">0</div>
            <div>Courses</div>
          </div>
          <div class="stat">
            <div class="num" id="badgesCount" data-target="<?= (int)$totalBadges ?>" data-start-extra="100">0</div>
            <div>Badges</div>
          </div>
          <div class="stat">
            <div class="num" id="xpCount" data-target="<?= (int)$topXP ?>" data-start-extra="100">0</div>
            <div>Top&nbsp;XP</div>
          </div>
        </div>

        <!-- 2x2 KPI grid for phones (hidden on desktop) -->
        <div class="kpis">
          <div class="kpi">
            <div class="num" id="usersCountM" data-target="<?= (int)$totalUsers ?>" data-start-extra="100">0</div>
            <div class="label">Users</div>
          </div>
          <div class="kpi">
            <div class="num" id="coursesCountM" data-target="<?= (int)$totalCourses ?>" data-start-extra="100">0</div>
            <div class="label">Courses</div>
          </div>
          <div class="kpi">
            <div class="num" id="badgesCountM" data-target="<?= (int)$totalBadges ?>" data-start-extra="100">0</div>
            <div class="label">Badges</div>
          </div>
          <div class="kpi">
            <div class="num" id="xpCountM" data-target="<?= (int)$topXP ?>" data-start-extra="100">0</div>
            <div class="label">Top&nbsp;XP</div>
          </div>
        </div>
      </div>

      <!-- RIGHT -->
      <div class="right">
        <?php if(isset($_GET['msg'])): ?>
          <div class="notice"><?= htmlspecialchars($_GET['msg'], ENT_QUOTES) ?></div>
        <?php endif; ?>

        <div class="tabs">
          <div class="tab active" data-target="login">Login</div>
          <div class="tab" data-target="register">Register</div>
          <div class="underline" id="tab-underline"></div>
        </div>

        <div class="panes">
          <div id="pg-login" class="pane active">
            <h1>Welcome back!</h1>
            <p class="muted">Login with your username</p>
            <form method="POST" action="auth/login.php" autocomplete="on">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
              <label>Username</label>
              <input class="input" type="text" name="username" placeholder="yourusername" autocomplete="username" required>
              <label>Password</label>
              <input class="input" type="password" name="password" placeholder="••••••••" autocomplete="current-password" required>
              <button class="btn" type="submit">Secure Login 🔒</button>
            </form>
          </div>

          <div id="pg-register" class="pane">
            <h1>Create your account</h1>
            <p class="muted">Start your security journey</p>
            <form method="POST" action="auth/register.php" autocomplete="on">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
              <div class="row2">
                <div><label>First name</label><input class="input" type="text" name="first_name" autocomplete="given-name" required></div>
                <div><label>Last name</label><input class="input" type="text" name="last_name" autocomplete="family-name" required></div>
              </div>
              <label>Username</label>
              <input class="input" type="text" name="username" minlength="3" maxlength="32" pattern="[a-zA-Z0-9_.\-]+" autocomplete="username" required>
              <label>Work email (optional)</label>
              <input class="input" type="email" name="email" placeholder="you@company.com" autocomplete="email">
              <label>Create password</label>
              <input class="input" type="password" name="password" minlength="8" autocomplete="new-password" required>
              <p class="muted" style="margin-top:6px">Use 8+ characters, include a number &amp; symbol.</p>
              <button class="btn" type="submit">Create Account</button>
            </form>
          </div>
        </div>
      </div><!-- /RIGHT -->
    </div><!-- /card -->
  </div><!-- /wrap -->
</div><!-- /pg -->

<script>
/* Tabs underline */
document.addEventListener('DOMContentLoaded',()=>{
  const tabs=[...document.querySelectorAll('.tab[data-target]')];
  const underline=document.getElementById('tab-underline');
  const panes={login:document.getElementById('pg-login'),register:document.getElementById('pg-register')};
  const right=document.querySelector('.right');
  let current='login';
  function sizeRegister(){
    const reg=panes.register; if(!reg) return;
    const target = right || reg;
    // Reset then compute available height from element top to viewport bottom
    target.style.removeProperty('max-height');
    const rectTop=(target.getBoundingClientRect?target.getBoundingClientRect().top:0);
    const vh=(window.visualViewport?window.visualViewport.height:window.innerHeight)||window.innerHeight;
    const max=Math.max(260, Math.floor(vh - rectTop - 8));
    target.style.maxHeight=max+'px'; target.style.overflowY='auto'; target.style.webkitOverflowScrolling='touch';
  }
  function moveUnderline(el){
    if(!underline||!el||!el.parentElement)return;
    const r=el.getBoundingClientRect(),base=el.parentElement.getBoundingClientRect().left;
    underline.style.width=r.width+'px'; underline.style.transform=`translateX(${r.left-base}px)`;
  }
  function show(next){
    if(!panes[next]||next===current)return;
    panes[current]?.classList.remove('active'); panes[current]?.setAttribute('hidden','');
    panes[next]?.classList.add('active'); panes[next]?.removeAttribute('hidden');
    tabs.forEach(t=>t.classList.toggle('active',t.dataset.target===next));
    moveUnderline(tabs.find(t=>t.dataset.target===next));
    current=next;
    if(right){ right.classList.toggle('reg-scroll', current==='register'); }
    if(current==='register') sizeRegister();
  }
  panes[current]?.classList.add('active'); panes[current]?.removeAttribute('hidden');
  moveUnderline(tabs[0]); if(right){ right.classList.toggle('reg-scroll', current==='register'); } if(current==='register') sizeRegister();
  tabs.forEach(t=>t.addEventListener('click',()=>show(t.dataset.target)));
  window.addEventListener('resize',()=>{ moveUnderline(tabs.find(t=>t.classList.contains('active'))||tabs[0]); if(current==='register') sizeRegister(); });
  if(window.visualViewport){ visualViewport.addEventListener('resize',()=>{ if(current==='register') sizeRegister(); }); }
});

/* Animate from target+100 down to target */
function animateDown(el){
  if(!el) return;
  const target = Math.max(0, parseInt(el.dataset.target || '0', 10));
  const extra  = parseInt(el.dataset.startExtra || '100', 10);
  let start    = target + (isNaN(extra) ? 100 : extra);

  const distance = Math.max(1, start - target);
  const duration = Math.min(3000, Math.max(1200, 8 * distance));
  const easeOut  = t => 1 - Math.pow(1 - t, 4);
  function stepSize(p){ const e=Math.max(1,Math.round(distance*0.10)), m=Math.max(1,Math.round(distance*0.04)); return p<0.35?e:(p<0.75?m:1); }

  let t0=null,last=start;
  function tick(ts){
    if(t0===null)t0=ts;
    const p=Math.min(1,(ts-t0)/duration);
    const cur=Math.round(start - easeOut(p)*distance);
    const step=stepSize(p);
    if(cur<last-step) last-=step; else last=cur;
    el.textContent=last.toLocaleString();
    if(p<1) requestAnimationFrame(tick); else el.textContent=target.toLocaleString();
  }
  el.textContent=start.toLocaleString(); requestAnimationFrame(tick);
}

/* Fire all counters */
(function(){
  animateDown(document.getElementById('usersCount'));
  animateDown(document.getElementById('coursesCount'));
  animateDown(document.getElementById('badgesCount'));
  animateDown(document.getElementById('xpCount'));  // Top XP from DB (leaderboard-consistent)
  // Mobile KPI grid (if present)
  animateDown(document.getElementById('usersCountM'));
  animateDown(document.getElementById('coursesCountM'));
  animateDown(document.getElementById('badgesCountM'));
  animateDown(document.getElementById('xpCountM'));
})();
</script>
</body>
</html>
