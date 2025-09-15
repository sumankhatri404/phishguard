<?php
declare(strict_types=1);
/**
 * website_impersonation.php
 * - DB-driven scenarios + clues
 * - Server-enforced locking (prev-level prerequisite by default)
 * - XP granted once per level (user_level_completions) + toast
 * - Hint popover shows ONLY when clicking the Hint button
 * - Username pulled from session/DB + logout dropdown
 */


session_start();

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/user.php'; // <- sets $first and $initial

/* ---------- tiny utils ---------- */
if (!function_exists('h')) {
  function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
function tbl_exists(PDO $pdo, string $t): bool {
  $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
  $q->execute([$t]); return (int)$q->fetchColumn()>0;
}

/* ---------- ensure completions table ---------- */
function ensure_user_level_completions(PDO $pdo): void {
  // harmless if schema already exists with extra columns (MySQL ignores diffs with IF NOT EXISTS)
  $pdo->exec("CREATE TABLE IF NOT EXISTS user_level_completions (
      user_id INT NOT NULL,
      level_no INT NOT NULL,
      completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id, level_no),
      KEY idx_user (user_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* ---------- AJAX: finish level ---------- */
if (($_GET['ajax'] ?? '') === 'finish' && $_SERVER['REQUEST_METHOD']==='POST') {
  header('Content-Type: application/json; charset=utf-8');
  $uid=(int)($_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? 0));
  if ($uid<=0) { http_response_code(401); echo json_encode(['ok'=>false,'err'=>'auth']); exit; }

  $levelNo=(int)($_POST['level_no'] ?? 0);
  $scenarioId=(int)($_POST['scenario_id'] ?? 0);

  try {
    $L=$pdo->prepare("SELECT level_no,title,xp_reward,order_index FROM sim_levels WHERE enabled=1 AND level_no=? LIMIT 1");
    $L->execute([$levelNo]); $lvl=$L->fetch(PDO::FETCH_ASSOC);
    if(!$lvl) throw new RuntimeException('Level not found');

    ensure_user_level_completions($pdo);
    $ins=$pdo->prepare("INSERT IGNORE INTO user_level_completions (user_id, level_no) VALUES (?,?)");
    $ins->execute([$uid,$levelNo]); $awardedNow=($ins->rowCount()>0);

    $xpAwarded=0;
    if($awardedNow){
      $xpAwarded=max(0,(int)$lvl['xp_reward']);
      pg_add_xp($pdo,$uid,XP_MODULE_SPOT,$xpAwarded);
    }

    // next level
    $nq=$pdo->prepare("
      SELECT level_no
      FROM sim_levels
      WHERE enabled=1
        AND order_index > (SELECT order_index FROM sim_levels WHERE level_no=?)
      ORDER BY order_index ASC, level_no ASC
      LIMIT 1
    ");
    $nq->execute([$levelNo]); $next=(int)($nq->fetchColumn() ?: 0);

    // aggregated completions (used for unlocking post-test etc.)
    $completedAll = 0;
    try {
      $st = $pdo->prepare("SELECT COUNT(DISTINCT case_id) FROM training_mail_progress WHERE user_id=?");
      $st->execute([$uid]); $completedAll += (int)$st->fetchColumn();

      $st = $pdo->prepare("SELECT COUNT(DISTINCT case_id) FROM training_sms_progress WHERE user_id=?");
      $st->execute([$uid]); $completedAll += (int)$st->fetchColumn();

      $st = $pdo->prepare("SELECT COUNT(DISTINCT case_id) FROM training_web_progress WHERE user_id=?");
      $st->execute([$uid]); $completedAll += (int)$st->fetchColumn();

      $st = $pdo->prepare("SELECT COUNT(DISTINCT level_no) FROM user_level_completions WHERE user_id=?");
      $st->execute([$uid]); $completedAll += (int)$st->fetchColumn();
    } catch (Throwable $e) { $completedAll = 0; }

    // ---- simulator-only progress metrics for the Training card (returned for server truth) ----
    $levelsTotal = 0;
    $levelsDone  = 0;
    try {
      $levelsTotal = (int)$pdo->query("SELECT COUNT(*) FROM sim_levels WHERE enabled=1")->fetchColumn();
      $st = $pdo->prepare("SELECT COUNT(DISTINCT level_no) FROM user_level_completions WHERE user_id=?");
      $st->execute([$uid]);
      $levelsDone = (int)$st->fetchColumn();
    } catch (Throwable $e) { /* ignore */ }
    $moduleProgressPct = $levelsTotal ? (int)round(($levelsDone * 100) / $levelsTotal) : 0;

    echo json_encode([
      'ok'=>true,
      'awarded_now'=>$awardedNow,
      'xp_awarded'=>$xpAwarded,
      'next_level'=>$next,
      'completed_all'=>$completedAll,

      // these are still returned (dashboard will recalc from DB on load)
      'levels_total' => $levelsTotal,
      'levels_done'  => $levelsDone,
      'module_progress_pct' => $moduleProgressPct,

      'message'=>$awardedNow ? "🎉 Level {$levelNo} complete • +{$xpAwarded} XP"
                             : "Level {$levelNo} already completed — no XP this time."
    ]);
  } catch(Throwable $e){
    http_response_code(500); echo json_encode(['ok'=>false,'err'=>$e->getMessage()]);
  }
  exit;
}

/* ---------- data loaders ---------- */
function latest_published(PDO $pdo, int $levelNo): ?array {
  $st=$pdo->prepare("SELECT * FROM sim_scenarios WHERE level_no=? AND status='published' ORDER BY version DESC, id DESC LIMIT 1");
  $st->execute([$levelNo]); return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function scenario_has_clues(PDO $pdo, int $scenarioId): bool {
  $st=$pdo->prepare("SELECT COUNT(*) FROM sim_clues WHERE scenario_id=?");
  $st->execute([$scenarioId]); return (int)$st->fetchColumn()>0;
}
function latest_with_clues(PDO $pdo, int $levelNo): ?array {
  $st=$pdo->prepare("SELECT s.* FROM sim_scenarios s
                     JOIN (SELECT scenario_id,COUNT(*) c FROM sim_clues GROUP BY scenario_id) k
                       ON k.scenario_id=s.id
                     WHERE s.level_no=? AND s.status='published'
                     ORDER BY s.version DESC, s.id DESC
                     LIMIT 1");
  $st->execute([$levelNo]); return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function load_clues(PDO $pdo, int $scenarioId): array {
  $st=$pdo->prepare("SELECT id,clue_key,title,css_selector,edu_key,sort_order,required
                     FROM sim_clues WHERE scenario_id=?
                     ORDER BY sort_order,id");
  $st->execute([$scenarioId]); return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
function load_edu(PDO $pdo): array {
  $rows=$pdo->query("SELECT edu_key,title,why_json,action_text,icon_class FROM sim_edu")->fetchAll(PDO::FETCH_ASSOC);
  $out=[]; foreach($rows as $r){
    $why=json_decode($r['why_json'],true); if(!is_array($why)) $why=[];
    $out[$r['edu_key']]=[
      'title'=>$r['title'],
      'why'=>array_values($why),
      'action'=>$r['action_text'],
      'icon'=>$r['icon_class']
    ];
  }
  return $out;
}
function build_levels_bundle(PDO $pdo): array {
  $levels=$pdo->query("SELECT level_no,slug,title,COALESCE(subtitle,'') subtitle,difficulty,icon_class,xp_reward,order_index,prerequisite_level_no,min_xp_required,enabled
                       FROM sim_levels
                       WHERE enabled=1
                       ORDER BY order_index,level_no")->fetchAll(PDO::FETCH_ASSOC);
  $bundle=[];
  foreach($levels as $L){
    $lv=(int)$L['level_no']; $pick=latest_published($pdo,$lv); $chosen=$pick; $fallback=false;
    if($pick && !scenario_has_clues($pdo,(int)$pick['id'])){ $fb=latest_with_clues($pdo,$lv); if($fb){$chosen=$fb; $fallback=true;} }
    if(!$chosen) $chosen=['id'=>0,'url_in_bar'=>'','show_padlock'=>0,'show_not_secure'=>0,'countdown_seconds'=>0,'content_html'=>'','hint_order_json'=>null];
    $clues=$chosen['id']?load_clues($pdo,(int)$chosen['id']):[];
    $hint=[]; if(!empty($chosen['hint_order_json'])){ $t=json_decode($chosen['hint_order_json'],true); if(is_array($t)) $hint=array_values($t); }
    if(!$hint && $clues) $hint=array_values(array_map(fn($c)=>$c['clue_key'],$clues));

    $bundle[]=[
      'level_no'=>$lv,
      'meta'=>[
        'title'=>$L['title'],'subtitle'=>$L['subtitle'],'difficulty'=>$L['difficulty'],
        'icon_class'=>$L['icon_class'],'xp_reward'=>(int)$L['xp_reward'],'order_index'=>(int)$L['order_index'],
        'prerequisite_level_no'=>$L['prerequisite_level_no']!==null?(int)$L['prerequisite_level_no']:null,
        'min_xp_required'=>(int)$L['min_xp_required']
      ],
      'scenario'=>[
        'id'=>(int)$chosen['id'],'url'=>(string)$chosen['url_in_bar'],'show_padlock'=>((int)$chosen['show_padlock']===1),
        'show_not_secure'=>((int)$chosen['show_not_secure']===1),'countdown'=>(int)$chosen['countdown_seconds'],
        'html'=>(string)$chosen['content_html'],'hint_order'=>$hint,'used_fallback'=>$fallback
      ],
      'clues'=>$clues
    ];
  }
  return $bundle;
}
function load_unlocks(PDO $pdo, int $userId, array $bundle): array {
  $completed=[]; if($userId>0){ ensure_user_level_completions($pdo); $st=$pdo->prepare("SELECT level_no FROM user_level_completions WHERE user_id=?"); $st->execute([$userId]); $completed=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN)); }
  $xp=($userId>0)?pg_total_xp($pdo,$userId):0;

  $sorted=$bundle; usort($sorted, fn($a,$b)=>($a['meta']['order_index']<=>$b['meta']['order_index'])?:($a['level_no']<=>$b['level_no']));
  $chain=array_values(array_map(fn($p)=>(int)$p['level_no'],$sorted));

  $unlocked=[];
  foreach($sorted as $p){
    $ln=(int)$p['level_no']; $meta=$p['meta']; $req=$meta['prerequisite_level_no'];
    if($req===null){ $idx=array_search($ln,$chain,true); $req = ($idx!==false && $idx>0) ? $chain[$idx-1] : null; }
    $okByXP = ($xp >= (int)$meta['min_xp_required']);
    $okByPre= ($req===null) ? true : in_array($req,$completed,true);
    $unlocked[$ln] = ($okByXP && $okByPre) ? 1 : 0;
  }
  return ['completed'=>$completed,'unlocked'=>$unlocked,'xp'=>$xp];
}

/* ---------- payloads ---------- */
$uid=(int)($_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? 0));
$LEVELS = build_levels_bundle($pdo);
$EDU    = load_edu($pdo);
$USTATE = load_unlocks($pdo,$uid,$LEVELS);
$shouldClear = isset($_GET['clear']) && $_GET['clear']==='1';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<title>PhishGuard</title>
<link rel="icon" type="image/svg+xml"
        href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='12' fill='%236d28d9'/%3E%3Cpath d='M18 38l14-20 14 20-14 8z' fill='white'/%3E%3C/svg%3E">

<meta name="viewport" content="width=device-width,initial-scale=1"/>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
  /* ---------- base + palette ---------- */
  :root{
    --bg1:#0b1020; --bg2:#0a0e19; --surface:#0f172a; --surface-2:#111827;
    --border:#1f2937; --text:#e5e7eb; --muted:#94a3b8;
    --brand:#6d28d9; --brand-2:#2563eb;
    --progress-1:#22c55e; --progress-2:#16a34a;
  }
  *{box-sizing:border-box;font-family:"Inter",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
  html{font-size:14px}
  body{margin:0;color:var(--text);background:
    radial-gradient(70rem 70rem at 15% -20%, rgba(109,40,217,.16), transparent 60%),
    radial-gradient(60rem 60rem at 110% -10%, rgba(37,99,235,.14), transparent 60%),
    linear-gradient(180deg,var(--bg1),var(--bg2));
    min-height:100vh; padding:clamp(10px,1.2vw,16px)}
  .container{max-width:1120px;margin:0 auto}

  /* ---------- topbar ---------- */
  .pg-topbar{max-width:1120px;margin:0 auto 10px}
  .pg-topbar .inner{display:flex;align-items:center;justify-content:space-between;color:#cbd5e1;font-weight:700;gap:10px;flex-wrap:wrap}
  .pg-brand{color:#e5e7eb;text-decoration:none;font-weight:800}
  .pg-nav{display:flex;gap:14px;flex-wrap:wrap}
  .pg-nav a{color:#9aa6c3;text-decoration:none;font-weight:700}
  .pg-nav a.active{color:#e5e7eb}
  .pg-user{position:relative;display:flex;align-items:center;gap:8px}
  .pg-avatar{background:#101827;color:#fff;border-radius:50%;width:32px;height:32px;display:grid;place-items:center;font-weight:700;border:1px solid rgba(255,255,255,.15);cursor:pointer}
  .pg-user-menu{display:none;position:absolute;right:0;top:40px;min-width:150px;background:#0b1323;border:1px solid rgba(255,255,255,.08);border-radius:10px;box-shadow:0 8px 20px rgba(0,0,0,.45);z-index:100}
  .pg-user-menu a{display:block;padding:10px 14px;color:#e5e7eb;text-decoration:none;font-weight:600}
  .pg-user-menu a:hover{background:rgba(255,255,255,.06)}

  /* ---------- header strip ---------- */
  .header{color:#fff;background:linear-gradient(90deg,var(--brand),var(--brand-2));padding:clamp(10px,1.2vw,18px);border-radius:14px;box-shadow:0 18px 45px rgba(0,0,0,.35);margin-bottom:14px;text-align:center}
  .header h1{font-size:clamp(1rem,1vw + .9rem,1.45rem);font-weight:800}
  .header p{color:#cbd5e1;font-size:clamp(.8rem,.2vw + .78rem,.96rem);margin-top:4px}

  /* ---------- level chips (scrollable on phone) ---------- */
  .level-selector{display:flex;gap:12px;flex-wrap:wrap;margin:14px 0 16px}
  @media (max-width:740px){
    .level-selector{flex-wrap:nowrap;overflow-x:auto;-webkit-overflow-scrolling:touch;scrollbar-width:none}
    .level-selector::-webkit-scrollbar{display:none}
  }
  .level-btn{padding:8px 12px;border-radius:12px;background:#0f172a;border:1px solid var(--border);color:#e5e7eb;display:inline-flex;align-items:center;gap:8px;font-weight:700;cursor:pointer;transition:all .2s}
  .level-btn .difficulty{background:#1f2937;color:#fbbf24;padding:2px 8px;border-radius:999px;font-size:.75rem;position:relative}
  .level-btn.active{border-color:#7c3aed;background:linear-gradient(180deg,rgba(124,58,237,.16),rgba(37,99,235,.16));box-shadow:0 8px 22px rgba(79,70,229,.35);transform:translateY(-1px)}
  .level-btn.locked{opacity:.55;filter:grayscale(12%);pointer-events:none}
  .level-btn.locked .difficulty{padding-right:20px}
  .level-btn.locked .difficulty::after{content:"\f023";font-family:"Font Awesome 6 Free";font-weight:900;position:absolute;right:6px;top:50%;transform:translateY(-50%);font-size:.78rem;color:#fbbf24}
  .level-btn.completed{box-shadow:inset 0 0 0 2px rgba(34,197,94,.18)}

  /* ---------- layout: grid ---------- */
  .browser-simulator{
    display:grid;
    grid-template-columns:minmax(320px,1.35fr) minmax(300px,1fr);
    gap:16px; margin-bottom:16px
  }
  .browser-container,.learning-panel{
    background:var(--surface);border-radius:14px;box-shadow:0 18px 50px rgba(0,0,0,.45);
    overflow:hidden; height:min(66vh,600px); display:flex;flex-direction:column
  }
  .learning-panel{padding:14px}
  @media (max-width:1024px){
    .browser-container,.learning-panel{height:min(62vh,560px)}
  }
  @media (max-width:900px){
    .browser-simulator{grid-template-columns:1fr}
    .browser-container,.learning-panel{height:auto}
    .browser-content{max-height:56vh;overflow:auto}
    .clue-list{max-height:42vh;overflow:auto}
  }

  /* ---------- toolbar ---------- */
  .browser-toolbar{background:#111827;border-bottom:1px solid var(--border);padding:8px 10px;display:flex;align-items:center;gap:8px}
  .btn-round{background:#1f2937;color:#cbd5e1;border:1px solid var(--border);width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center}
  .url-bar{flex:1;background:#0b1220;border:1px solid var(--border);border-radius:16px;padding:4px 8px;display:flex;align-items:center;gap:8px;min-width:0}
  .padlock{color:#22c55e}
  .url-text{flex:1;min-width:0;font:600 .86rem ui-monospace,Menlo,monospace;color:#e2e8f0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .not-secure{display:none;background:#3f1b2a;border:1px solid #5b2336;color:#fda4af;padding:2px 8px;border-radius:999px;font-size:.74rem;font-weight:800}
  .not-secure.show{display:inline-block}
  @media (max-width:480px){
    .btn-round{width:24px;height:24px}
    .url-text{font-size:.78rem}
  }

  /* ---------- mock site ---------- */
  .browser-content{flex:1;overflow:auto;background:#0b1020}
  .website{height:100%;background:var(--surface-2);padding:14px}
  .website-container{max-width:560px;margin:0 auto}
  @media (max-width:600px){ .website-container{max-width:100%} }

  .brand-header{padding:10px 0;border-bottom:1px solid var(--border);text-align:center}
  .brand-logo{font-size:1.2rem;font-weight:800;color:#c7d2fe}
  .brand-slogan{color:#93c5fd;opacity:.85;font-size:.78rem}
  .login-form{max-width:360px;margin:16px auto;padding:12px;background:#0b1220;border:1px solid var(--border);border-radius:12px}
  .urgency-banner{background:#2b1520;color:#fda4af;border:1px solid #3f1b2a;padding:10px;border-radius:10px;text-align:center;font-weight:800;font-size:.82rem;margin:12px 0}
  .form-group{margin-bottom:10px}
  .form-group label{display:block;margin-bottom:4px;color:#9aa6c3;font:700 .8rem/1 Inter}
  .form-control{width:100%;padding:8px 10px;border-radius:8px;border:1px solid var(--border);background:#0f172a;color:#e5e7eb}
  .btn-login{width:100%;padding:10px;border:none;border-radius:10px;font-weight:800;background:#fbbf24;color:#111827;margin-top:8px;cursor:pointer}
  .footer-links{text-align:center;margin-top:12px;color:#9aa6c3;font-size:.78rem}
  .footer-links a{color:#93c5fd;text-decoration:underline}

  /* ---------- panel ---------- */
  .panel-title{display:flex;align-items:center;gap:8px;color:#c7d2fe;font-size:1rem;margin-bottom:10px;padding-bottom:8px;border-bottom:1px dashed var(--border)}
  .clue-item{background:#0b1323;border-left:3px solid #4753ff;border-radius:12px;padding:12px;margin-bottom:10px}
  .clue-item h3{color:#c7d2fe;font-weight:800;font-size:.95rem;margin-bottom:4px}
  .clue-item p{color:#9aa6c3;font-size:.86rem}
  .clue-item.found{background:#0b1f17;border-left-color:#22c55e}
  .clue-item.found h3{color:#22c55e}
  .progress-label{display:flex;justify-content:space-between;color:#9aa6c3;font-weight:700;font-size:.85rem;margin:8px 0 6px}
  .progress-bar{height:8px;background:#1f2937;border-radius:6px;overflow:hidden}
  .progress-fill{height:100%;width:0%;background:linear-gradient(90deg,var(--progress-1),var(--progress-2));transition:width .4s ease}
  .btn-container{display:flex;gap:10px;margin-top:12px}
  .btn{flex:1;border-radius:10px;padding:10px 12px;font-weight:800;cursor:pointer;border:1px solid transparent}
  .btn-primary{background:#4f46e5;color:#fff}
  .btn-secondary{background:#111827;color:#e5e7eb;border-color:var(--border)}

  /* ---------- hint highlight ---------- */
  @keyframes pulse{0%{box-shadow:0 0 0 0 rgba(245,158,11,.45)}70%{box-shadow:0 0 0 10px rgba(245,158,11,0)}100%{box-shadow:0 0 0 0 rgba(245,158,11,0)}}
  .pf-hint{outline:3px dashed #f59e0b;outline-offset:3px;animation:pulse 1.2s ease-out 1}

  /* ---------- toast ---------- */
  .pf-toast-wrap{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);display:flex;flex-direction:column;gap:8px;z-index:6000;pointer-events:none}
  .pf-toast{pointer-events:auto;background:#0f172a;color:#e5e7eb;border-left:4px solid #22c55e;box-shadow:0 14px 40px rgba(0,0,0,.45);padding:10px 14px;border-radius:10px;font-weight:800;opacity:0;transform:translateY(12px);transition:opacity .25s,transform .25s}
  .pf-toast.show{opacity:1;transform:translateY(0)}
  .pf-toast.info{border-left-color:#60a5fa}
  .pf-toast.error{border-left-color:#f43f5e}

  /* ---------- edu popover ---------- */
  .pf-edu{position:fixed;z-index:4800;width:min(360px,92vw);background:var(--surface);border:1px solid var(--border);border-radius:14px;box-shadow:0 24px 60px rgba(0,0,0,.5);transform:translateY(6px) scale(.98);opacity:0;transition:transform .2s ease,opacity .2s ease;color:#e5e7eb}
  .pf-edu.show{transform:translateY(0) scale(1);opacity:1}
  .pf-edu .edu-head{display:flex;align-items:center;gap:8px;padding:10px 12px;border-bottom:1px solid var(--border);background:linear-gradient(90deg,rgba(124,58,237,.12),rgba(59,130,246,.12))}
  .pf-edu .badge{background:#f59e0b;color:#fff;padding:4px 8px;border-radius:999px;font-size:.75rem}
  .pf-edu .edu-title{font-weight:900}
  .pf-edu .edu-body{padding:12px}
  .pf-edu ul{margin:8px 0 0 0;padding-left:18px}
  .pf-edu li{margin:4px 0;opacity:0;transform:translateX(6px)}
  .pf-edu .edu-cta{margin-top:10px;padding:10px 12px;border-top:1px dashed var(--border);font-weight:800;background:#111827}
  .pf-edu .close{margin-left:auto;background:#111827;border:1px solid var(--border);color:#e5e7eb;padding:6px 10px;border-radius:8px;cursor:pointer;font-weight:800}


/* ========== RESPONSIVE PATCH (paste at the very end of your <style>) ========== */
html{font-size: clamp(13px, 0.95vw + 10px, 16px);}
.container,.pg-topbar{ max-width: min(96vw, 1180px); }
.browser-simulator{ display: grid; grid-template-columns: minmax(320px, 1.25fr) minmax(300px, 1fr); gap: 16px; }
@media (max-width: 1280px){ .browser-simulator{ grid-template-columns: minmax(300px, 1.15fr) minmax(280px, 1fr); } }
@media (max-width: 940px){ .browser-simulator{ grid-template-columns: 1fr; } }
.browser-container,.learning-panel{ display: flex; flex-direction: column; min-height: 0; height: clamp(420px, 64vh, 620px); }
@media (max-width: 1024px){ .browser-container, .learning-panel{ height: clamp(380px, 58vh, 560px); } }
@media (max-width: 940px){ .browser-container, .learning-panel{ height: auto; } }
.browser-content,.clue-list{ min-height: 0; overflow: auto; }
@media (max-width: 940px){ .browser-content{ max-height: 56vh; } .clue-list{ max-height: 44vh; } }
.clue-item{ padding: 10px 12px; } .clue-item h3{ font-size: .92rem; margin-bottom: 4px; } .clue-item p{ font-size: .84rem; }
@media (max-width: 740px){
  .level-selector{ flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch; scrollbar-width: none; }
  .level-selector::-webkit-scrollbar{ display: none; }
}
.pg-topbar .inner{ gap: 10px; flex-wrap: wrap; }
@media (max-width: 480px){ .btn-round{ width: 24px; height: 24px; } .url-text{ font-size: .78rem; } }
.url-bar{ min-width: 0; } .url-text{ min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
/* ========== /PATCH ========== */

/* ===================== COMPACT DESKTOP PATCH ===================== */
.container,.pg-topbar { max-width: 1080px; }
@media (min-width: 1200px){ html{ font-size: 13.25px; } }
@media (min-width: 1600px){ html{ font-size: 13px; } }
.header{ padding: 10px 12px; }
.header h1{ font-size: 1.15rem; } .header p { font-size: .84rem; }
.browser-simulator{ grid-template-columns: minmax(360px, 1.2fr) minmax(300px, 0.9fr); gap: 14px; }
.browser-container,.learning-panel{ height: clamp(420px, 54vh, 560px); }
@media (max-width: 1024px){ .browser-container, .learning-panel{ height: clamp(380px, 58vh, 560px); } }
@media (max-width: 940px){ .browser-container, .learning-panel{ height: auto; } }
.browser-content,.clue-list{ min-height: 0; overflow: auto; }
@media (max-width: 940px){ .browser-content{ max-height: 56vh; } .clue-list{ max-height: 44vh; } }
.browser-toolbar{ padding: 6px 8px; } .btn-round{ width: 24px; height: 24px; }
.url-bar{ padding: 3px 8px; min-width: 0; } .url-text{ min-width: 0; font-size: .82rem; }
.level-btn{ padding: 6px 10px; font-weight: 700; } .level-btn .difficulty{ padding: 2px 7px; font-size: .72rem; }
.clue-item{ padding: 10px 12px; } .clue-item h3{ font-size: .9rem; margin-bottom: 4px; } .clue-item p { font-size: .82rem; }
.progress-label{ font-size: .82rem; margin: 6px 0 4px; } .btn{ padding: 9px 10px; }
.login-form{ max-width: 340px; padding: 10px; } .form-group{ margin-bottom: 8px; }
.form-group label{ font-size: .78rem; } .form-control{ padding: 7px 9px; } .btn-login{ padding: 9px 10px; }
@media (max-width: 740px){
  .level-selector{ flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch; scrollbar-width: none; }
  .level-selector::-webkit-scrollbar{ display: none; }
}
/* =================== /COMPACT DESKTOP PATCH =================== */

/* === Force the Learning Panel to show all clues (no inner scrollbar) === */
.learning-panel{ height: auto !important; max-height: none !important; }
.clue-list{ max-height: none !important; overflow: visible !important; }
.learning-panel .clue-item{ padding: 10px 12px; margin-bottom: 10px; }
.learning-panel .panel-title{ margin-bottom: 10px; padding-bottom: 8px; }
.progress-container{ margin-top: 8px; }
@media (min-width: 900px){
  body.pf-four-up .learning-panel .clue-item{ padding: 8px 10px; margin-bottom: 8px; }
  body.pf-four-up .learning-panel .panel-title{ margin-bottom: 8px; padding-bottom: 6px; }
  body.pf-four-up .btn{ padding: 8px 10px; }
}
/* Popover arrow + per-side placement */
.pf-edu .pf-edu-arrow{ position:absolute;width:12px;height:12px;background:var(--surface);border:1px solid var(--border);transform:rotate(45deg); z-index:-1; }
.pf-edu[data-place="right"]  .pf-edu-arrow{ left:-6px;  top:50%; transform:translateY(-50%) rotate(45deg) }
.pf-edu[data-place="left"]   .pf-edu-arrow{ right:-6px; top:50%; transform:translateY(-50%) rotate(45deg) }
.pf-edu[data-place="bottom"] .pf-edu-arrow{ top:-6px;   left:24px }
.pf-edu[data-place="top"]    .pf-edu-arrow{ bottom:-6px; left:24px }

/* === MOBILE OVERRIDES === */
@media (max-width: 480px) {
  body { padding: 10px; }
  .container, .pg-topbar { max-width: 100%; }
  .header { padding: 10px; border-radius: 12px; }
  .header h1 { font-size: 1rem; }
  .header p  { font-size: .78rem; }
  .level-selector{ gap: 8px; flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch; scrollbar-width: none; }
  .level-selector::-webkit-scrollbar { display: none; }
  .level-btn{ padding: 6px 10px; font-size: .9rem; }
  .browser-simulator{ display: flex; flex-direction: column; gap: 12px; margin-bottom: 12px; }
  .browser-container,.learning-panel{ height: auto; border-radius: 12px; }
  .browser-toolbar{ padding: 6px 8px; gap: 6px; }
  .btn-round{ width: 24px; height: 24px; }
  .url-bar{ padding: 3px 8px; border-radius: 14px; }
  .url-text{ font-size: .8rem; }
  .browser-content{ max-height: 58vh; overflow: auto; }
  .clue-list{ display: grid; grid-template-rows: repeat(4, minmax(0, 1fr)); gap: 8px; max-height: none; overflow: visible; }
  .clue-item{ margin: 0; padding: 10px; border-radius: 10px; }
  .clue-item h3{ font-size: .9rem; margin: 0 0 2px; }
  .clue-item p{ display: none; }
  .panel-title{ margin-bottom: 8px; padding-bottom: 6px; }
  .progress-label{ font-size: .8rem; }
  .btn-container{ gap: 8px; }
  .btn{ padding: 9px 10px; border-radius: 9px; }
  @media (max-height: 700px){
    .browser-content{ max-height: 54vh; }
    .clue-item h3{ font-size: .86rem; }
  }
}
</style>
</head>
<body>
<?php
  $PAGE = ['title'=>'Dashboard','active'=>'dashboard','base'=>''];
  include __DIR__ . '/inc/app_topbar.php';
?>

<?php if ($shouldClear): ?><script>try{localStorage.clear()}catch(e){}</script><?php endif; ?>

<div class="container">
  <div class="header">
    <h1>PhishGuide – Browser Simulator</h1>
    <p>Practice spotting phishing red flags in a safe, offline mock browser.</p>
  </div>

  <div id="levelSelector" class="level-selector"></div>

  <div class="browser-simulator">
    <div class="browser-container">
      <div class="browser-toolbar">
        <button class="btn-round"><i class="fas fa-arrow-left"></i></button>
        <button class="btn-round"><i class="fas fa-arrow-right"></i></button>
        <button class="btn-round"><i class="fas fa-redo"></i></button>

        <div class="url-bar">
          <i class="fas fa-lock padlock" title="Connection is secure"></i>
          <div class="url-text"></div>
          <span class="not-secure" id="notSecureBadge">Not secure</span>
        </div>

        <div class="toolbar-more"><i class="fas fa-star"></i> <i class="fas fa-bars"></i></div>
      </div>
      <div class="browser-content"><div id="websiteContent" class="website"></div></div>
    </div>

    <div class="learning-panel">
      <h2 class="panel-title"><i class="fas fa-book-open"></i> Learning Panel</h2>
      <div id="clueList" class="clue-list"></div>
      <div class="progress-container">
        <div class="progress-label"><span>Progress</span><span id="progressText">0/0 clues found</span></div>
        <div class="progress-bar"><div id="progressFill" class="progress-fill"></div></div>
      </div>
      <div class="btn-container">
        <button class="btn btn-secondary" id="hintBtn">Hint</button>
        <button class="btn btn-primary" id="finishBtn" disabled>Finish Level</button>
        <button class="btn btn-secondary" id="retryBtn">Retry Level</button>
      </div>
    </div>
  </div>
</div>

<script>
/* payloads */
const LEVELS = <?= json_encode($LEVELS, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const EDU    = <?= json_encode($EDU,    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const USTATE = <?= json_encode($USTATE, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;

/* avatar dropdown */
(()=>{const a=document.getElementById('pgAvatar'),m=document.getElementById('pgUserDropdown');
if(!a||!m)return;const t=(s)=>m.style.display=(typeof s==='boolean')?(s?'block':'none'):(m.style.display==='block'?'none':'block');
a.addEventListener('click',e=>{e.stopPropagation();t()});document.addEventListener('click',()=>t(false));m.addEventListener('click',e=>e.stopPropagation());})();

/* toast */
let __toastWrap;
function toast(msg, kind='success', ms=2200){
  if(!__toastWrap){__toastWrap=document.createElement('div');__toastWrap.className='pf-toast-wrap';document.body.appendChild(__toastWrap);}
  const el=document.createElement('div'); el.className=`pf-toast ${kind}`; el.textContent=msg;
  __toastWrap.appendChild(el); requestAnimationFrame(()=>el.classList.add('show'));
  setTimeout(()=>{el.classList.remove('show'); setTimeout(()=>el.remove(),180)}, ms);
}

/* EDU popover (only via Hint) */
function showEduNear(eduKey, anchor){
  const data = EDU[eduKey];
  if (!data || !anchor) return;
  document.querySelectorAll('.pf-edu').forEach(n=>n.remove());
  const pop = document.createElement('div');
  pop.className = 'pf-edu';
  pop.innerHTML = `
    <div class="pf-edu-arrow"></div>
    <div class="edu-head">
      <span class="badge">Learn</span>
      <div class="edu-title">${data.title}</div>
      <button class="close" type="button">Close</button>
    </div>
    <div class="edu-body">
      <ul>${data.why.map(x=>`<li>• ${x}</li>`).join('')}</ul>
      <div class="edu-cta"><i class="${data.icon||'fas fa-lightbulb'}"></i> ${data.action}</div>
    </div>`;
  document.body.appendChild(pop);
  pop.style.visibility = 'hidden';
  pop.classList.add('show');

  const r  = anchor.getBoundingClientRect();
  const vw = window.innerWidth, vh = window.innerHeight, m=12;
  const pw = Math.min(pop.offsetWidth || 360, 360);
  const ph = pop.offsetHeight || 180;
  const clamp=(v,min,max)=>Math.min(Math.max(v,min),max);
  const canRight  = r.right + m + pw <= vw;
  const canLeft   = r.left  - m - pw >= 0;
  const canBottom = r.bottom + m + ph <= vh;
  const canTop    = r.top   - m - ph >= 0;

  let place='right';
  if      (canRight)  place='right';
  else if (canLeft)   place='left';
  else if (canBottom) place='bottom';
  else                place='top';

  let left=0, top=0;
  switch(place){
    case 'right':  left=r.right+m;  top=clamp(r.top+r.height/2 - ph/2, 8, vh-ph-8); break;
    case 'left':   left=r.left-pw-m;top=clamp(r.top+r.height/2 - ph/2, 8, vh-ph-8); break;
    case 'bottom': left=clamp(r.left+r.width/2 - pw/2, 8, vw-pw-8); top=r.bottom+m; break;
    case 'top':    left=clamp(r.left+r.width/2 - pw/2, 8, vw-pw-8); top=r.top-ph-m; break;
  }
  pop.style.left=left+'px'; pop.style.top=top+'px';
  pop.dataset.place=place; pop.style.visibility='visible';
  pop.querySelectorAll('li').forEach((li,i)=>setTimeout(()=>{li.style.opacity=1;li.style.transform='translateX(0)'},120+i*90));
  const close=()=>{pop.classList.remove('show'); setTimeout(()=>pop.remove(),160);};
  pop.querySelector('.close').onclick=close;
  setTimeout(close, 5000);
}

/* app */
(function(){
  const levelSelector=document.getElementById('levelSelector');
  const websiteEl=document.getElementById('websiteContent');
  const urlBarText=document.querySelector('.url-text');
  const padlockEl=document.querySelector('.padlock');
  const notSecureEl=document.getElementById('notSecureBadge');
  const clueList=document.getElementById('clueList');
  const progressFill=document.getElementById('progressFill');
  const progressText=document.getElementById('progressText');
  const hintBtn=document.getElementById('hintBtn');
  const finishBtn=document.getElementById('finishBtn');
  const retryBtn=document.getElementById('retryBtn');

  let currentLevelNo=1, found=new Set(), required=new Set();

  function buildLevelButtons(){
    levelSelector.innerHTML = LEVELS.map(pack=>{
      const ln=+pack.level_no, m=pack.meta;
      const unlocked = (USTATE.unlocked[ln] == 1);
      const completed = (USTATE.completed||[]).some(v=> +v===ln);
      return `<button class="level-btn ${unlocked?'':'locked'} ${completed?'completed':''}" data-level="${ln}">
        <i class="${m.icon_class}"></i> ${m.title}
        <span class="difficulty">${m.difficulty}</span>
      </button>`;
    }).join('');
    levelSelector.querySelectorAll('.level-btn').forEach(btn=>{
      btn.addEventListener('click',()=>{
        const lvl=+btn.dataset.level;
        if(USTATE.unlocked[lvl] != 1) return;
        setActive(lvl); render(lvl);
        const u=new URL(location.href); u.searchParams.set('id',String(lvl)); history.replaceState(null,'',u);
      });
    });
  }

  function setActive(levelNo){
    currentLevelNo=levelNo;
    levelSelector.querySelectorAll('.level-btn').forEach(b=> b.classList.toggle('active', +b.dataset.level===levelNo));
  }

  function rebuildClueCards(clues){
    if (!clues || clues.length===0) {
      clueList.innerHTML = `<div class="clue-item"><h3>No clues configured</h3><p>This level has no DB clues yet.</p></div>`;
      required = new Set(); found = new Set(); updateProgress();
      return;
    }

    clueList.innerHTML = clues.map(c=>`
      <div class="clue-item" id="clue-${c.clue_key}">
        <h3><i class="fas fa-exclamation-triangle"></i> ${c.title}</h3>
        <p>Click the page element to investigate.</p>
      </div>
    `).join('');

    required = new Set(clues.filter(c=>+c.required===1).map(c=>c.clue_key));
    found    = new Set();
    updateProgress();
  }

  function updateProgress(){
    const need=required.size, have=[...found].filter(k=>required.has(k)).length;
    progressFill.style.width=(need?(have/need*100):0)+'%';
    progressText.textContent=`${have}/${need} clues found`;
    finishBtn.disabled = !(need>0 && have>=need);
  }

  function markFound(key){
    if(!required.has(key) || found.has(key)) return;
    found.add(key);
    const card=document.getElementById('clue-'+key);
    if(card){ card.classList.add('found'); const ic=card.querySelector('h3 i'); if(ic) ic.className='fas fa-check-circle'; }
    updateProgress();
  }

  function bindClueTargets(pack){
    const clues=pack.clues||[];
    clues.forEach(c=>{
      const sel=c.css_selector;
      const target = sel==='.url-text'?urlBarText : sel==='.padlock'?padlockEl :
                     sel==='#notSecureBadge'?notSecureEl : websiteEl.querySelector(sel);
      if(target){ target.addEventListener('click', ()=> markFound(c.clue_key), {once:false}); }
    });
  }

  function render(levelNo){
    const pack=LEVELS.find(p=> +p.level_no===levelNo); if(!pack){ websiteEl.innerHTML=''; rebuildClueCards([]); return; }
    const scn=pack.scenario;
    urlBarText.textContent=scn.url||'';
    padlockEl.style.display=scn.show_padlock ? '' : 'none';
    if(scn.show_not_secure) notSecureEl.classList.add('show'); else notSecureEl.classList.remove('show');

    websiteEl.innerHTML=scn.html||'';
    websiteEl.querySelectorAll('.login-form').forEach(box=>{
      if(!box || box.closest('form') || !box.querySelector('input[type="password"]')) return;
      const f=document.createElement('form'); f.setAttribute('novalidate',''); f.addEventListener('submit',e=>e.preventDefault());
      while(box.firstChild) f.appendChild(box.firstChild); box.appendChild(f);
    });

    if(scn.countdown>0){ const tEl=websiteEl.querySelector('#dealTimer'); let s=scn.countdown; clearInterval(window.__pfTimer);
      if(tEl) window.__pfTimer=setInterval(()=>{ if(s<=0) return; s--; tEl.textContent=String(Math.floor(s/60)).padStart(2,'0')+':'+String(s%60).padStart(2,'0'); },1000);
    } else clearInterval(window.__pfTimer);

    rebuildClueCards(pack.clues);
    bindClueTargets(pack);
  }

  function showHint(){
    const pack=LEVELS.find(p=> +p.level_no===currentLevelNo);
    const order=pack?.scenario?.hint_order||[];
    const nextKey=order.find(k=>!found.has(k) && required.has(k));
    if(!nextKey){ toast('All required clues found!','info',1400); return; }
    const clue=(pack.clues||[]).find(c=>c.clue_key===nextKey); if(!clue) return;
    const target = clue.css_selector==='.url-text'?urlBarText : clue.css_selector==='.padlock'?padlockEl :
                   clue.css_selector==='#notSecureBadge'?notSecureEl : websiteEl.querySelector(clue.css_selector);
    if(!target) return;
    target.scrollIntoView({behavior:'smooth',block:'center',inline:'center'});
    target.classList.add('pf-hint'); setTimeout(()=>target.classList.remove('pf-hint'),1200);
    showEduNear(clue.edu_key, target);
    const card=document.getElementById('clue-'+nextKey);
    if(card) card.animate([{transform:'scale(1)'},{transform:'scale(1.03)'},{transform:'scale(1)'}],{duration:500,easing:'ease-out'});
  }

  async function finishLevel(){
    if(finishBtn.disabled){ toast('Find all required clues first.','info',1500); return; }
    const pack=LEVELS.find(p=> +p.level_no===currentLevelNo);
    try{
      const fd=new FormData(); fd.set('level_no',String(currentLevelNo)); fd.set('scenario_id',String(pack?.scenario?.id||0));
      const r=await fetch('?ajax=finish',{method:'POST',body:fd,credentials:'same-origin'}); const j=await r.json();
      try { console.log('web-finish response:', j); } catch(_){}
      try { localStorage.setItem('pg_last_finish_resp', JSON.stringify(j)); } catch(_){}
      if(!j.ok){ toast(j.err||'Error','error',2400); return; }

      toast(j.message, j.awarded_now?'success':'info', j.awarded_now?2600:2000);

      if(!USTATE.completed) USTATE.completed=[];
      if(!USTATE.completed.some(v=> +v===currentLevelNo)) USTATE.completed.push(currentLevelNo);
      const curBtn=document.querySelector(`[data-level="${currentLevelNo}"]`); if(curBtn) curBtn.classList.add('completed');

      if(j.next_level){ USTATE.unlocked[j.next_level]=1;
        const nb=document.querySelector(`[data-level="${j.next_level}"]`); if(nb) nb.classList.remove('locked'); }

      /* IMPORTANT: do NOT write pg_sim_progress_pct (or related progress keys) to localStorage here.
         The dashboard will compute per-user progress from the database on load. */

      // open next level automatically
      if (j.next_level) {
        const nextLv = Number(j.next_level) || 0;
        if (nextLv > 0) {
          setTimeout(()=>{
            try {
              setActive(nextLv);
              render(nextLv);
              const nb2 = document.querySelector(`[data-level="${nextLv}"]`);
              if (nb2) nb2.scrollIntoView({ behavior: 'smooth', block: 'center' });
              history.replaceState(null, '', '?id=' + nextLv);
            } catch(e) {}
          }, 650);
        }
      }

      // keep the global completed count in sync (post-test unlock UI)
      if (typeof j.completed_all !== 'undefined') {
        try {
          const prev = Number(localStorage.getItem('pg_completed_all') || '0');
          localStorage.setItem('pg_completed_all', String(j.completed_all));
          const newVal = Number(j.completed_all || 0);
          if (Number.isFinite(prev) && prev < 5 && newVal >= 5) {
            toast('Post-test unlocked — take the Post-Test to earn bonus XP!', 'success', 3000);
          }
        } catch(_){}
      }
    }catch(e){ toast('Network error','error',2200); }
  }

  function resetRound(){
    found=new Set(); updateProgress();
    clueList.querySelectorAll('.clue-item').forEach(i=>{ i.classList.remove('found'); const ic=i.querySelector('h3 i'); if(ic) ic.className='fas fa-exclamation-triangle'; });
  }

  // init
  buildLevelButtons();
  const qs=new URLSearchParams(location.search);
  const qsLevel=parseInt(qs.get('id')||'1',10);
  const start=(USTATE.unlocked[qsLevel] == 1) ? qsLevel : (LEVELS[0]?.level_no || 1);
  setActive(start); render(start);

  hintBtn.addEventListener('click', showHint);
  finishBtn.addEventListener('click', finishLevel);
  retryBtn.addEventListener('click', resetRound);
})();
</script>
</body>
</html>

