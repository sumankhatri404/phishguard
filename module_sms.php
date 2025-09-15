<?php
// module_sms.php — desktop/mobile UIs; contextual helper text; reliable typing animation; link inspector; sticky mobile drawer; Decide FAB (mobile)
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

if (empty($_SESSION['user_id'])) {
  header('Location: index.php?msg=' . urlencode('Please login first.'));
  exit;
}

$userId   = (int) $_SESSION['user_id'];
$moduleId = (int) ($_GET['id'] ?? 0);

require_once __DIR__ . '/inc/cases_table.php';
$caseTbl = getCasesTableForModule($pdo, $moduleId, 'sms');

$m = $pdo->prepare("SELECT id, title, description FROM training_modules WHERE id=? LIMIT 1");
$m->execute([$moduleId]);
$module = $m->fetch(PDO::FETCH_ASSOC);
if (!$module) { die('Module not found.'); }

$moduleTitle = htmlspecialchars($module['title'] ?? 'SMS & Social Media Scams', ENT_QUOTES, 'UTF-8');
$moduleDesc  = htmlspecialchars($module['description'] ?? '', ENT_QUOTES, 'UTF-8');

// Discover optional columns so this works with older/newer schemas
$hasSort = $hasFrom = $hasAvatar = $hasDays = $hasPoints = $hasActive = false;
try {
  $cols = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?");
  $cols->execute([$caseTbl]);
  $names = array_map('strval', $cols->fetchAll(PDO::FETCH_COLUMN));
  $hasSort   = in_array('sort_order',   $names, true);
  $hasFrom   = in_array('from_name',    $names, true);
  $hasAvatar = in_array('from_avatar',  $names, true);
  $hasDays   = in_array('days_ago',     $names, true);
  $hasPoints = in_array('points_max',   $names, true);
  $hasActive = in_array('is_active',    $names, true);
} catch (Throwable $e) { /* ignore, fall back */ }

$selFrom   = $hasFrom   ? 'from_name'   : "'' AS from_name";
$selAvatar = $hasAvatar ? 'from_avatar' : "'' AS from_avatar";
$selDays   = $hasDays   ? 'days_ago'    : '1 AS days_ago';
$selPts    = $hasPoints ? 'points_max'  : '10 AS points_max';
$whereAct  = $hasActive ? ' AND is_active=1' : '';

$sqlList = "SELECT id, title, $selFrom, $selAvatar, $selDays, $selPts
            FROM `$caseTbl`
            WHERE module_id=?$whereAct
            ORDER BY ".($hasSort?"sort_order, id":"id");
$cq = $pdo->prepare($sqlList);
$cq->execute([$moduleId]);
$cases = $cq->fetchAll(PDO::FETCH_ASSOC) ?: [];

$doneStmt = $pdo->prepare("
  SELECT DISTINCT p.case_id
  FROM training_sms_progress p
  JOIN `$caseTbl` c ON c.id = p.case_id
  WHERE p.user_id = ? AND c.module_id = ?
");
$doneStmt->execute([$userId, $moduleId]);
$doneIds = array_map('intval', $doneStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

$completed = 0; $total = count($cases);
foreach ($cases as $c) if (in_array((int)$c['id'], $doneIds, true)) $completed++;
$initialPct = $total ? (int)round($completed*100/$total) : 0;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title><?= $moduleTitle ?> · PhishGuard</title>
<link rel="icon" type="image/svg+xml"
      href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='12' fill='%236d28d9'/%3E%3Cpath d='M18 38l14-20 14 20-14 8z' fill='white'/%3E%3C/svg%3E">
<style>
:root{
  --bg:#070d1c; --panel:#0c1631; --panel2:#101d40;
  --ring:rgba(148,163,184,.16); --ring-2:rgba(148,163,184,.28);
  --text:#e9efff; --muted:#a6b4d8; --accent:#7c4dff;
  --ok:#22c55e; --warn:#ef4444; --chip:#172853; --hdr:48px;
}
*{box-sizing:border-box} html,body{height:auto;overflow-y:auto}
body.pg-mail{background:var(--bg);color:var(--text);margin:0}

/* Top */
.pg-mail-top{background:#0f172a;border-bottom:1px solid rgba(255,255,255,.08);height:var(--hdr);position:sticky;top:0;z-index:50}
.pg-mail-top .wrap{width:min(1280px,96vw);height:100%;margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:0 8px}
.pg-brand{display:flex;align-items:center;gap:8px;font-weight:900}
.pg-brand .logo{display:grid;place-items:center;width:24px;height:24px;border-radius:6px;background:linear-gradient(135deg,#6366f1,#06b6d4);color:#0b0720}
.pg-mail-top .btn{background:#1e2a4f;color:#fff;padding:6px 10px;font-size:.86rem;border-radius:8px;text-decoration:none;font-weight:800}
.pg-mail-top .btn:hover{background:#27345f}

.wrap{width:min(1280px,96vw);margin:10px auto 18px}
.h-card{background:var(--panel);border:1px solid var(--ring);border-radius:12px;padding:10px 12px}
.h-title{font-size:1rem;font-weight:800;margin-bottom:2px}
.h-desc{color:#a6b4d8;font-size:.9rem}

/* Progress */
.progress{margin:10px 0 4px}
.pbar{height:5px;background:rgba(255,255,255,.06);border:1px solid var(--ring);border-radius:999px;overflow:hidden}
.pfill{height:100%;width:<?= $initialPct ?>%;background:linear-gradient(90deg,#22c55e,#10b981)}
.progress .meta{display:flex;justify-content:space-between;color:#a6b4d8;margin-top:6px;font-size:.9rem}

/* Grid */
.layout{display:grid;gap:16px;grid-template-columns:clamp(260px,24vw,320px) clamp(380px,36vw,440px) clamp(360px,28vw,460px);align-items:start}

/* Case card (shared) */
.case{width:100%;background:var(--panel2);border:1px solid var(--ring);border-radius:12px;padding:12px;cursor:pointer;text-align:left}
.case .head{display:grid;grid-template-columns:28px 1fr auto;gap:10px;align-items:center;margin-bottom:6px}
.case .av{width:28px;height:28px;border-radius:50%;overflow:hidden;background:#24325f}
.case .av img{width:100%;height:100%;object-fit:cover}
.case .t{font-weight:800;color:#eaf2ff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.case .done{background:linear-gradient(90deg,#22c55e,#10b981);color:#04180f;padding:2px 8px;border-radius:999px;font-size:.7rem;font-weight:900}
.case .s{color:#c2cee9;font-size:.9rem;line-height:1.25}
.case .ago{color:#8fa0c7;font-size:.8rem;font-weight:600;margin-top:4px}

/* Desktop left column */
.left{padding:12px;background:var(--panel);border:1px solid var(--ring);border-radius:12px}
.left .title{font-weight:900;margin-bottom:8px}
.mailbox{display:flex;flex-direction:column;gap:10px;max-height:66vh;overflow:auto}

/* Phone (center) */
.center{display:block}
.phone-wrap{position:sticky;top:10px;width:100%}
.phone{width:min(420px,100%);height:min(70vh,680px);margin:0 auto;border-radius:28px;background:linear-gradient(180deg,#0e1a3c,#0b1428);border:1px solid var(--ring);box-shadow:0 20px 60px rgba(0,0,0,.5),inset 0 0 1px rgba(255,255,255,.06);overflow:hidden;position:relative}
.phone:before{content:"";position:absolute;left:50%;transform:translateX(-50%);top:8px;width:42%;height:10px;border-radius:10px;background:#0a1430;box-shadow:inset 0 0 0 1px rgba(255,255,255,.06)}
.ph-status{display:flex;justify-content:space-between;align-items:center;padding:10px 12px 6px;font-weight:900;color:#d7e2ff;font-size:.9rem}
.ph-top{display:flex;align-items:center;gap:10px;padding:8px 12px;border-top:1px solid var(--ring);border-bottom:1px solid var(--ring);background:rgba(255,255,255,.03)}
.ph-icon{width:30px;height:30px;display:grid;place-items:center;border-radius:10px;background:linear-gradient(135deg,#1d2b55,#273a73);border:1px solid rgba(148,163,184,.35);color:#dbe7ff;font-weight:900;cursor:pointer}
.ph-top .av{width:28px;height:28px;border-radius:50%;overflow:hidden}
.ph-top .av img{width:100%;height:100%;object-fit:cover;background:#24325f}
.ph-top .who{font-weight:800;font-size:.95rem}
.ph-top .meta{font-size:.8rem;color:#b9c7ea;font-weight:600}

/* Chat */
.chat{height:calc(100% - 110px);overflow:auto;padding:12px 10px;background:
  radial-gradient(100% 50% at 70% 10%,rgba(124,77,255,.08),transparent 45%),
  radial-gradient(100% 50% at 20% 90%,rgba(92,171,255,.08),transparent 45%),
  linear-gradient(180deg,#0b1429,#0b1426)}
.row{display:flex;align-items:flex-end;gap:6px}
.row .ava{width:24px;height:24px;border-radius:50%;overflow:hidden;background:#24325f;flex:0 0 auto}
.row .ava img{width:100%;height:100%;object-fit:cover}
.row.in{justify-content:flex-start}
.row.out{justify-content:flex-end}
.row.out .ava{order:2}.row.out .bubble{order:1}
.bubble{display:inline-block;max-width:86%;margin:6px 0;padding:9px 11px;border-radius:14px;background:#162149;color:#eaf2ff;line-height:1.45;box-shadow:0 2px 6px rgba(0,0,0,.25),inset 0 0 0 1px rgba(255,255,255,.05)}
.row.in .bubble{border-top-left-radius:6px}
.row.out .bubble{background:#7c4dff;color:#0b0720;border-top-right-radius:6px;font-weight:700}

/* Typing dots animation */
.dot{display:inline-block;width:6px;height:6px;border-radius:50%;background:#cfe1ff;opacity:.25;margin:0 2px;animation:tdot 1s infinite ease-in-out}
.dot:nth-child(2){animation-delay:.2s}
.dot:nth-child(3){animation-delay:.4s}
@keyframes tdot{0%,80%,100%{opacity:.25;transform:translateY(0)}40%{opacity:1;transform:translateY(-3px)}}

/* Desktop right column */
.right{background:var(--panel);border:1px solid var(--ring);border-radius:12px;padding:12px 14px;position:sticky;top:10px;width:100%}
.right h3{margin:6px 0 8px;font-size:1rem}
.right .muted{color:#a6b4d8;font-size:.92rem;margin-bottom:8px}
.sel{color:#cdd7ff;font-weight:800;margin-bottom:6px}
.chips{display:flex;gap:6px;flex-wrap:wrap}
.chip{background:var(--chip);color:#dfe6ff;border:1px solid var(--ring);padding:6px 10px;border-radius:999px;cursor:pointer;font-weight:900;font-size:.86rem}
.chip.active{outline:2px solid #5cabff}
.pair{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px}
.btn{background:#334155;color:#e5edff;border:0;border-radius:10px;padding:10px 14px;font-weight:900;cursor:pointer;font-size:.95rem;text-align:center}
.btn.warn{background:#ef4444}.btn.primary{background:#22c55e;color:#041a10}.btn[disabled]{opacity:.55;cursor:not-allowed}

/* Mobile Cases drawer */
.cases-wrap{position:absolute;inset:0;pointer-events:none;z-index:30}
.cases-scrim{position:absolute;inset:0;background:rgba(2,6,23,.55);opacity:0;transition:opacity .2s ease;pointer-events:none}
.cases-drawer{position:absolute;inset:0;transform:translateX(-100%);transition:transform .2s ease;pointer-events:auto}
.cases-wrap.open .cases-scrim{opacity:1;pointer-events:auto}
.cases-wrap.open .cases-drawer{transform:translateX(0)}
.cases-panel{position:absolute;left:0;top:0;bottom:0;width:86%;background:#0b1426;border-right:1px solid var(--ring-2);display:flex;flex-direction:column}
.cases-head{position:sticky;top:0;z-index:2;display:flex;align-items:center;justify-content:space-between;padding:10px 12px 8px;background:#0b1426;border-bottom:1px solid var(--ring-2);box-shadow:0 6px 12px rgba(0,0,0,.25)}
.cases-title{font-weight:900}
.cases-close{width:30px;height:30px;display:grid;place-items:center;border-radius:8px;border:1px solid var(--ring);background:#172853;cursor:pointer}
.cases-body{flex:1 1 auto;min-height:0;overflow:auto;padding:10px 12px}
.cases-body .mailbox{max-height:none}

/* Mobile decision sheet */
.m-sheet-wrap{position:absolute;inset:0;display:grid;align-items:end;z-index:35;pointer-events:none}
.m-sheet-backdrop{position:absolute;inset:0;background:rgba(2,6,23,.5);opacity:0;transition:opacity .16s ease;pointer-events:none}
.m-sheet-backdrop.show{opacity:1;pointer-events:auto}
.m-sheet{transform:translateY(100%);transition:transform .16s ease,opacity .16s ease;background:linear-gradient(180deg,#0f172a,#0b1224);border-top:1px solid var(--ring-2);border-radius:16px 16px 0 0;padding:12px;pointer-events:auto}
.m-sheet.open{transform:translateY(0)}
.m-head{display:flex;align-items:center;gap:8px;margin-bottom:6px}
.m-head .drag{width:38px;height:4px;border-radius:999px;background:#1e2b52;margin:0 auto}
.m-title{font-weight:900}
.m-muted{color:#9fb0d7;font-size:.9rem;margin:4px 0 8px}
.m-chips{display:flex;gap:6px;flex-wrap:wrap}
.m-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px}
.m-submit{width:100%;margin-top:10px}

/* FAB (mobile) */
.fab-decide{position:absolute;right:12px;bottom:calc(24px + env(safe-area-inset-bottom,0px));border-radius:999px;padding:12px 16px;font-weight:900;border:1px solid var(--ring);background:#7c4dff;color:#0b0720;box-shadow:0 8px 24px rgba(0,0,0,.35);cursor:pointer}

/* Link Inspector modal */
.li-wrap{position:absolute;inset:0;z-index:45;display:none}
.li-wrap.show{display:block}
.li-scrim{position:absolute;inset:0;background:rgba(2,6,23,.55)}
.li-modal{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:calc(100% - 40px);max-width:520px;background:#0f172a;border:1px solid var(--ring-2);border-radius:14px;padding:12px;box-shadow:0 16px 40px rgba(0,0,0,.5)}
.li-title{font-weight:900;margin-bottom:6px}
.li-row{display:flex;gap:8px;margin:6px 0;color:#cfe1ff}
.li-lab{flex:0 0 160px;color:#9fb0d7;font-weight:800}
.li-val{word-break:break-all}

/* visibility helpers */
.mobile-only{display:none}
.desktop-only{display:block}
@media (max-width:1100px){
  .layout{grid-template-columns:1fr}
  .left,.right{display:none}
  .phone-wrap{position:relative;top:auto}
  .mobile-only{display:flex}
  .desktop-only{display:none}
  .fab-decide{display:inline-flex}
}
@media (min-width:1101px){
  .fab-decide,.mobile-only{display:none!important}
}
.pg-spin{display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;margin-right:6px;vertical-align:-2px;animation:pgR .9s linear infinite}
@keyframes pgR{to{transform:rotate(360deg)}}
</style>
</head>
<body class="pg-mail">

<header class="pg-mail-top">
  <div class="wrap">
    <div class="pg-brand"><span class="logo">🛡️</span><span>PhishGuard</span></div>
    <a class="btn" href="dashboard.php">← Back to Dashboard</a>
  </div>
</header>

<div class="wrap">
  <div class="h-card">
    <div class="h-title"><?= $moduleTitle ?></div>
    <div class="h-desc"><?= $moduleDesc ?></div>
  </div>

  <div class="progress">
    <div class="pbar"><div id="pfill" class="pfill"></div></div>
    <div class="meta">
      <div id="progressText"><?= $completed ?> / <?= $total ?> completed</div>
      <div id="progressPct"><?= $initialPct ?>%</div>
    </div>
  </div>

  <div class="layout">
    <!-- Desktop: Mailbox -->
    <aside class="left desktop-only">
      <div class="title">Cases</div>
      <div class="mailbox" id="caseList">
        <?php if (!$cases): ?>
          <div style="opacity:.8">No cases yet. Add rows to <code><?= htmlspecialchars($caseTbl, ENT_QUOTES, 'UTF-8') ?></code>.</div>
        <?php else: foreach ($cases as $c):
          $isDone = in_array((int)$c['id'], $doneIds, true);
          $raw    = trim((string)($c['from_avatar'] ?? ''));
          $avatar = htmlspecialchars($raw !== '' ? $raw : 'assets/img/avatars/default.svg', ENT_QUOTES, 'UTF-8');
          $from   = htmlspecialchars($c['from_name'] ?? 'Sender', ENT_QUOTES, 'UTF-8');
        ?>
          <button class="case"
                  data-id="<?= (int)$c['id'] ?>"
                  data-from="<?= $from ?>"
                  data-avatar="<?= $avatar ?>"
                  data-days="<?= (int)($c['days_ago'] ?? 1) ?>">
            <div class="head">
              <div class="av"><img src="<?= $avatar ?>" alt="<?= $from ?>" onerror="this.src='assets/img/avatars/default.svg'"></div>
              <div class="t"><?= htmlspecialchars($c['title'] ?? ('Case #'.(int)$c['id']), ENT_QUOTES, 'UTF-8') ?></div>
              <?php if ($isDone): ?><span class="done">Done</span><?php endif; ?>
            </div>
            <div class="s">Open this conversation to review and decide.</div>
            <div class="ago"><?= (int)($c['days_ago'] ?? 1) ?> days ago</div>
          </button>
        <?php endforeach; endif; ?>
      </div>
    </aside>

    <!-- PHONE -->
    <section class="center">
      <div class="phone-wrap">
        <div class="phone" id="phone">

          <div class="ph-status">
            <div><?= date('g:i') ?></div>
            <div>📶 🔋</div>
          </div>

          <div class="ph-top">
            <div class="mobile-only" style="gap:8px">
              <button id="btnCases" class="ph-icon" title="Cases">≡</button>
            </div>
            <div class="av">
              <img id="pAvatarImg" src="assets/img/avatars/default.svg" alt="Sender" onerror="this.src='assets/img/avatars/default.svg'">
            </div>
            <div style="min-width:0">
              <div id="pName" class="who">—</div>
              <div id="pMeta" class="meta">SMS</div>
            </div>
          </div>

          <div id="chat" class="chat" aria-live="polite"></div>

          <!-- Link Inspector -->
          <div id="liWrap" class="li-wrap" aria-hidden="true">
            <div id="liScrim" class="li-scrim"></div>
            <div class="li-modal" role="dialog" aria-modal="true" aria-labelledby="liTitle">
              <div id="liTitle" class="li-title">Link details</div>
              <div class="li-row"><div class="li-lab">Shown link</div><div class="li-val" id="liShown">—</div></div>
              <div class="li-row"><div class="li-lab">Actual destination</div><div class="li-val" id="liHref">—</div></div>
            </div>
          </div>

          <!-- MOBILE: Decide FAB -->
          <button id="fabDecide" class="fab-decide" aria-label="Decide">Decide</button>

          <!-- MOBILE: Cases Drawer -->
          <div id="casesWrap" class="cases-wrap" aria-hidden="true">
            <div id="casesScrim" class="cases-scrim"></div>
            <div class="cases-drawer">
              <div class="cases-panel">
                <div class="cases-head">
                  <div class="cases-title">Cases</div>
                  <button id="casesClose" class="cases-close" title="Close">✕</button>
                </div>
                <div class="cases-body">
                  <div class="mailbox" id="mCaseList">
                    <?php if ($cases): foreach ($cases as $c):
                      $isDone = in_array((int)$c['id'], $doneIds, true);
                      $raw    = trim((string)($c['from_avatar'] ?? ''));
                      $avatar = htmlspecialchars($raw !== '' ? $raw : 'assets/img/avatars/default.svg', ENT_QUOTES, 'UTF-8');
                      $from   = htmlspecialchars($c['from_name'] ?? 'Sender', ENT_QUOTES, 'UTF-8');
                    ?>
                      <button class="case"
                              data-id="<?= (int)$c['id'] ?>"
                              data-from="<?= $from ?>"
                              data-avatar="<?= $avatar ?>"
                              data-days="<?= (int)($c['days_ago'] ?? 1) ?>">
                        <div class="head">
                          <div class="av"><img src="<?= $avatar ?>" alt="<?= $from ?>" onerror="this.src='assets/img/avatars/default.svg'"></div>
                          <div class="t" title="<?= htmlspecialchars($c['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($c['title'] ?? ('Case #'.(int)$c['id']), ENT_QUOTES, 'UTF-8') ?>
                          </div>
                          <?php if ($isDone): ?><span class="done">Done</span><?php endif; ?>
                        </div>
                        <div class="s">Open conversation.</div>
                        <div class="ago"><?= (int)($c['days_ago'] ?? 1) ?> days ago</div>
                      </button>
                    <?php endforeach; endif; ?>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- MOBILE: Decision Sheet -->
          <div class="m-sheet-wrap" aria-hidden="true">
            <div id="mSheetBackdrop" class="m-sheet-backdrop"></div>
            <div id="mSheet" class="m-sheet" role="dialog" aria-modal="true" aria-labelledby="mTitle">
              <div class="m-head"><div class="drag"></div></div>
              <div id="mTitle" class="m-title">Reason</div>
              <div class="m-muted">Pick one reason that best explains your decision.</div>
              <div class="m-chips" id="mChips">
                <button class="chip" data-v="suspicious_links">Suspicious link</button>
                <button class="chip" data-v="unknown_untrustworthy">Unknown sender</button>
                <button class="chip" data-v="urgent_tone">Urgent/scare</button>
                <button class="chip" data-v="spoofed_profile">Brand spoof</button>
                <button class="chip" data-v="too_good_to_be_true">Too good</button>
                <button class="chip" data-v="legit">Looks legit</button>
              </div>

              <div class="m-title" style="margin-top:10px">Classification</div>
              <div class="m-actions">
                <button id="mLegit" class="btn">Legit</button>
                <button id="mPhish" class="btn warn">Phish</button>
              </div>

              <button id="mSubmit" class="btn primary m-submit" disabled>Submit</button>
            </div>
          </div>

        </div>
      </div>
    </section>

    <!-- Desktop Controls -->
    <aside class="right desktop-only">
      <h3>Reason</h3>
      <div class="muted" id="webHelper">
        <b>Web tip:</b> Pick a case from the <b>left</b> panel, analyze the conversation, pick <b>one Reason</b>, choose <b>Legit</b> or <b>Phish</b>, then <b>Submit</b>.
      </div>
      <div class="sel">Selected: <span id="selReason">—</span></div>
      <div class="chips" id="chips">
        <button class="chip" data-v="suspicious_links">Suspicious link</button>
        <button class="chip" data-v="unknown_untrustworthy">Unknown sender</button>
        <button class="chip" data-v="urgent_tone">Urgent/scare</button>
        <button class="chip" data-v="spoofed_profile">Brand spoof</button>
        <button class="chip" data-v="too_good_to_be_true">Too good</button>
        <button class="chip" data-v="legit">Looks legit</button>
      </div>

      <h3 style="margin-top:12px">Classification</h3>
      <div class="pair">
        <button id="btnLegit" class="btn">Legit</button>
        <button id="btnPhish" class="btn warn">Phish</button>
      </div>

      <h3 style="margin-top:12px">Submit</h3>
      <button id="btnSubmit" class="btn primary" style="width:100%" disabled>Submit</button>
    </aside>
  </div>
</div>

<script>
// ===== Refs/State =====
const chat        = document.getElementById('chat');
const pName       = document.getElementById('pName');
const pAvatarImg  = document.getElementById('pAvatarImg');
const pMeta       = document.getElementById('pMeta');
const pfill       = document.getElementById('pfill');

const DEFAULT_AVATAR = 'assets/img/avatars/default.svg';
const USER_AVATAR    = 'assets/img/avatars/user.svg';
let   ATTACKER_AVATAR = DEFAULT_AVATAR;

let current = { id:null, choice:'', reason:'', from:'', avatar:'', days:'1' };
let previewReasonRow = null;
let previewChoiceRow = null;

// ---- Helpers ----
function isMobile(){ return window.matchMedia('(max-width:1100px)').matches; }

/**
 * When hasCase=false → onboarding text (how to pick a case)
 * When hasCase=true  → post-selection text (what to do next)
 */
function getHelperText(hasCase){
  if (isMobile()){
    return hasCase
      ? 'Review the conversation, then tap <b>Decide</b>, pick a <b>Reason</b>, choose <b>Legit</b> or <b>Phish</b>, and <b>Submit</b>.'
      : 'Tap the <b>≡</b> Cases button to choose a case. Then review it, tap <b>Decide</b>, pick a <b>Reason</b>, select <b>Legit</b> or <b>Phish</b>, and <b>Submit</b>.';
  } else {
    return hasCase
      ? 'Analyze the conversation, pick <b>one Reason</b>, choose <b>Legit</b> or <b>Phish</b>, then <b>Submit</b>.'
      : 'Pick a case from the <b>left</b> panel. Then analyze it, pick <b>one Reason</b>, choose <b>Legit</b> or <b>Phish</b>, and <b>Submit</b>.';
  }
}


function safeAvatar(url){ return (url && url.trim()!=='') ? url : DEFAULT_AVATAR; }
function scrollChat(){ chat.scrollTop = chat.scrollHeight; }

function addRow(html, who='in'){
  const row = document.createElement('div');
  row.className = 'row ' + who;
  const msg = document.createElement('div'); msg.className = 'bubble'; msg.innerHTML = html;
  const ava = document.createElement('div'); ava.className = 'ava';
  const img = document.createElement('img'); img.src = (who === 'in') ? ATTACKER_AVATAR : USER_AVATAR;
  img.onerror = () => { img.src = DEFAULT_AVATAR; }; ava.appendChild(img);
  if (who === 'in') { row.appendChild(ava); row.appendChild(msg); } else { row.appendChild(msg); row.appendChild(ava); }
  chat.appendChild(row); scrollChat(); return row;
}

// Force a paint so the typing row visibly shows before we swap it
function forcePaint(){ chat.offsetHeight; } // read layout to flush

function typingOn(){
  const row = document.createElement('div');
  row.id = 'typingRow'; row.className = 'row in';
  row.innerHTML = `<div class="ava"><img src="${ATTACKER_AVATAR}" onerror="this.src='${DEFAULT_AVATAR}'"></div>
                   <div class="bubble" style="opacity:.85"><span class="dot"></span><span class="dot"></span><span class="dot"></span></div>`;
  chat.appendChild(row); forcePaint(); scrollChat();
}
function typingOff(){ const r = document.getElementById('typingRow'); if (r) r.remove(); }

function botSay(html, delay=900){
  // show dots, wait a tick (paint), then show text after delay
  typingOn();
  setTimeout(()=>{ typingOff(); addRow(html,'in'); }, Math.max(400, delay));
}

// ===== Welcome sequence =====
function renderWelcome(){
  chat.innerHTML = '';
  ATTACKER_AVATAR = DEFAULT_AVATAR;
  addRow('Connecting you to this conversation…','in');
  // Onboarding helper (no case yet)
  botSay(getHelperText(false), 900);
  document.getElementById('webHelper')?.replaceChildren(
    (() => { const d=document.createElement('div'); d.innerHTML = '<b>Web tip:</b> ' + getHelperText(false); return d; })()
  );
}
renderWelcome();

// Retry helper
function loadCaseById(id, from, avatar, days){
  const dummy = document.createElement('button');
  dummy.setAttribute('data-id', id);
  dummy.setAttribute('data-from', from || '');
  dummy.setAttribute('data-avatar', avatar || '');
  dummy.setAttribute('data-days', days || '1');
  loadCase(dummy);
}

// ===== Load Case (connect → typing → body → helper) =====
function loadCase(btn){
  const id    = btn.getAttribute('data-id');
  const from  = btn.getAttribute('data-from') || 'Sender';
  const avatar= safeAvatar(btn.getAttribute('data-avatar'));
  const days  = btn.getAttribute('data-days') || '1';

  current = { id, choice:'', reason:'', from, avatar, days };
  ATTACKER_AVATAR = avatar;
  pAvatarImg.src  = avatar; pAvatarImg.onerror = () => { pAvatarImg.src = DEFAULT_AVATAR; };
  pName.textContent = from;
  pMeta.textContent = `SMS · ${days} day${days==='1'?'':'s'} ago`;

  // ✅ Right-panel helper switches to post-selection guidance
  document.getElementById('webHelper')?.replaceChildren(
    (() => { const d=document.createElement('div'); d.innerHTML = '<b>Web tip:</b> ' + getHelperText(true); return d; })()
  );

  chat.innerHTML = '';
  addRow('Connecting you to this conversation…','in');
  typingOn();
// inside loadCase(btn)...
fetch('api_sms_get_body.php?id=' + encodeURIComponent(id) + '&module_id=<?= (int)$moduleId ?>',
    { headers:{ 'Accept':'application/json' } })
  .then(r=>r.json())
  .then(data=>{
    typingOff();
    if (!data.ok){
      const retryHtml = `Failed to load the message. <button class="btn" style="padding:6px 10px;margin-left:6px" onclick="loadCaseById('${id}','${from.replace(/'/g,"&#39;")}','${avatar.replace(/'/g,"&#39;")}','${days}')">Retry</button>`;
      addRow(retryHtml, 'in');
      return;
    }

    // 1) Show the case body
    addRow(data.body_html, 'in');

    // 2) Show the post-selection helper (we *already have a case*)
    botSay(getHelperText(true), 800);
  })
  .catch(()=>{
    typingOff();
    const retryHtml = `Failed to load the message. <button class="btn" style="padding:6px 10px;margin-left:6px" onclick="loadCaseById('${id}','${from.replace(/'/g,"&#39;")}','${avatar.replace(/'/g,"&#39;")}','${days}')">Retry</button>`;
    addRow(retryHtml, 'in');
  });


  clearPreviews();
  updateSubmitButtons();
  closeCases();
}

// Bind case lists (delegation for any future inserts)
document.getElementById('caseList')?.addEventListener('click', e=>{
  const btn = (e.target instanceof Element) ? e.target.closest('.case') : null;
  if (btn) loadCase(btn);
});
document.getElementById('mCaseList')?.addEventListener('click', e=>{
  const btn = (e.target instanceof Element) ? e.target.closest('.case') : null;
  if (btn) loadCase(btn);
});

// ===== Desktop controls =====
const chips = Array.from(document.querySelectorAll('#chips .chip'));
const selOut = document.getElementById('selReason');
chips.forEach(ch=>{
  ch.addEventListener('click', ()=>{
    chips.forEach(a=>a.classList.remove('active'));
    ch.classList.add('active');
    const label = ch.textContent.trim();
    current.reason = ch.getAttribute('data-v') || '';
    selOut && (selOut.textContent = label || '—');
    previewReasonRow?.remove();
    previewReasonRow = addRow('Reason: ' + label, 'out');
    updateSubmitButtons();
  });
});
const btnLegit  = document.getElementById('btnLegit');
const btnPhish  = document.getElementById('btnPhish');
const btnSubmit = document.getElementById('btnSubmit');
btnLegit && btnLegit.addEventListener('click', ()=>{
  current.choice = 'legit';
  previewChoiceRow?.remove();
  previewChoiceRow = addRow('I think this is <b>Legit</b>.', 'out');
  updateSubmitButtons();
});
btnPhish && btnPhish.addEventListener('click', ()=>{
  current.choice = 'phish';
  previewChoiceRow?.remove();
  previewChoiceRow = addRow('I think this is <b>Phish</b>.', 'out');
  updateSubmitButtons();
});

// ===== Mobile drawers + sheet + FAB =====
const casesWrap   = document.getElementById('casesWrap');
const casesScrim  = document.getElementById('casesScrim');
const casesClose  = document.getElementById('casesClose');
const btnCases    = document.getElementById('btnCases');
const fabDecide   = document.getElementById('fabDecide');

function openCases(){ if (casesWrap){ casesWrap.classList.add('open'); casesWrap.setAttribute('aria-hidden','false'); } }
function closeCases(){ if (casesWrap){ casesWrap.classList.remove('open'); casesWrap.setAttribute('aria-hidden','true'); } }
btnCases && btnCases.addEventListener('click', openCases);
casesClose && casesClose.addEventListener('click', closeCases);
casesScrim && casesScrim.addEventListener('click', closeCases);
document.addEventListener('keydown', (e)=>{ if(e.key==='Escape') closeCases(); });

const mSheet    = document.getElementById('mSheet');
const mBackdrop = document.getElementById('mSheetBackdrop');
const mChips    = Array.from(document.querySelectorAll('#mChips .chip'));
const mLegit    = document.getElementById('mLegit');
const mPhish    = document.getElementById('mPhish');
const mSubmit   = document.getElementById('mSubmit');

function openSheet(){ if (mSheet){ mSheet.classList.add('open'); mBackdrop?.classList.add('show'); } }
function closeSheet(){ if (mSheet){ mSheet.classList.remove('open'); mBackdrop?.classList.remove('show'); } }
function closeSheetInstant(){
  if (!mSheet) return;
  mSheet.classList.add('instant'); mSheet.classList.remove('open');
  if (mBackdrop){ mBackdrop.classList.add('instant'); mBackdrop.classList.remove('show'); }
  void mSheet.offsetHeight;
  mSheet.classList.remove('instant'); mBackdrop?.classList.remove('instant');
}
fabDecide && fabDecide.addEventListener('click', ()=>{ if (current.id) openSheet(); });

mChips.forEach(ch=>{
  ch.addEventListener('click', ()=>{
    mChips.forEach(a=>a.classList.remove('active'));
    ch.classList.add('active');
    const label = ch.textContent.trim();
    current.reason = ch.getAttribute('data-v') || '';
    previewReasonRow?.remove();
    previewReasonRow = addRow('Reason: ' + label, 'out');
    updateSubmitButtons();
  });
});
mLegit && mLegit.addEventListener('click', ()=>{
  current.choice = 'legit';
  previewChoiceRow?.remove();
  previewChoiceRow = addRow('I think this is <b>Legit</b>.', 'out');
  updateSubmitButtons();
});
mPhish && mPhish.addEventListener('click', ()=>{
  current.choice = 'phish';
  previewChoiceRow?.remove();
  previewChoiceRow = addRow('I think this is <b>Phish</b>.', 'out');
  updateSubmitButtons();
});
mBackdrop && mBackdrop.addEventListener('click', closeSheet);

// ===== Link inspector (block real nav) =====
const liWrap  = document.getElementById('liWrap');
const liScrim = document.getElementById('liScrim');
const liShown = document.getElementById('liShown');
const liHref  = document.getElementById('liHref');

function showLinkInspector(shown, href){
  liShown.textContent = shown || '—';
  liHref.textContent  = href || '—';
  liWrap.classList.add('show'); liWrap.setAttribute('aria-hidden','false');
}
function hideLinkInspector(){ liWrap.classList.remove('show'); liWrap.setAttribute('aria-hidden','true'); }
liScrim && liScrim.addEventListener('click', hideLinkInspector);
document.addEventListener('keydown', (e)=>{ if(e.key==='Escape') hideLinkInspector(); });

chat.addEventListener('click', (e)=>{
  const a = (e.target instanceof Element) ? e.target.closest('a') : null;
  if (!a) return;
  e.preventDefault();
  showLinkInspector((a.textContent||'').trim(), a.getAttribute('href')||'');
}, true);

// ===== Submit =====
function updateSubmitButtons(){
  const ok = (current.id && current.choice && current.reason);
  document.getElementById('btnSubmit') && (document.getElementById('btnSubmit').disabled = !ok);
  document.getElementById('mSubmit')   && (document.getElementById('mSubmit').disabled   = !ok);
}
function clearPreviews(){
  const sel = document.getElementById('selReason'); if (sel) sel.textContent = '—';
  previewReasonRow?.remove(); previewChoiceRow?.remove();
  previewReasonRow = previewChoiceRow = null;
  document.querySelectorAll('#chips .chip').forEach(a=>a.classList.remove('active'));
  document.querySelectorAll('#mChips .chip').forEach(a=>a.classList.remove('active'));
}

async function doSubmit(){
  if (!current.id || !current.choice || !current.reason) return;
  closeSheetInstant();
  document.getElementById('btnSubmit') && (document.getElementById('btnSubmit').disabled = true);
  document.getElementById('mSubmit')   && (document.getElementById('mSubmit').disabled   = true);

  const analyzing = addRow(`<span class="pg-spin"></span> Analyzing your answer…`, 'in');
  try{
    const body = new URLSearchParams({ case_id: current.id, choice: current.choice, reason: current.reason, module_id: '<?= (int)$moduleId ?>' });
    const res  = await fetch('ajax_sms_submit.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body });
    const data = await res.json();

    if (!data.ok){
      analyzing.innerHTML = '⚠ ' + (data.message || 'Something went wrong. Please try again.');
      document.getElementById('btnSubmit') && (document.getElementById('btnSubmit').disabled = false);
      document.getElementById('mSubmit')   && (document.getElementById('mSubmit').disabled   = false);
      return;
    }

    analyzing.innerHTML = `${data.correct ? '✅ <b>Correct</b>' : '❌ <b>Not quite</b>'} — <b>${data.points}</b> XP awarded.`;
    if (data.explain_html) addRow(data.explain_html, 'in');

    document.querySelectorAll(`.case[data-id="${CSS.escape(String(current.id))}"]`).forEach(card=>{
      if (!card.querySelector('.done')) {
        const head = card.querySelector('.head') || card;
        const span = document.createElement('span'); span.className='done'; span.textContent='Done';
        head.appendChild(span);
      }
    });

    if (Number.isFinite(data.completed_count) && Number.isFinite(data.total_count)) {
      const pct = data.total_count ? Math.round((data.completed_count*100)/data.total_count) : 0;
      pfill && (pfill.style.width = pct + '%');
      const t = document.getElementById('progressText'); const p = document.getElementById('progressPct');
      if (t) t.textContent = `${data.completed_count} / ${data.total_count} completed`;
      if (p) p.textContent = `${pct}%`;
    }

    clearPreviews();
    current.choice=''; current.reason='';
    updateSubmitButtons();
    scrollChat();

  } catch(e){
    analyzing.innerHTML = '⚠ Submit failed. Please try again.';
    document.getElementById('btnSubmit') && (document.getElementById('btnSubmit').disabled = false);
    document.getElementById('mSubmit')   && (document.getElementById('mSubmit').disabled   = false);
  }
}
document.getElementById('btnSubmit') && document.getElementById('btnSubmit').addEventListener('click', doSubmit);
document.getElementById('mSubmit')  && document.getElementById('mSubmit').addEventListener('click', doSubmit);
</script>

<script>
function renderTpl(tpl, ctx){
  return tpl.replace(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g, (_,k)=> (ctx[k] ?? ''));
}
// Example when you receive task JSON:
const ctx = {
  first_name: window.USER_FIRST_NAME || 'there',
  username: window.USER_USERNAME || ''
};
task.body_html = renderTpl(task.body_html, ctx);
document.querySelector('#task-body').innerHTML = task.body_html;
</script>

</body>
</html>
