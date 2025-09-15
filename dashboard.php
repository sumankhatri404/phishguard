<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

// ---------- Auth ----------
if (empty($_SESSION['user_id'])) {
  header('Location: index.php?msg=' . urlencode('Please login first.'));
  exit;
}


$userId  = (int)$_SESSION['user_id'];
$first   = htmlspecialchars($_SESSION['user_name'] ?? 'User', ENT_QUOTES, 'UTF-8');
$initial = strtoupper(substr($first, 0, 1));

// ---------- Onboarding flags ----------
$FLAGS = ['has_consent'=>0,'has_pretest'=>0,'has_posttest'=>0];
try {
  $stFlags = $pdo->prepare("SELECT has_consent, has_pretest, has_posttest FROM users WHERE id=?");
  $stFlags->execute([$userId]);
  $row = $stFlags->fetch(PDO::FETCH_ASSOC);
  if ($row) $FLAGS = $row;
} catch (Throwable $e) {}
$NEED_ONBOARD = ((int)($FLAGS['has_consent'] ?? 0) === 0) || ((int)($FLAGS['has_pretest'] ?? 0) === 0);

// ---------- Levels ----------
$LEVELS = [
  ['name'=>'Bronze',   'min'=>0,    'color'=>'#c084fc'],
  ['name'=>'Silver',   'min'=>250,  'color'=>'#60a5fa'],
  ['name'=>'Gold',     'min'=>1000, 'color'=>'#f59e0b'],
  ['name'=>'Platinum', 'min'=>2500, 'color'=>'#34d399'],
  ['name'=>'Diamond',  'min'=>5000, 'color'=>'#22d3ee'],
  ['name'=>'Master',   'min'=>10000,'color'=>'#f472b6'],
];
function pg_current_level(array $LEVELS, int $xp): array {
  $cur = $LEVELS[0]; foreach($LEVELS as $lv){ if($xp >= $lv['min']) $cur=$lv; }
  $next=null; foreach($LEVELS as $lv){ if($lv['min']>$cur['min']) { $next=$lv; break; } }
  return [$cur,$next];
}
function pg_reward_given(PDO $pdo, int $uid, string $key): bool {
  try{
    $s=$pdo->prepare("SELECT 1 FROM user_rewards WHERE user_id=? AND reward_key=? LIMIT 1");
    $s->execute([$uid,$key]); return (bool)$s->fetchColumn();
  }catch(Throwable $e){ return false; }
}
function pg_mark_reward(PDO $pdo, int $uid, string $key): void {
  try{ $s=$pdo->prepare("INSERT IGNORE INTO user_rewards (user_id,reward_key) VALUES (?,?)"); $s->execute([$uid,$key]); }catch(Throwable $e){}
}
function pg_award_badge(PDO $pdo, int $uid, string $name, string $icon='', string $color='#9ec0ff'): void {
  try{ $s=$pdo->prepare("INSERT INTO user_badges (user_id,name,icon,color) VALUES (?,?,?,?)"); $s->execute([$uid,$name,$icon,$color]); }catch(Throwable $e){}
}
function pg_award_xp(PDO $pdo, int $uid, int $points, int $moduleId = 0): void {
  $st = $pdo->prepare("
    INSERT INTO user_xp (user_id, module_id, points)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE points = points + VALUES(points)
  ");
  $st->execute([$uid, $moduleId, (int)$points]);
}


// ---------- Ensure helper tables (idempotent) ----------
try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS user_rewards (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      reward_key VARCHAR(190) NOT NULL,
      awarded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_user_reward (user_id, reward_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS user_badges (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      name VARCHAR(190) NOT NULL,
      icon VARCHAR(16) NULL,
      color VARCHAR(16) NULL,
      earned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
} catch (Throwable $e) {}

// ---------- Modules ----------
function pg_default_cases_table(string $channel): string {
  switch (strtolower($channel)) {
    case 'sms': return 'training_sms_cases';
    case 'web': return 'training_web_cases'; // may not exist anymore; safe due to try/catch below
    default:    return 'training_mail_cases';
  }
}
$modules=[];
try{
  $modules=$pdo->query("
    SELECT id,title,description,image_path,level,duration_minutes,channel,cases_table
    FROM training_modules ORDER BY id ASC
  ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}catch(Throwable $e){ $modules=[]; }

// ---------- Per-module completion ----------
$modulePercents = [];
$sumTotals = 0;
$sumDone   = 0;
$moduleTasksDone = 0;

foreach ($modules as $m) {
  $mid=(int)$m['id'];
  $channel=strtolower($m['channel']??'email');
  $caseTbl=$m['cases_table'] ?: pg_default_cases_table($channel);
  $total=0; $done=0;

  // total
  try {
    $st=$pdo->prepare("SELECT COUNT(*) FROM `$caseTbl` WHERE module_id=? AND is_active=1");
    $st->execute([$mid]); $total=(int)$st->fetchColumn();
  } catch(Throwable $e) {
    // table might not exist (e.g., web after schema change) — leave $total=0
  }

  // done
  try {
    if ($channel==='sms'){
      $st=$pdo->prepare("
        SELECT COUNT(DISTINCT p.case_id)
        FROM training_sms_progress p
        JOIN `$caseTbl` c ON c.id=p.case_id
        WHERE p.user_id=? AND c.module_id=? AND c.is_active=1
      "); $st->execute([$userId,$mid]);
    } elseif ($channel==='web'){
      // Old web tables removed; leave $done=0 here.
      // The card's percent is handled later by the separate web-sim sync block,
      // and the unlock will count simulator levels explicitly below.
      $done = 0;
    } else {
      $st=$pdo->prepare("
        SELECT COUNT(DISTINCT p.case_id)
        FROM training_mail_progress p
        JOIN `$caseTbl` c ON c.id=p.case_id
        WHERE p.user_id=? AND c.module_id=?
      "); $st->execute([$userId,$mid]);
    }
    if ($channel!=='web') {
      $done=(int)$st->fetchColumn();
    }
  } catch(Throwable $e) {
    // ignore; leave $done=0
  }

  // accumulate AFTER $done is known
  $moduleTasksDone += $done;

  $modulePercents[$mid] = ($total>0) ? (int)round(($done/$total)*100) : 0;
  $sumTotals += $total;
  $sumDone   += $done;
}

// Include website sim levels (each level counts as one task)
try{
  $simTotal = 0; $simDone = 0;
  $st = $pdo->prepare("SELECT COUNT(*) FROM sim_levels WHERE enabled=1"); $st->execute(); $simTotal = (int)$st->fetchColumn();
  $st = $pdo->prepare("SELECT COUNT(DISTINCT level_no) FROM user_level_completions WHERE user_id=?"); $st->execute([$userId]); $simDone = (int)$st->fetchColumn();
  $sumTotals += $simTotal;
  $sumDone   += $simDone;
}catch(Throwable $e){}

$overallCompletion = ($sumTotals>0) ? (int)round(($sumDone*100)/$sumTotals) : 0;

// ---------- Direct total of finished tasks across all channels ----------
// Email + SMS from their progress tables, and Web from the simulator levels.
$completedTasksDirect = 0;

// EMAIL tasks done
try {
  $st=$pdo->prepare("
    SELECT COUNT(DISTINCT p.case_id)
    FROM training_mail_progress p
    JOIN training_mail_cases c ON c.id=p.case_id
    WHERE p.user_id=? AND c.is_active=1
  ");
  $st->execute([$userId]);
  $completedTasksDirect += (int)$st->fetchColumn();
} catch(Throwable $e) {}

// SMS tasks done
try {
  $st=$pdo->prepare("
    SELECT COUNT(DISTINCT p.case_id)
    FROM training_sms_progress p
    JOIN training_sms_cases c ON c.id=p.case_id
    WHERE p.user_id=? AND c.is_active=1
  ");
  $st->execute([$userId]);
  $completedTasksDirect += (int)$st->fetchColumn();
} catch(Throwable $e) {}

// WEB tasks done = simulator level completions
$completedTasksDirect += (int)($simDone ?? 0);
// WEB tasks done = simulator level completions
$completedTasksDirect += (int)($simDone ?? 0);

// Safe default so the debug echo never hits an undefined var
$completedTasks = (int)$completedTasksDirect;


// ---------- Post-test unlock (tasks-based) ----------
$showPostTest = false;

try {
  $POST_UNLOCK_THRESHOLD = 5;

  // Any 5 tasks across Email + SMS + Web(simulator)
  $completedTasks = (int)$completedTasksDirect;

  // Gate by users.has_posttest so we only show once
  $hasPost = (int)($FLAGS['has_posttest'] ?? 0);
  $showPostTest = ($completedTasks >= $POST_UNLOCK_THRESHOLD) && ($hasPost === 0);

} catch (Throwable $e) {
  $showPostTest = false;
}

// Debug
echo "\n<!-- completedTasks={$completedTasks}, direct={$completedTasksDirect}, simDone=".(int)($simDone??0).", has_posttest=".(int)($FLAGS['has_posttest']??0)." -->\n";


// ---------- XP / Level / Leaderboard ----------
$myXP=0; try{ $s=$pdo->prepare("SELECT GREATEST(0, COALESCE(SUM(points),0)) FROM user_xp WHERE user_id=?"); $s->execute([$userId]); $myXP=(int)$s->fetchColumn(); }catch(Throwable $e){}
[$currentLevel,$nextLevel]=pg_current_level($LEVELS,$myXP);
$xpToNext=$nextLevel?max(0,$nextLevel['min']-$myXP):0;
$rangeMin=$currentLevel['min']; $rangeMax=$nextLevel?$nextLevel['min']:max($rangeMin+1,$myXP+1);
$progress=max(0,min(100,(int)round(($myXP-$rangeMin)/($rangeMax-$rangeMin)*100)));

$leadersAll=[];
try{
  $leadersAll=$pdo->query("
    SELECT ux.user_id, IFNULL(u.username, CONCAT('User #', ux.user_id)) AS display_name, GREATEST(0, SUM(ux.points)) AS total_points
    FROM user_xp ux LEFT JOIN users u ON u.id=ux.user_id
    GROUP BY ux.user_id ORDER BY total_points DESC, ux.user_id ASC
  ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}catch(Throwable $e){}
$leadersTop=[]; $rank=0; $lastPts=null; $meRow=null; $meRank=null;
foreach($leadersAll as $idx=>$row){
  $pts=(int)$row['total_points'];
  if ($pts!==$lastPts){ $rank=$idx+1; $lastPts=$pts; }
  $row['rank']=$rank;
  if ($idx<5) $leadersTop[]=$row;
  if ((int)$row['user_id']===$userId){ $meRow=$row; $meRank=$rank; }
}
if(!$meRow){ $meRank=count($leadersAll)+1; $meRow=['user_id'=>$userId,'display_name'=>$first,'total_points'=>0,'rank'=>$meRank]; }

// ---------- Streaks ----------
try{ $pdo->exec("SET time_zone = '+00:00'"); }catch(Throwable $e){}
$streakDays=0; $bestStreak=0;
try{
  $st=$pdo->prepare("SELECT streak_current,streak_best FROM user_streaks WHERE user_id=? LIMIT 1");
  $st->execute([$userId]); $sr=$st->fetch(PDO::FETCH_ASSOC);
  if ($sr){ $streakDays=(int)$sr['streak_current']; $bestStreak=(int)$sr['streak_best']; }
}catch(Throwable $e){}

// ---------- Auto-rewards ----------
$STREAK_REWARDS=[
  3=>['xp'=>5, 'badge'=>['name'=>'3-Day Streak','icon'=>'🔥','color'=>'#f87171']],
  7=>['xp'=>10,'badge'=>['name'=>'7-Day Streak','icon'=>'⚡','color'=>'#fbbf24']],
  14=>['xp'=>25,'badge'=>['name'=>'14-Day Streak','icon'=>'🏆','color'=>'#60a5fa']],
  30=>['xp'=>100,'badge'=>['name'=>'30-Day Streak','icon'=>'🌟','color'=>'#34d399']],
];
foreach($STREAK_REWARDS as $days=>$reward){
  if ($streakDays>=$days && !pg_reward_given($pdo,$userId,"streak:$days")){
    pg_award_xp($pdo,$userId,(int)$reward['xp']);
    $b=$reward['badge']; pg_award_badge($pdo,$userId,$b['name'],$b['icon'],$b['color']);
    pg_mark_reward($pdo,$userId,"streak:$days");
  }
}
$LEVEL_BONUS = ['Silver'=>100,'Gold'=>500,'Platinum'=>750,'Diamond'=>1500,'Master'=>2000];
foreach($LEVELS as $lv){
  if ($myXP >= $lv['min'] && !pg_reward_given($pdo,$userId,"level:".$lv['name'])){
    pg_award_badge($pdo,$userId,$lv['name']." Level","★",$lv['color']);
    if(isset($LEVEL_BONUS[$lv['name']])) pg_award_xp($pdo,$userId,(int)$LEVEL_BONUS[$lv['name']]);
    pg_mark_reward($pdo,$userId,"level:".$lv['name']);
  }
}
// recalc xp/progress after bonuses
try{ $s=$pdo->prepare("SELECT COALESCE(SUM(points),0) FROM user_xp WHERE user_id=?"); $s->execute([$userId]); $myXP=(int)$s->fetchColumn(); }catch(Throwable $e){}
[$currentLevel,$nextLevel]=pg_current_level($LEVELS,$myXP);
$xpToNext=$nextLevel?max(0,$nextLevel['min']-$myXP):0;
$rangeMin=$currentLevel['min']; $rangeMax=$nextLevel?$nextLevel['min']:max($rangeMin+1,$myXP+1);
$progress=max(0,min(100,(int)round(($myXP-$rangeMin)/($rangeMax-$rangeMin)*100)));

// ---------- Badges ----------
$badges=[]; try{
  $stmtB=$pdo->prepare("SELECT id,name,icon,color,earned_at FROM user_badges WHERE user_id=? ORDER BY earned_at DESC LIMIT 3");
  $stmtB->execute([$userId]); $badges=$stmtB->fetchAll(PDO::FETCH_ASSOC) ?: [];
}catch(Throwable $e){}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>PhishGuard — Dashboard</title>
<link rel="icon" type="image/svg+xml"
        href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='12' fill='%236d28d9'/%3E%3Cpath d='M18 38l14-20 14 20-14 8z' fill='white'/%3E%3C/svg%3E">

<link rel="stylesheet" href="assets/css/pg.css"/>
<link rel="stylesheet" href="assets/css/nav.css"/>
<style>

/* === Spot result (banner + rationale) === */
.res-wrap{margin-top:10px}
.res-banner{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:12px;
  font-weight:800;letter-spacing:.2px}
.res-banner.ok{background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.35);color:#a7f3d0}
.res-banner.bad{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.35);color:#fecaca}
.res-icon{font-size:18px;filter:drop-shadow(0 1px 0 rgba(0,0,0,.25))}
.res-xp{margin-left:auto;background:#0b1224;border:1px solid rgba(148,163,184,.28);
  padding:4px 10px;border-radius:999px;font-weight:900;color:#e6edff}
.res-xp.negative{color:#fecaca}
.res-xp.positive{color:#a7f3d0}

.res-cards{display:grid;grid-template-columns:1fr 1.4fr;gap:10px;margin-top:8px}
@media (max-width:700px){.res-cards{grid-template-columns:1fr}}

.res-card{background:rgba(255,255,255,.04);border:1px dashed rgba(148,163,184,.35);
  border-radius:12px;padding:10px 12px;color:#e2e8f0}
.res-title{font-weight:800;color:#cbd5e1;margin-bottom:6px}

.answer-pill{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;
  font-weight:900;border:1px solid rgba(148,163,184,.25);background:#0f172a}
.pill-phish{background:linear-gradient(90deg,#ef4444,#f97316);color:#140b0b;border-color:rgba(239,68,68,.45)}
.pill-legit{background:linear-gradient(90deg,#22c55e,#16a34a);color:#041a10;border-color:rgba(34,197,94,.45)}

.res-list{margin:0;padding-left:18px}
.res-list li{margin:4px 0;line-height:1.45;color:#dbe5ff}

/* Compact, needed styles only (cards, modal, overlay) */
.badge-pill{width:50px;height:50px;border-radius:50%;display:grid;place-items:center;background:radial-gradient(120% 120% at 30% 30%,rgba(255,255,255,.35),rgba(0,0,0,.15));box-shadow:0 6px 28px rgba(0,0,0,.35),inset 0 0 0 2px rgba(255,255,255,.08);position:relative}
.badge-dot{width:26px;height:26px;border-radius:50%;display:grid;place-items:center;color:#0a1022;font-weight:900;background:#9ec0ff;box-shadow:inset 0 1px 6px rgba(0,0,0,.35),0 4px 12px rgba(0,0,0,.25)}
.badge-tt{position:absolute;left:50%;bottom:70px;transform:translateX(-50%);background:#0f172a;color:#e6edff;border:1px solid rgba(148,163,184,.25);padding:8px 10px;border-radius:8px;font-size:.82rem;white-space:nowrap;opacity:0;pointer-events:none;transition:.15s;box-shadow:0 6px 24px rgba(2,6,23,.45)}
.badge-pill:hover .badge-tt{opacity:1;transform:translate(-50%,-6px)}
.ms-progress{width:100%;height:8px;border-radius:999px;background:rgba(255,255,255,.06)}
.ms-fill{height:100%;border-radius:999px;background:linear-gradient(90deg, <?= htmlspecialchars($currentLevel['color'], ENT_QUOTES, 'UTF-8') ?>, #7c4dff);width:<?= (int)$progress ?>%}
.lvl-chip{display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);padding:6px 10px;border-radius:999px;font-weight:700}
.lvl-dot{width:10px;height:10px;border-radius:999px;background:<?= htmlspecialchars($currentLevel['color'], ENT_QUOTES, 'UTF-8') ?>}

/* Spot daily */
.spot-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px}
.daily-spot{background:rgba(255,255,255,.06);border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,.12);padding:12px}
.ds-head{display:flex;gap:10px;align-items:center}
.ds-icon{width:30px;height:30px;border-radius:50%;display:grid;place-items:center;background:#181f36}
.ds-title{font-weight:700;color:#fff}
.ds-sub{color:#a8b4d7;font-size:.92rem}
.ds-chip{margin-left:auto;background:#1f2848;color:#c7d2fe;border-radius:999px;padding:4px 10px;font-size:.85rem}
.ds-body{margin-top:8px;color:#e9edfa}
.ds-foot{margin-top:10px;display:flex}
.btn-open{background:#7c4dff;color:#fff;font-weight:700;border:0;border-radius:10px;padding:8px 14px;cursor:pointer}
.btn-locked{background:#334155;color:#cbd5e1;border:0;border-radius:10px;padding:8px 14px}

/* All-locked overlay */
.daily-wrap{position:relative}
.daily-wrap.locked-all .spot-grid{filter:blur(3px);pointer-events:none;user-select:none}
.daily-overlay{position:absolute;inset:0;display:none;align-items:center;justify-content:center}
.daily-wrap.locked-all .daily-overlay{display:flex}
.do-panel{background:rgba(15,23,42,.9);border:1px solid rgba(255,255,255,.12);border-radius:14px;box-shadow:0 12px 60px rgba(0,0,0,.55);padding:18px 22px;color:#e6edff;display:flex;gap:10px;align-items:center}
.do-chip{background:#1f2848;color:#c7d2fe;border-radius:999px;padding:4px 10px;font-weight:700}
.do-timer{font-weight:900;color:#fbbf24}

/* Modal */
.spot-modal[hidden]{display:none}
.spot-modal{position:fixed;inset:0;z-index:1000}
.spot-modal-backdrop{position:absolute;inset:0;background:rgba(2,6,23,.65);backdrop-filter:blur(6px)}
.spot-modal-card{position:relative;margin:6vh auto 0;width:min(860px,92vw);background:linear-gradient(180deg, rgba(17,24,39,.95), rgba(6,10,25,.95));border:1px solid rgba(255,255,255,.1);border-radius:16px;box-shadow:0 8px 50px rgba(0,0,0,.45);padding:18px 18px 20px}
.spot-close{position:absolute;top:10px;right:12px;background:transparent;border:0;color:#cbd5e1;font-size:26px;cursor:pointer}
.spot-modal-header{display:flex;align-items:center;gap:10px;margin-bottom:8px}
.spot-modal-icon{width:36px;height:36px;border-radius:50%;display:grid;place-items:center;background:#1f2848}
.sm-from{color:#fff;font-weight:700}
.sm-meta{font-size:.92rem;color:#a8b4d7}
.sm-chip{margin-left:auto;background:#1f2848;color:#c7d2fe;border-radius:999px;padding:4px 10px;font-size:.85rem}
.sm-title{color:#cbd5e1;margin:10px 0}
.sm-body{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:14px;color:#e2e8f0;line-height:1.55;max-height:48vh;overflow:auto}
.sm-choices{display:flex;gap:22px;margin-top:14px;color:#cbd5e1}
.sm-actions{margin-top:14px;display:flex;align-items:center;gap:10px}
.btn-submit{background:#22c55e;color:#041a10;font-weight:800;border:0;border-radius:10px;padding:8px 16px;cursor:pointer}
.btn-submit[disabled]{opacity:.6;cursor:not-allowed}

/* Time-over animation overlay */
.sm-timeover{position:absolute;inset:0;display:grid;place-items:center;background:rgba(2,6,23,.80);backdrop-filter:blur(1px);border-radius:16px;animation:smFadeIn .25s ease-out}
.sm-timeover .inner{display:flex;gap:10px;align-items:center;background:rgba(15,23,42,.95);border:1px solid rgba(148,163,184,.25);padding:12px 16px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.45);animation:smPop .22s ease-out}
.sm-timeover .icon{font-size:20px;animation:smPulse 1.2s infinite}
.sm-timeover .text{font-weight:800;color:#fbbf24}
@keyframes smFadeIn{from{opacity:0}to{opacity:1}}
@keyframes smPop{from{transform:translateY(6px) scale(.98)}to{transform:translateY(0) scale(1)}}
@keyframes smPulse{0%{transform:scale(1)}50%{transform:scale(1.12)}100%{transform:scale(1)}}

/* Onboarding lock (disabled) – we rely on modal JS to lock the page */
.pg-dashboard{filter:none !important; pointer-events:auto !important; user-select:auto !important}


/* Research modals (compact style) */
:root{
  --rs-bg:#0b1224; --rs-card:#0e1833; --rs-elev:#0f1d3f; --rs-txt:#e6edff; --rs-sub:#9fb0d7; --rs-brand:#7c4dff;
  --rs-okay:#10b981; --rs-ring:rgba(148,163,184,.25);
}
.rs-modal[hidden]{display:none}
.rs-modal{position:fixed; inset:0; z-index:2000; display:grid; place-items:center;
  background:radial-gradient(120% 120% at 20% 10%, rgba(124,77,255,.08), transparent 60%),
             radial-gradient(120% 120% at 80% 90%, rgba(34,211,238,.06), transparent 60%),
             rgba(2,6,23,.72); backdrop-filter: blur(8px);}
.rs-card{width:min(1000px,94vw); max-height:88vh; display:flex; flex-direction:column;
  background:linear-gradient(180deg, rgba(15,23,42,.92), rgba(11,18,36,.96)); border:1px solid rgba(255,255,255,.08);
  border-radius:16px; box-shadow:0 18px 70px rgba(0,0,0,.55); color:var(--rs-txt);}
.rs-head{position:sticky; top:0; z-index:2; padding:18px 22px; background:linear-gradient(180deg, rgba(16,24,39,.98), rgba(16,24,39,.90));
  border-bottom:1px solid rgba(255,255,255,.08); display:flex; align-items:center; gap:14px;}
.rs-emoji{font-size:22px}
.rs-title{font-size:1.25rem; font-weight:800}
.rs-sub{margin-left:auto; font-size:.92rem; color:var(--rs-sub)}
.rs-body{padding:18px 22px; overflow:auto;}
.rs-section{background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.08); border-radius:12px; padding:14px; margin-bottom:12px;}
.rs-foot{position:sticky; bottom:0; z-index:2; display:flex; gap:10px; justify-content:flex-end;
  padding:14px 22px; background:linear-gradient(0deg, rgba(16,24,39,.96), rgba(16,24,39,.92)); border-top:1px solid rgba(255,255,255,.08);}
.rs-btn{border:0; border-radius:10px; padding:9px 16px; font-weight:800; cursor:pointer}
.rs-btn.secondary{background:#1f2848; color:#c7d2fe}
.rs-btn.primary{background:var(--rs-brand); color:#fff}
.rs-btn.success{background:var(--rs-okay); color:#062f23}
.rs-msg{margin-top:6px; color:#9fb0d7}
.rs-chip{display:inline-flex; align-items:center; gap:6px; padding:2px 10px; border-radius:999px; font-size:.8rem; background:#1f2848; color:#c7d2fe; border:1px solid rgba(255,255,255,.08)}
.rs-section label{ display:flex; align-items:center; gap:10px; color:var(--rs-txt); line-height:1.35; margin:6px 0; cursor:pointer; }
input[type="checkbox"], input[type="radio"]{ width:18px; height:18px; accent-color:var(--rs-brand); }
.rs-range{ width:260px; vertical-align:middle; }
#ageOut{ display:inline-block; min-width:28px; text-align:center; background:#0d1526; border:1px solid var(--rs-ring); color:#cbd5e1; border-radius:8px; padding:3px 6px; margin-left:6px; }

/* ----- Mobile responsive tweaks ----- */
html.modal-open, body.modal-open { height:100%; overflow:hidden; }

/* Make top stat cards wrap nicely */
@media (max-width: 1024px){
  main.pg-dashboard > div[style*="grid-template-columns:repeat(3,1fr)"]{
    display:grid !important;
    grid-template-columns: repeat(2,1fr) !important;
    gap: 14px !important;
  }
}
@media (max-width: 720px){
  main.pg-dashboard > div[style*="grid-template-columns:repeat(3,1fr)"]{
    grid-template-columns: 1fr !important;
  }
}

/* Training grid: single column on phones */
@media (max-width: 680px){
  .pg-dashboard > div[style*="grid-template-columns:repeat(auto-fit"],
  .pg-dashboard > div[style*="grid-template-columns: repeat(auto-fit"]{
    grid-template-columns: 1fr !important;
  }
}

/* Leaderboard table makes rows readable on small screens */
@media (max-width: 640px){
  table { font-size: 0.95rem; }
  th, td { padding: 12px 12px !important; }
}

/* Daily spot tiles stack */
@media (max-width: 640px){
  .spot-grid{ grid-template-columns: 1fr !important; }
}

/* Spot modal — fit viewport and scroll inside */
.spot-modal-card{
  width: min(860px, 96vw);
  margin: 4vh auto 0;
  max-height: 92vh;
  display:flex; flex-direction:column;
}
/* Reveal link UI inside Spot-the-Phish */
/* Link chip that the user clicks to reveal the real URL */
.reveal-link{
  display:inline-flex;align-items:center;gap:6px;
  padding:2px 8px;border-radius:999px;
  background:rgba(99,102,241,.12);
  color:#c7d2fe;text-decoration:none;border:1px solid rgba(99,102,241,.35);
  transition:.15s ease-in-out;
}
.reveal-link:hover{ background:rgba(99,102,241,.18); border-color:rgba(99,102,241,.55); }
.reveal-link .ext{ opacity:.8; font-size:.92em; }

/* The revealed box showing the true URL */
.reveal-box{
  margin:8px 0 2px 0;padding:10px 12px;
  background:linear-gradient(180deg, rgba(148,163,184,.10), rgba(148,163,184,.06));
  border:1px solid rgba(148,163,184,.28);color:#e5e7eb;border-radius:10px;
  font-size:.92rem;word-break:break-word;
}
.reveal-box .host{color:#bfdbfe;font-weight:800;margin-bottom:2px}
.reveal-box code{
  font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;
  font-size:.92em
}
.reveal-actions{display:flex;gap:8px;margin-top:8px}
.reveal-btn{
  background:rgba(99,102,241,.12);color:#c7d2fe;border:1px solid rgba(99,102,241,.45);
  border-radius:8px;padding:6px 12px;font-weight:700;cursor:pointer;
}
.reveal-btn:hover{ background:rgba(99,102,241,.18); border-color:rgba(99,102,241,.65); }
.reveal-muted{color:#9ca3af;font-size:.85rem}
.sm-body{
  max-height: 54vh;
  overflow:auto;
  -webkit-overflow-scrolling: touch;
  overscroll-behavior: contain;
}
@media (max-width: 640px){
  .sm-choices{ flex-direction:column; gap:10px; }
  .sm-actions{ flex-wrap:wrap; }
}

/* Research modals (Info, Consent, Pre/Post) — make content scrollable */
.rs-card{ width: min(1000px, 96vw); max-height: 92vh; display:flex; flex-direction:column; }
.rs-body{ flex:1 1 auto; min-height: 0; overflow:auto; -webkit-overflow-scrolling: touch; overscroll-behavior: contain; }
.rs-foot{ flex: 0 0 auto; }

/* Make consent range input & chips full width on phones */
@media (max-width: 640px){
  .rs-card{ border-radius:12px; }
  .rs-body{ padding:14px; }
  .rs-section{ padding:12px; }
  .rs-range{ width:100%; }
  .rs-chip{ margin-top:6px; }
  #postOpenBtn{ width:100%; }
  .pg-dashboard{ padding: 20px 12px !important; }
}



#pgToast {
  position: fixed; left: 50%; bottom: 24px; transform: translateX(-50%);
  z-index: 9999; background: rgba(15,23,42,.95); color: #e6edff;
  border: 1px solid rgba(148,163,184,.25); border-radius: 10px;
  padding: 10px 14px; font-weight: 800; box-shadow: 0 8px 30px rgba(0,0,0,.35);
  opacity: 0; pointer-events: none; transition: opacity .2s, transform .2s;
}
#pgToast.show { opacity: 1; transform: translate(-50%, -6px); }
</style>


</head>

<body class="pg-page" data-need-onboard="<?= $NEED_ONBOARD ? '1' : '0' ?>">

<?php
  $PAGE = ['title'=>'Dashboard','active'=>'dashboard','base'=>''];
  include __DIR__ . '/inc/app_topbar.php';
?>

<!-- DEBUG flags: consent=<?= (int)($FLAGS['has_consent'] ?? 0) ?>
     pretest=<?= (int)($FLAGS['has_pretest'] ?? 0) ?>
     need=<?= $NEED_ONBOARD ? '1' : '0' ?> -->


<main class="pg-dashboard" style="max-width:1280px;margin:0 auto;padding:32px 0;">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
    <h1 style="font-size:1.7rem;font-weight:800;color:#fff;margin:0;">Phishing Awareness Training</h1>
    <div style="font-size:1rem;color:#fbbf24;font-weight:700;">
      <?= (int)$overallCompletion ?>% Completion &nbsp; 💰
      <span id="pgXp" data-xp="<?= (int)$myXP ?>"><?= number_format((int)$myXP) ?></span> XP
    </div>
  </div>
  <p style="font-size:1rem;color:#e9edfa;margin-bottom:24px;">Test your phishing detection skills and rise through the ranks!</p>

  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-bottom:28px;">
    <!-- Streak -->
    <div style="background:rgba(16,26,49,0.98);border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,.18);padding:18px 16px;color:#fff;">
      <div style="font-size:1.05rem;color:#e6edff;margin-bottom:8px;">Current Streak</div>
      <div style="font-size:1.2rem;color:#7c4dff;font-weight:700;">
        <span id="streakDays"><?= (int)$streakDays ?></span> day<?= $streakDays==1?'':'s' ?>
      </div>
      <div id="bestStreak" style="margin-top:4px;color:#bdb9ff;font-size:.9rem;">
        Best: <?= (int)$bestStreak ?> day<?= $bestStreak==1?'':'s' ?>
      </div>
      <div style="margin-top:8px;color:#a8b4d7;font-size:.98rem;">Keep going to earn bonus XP!</div>
    </div>

    <!-- Badges -->
    <div style="background:rgba(16,26,49,0.98);border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,.18);padding:18px 16px;color:#fff;">
      <div style="font-size:1.05rem;color:#e6edff;margin-bottom:12px;">Recent Badges</div>
      <div style="display:flex;gap:16px;align-items:center;">
        <?php if (!empty($badges)): foreach ($badges as $b):
          $bName=htmlspecialchars($b['name']??'',ENT_QUOTES,'UTF-8');
          $bWhen=!empty($b['earned_at'])?date('M j, Y',strtotime($b['earned_at'])):''; $bColor=$b['color']?:'#9ec0ff';
          $icon=($b['icon']??'')!==''?$b['icon']:mb_strtoupper(mb_substr($bName,0,1));
        ?>
          <div class="badge-pill" aria-label="<?= $bName ?>">
            <div class="badge-dot" style="background:<?= htmlspecialchars($bColor,ENT_QUOTES,'UTF-8')?>;"><span><?= htmlspecialchars($icon,ENT_QUOTES,'UTF-8')?></span></div>
            <div class="badge-tt"><strong><?= $bName ?></strong><br><span style="opacity:.8;">Earned <?= htmlspecialchars($bWhen,ENT_QUOTES,'UTF-8') ?></span></div>
          </div>
        <?php endforeach; else: ?>
          <div class="badge-pill"><div class="badge-dot"><span>✓</span></div><div class="badge-tt"><strong>Starter</strong><br><span style="opacity:.8;">Earned soon</span></div></div>
          <div class="badge-pill"><div class="badge-dot" style="background:#d8b4fe;"><span>★</span></div><div class="badge-tt"><strong>First Win</strong><br><span style="opacity:.8;">Keep training</span></div></div>
          <div class="badge-pill"><div class="badge-dot" style="background:#86efac;"><span>!</span></div><div class="badge-tt"><strong>Vigilant</strong><br><span style="opacity:.8;">Spot the phish</span></div></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Milestone -->
    <div style="background:rgba(16,26,49,0.98);border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,.18);padding:18px 16px;color:#fff;">
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <div style="font-size:1.05rem;color:#e6edff;">Next Milestone</div>
        <div class="lvl-chip"><span class="lvl-dot"></span> <?= htmlspecialchars($currentLevel['name'], ENT_QUOTES, 'UTF-8') ?></div>
      </div>
      <?php if ($nextLevel): ?>
        <div style="margin:6px 0 8px;font-weight:700;color:#a78bfa;"><?= number_format((int)$xpToNext) ?> XP to <?= htmlspecialchars($nextLevel['name'], ENT_QUOTES, 'UTF-8') ?></div>
        <div class="ms-progress"><div class="ms-fill"></div></div>
      <?php else: ?>
        <div style="margin:6px 0 8px;font-weight:700;color:#34d399;">Max level reached — you're at <span style="color:<?= htmlspecialchars($currentLevel['color'], ENT_QUOTES, 'UTF-8') ?>;"><?= htmlspecialchars($currentLevel['name'], ENT_QUOTES, 'UTF-8') ?></span>!</div>
        <div class="ms-progress"><div class="ms-fill" style="width:100%"></div></div>
      <?php endif; ?>
      <div style="margin-top:10px;color:#a8b4d7;">Rewards</div>
      <ul style="margin:6px 0 0 16px;color:#e6edff;">
        <li>Exclusive badge</li><li>+100 XP bonus on reaching Silver</li><li>Leaderboard visibility</li>
      </ul>
    </div>
  </div>

 

  <!-- Spot the Phish — Daily -->
  <section id="spot-section" style="margin-top:34px;">
    <h2 style="font-size:1.6rem; font-weight:800; color:#fff; margin:0 0 14px 0;">Spot the Phish — Daily</h2>
    <p style="margin:-2px 0 16px; color:#a8b4d7;">
      You get three fresh, randomized tasks each day. Each one locks for 24 hours after answering.
    </p>
    <div id="dailyHost" style="min-height:120px;color:#a8b4d7">Loading daily tasks…</div>
  </section>

  <!-- Spot Modal -->
  <div id="spotModal" class="spot-modal" hidden>
    <div class="spot-modal-backdrop"></div>
    <div class="spot-modal-card">
      <button class="spot-close" aria-label="Close">×</button>
      <div class="spot-modal-header">
        <div class="spot-modal-icon" id="smIcon">📱</div>
        <div>
          <div id="smFrom" class="sm-from">From</div>
          <div id="smMeta" class="sm-meta">meta</div>
        </div>
        <div id="smChannel" class="sm-chip" style="margin-left:auto;">Sms</div>
        <div class="sm-timer" id="smTimer" style="margin-left:12px;color:#fbbf24;font-weight:800">Time left: --s</div>
      </div>

      <h3 id="smTitle" class="sm-title">Title</h3>
      <div id="smBody" class="sm-body"></div>

      <div class="sm-choices">
        <label><input type="radio" name="smChoice" value="phish"> This is <strong>Phish</strong></label>
        <label><input type="radio" name="smChoice" value="legit"> This is <strong>Legit</strong></label>
      </div>

      <div class="sm-actions">
        <button id="smSubmit" class="btn-submit" disabled>Submit</button>
        <span id="smStatus" class="sm-status"></span>
      </div>
    </div>
  </div>

  <!-- Leaderboard -->
  <h2 style="font-size:1.6rem;font-weight:800;color:#fff;margin:48px 0 18px 0;">Leaderboard</h2>
  <div style="background:rgba(255,255,255,0.08);border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,0.12);padding:0;margin-bottom:32px;overflow-x:auto;">
    <div style="display:flex;gap:16px;align-items:center;padding:16px;border-bottom:1px solid rgba(255,255,255,0.08);">
      <div style="background:#181f36;color:#fff;padding:10px 14px;border-radius:10px;font-weight:800;">
        Your Rank: <?= $meRank<=3 ? (['🥇','🥈','🥉'][$meRank-1]) : (int)$meRank ?>
      </div>
      <div style="color:#a8b4d7;">
        <?= htmlspecialchars($meRow['display_name'] ?: $first, ENT_QUOTES, 'UTF-8') ?> —
        <strong style="color:#fff;"><?= number_format((int)$meRow['total_points']) ?> XP</strong>
      </div>
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:1rem;">
      <thead><tr style="background:rgba(255,255,255,0.04);color:#a8b4d7;text-align:left;"><th style="padding:14px 18px;">RANK</th><th style="padding:14px 18px;">PLAYER</th><th style="padding:14px 18px;">XP</th></tr></thead>
      <tbody>
      <?php if (!empty($leadersTop)): foreach ($leadersTop as $row): ?>
        <tr style="border-top:1px solid rgba(255,255,255,0.06);">
          <td style="padding:14px 18px;color:#fff;"><?= ((int)$row['rank']<=3)?(['🥇','🥈','🥉'][(int)$row['rank']-1]):(int)$row['rank'] ?></td>
          <td style="padding:14px 18px;color:#e9edfa;"><?= htmlspecialchars($row['display_name'], ENT_QUOTES, 'UTF-8') ?><?php if ((int)$row['user_id']===$userId): ?><span style="color:#7c4dff;font-weight:700;"> (you)</span><?php endif; ?></td>
          <td style="padding:14px 18px;color:#fff;"><?= number_format((int)$row['total_points']) ?></td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="3" style="padding:24px;color:#a8b4d7;text-align:center;font-size:1.05rem;">No leaderboard data yet. Play modules to earn XP!</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>




  <?php
// ---- Web simulator progress for "Website Impersonation" card ----
$uid = (int)($_SESSION['user_id'] ?? 0);
$webPct = 0;

try {
  // how many simulator levels exist
  $levelsTotal = (int)$pdo->query("SELECT COUNT(*) FROM sim_levels WHERE enabled=1")->fetchColumn();

  if ($uid > 0 && $levelsTotal > 0) {
    // how many levels this user has completed
    $st = $pdo->prepare("SELECT COUNT(DISTINCT level_no) FROM user_level_completions WHERE user_id=?");
    $st->execute([$uid]);
    $levelsDone = (int)$st->fetchColumn();

    $webPct = (int)round(($levelsDone * 100) / $levelsTotal);
  }

  // push that % into the “Website Impersonation” module card
  foreach ($modules as $m) {
    if (strcasecmp(trim((string)$m['title']), 'Website Impersonation') === 0) {
      $modulePercents[(int)$m['id']] = $webPct;
      break;
    }
  }
} catch (Throwable $e) {
  // ignore — card will show 0% if something goes wrong
}
?>

 <!-- Post-Test CTA -->
  <?php if ($showPostTest): ?>
    <section id="posttest-section" style="margin:18px 0 26px">
      <div style="background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.35);border-radius:14px;padding:16px;color:#e6ffef">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
          <div style="font-weight:800;font-size:1.05rem;">🎯 Post-Test unlocked</div>
          <div style="color:#a7f3d0;">Take the post-test to measure your progress and earn bonus XP.</div>
          <button id="postOpenBtn" class="rs-btn success" style="margin-left:auto;background:#10b981;color:#062f23">Start Post-Test</button>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <!-- Training Modules -->
<h2 style="font-size:1.6rem;font-weight:800;color:#fff;margin:32px 0 18px 0;">Training Modules</h2>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px;">
<?php foreach($modules as $module):
  $modId=(int)$module['id']; $percent=(int)($modulePercents[$modId]??0);
  $level=strtolower($module['level']??'');
  $badgeColor= ['beginner'=>'#2563eb','intermediate'=>'#a855f7','advanced'=>'#fbbf24'][$level] ?? '#7c4dff';
  $textColor = ($level==='advanced') ? '#222' : '#fff';
  $titleTrim=trim((string)$module['title']);
  $isEmail=(strcasecmp($titleTrim,'Email Phishing Fundamentals')===0);
  $isSms=(strcasecmp($titleTrim,'SMS & Social Media Scams')===0);
  $isWeb=(strcasecmp($titleTrim,'Website Impersonation')===0);
  if($isEmail) $link="module_mail.php?id={$modId}";
  elseif($isSms) $link="module_sms.php?id={$modId}";
  elseif($isWeb) $link="website_impersonation.php?id={$modId}"; // <-- lower-case
  else $link="module_player.php?id={$modId}";
  $btnLabel=($percent>=100)?'View Again':(($percent>0)?'Resume':'Start');
  $img=htmlspecialchars((string)$module['image_path'],ENT_QUOTES,'UTF-8');
?>
  <div style="background:rgba(255,255,255,0.08);border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,0.12);overflow:hidden;">
    <div style="height:160px;background:url('<?= $img ?>') center/cover no-repeat;"></div>
    <div style="padding:18px 16px;">
      <span style="background:<?= $badgeColor ?>;color:<?= $textColor ?>;border-radius:8px;padding:2px 10px;font-size:.85rem;font-weight:600;">
        <?= htmlspecialchars((string)$module['level'],ENT_QUOTES,'UTF-8') ?>
      </span>


      <div style="font-weight:700;color:#fff;font-size:1.1rem;margin:8px 0 4px 0;">
        <?= htmlspecialchars((string)$module['title'],ENT_QUOTES,'UTF-8') ?>
      </div>

      <div style="color:#a8b4d7;font-size:.98rem;margin-bottom:8px;">
        <?= htmlspecialchars((string)$module['description'],ENT_QUOTES,'UTF-8') ?>
      </div>

      <div style="background:#181f36;border-radius:6px;height:6px;width:100%;margin-bottom:8px;">
        <div
          style="background:<?= $badgeColor ?>;height:6px;border-radius:6px;width:<?= $percent ?>%;"
          <?= $isWeb ? 'data-web-sim-bar' : '' ?>></div>
      </div>

      <span style="color:#a8b4d7;font-size:.9rem;" <?= $isWeb ? 'data-web-sim-text' : '' ?>>
        <?= $percent ?>% completed
      </span>

      <?php if ($NEED_ONBOARD ?? false): ?>
        <a href="#"
           onclick="alert('Please complete the Pre-Test to start training.'); return false;"
           style="float:right;background:<?= $badgeColor ?>;color:<?= $textColor ?>;border-radius:8px;padding:4px 18px;font-weight:700;text-decoration:none;font-size:.95rem;margin-top:4px;">
           Start
        </a>
      <?php else: ?>
        <a href="<?= $link ?>"
           style="float:right;background:<?= $badgeColor ?>;color:<?= $textColor ?>;border-radius:8px;padding:4px 18px;font-weight:700;text-decoration:none;font-size:.95rem;margin-top:4px;">
           <?= $btnLabel ?>
        </a>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>

<!-- tiny updater: keeps Website Impersonation card in sync after a level finish
<script>
(function(){
  const bar = document.querySelector('[data-web-sim-bar]');
  const txt = document.querySelector('[data-web-sim-text]');
  if (!bar || !txt) return;

  function apply(pct){
    const v = Math.max(0, Math.min(100, Number(pct)||0));
    bar.style.width = v + '%';
    txt.textContent = v + '% completed';
  }

  // init from localStorage (if user just completed a level)
  try { apply(localStorage.getItem('pg_sim_progress_pct')); } catch(_){}

  // update when simulator tab updates localStorage
  window.addEventListener('storage', (e)=>{
    if (e.key === 'pg_sim_progress_pct') apply(e.newValue);
  });

  // update when simulator dispatches the custom event
  window.addEventListener('pg:simProgress', (e)=>{
    if (e?.detail?.pct != null) apply(e.detail.pct);
  });
})();
</script> -->


<!-- ================= Onboarding: Info / Consent / Pre-test ================= -->

<!-- Info -->
<div id="mInfo" class="rs-modal" <?= $NEED_ONBOARD ? '' : 'hidden' ?>>
  <div class="rs-card">
    <div class="rs-head">
      <span class="rs-emoji">📘</span>
      <div class="rs-title">Welcome to PhishGuard</div>
      <div class="rs-sub">Research Participation</div>
    </div>
    <div class="rs-body">
      <div class="rs-section">
        <h4>About this study</h4>
        <p>We are evaluating how interactive training impacts phishing awareness. Usage data (e.g., scores, timing) may be analysed anonymously.</p>
      </div>
      <div class="rs-section">
        <h4>Your participation</h4>
        <ul>
          <li>Complete a short consent form and pre-test.</li>
          <li>Train with modules and daily “Spot the Phish”.</li>
          <li>Take a post-test.</li>
        </ul>
      </div>
      <div class="rs-section">
        <h4>Data & privacy</h4>
        <p>No personal content is collected from your mailbox/phone. Only activity within this site is recorded for research.</p>
      </div>
      <div class="rs-msg">To proceed, click Continue.</div>
    </div>
    <div class="rs-foot">
      <button class="rs-btn secondary" id="infoDecline">Cancel</button>
      <button class="rs-btn primary" id="infoContinue">Continue</button>
    </div>
  </div>
</div>

<!-- Consent -->
<div id="mConsent" class="rs-modal" hidden>
  <div class="rs-card">
    <div class="rs-head">
      <span class="rs-emoji">📝</span>
      <div class="rs-title">Consent Form</div>
      <div class="rs-sub"><span class="rs-chip">Required</span></div>
    </div>
    <div class="rs-body">
      <div class="rs-section">
        <label><input type="checkbox" id="c1"> I understand the purpose of this research activity.</label>
        <label><input type="checkbox" id="c2"> I agree my training results can be used anonymously for research.</label>
        <label><input type="checkbox" id="c3"> I know I can stop using the site at any time.</label>
        <label><input type="checkbox" id="c4"> I agree to participate and follow instructions.</label>
      </div>
      <div class="rs-section">
        <h4>Age confirmation</h4>
        <div>Age: <input id="ageInput" type="range" min="16" max="70" value="18" class="rs-range"> <strong id="ageOut">18</strong></div>
      </div>
      <div id="consentMsg" class="rs-msg"></div>
    </div>
    <div class="rs-foot">
      <button class="rs-btn secondary" id="consentBack">Back</button>
      <button class="rs-btn primary" id="consentSave">Save & Continue</button>
    </div>
  </div>
</div>

<!-- Pre-test -->
<div id="mPre" class="rs-modal" hidden>
  <div class="rs-card">
    <div class="rs-head">
      <span class="rs-emoji">🧪</span>
      <div class="rs-title">Pre-Test (quick)</div>
      <div class="rs-sub"><span class="rs-chip">Awards XP</span></div>
    </div>
    <div class="rs-body">
      <?php include __DIR__ . '/partials/pretest_questions.php'; ?>
      <div id="preMsg" class="rs-msg"></div>
    </div>
    <div class="rs-foot">
      <button class="rs-btn primary" id="preSubmit">Submit Pre-Test</button>
    </div>
  </div>
</div>

<!-- Exit / Ineligible modal -->
<div id="mExit" class="rs-modal" hidden>
  <div class="rs-card">
    <div class="rs-head">
      <span class="rs-emoji">🙏</span>
      <div class="rs-title">Participation not possible</div>
      <div class="rs-sub">Eligibility & Consent</div>
    </div>
    <div class="rs-body">
      <div class="rs-section" id="exitMsg">
        <p>Thank you for your interest.</p>
        <p>Unfortunately, you must be 18+ and provide consent to participate in this study.</p>
        <p class="rs-msg">If you believe this is a mistake, please contact the researcher at
          <a href="mailto:S.Khatri3339@student.leedsbeckett.ac.uk">S.Khatri3339@student.leedsbeckett.ac.uk</a>.
        </p>
      </div>
    </div>
    <div class="rs-foot">
      <a class="rs-btn secondary" href="index.php">Back to Home</a>
      <a class="rs-btn primary" href="auth/logout.php">Logout</a>
    </div>
  </div>
</div>

<!-- Post-test -->
<div id="mPost" class="rs-modal" hidden>
  <div class="rs-card">
    <div class="rs-head">
      <span class="rs-emoji">📈</span>
      <div class="rs-title">Post-Test</div>
      <div class="rs-sub"><span class="rs-chip">Awards XP</span></div>
    </div>
    <div class="rs-body">
      <?php include __DIR__ . '/partials/posttest_questions.php'; ?>
      <div id="postMsg" class="rs-msg"></div>
    </div>
    <div class="rs-foot">
      <button class="rs-btn primary" id="postSubmit">Submit Post-Test</button>
    </div>
  </div>
</div>
<!-- ================= Scripts ================= -->

<script>
/** Strict JSON guard: throws if host returns HTML instead of JSON */
async function parseJsonOrThrow(res) {
  const ct = res.headers.get('content-type') || '';
  if (!ct.includes('application/json')) {
    const text = await res.text();
    throw new Error('NON_JSON:' + text.slice(0, 200));
  }
  return res.json();
}

/** Thin wrapper to keep cookies + no-store everywhere */
function jsonFetch(url, opts = {}) {
  const headers = Object.assign({'Accept':'application/json'}, opts.headers || {});
  return fetch(url, Object.assign({cache:'no-store', credentials:'same-origin', headers}, opts));
}
</script>

<script>
// ---------- HUD helpers (XP + streak) ----------
function updateHeaderXp(totalXp){
  const xpEl = document.getElementById('pgXp');
  if (!xpEl) return;
  const n = Number(totalXp);
  if (!Number.isFinite(n)) return;
  xpEl.setAttribute('data-xp', String(n));
  try { xpEl.textContent = n.toLocaleString(); } catch(_) { xpEl.textContent = String(n); }
}

function updateStreakUI(streak, best){
  const sCur  = document.getElementById('streakDays');
  const sBest = document.getElementById('bestStreak');
  if (sCur){
    sCur.textContent = String(streak);
    if (sCur.nextSibling && sCur.nextSibling.nodeType === 3) {
      sCur.nextSibling.textContent = ' day' + (Number(streak) === 1 ? '' : 's');
    }
  }
  if (sBest){
    sBest.textContent = 'Best: ' + (best ?? 0) + ' day' + (Number(best) === 1 ? '' : 's');
  }
}

function toast(msg, ms=1800){
  const el = document.getElementById('pgToast');
  if (!el) return;
  el.textContent = String(msg || '');
  el.classList.add('show');
  clearTimeout(el._tid);
  el._tid = setTimeout(()=> el.classList.remove('show'), ms);
}

// Pull authoritative HUD from server
async function refreshHud(){
  try{
    const r = await fetch('ajax_me.php', { headers: { 'Accept':'application/json' }, cache:'no-store' });
    const j = await r.json();
    if (j && j.ok){
      if (typeof j.total_xp !== 'undefined') updateHeaderXp(j.total_xp);
      if (typeof j.streak_current !== 'undefined') updateStreakUI(j.streak_current, j.streak_best || 0);
    }
  }catch(_){}
}

// Initial sync on load
window.addEventListener('load', refreshHud);

// ---------- Patch fetch: live-sync HUD after XP awards ----------
const _fetch = window.fetch;
window.fetch = async (...args) => {
  const res = await _fetch(...args);

  // Best-effort: look at JSON bodies without breaking non-JSON calls
  try {
    const j = await res.clone().json();
    if (j && j.ok){
      // If backend already sent authoritative numbers, apply them immediately
      if (typeof j.total_xp !== 'undefined') updateHeaderXp(Number(j.total_xp));
      if (typeof j.streak   !== 'undefined') updateStreakUI(Number(j.streak), Number(j.best_streak || 0));

      // Heuristic: if this response likely awarded XP, pull fresh HUD from server
      const awarded =
        (typeof j.awarded_xp !== 'undefined' && Number(j.awarded_xp) > 0) ||
        (typeof j.points     !== 'undefined' && Number(j.points)     > 0) ||
        (typeof j.delta_xp   !== 'undefined' && Number(j.delta_xp)   > 0) ||
        (j.applied === true); // e.g., Spot-the-Phish first-time apply

      if (awarded) Promise.resolve().then(refreshHud);
    }
  } catch(_){ /* non-JSON or irrelevant response */ }

  return res;
};

// ---------- Page lock helper (modals) ----------
function setPageLocked(isLocked){
  document.documentElement.classList.toggle('modal-open', !!isLocked);
  document.body.classList.toggle('modal-open', !!isLocked);
}
</script>

<script>
// Listen for other tabs/pages writing the aggregated completed count
window.addEventListener('storage', function(e){
  if (!e) return; try { if (e.key === 'pg_completed_all') {
    const prev = Number(e.oldValue || localStorage.getItem('pg_completed_all') || '0');
    const val  = Number(e.newValue || '0');
    if (Number.isFinite(val) && Number.isFinite(prev)) {
      // Show unlock toast only when crossing the threshold
      if (prev < 5 && val >= 5) {
        toast('Post-test unlocked — refresh to access it.', 2200);
      }
      // Always reload after a short delay so dashboard percent reflects server state
      setTimeout(()=>location.reload(), 900);
    }
  } } catch(_){}
});
</script>

<!-- Daily “Spot the Phish” modal/app JS (unchanged logic) -->
<script>
(() => {
  const dailyHost = document.getElementById('dailyHost');

  async function refreshDaily(){
    try{
      const res = await fetch('ajax_spot_list.php?_=' + Date.now(), {
        headers:{'Accept':'application/json'}, cache:'no-store'
      });
      const raw = await res.text();
      let data;
      try { data = JSON.parse(raw); }
      catch(e){ dailyHost.textContent = 'Load failed.'; console.error('list parse', raw); return; }
      if (!data.ok){ dailyHost.textContent = data.message || 'Load failed.'; return; }
      renderDaily(data.html || '', !!data.all_locked, data.next_unlock_epoch || 0);
    }catch(e){ dailyHost.textContent='Load failed.'; }
  }

  function renderDaily(html, allLocked, nextEpoch){
    const wrap = document.createElement('div');
    wrap.id = 'dailyWrap';
    wrap.className = 'daily-wrap' + (allLocked ? ' locked-all' : '');
    if (nextEpoch) wrap.dataset.reset = String(nextEpoch);

    // Prevent injected HTML from prematurely closing this script
    const safe = String(html || '').replace(/<\/script/gi, '<\\/script>');

    wrap.innerHTML =
      '<div class="spot-grid">' + safe + '</div>' +
      '<div class="daily-overlay"><div class="do-panel"><span class="do-chip">Daily</span>' +
      '<div>All tasks completed. New set unlocks in <span id="dailyResetTimer" class="do-timer">--:--:--</span></div>' +
      '</div></div>';

    dailyHost.innerHTML = '';
    dailyHost.appendChild(wrap);

    if (allLocked && nextEpoch) startOverlayCountdown(nextEpoch);
    wrap.querySelectorAll('.js-open-spot').forEach(b => b.addEventListener('click', onOpenTask));
  }

  let overlayTid = null;
  function startOverlayCountdown(untilEpoch){
    stopOverlayCountdown();
    const el = document.getElementById('dailyResetTimer');
    const tick = async () => {
      const now = Math.floor(Date.now()/1000);
      let left = Math.max(0, untilEpoch - now);
      const h = String(Math.floor(left/3600)).padStart(2,'0'); left %= 3600;
      const m = String(Math.floor(left/60)).padStart(2,'0');
      const s = String(left%60).padStart(2,'0');
      if (el) el.textContent = `${h}:${m}:${s}`;
      if (untilEpoch <= now){ stopOverlayCountdown(); await refreshDaily(); }
    };
    tick();
    overlayTid = setInterval(tick, 1000);
  }
  function stopOverlayCountdown(){ if (overlayTid){ clearInterval(overlayTid); overlayTid = null; } }

  // ===== modal bits =====
  const modal     = document.getElementById('spotModal');
  const closeBtn  = modal.querySelector('.spot-close');
  const iconEl    = document.getElementById('smIcon');
  const fromEl    = document.getElementById('smFrom');
  const metaEl    = document.getElementById('smMeta');
  const chipEl    = document.getElementById('smChannel');
  const titleEl   = document.getElementById('smTitle');
  const bodyEl    = document.getElementById('smBody');
  const submitBtn = document.getElementById('smSubmit');
  const statusEl  = document.getElementById('smStatus');
  const timerEl   = document.getElementById('smTimer');

  let currentTaskId = null, currentSessionId = null, chosen = '';
  let tickId = null, serverNow = 0, expiresAt = 0;
  let timeOverShown = false;
  let readyToSubmit = false, submitting = false, autoCloseTimer = null;

  function stopTick(){ if (tickId){ clearInterval(tickId); tickId = null; } }
  function setSubmitEnabled(en){ submitBtn.disabled = !en; }

  function showTimeOver(){
    if (timeOverShown) return; timeOverShown = true;
    try{
      modal.querySelectorAll('input[name="smChoice"]').forEach(r => r.disabled = true);
      submitBtn.disabled = true;
      const card = modal.querySelector('.spot-modal-card');
      if (card){
        const ov = document.createElement('div');
        ov.className = 'sm-timeover';
        ov.innerHTML = '<div class="inner"><div class="icon">⏳</div><div class="text">Time over</div></div>';
        card.appendChild(ov);
      }
    }catch(_){/* ignore */}
    stopTick();
    setTimeout(async ()=>{ try{ closeModal(); }catch(_){}; try{ await refreshDaily(); }catch(_){ } }, 1500);
  }

  function updateTimer(){
    if (!serverNow || !expiresAt){ timerEl.textContent = 'Time left: --s'; return; }
    const left = Math.max(0, Math.floor(expiresAt - serverNow));
    timerEl.textContent = 'Time left: ' + left + 's';
    serverNow += 1;
    if (left <= 0){ showTimeOver(); }
  }

  function openModal(task){
    timeOverShown = false;
    try{ modal.querySelectorAll('.sm-timeover').forEach(n=>n.remove()); }catch(_){ }
    iconEl.textContent = task.icon || '✉';
    fromEl.textContent = task.from_line || '';
    metaEl.textContent = task.meta_line || '';
    chipEl.textContent = task.channel_label || '';
    titleEl.textContent = task.title || '';
    bodyEl.innerHTML    = task.body_html || '';
    try { enhanceScenarioLinks(bodyEl); } catch(_){}
    statusEl.innerHTML  = '';

    modal.querySelectorAll('input[name="smChoice"]').forEach(r => {
      r.checked = false; r.disabled = false;
      r.onchange = () => { chosen = r.value; setSubmitEnabled(!!chosen && readyToSubmit && !submitting); };
    });

    currentTaskId   = String(task.id || task.task_id || '');
    currentSessionId= task.session_id || null;
    serverNow       = task.now || 0;
    expiresAt       = task.expires_at || 0;
    readyToSubmit   = !!currentSessionId;
    setSubmitEnabled(false);

    stopTick(); updateTimer(); tickId = setInterval(updateTimer, 1000);
    modal.hidden = false;
    document.documentElement.classList.add('modal-open');
    document.body.classList.add('modal-open');
  }
  function closeModal(){
    stopTick();
    modal.hidden = true;
    readyToSubmit = false;
    setSubmitEnabled(false);
    if (autoCloseTimer){ clearTimeout(autoCloseTimer); autoCloseTimer = null; }
    document.documentElement.classList.remove('modal-open');
    document.body.classList.remove('modal-open');
  }

  closeBtn.addEventListener('click', closeModal);
  modal.querySelector('.spot-modal-backdrop').addEventListener('click', closeModal);

  async function openTaskOnServer(taskId){
    const res = await fetch('ajax_spot_open.php?id=' + encodeURIComponent(taskId) + '&_=' + Date.now(), {
      headers:{'Accept':'application/json'}, cache:'no-store'
    });
    const raw = await res.text();
    let data; try { data = JSON.parse(raw); } catch(e){ throw new Error('OPEN_PARSE'); }
    if (!data.ok || !data.task){
      const err = new Error(data.message || 'Failed to open'); err.code = data.code || 'OPEN_FAILED'; throw err;
    }
    return data.task;
  }
  async function onOpenTask(e){
    const card = e.currentTarget.closest('.daily-spot');
    if (!card || card.getAttribute('data-locked') === '1') return;
    const id = card.getAttribute('data-task-id');
    try { const task = await openTaskOnServer(id); openModal(task); }
    catch(err){ alert(err.message || 'Failed to open task.'); }
  }

  // Disable navigation inside scenario content and allow revealing real URLs
  function enhanceScenarioLinks(root){
    if (!root) return;
    const escapeHtml = (s) => (s??'').toString().replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));
    const makeBox = (href) => {
      const box = document.createElement('div');
      box.className = 'reveal-box';
      let host='';
      try{ if(/^https?:/i.test(href)){ host = new URL(href).host; } }catch(_){ host=''; }
      const hostHtml = host ? `<div class="host">${escapeHtml(host)}</div>` : '';
      box.innerHTML = `${hostHtml}<div class="reveal-muted">Real URL</div><div><code>${escapeHtml(href||'')}</code></div>`;
      const row = document.createElement('div'); row.className='reveal-actions';
      const copyBtn = document.createElement('button');
      copyBtn.className='reveal-btn';
      copyBtn.type='button';
      copyBtn.textContent='📋 Copy';
      copyBtn.addEventListener('click', async ()=>{
        try{
          await navigator.clipboard.writeText(href||'');
          copyBtn.textContent='✓ Copied';
          setTimeout(()=>copyBtn.textContent='📋 Copy',1200);
        }catch(_){/* ignore */}
      });
      row.appendChild(copyBtn);
      box.appendChild(row);
      return box;
    };
    const revealFor = (el) => {
      const href = el.getAttribute('data-real-href') || el.getAttribute('href') || el.getAttribute('data-href') || '';
      let box = el.nextElementSibling;
      if (!box || !box.classList || !box.classList.contains('reveal-box')){
        box = makeBox(href);
        el.insertAdjacentElement('afterend', box);
      } else {
        box.style.display = (box.style.display==='none'?'':'none');
      }
    };
    // Neutralize inline handlers for all descendants
    root.querySelectorAll('[onclick]').forEach(el=>{ try{ el.removeAttribute('onclick'); }catch(_){} });
    // Make all buttons non-submitting by default inside the training content
    root.querySelectorAll('button').forEach(b=>{ try{ if(!b.getAttribute('type')) b.setAttribute('type','button'); }catch(_){} });
    // Disable navigation on anchors and make them revealable
    root.querySelectorAll('a[href]').forEach(a=>{
      const href = a.getAttribute('href') || '';
      a.setAttribute('data-real-href', href);
      a.setAttribute('href', '#');
      a.setAttribute('rel', 'noopener noreferrer nofollow');
      a.setAttribute('role', 'button');
      a.setAttribute('title','Click to reveal real URL');
      a.classList.add('reveal-link');
      // Add a small external indicator if not present
      if (!a.querySelector('.ext')){
        const ext = document.createElement('span');
        ext.className = 'ext';
        ext.textContent = '↗';
        a.appendChild(ext);
      }
      a.removeAttribute('target');
      a.onclick = null;
      a.addEventListener('click', (e)=>{ e.preventDefault(); e.stopPropagation(); revealFor(a); });
    });
    // Also support generic elements that provide a data-href
    root.querySelectorAll('[data-href]').forEach(el=>{
      const href = el.getAttribute('data-href') || '';
      if (!href) return;
      el.setAttribute('data-real-href', href);
      el.style.cursor = el.style.cursor || 'pointer';
      el.addEventListener('click', (e)=>{ e.preventDefault(); e.stopPropagation(); revealFor(el); });
    });
    // Avoid global capture handler to prevent side effects
  }

  async function submitOnce(){
    const body = new URLSearchParams({task_id:currentTaskId, choice:chosen, session_id:currentSessionId});
    const res  = await fetch('ajax_spot_submit.php?_=' + Date.now(), {
      method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body, cache:'no-store'
    });
    const raw = await res.text();
    let data; try { data = JSON.parse(raw); } catch(e){ const err = new Error('SUBMIT_PARSE'); err.raw = raw; throw err; }
    return data;
  }

  document.getElementById('smSubmit').addEventListener('click', async ()=>{
    if(!currentTaskId || !chosen || !currentSessionId || !readyToSubmit || submitting) return;
    submitting = true; setSubmitEnabled(false);
    statusEl.innerHTML = 'Submitting…';

    try{
      let data = await submitOnce();
      if (!data.ok && (data.code === 'NOT_OPEN' || data.code === 'MISSING_SESSION' || data.code === 'SESSION_NOT_FOUND')){
        try { const t = await openTaskOnServer(currentTaskId); currentSessionId = t.session_id || currentSessionId; readyToSubmit = !!currentSessionId; data = await submitOnce(); }
        catch(_){}
      }
      if (!data.ok){
        statusEl.innerHTML = '⚠ ' + (data.message || 'Submit failed.');
        if (data.code === 'EXPIRED' || data.code === 'LOCKED'){
          showTimeOver();
        }
        submitting = false; return;
      }

      const isCorrect = !!data.correct;
      const xp        = Number(data.points || 0);
      const ans       = (data.correct_answer || '').toString().toUpperCase(); // "PHISH"|"LEGIT"
      const whyRaw    = (data.rationale || '').trim();

      const xpLabel = xp > 0 ? `+${xp} XP` : (xp < 0 ? `−${Math.abs(xp)} XP` : `0 XP`);
      const reasons = whyRaw ? whyRaw.split(/[\n;•]+/).map(s => s.trim()).filter(Boolean) : [];
      const whyList = reasons.length
        ? `<ul class="res-list">${reasons.map(r => `<li>${r}</li>`).join('')}</ul>`
        : `<div style="opacity:.8">No explanation provided.</div>`;

      const bannerClass = isCorrect ? 'ok' : 'bad';
      const icon = isCorrect ? '✅' : '❌';

      statusEl.innerHTML = `
        <div class="res-wrap">
          <div class="res-banner ${bannerClass}">
            <div class="res-icon">${icon}</div>
            <div>${isCorrect ? 'Correct' : 'Incorrect'}</div>
            <div class="res-xp ${xp>0?'positive':(xp<0?'negative':'')}">${xpLabel}</div>
          </div>
          <div class="res-cards">
            <div class="res-card">
              <div class="res-title">Correct answer</div>
              <div class="answer-pill ${ans==='PHISH'?'pill-phish':'pill-legit'}">${ans}</div>
            </div>
            <div class="res-card">
              <div class="res-title">Why</div>
              ${whyList}
            </div>
          </div>
        </div>
      `;

      // Prefer total_xp from backend; fallback to adding delta
      try {
        if (typeof data.total_xp !== 'undefined' && data.total_xp !== null) {
          updateHeaderXp(Number(data.total_xp));
        } else {
          const xpEl = document.getElementById('pgXp');
          if (xpEl) {
            const cur = parseInt(xpEl.getAttribute('data-xp') || xpEl.textContent.replace(/[^0-9\-]/g,''), 10) || 0;
            const next = cur + xp;
            updateHeaderXp(next);
          }
        }
      } catch (_){}

      modal.querySelectorAll('input[name="smChoice"]').forEach(r => r.disabled = true);
      stopTick();

      if (autoCloseTimer) clearTimeout(autoCloseTimer);
      autoCloseTimer = setTimeout(async ()=>{
        closeModal();
        await refreshDaily();
      }, 5500);

    }catch(e){
      statusEl.textContent = (e.message === 'SUBMIT_PARSE') ? '⚠ Submit parse error.' : '⚠ Submit failed.';
    }finally{
      submitting = false;
    }
  });

  refreshDaily();
})();
</script>

<!-- Onboarding flow -->
<script>
(() => {
  // const needOnboard=document.body.getAttribute('data-need-onboard')==='1';
  const qs=(s,r=document)=>r.querySelector(s);
  const mInfo=qs('#mInfo'), mConsent=qs('#mConsent'), mPre=qs('#mPre'), mExit=qs('#mExit'), mPost=qs('#mPost');
  const exitMsg=qs('#exitMsg');

  // modal lock helpers (only lock when any research modal is visible)
  const anyResearchModalOpen=()=>[mInfo,mConsent,mPre,mPost].some(el=>el && !el.hidden);
  const showModal=(el)=>{ if(!el) return; el.hidden=false; setPageLocked(true); };
  const hideModal=(el)=>{ if(!el) return; el.hidden=true; if(!anyResearchModalOpen()) setPageLocked(false); };

  const infoContinue=qs('#infoContinue'), infoDecline=qs('#infoDecline');
  const consentBack=qs('#consentBack'), consentSave=qs('#consentSave');
  const ageInput=qs('#ageInput'), ageOut=qs('#ageOut'), consentMsg=qs('#consentMsg');
  const preSubmit=qs('#preSubmit'), preMsg=qs('#preMsg');

  if(ageInput&&ageOut){ ageOut.textContent=ageInput.value; ageInput.addEventListener('input',()=>ageOut.textContent=ageInput.value); }
  function goConsent(){ hideModal(mInfo); showModal(mConsent); }
  function goPre(){ hideModal(mConsent); showModal(mPre); preStartedMs=Date.now(); }
  function endOnboard(){ hideModal(mInfo); hideModal(mConsent); hideModal(mPre); }

  // --- Exit / Ineligible modal helper ---
  function showExit(kind){
    if(exitMsg){
      let html = '';
      switch(kind){
        case 'decline':
          html = `<p>Thanks for considering it.</p><p>You chose not to participate at this time. Participation requires consent.</p>`; break;
        case 'no-consent':
          html = `<p>Thanks for reviewing the form.</p><p>To participate, you must provide consent. You can return anytime if you change your mind.</p>`; break;
        case 'under-18':
          html = `<p>Thank you for your interest.</p><p>Unfortunately, you must be 18 years or older to take part in this study.</p>`; break;
        case 'pre-ineligible':
        case 'post-ineligible':
          html = `<p>Thanks for completing the ${kind==='pre-ineligible'?'pre':'post'}-test screen.</p><p>Participation requires confirming you are 18+ and that you consent.</p>`; break;
        default:
          html = `<p>Thank you for your interest.</p><p>Unfortunately, you must be 18+ and provide consent to participate in this study.</p>`;
      }
      html += `<p class="rs-msg">If you believe this is a mistake, please contact the researcher at
        <a href="mailto:S.Khatri3339@student.leedsbeckett.ac.uk">S.Khatri3339@student.leedsbeckett.ac.uk</a>.</p>`;
      exitMsg.innerHTML = html;
    }
    hideModal(mInfo); hideModal(mConsent); hideModal(mPre); if(mPost) hideModal(mPost);
    showModal(mExit);
  }

  if(infoContinue) infoContinue.addEventListener('click',goConsent);
  if(infoDecline) infoDecline.addEventListener('click',()=>showExit('decline'));

  if(consentBack) consentBack.addEventListener('click',()=>{ hideModal(mConsent); showModal(mInfo); });
  if(consentSave) consentSave.addEventListener('click', async ()=>{
    const c1=qs('#c1').checked, c2=qs('#c2').checked, c3=qs('#c3').checked, c4=qs('#c4').checked;
    const age=ageInput?ageInput.value:''; consentMsg.textContent='';
    if(!age || +age<18){ showExit('under-18'); return; }
    if(!(c1&&c2&&c3&&c4)){ showExit('no-consent'); return; }
    try{
      const body=new URLSearchParams({read_info:1,consent:1,age});
      const res=await fetch('ajax_save_consent.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});
      const data=await res.json(); if(!data.ok){ consentMsg.textContent=data.message||'Save failed.'; return; } goPre();
    }catch(e){ consentMsg.textContent='Save failed.'; }
  });

  let preStartedMs=0;

  function collectPre(){
    const pickRadio = (n)=> {
      const el = document.querySelector(`input[name="${n}"]:checked`);
      return el ? el.value : null;
    };
    const pickMany = (n)=> {
      const els = Array.from(document.querySelectorAll(`input[name="${n}[]"]:checked`));
      return els.length ? els.map(x=>x.value).join(',') : null;
    };

    const out = {};
    out['elig_18']      = pickRadio('elig_18');
    out['elig_consent'] = pickRadio('elig_consent');

    // q1..q10 only; q2 is a checkbox group named q2[]
    out['q1']  = pickRadio('q1');
    out['q2']  = pickMany('q2');   // returns comma-joined or null
    out['q3']  = pickRadio('q3');
    out['q4']  = pickRadio('q4');
    out['q5']  = pickRadio('q5');
    out['q6']  = pickRadio('q6');
    out['q7']  = pickRadio('q7');
    out['q8']  = pickRadio('q8');
    out['q9']  = pickRadio('q9');
    out['q10'] = pickRadio('q10');

    return out;
  }

  if(preSubmit) preSubmit.addEventListener('click', async ()=>{
    preSubmit.disabled=true; preMsg.textContent='Submitting...';
    const answers=collectPre();

    // hard completeness check client-side
    const required = ['elig_18','elig_consent','q1','q2','q3','q4','q5','q6','q7','q8','q9','q10'];
    const miss = required.filter(k => !answers[k] || String(answers[k]).trim()==='');
    if (miss.length) {
      preMsg.textContent='Please answer all questions.';
      preSubmit.disabled=false;
      return;
    }
    if(answers['elig_18']!=='yes' || answers['elig_consent']!=='yes'){ showExit('pre-ineligible'); preSubmit.disabled=false; preMsg.textContent=''; return; }

    try{
      const body=new URLSearchParams({kind:"pre", started_ms:preStartedMs, answers:JSON.stringify(answers)});
      const res=await fetch('ajax_test_submit.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});
      const data=await res.json();
      if(!data.ok){ preMsg.textContent=data.message||'Submit failed.'; preSubmit.disabled=false; return; }
      preMsg.textContent=`Saved! Score ${data.score}/${data.total}. +${data.awarded_xp||0} XP`; setTimeout(()=>location.reload(),700);
    }catch(e){ preMsg.textContent='Submit failed.'; preSubmit.disabled=false; }
  });

  async function boot(){
    try{
      const res=await fetch('ajax_onboarding_status.php',{headers:{'Accept':'application/json'}});
      const data=await res.json(); if(!data.ok) return;
      if(!data.has_consent){ showModal(mInfo); return; }
      if(!data.has_pretest){ showModal(mPre); preStartedMs=Date.now(); return; }
    }catch(e){}
  }
  window.addEventListener('load',boot);
})();
</script>

<!-- Tiny “all questions answered” guard for inline forms -->
<script>
(function () {
  document.addEventListener('click', function (e) {
    const btn  = e.target.closest('[data-submit]');
    if (!btn) return;

    const form = btn.closest('form');
    if (!form) return;

    const kind  = (form.dataset.kind || 'pre').toLowerCase();
    const names = kind === 'pre'
      ? ['q1','q2','q3','q4','q5','q6','q7','q8','q9','q10']
      : ['pq1','pq2','pq3','pq4','pq5','pq6','pq7','pq8','pq9','pq10'];

    const has = (name) => {
      // If radios/checkboxes exist, require at least one :checked (handles q2[])
      const choiceEl = form.querySelector(`[name="${name}"]`) || form.querySelector(`[name="${name}[]"]`);
      if (choiceEl && /^(radio|checkbox)$/i.test(choiceEl.type)) {
        return !!form.querySelector(`[name="${name}"]:checked, [name="${name}[]"]:checked`);
      }
      const el = form.querySelector(`[name="${name}"]`) || form.querySelector(`[name="${name}[]"]`);
      return !!(el && el.value && el.value.trim());
    };

    const missing = names.filter(n => !has(n));
    if (missing.length) {
      e.preventDefault();
      alert('Please answer all questions before submitting.');
    }
  });
})();
</script>

<!-- Post-test JS -->
<script>
(() => {
  const mPost      = document.getElementById('mPost');
  const postMsg    = document.getElementById('postMsg');
  const postSubmit = document.getElementById('postSubmit');
  if (!mPost) return;

  // Hook up the CTA button to open the Post-Test modal
  const postOpenBtn = document.getElementById('postOpenBtn');
  if (postOpenBtn) {
    postOpenBtn.addEventListener('click', ()=>{
      try { mPost.hidden = false; setPageLocked(true); } catch(_) { mPost.hidden = false; }
      // start timing once visible (the MutationObserver below will capture this)
    });
  }

  const show = el => el && (el.hidden = false);

  function collectPost() {
    const pick1 = n => {
      const el = document.querySelector(`input[name="${n}"]:checked`);
      return el ? el.value : null;
    };

    const out = {};
    const g1 = pick1('pq_elig_18'); if (g1) out['pq_elig_18'] = g1;
    const g2 = pick1('pq_consent'); if (g2) out['pq_consent'] = g2;
    for (let i=1;i<=10;i++) {
      const v = pick1(`pq${i}`);
      if (v !== null && v !== '') out[`pq${i}`] = v;
    }
    return out;
  }

  if (postSubmit) {
    let startedMs = 0;
    const obs = new MutationObserver(()=>{ if (!mPost.hidden && !startedMs) startedMs = Date.now(); });
    obs.observe(mPost,{attributes:true,attributeFilter:['hidden']});

    postSubmit.addEventListener('click', async ()=>{
      postSubmit.disabled = true;
      postMsg.textContent = 'Submitting...';

      try {
        const a = collectPost();

        const required = ['pq1','pq2','pq3','pq4','pq5','pq6','pq7','pq8','pq9','pq10'];
        const missing  = required.filter(k => !a[k] || String(a[k]).trim()==='');
        if (a['pq_elig_18'] !== 'yes' || a['pq_consent'] !== 'yes') {
          postMsg.textContent = 'You must be 18+ and provide consent to continue.';
          postSubmit.disabled = false;
          return;
        }
        if (missing.length) {
          postMsg.textContent = 'Please answer all questions.';
          postSubmit.disabled = false;
          return;
        }

        const body = new URLSearchParams({ kind:'post', started_ms: startedMs, answers: JSON.stringify(a) });
        const res  = await jsonFetch('ajax_test_submit.php', {
          method:'POST',
          headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},
          body,
          credentials:'same-origin'
        });
        const data = await parseJsonOrThrow(res);

        if (!data.ok) {
          postMsg.textContent = data.message || 'Submit failed.';
          postSubmit.disabled = false;
          return;
        }

        const delta = (data.xp ?? data.awarded_xp ?? data.xp_delta ?? 0);
        const total = (data.xp_total ?? null);
        if (total != null) updateHeaderXp(Number(total));

        toast(total != null ? `Post-test saved. +${Number(delta)} XP (Total: ${total})`
                            : `Post-test saved. +${Number(delta)} XP`);
        postMsg.textContent = `Saved! Score ${data.score}/${data.total}. +${Number(delta)} XP`;
        setTimeout(()=>location.reload(), 700);
      } catch (e) {
        postMsg.textContent = 'Submit failed.';
        postSubmit.disabled = false;
      }
    });
  }
})();
</script>

<div id="pgToast"></div>
</body>
</html>


                
