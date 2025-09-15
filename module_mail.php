<?php
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

if (!isset($_SESSION['user_id'])) {
  header("Location: index.php?msg=" . urlencode("Please login first."));
  exit;
}

$userId   = (int) $_SESSION['user_id'];
$userName = htmlspecialchars($_SESSION['user_name'] ?? 'You', ENT_QUOTES, 'UTF-8');
$moduleId = (int)($_GET['id'] ?? 0);

require_once __DIR__ . '/inc/cases_table.php';
$caseTbl = getCasesTableForModule($pdo, $moduleId);

// helpers
function pg_has_col(PDO $pdo, string $t, string $c): bool {
  $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns
                      WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
  $q->execute([$t,$c]);
  return (int)$q->fetchColumn() > 0;
}
if (!pg_has_col($pdo, $caseTbl, 'from_name')) $caseTbl = 'training_mail_cases';
$hasExplain = pg_has_col($pdo, $caseTbl, 'explain_html');

/* dropdown vocab */
$whoRows = $pdo->query("SELECT `key`, label FROM training_who_options WHERE active=1 ORDER BY sort_order,id")->fetchAll(PDO::FETCH_ASSOC);
$whyRows = $pdo->query("SELECT `key`, label FROM training_why_clues  WHERE active=1 ORDER BY sort_order,id")->fetchAll(PDO::FETCH_ASSOC);

/* cases */
$stmt = $pdo->prepare("
  SELECT id,module_id,from_name,from_avatar,subject,snippet,days_ago,
         requester_email_html,forwarded_email_html,
         ".($hasExplain ? "COALESCE(explain_html,'')" : "''")." AS explain_html,
         COALESCE(correct_is_phish,1) AS correct_is_phish,
         COALESCE(correct_sender,'')  AS correct_sender,
         COALESCE(correct_content,'') AS correct_content,
         COALESCE(correct_extra,'')   AS correct_extra,
         COALESCE(points,10)          AS points
  FROM `$caseTbl` WHERE module_id=? ORDER BY id ASC
");
$stmt->execute([$moduleId]);
$cases = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* completed */
$doneStmt = $pdo->prepare("
  SELECT DISTINCT p.case_id
  FROM training_mail_progress p
  JOIN `$caseTbl` c ON c.id=p.case_id
  WHERE p.user_id=? AND c.module_id=?
");
$doneStmt->execute([$userId,$moduleId]);
$doneIds = array_map('intval',$doneStmt->fetchAll(PDO::FETCH_COLUMN));

$completedCount = 0;
$totalCount     = count($cases);

function renderOptions(array $rows, string $ph='— select —'): string {
  $h = '<option value="">'.htmlspecialchars($ph,ENT_QUOTES,'UTF-8').'</option>';
  foreach ($rows as $r) $h .= '<option value="'.htmlspecialchars($r['key'],ENT_QUOTES,'UTF-8').'">'.htmlspecialchars($r['label'],ENT_QUOTES,'UTF-8').'</option>';
  return $h;
}
$whoOptionsHtml = renderOptions($whoRows);
$whyOptionsHtml = renderOptions($whyRows);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
<title>PhishGuard Mail</title>
<link rel="icon" type="image/svg+xml"
 href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='12' fill='%236d28d9'/%3E%3Cpath d='M18 38l14-20 14 20-14 8z' fill='white'/%3E%3C/svg%3E">
<style>
  *{box-sizing:border-box}
  html,body{height:100%}
  html,body{overflow-x:hidden}
  body.pg-mail{margin:0;background:#081024;color:#dbe3ff;font:16px/1.45 system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial;-webkit-text-size-adjust:100%}
  img,svg,video,canvas,iframe{max-width:100%;height:auto}

  .pg-mail-top{position:sticky;top:0;z-index:40;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;background:linear-gradient(180deg,#1f2a52 0%,#0f1733 100%);border-bottom:1px solid rgba(148,163,184,.15)}
  .pg-mail-top .brand{font-weight:800;letter-spacing:.3px}
  .pg-mail-top .backToDash{color:#a9baf0;text-decoration:none;font-weight:700}

  .mail-wrap{display:grid;grid-template-columns:320px 1fr 420px;gap:16px;max-width:1400px;width:100%;padding:0 12px;margin:12px auto 40px;align-items:start}
  @media (max-width:1100px){.mail-wrap{grid-template-columns:300px 1fr}.forward-view{display:none}}
  @media (max-width:680px){.mail-wrap{grid-template-columns:1fr;gap:12px}}

  .pane-scroller{max-height:calc(100dvh - 150px);overflow:auto}
  @media (max-width:680px){.pane-scroller{max-height:calc(100dvh - 120px)}}

  .inbox{background:rgba(8,15,30,.5);border:1px solid #24304c;border-radius:14px;padding:12px;position:sticky;top:68px}
  .section-title{margin:4px 2px 8px;font-size:.85rem;font-weight:800;letter-spacing:.6px;color:#a9baf0}
  .preview{display:block;width:100%;text-align:left;cursor:pointer;padding:16px 18px;border-radius:16px;border:1px solid #24304c;background:rgba(8,15,30,.55);transition:border-color .18s,background .18s}
  .preview:hover{border-color:#34466e;background:rgba(12,22,44,.65)}
  .preview .title{display:flex;align-items:center;gap:10px;font-weight:700;color:#e8edf7;margin:0 0 6px}
  .preview .sub{color:#a6b1c3;font-size:.95rem;display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden}
  .preview .time{color:#7b859a;font-size:.82rem;margin-top:8px}
  .preview.completed{border-color:rgba(34,197,94,.40);background:rgba(6,78,59,.16)}
  .pill-done{margin-left:auto;font-size:.75rem;font-weight:800;color:#0b3b23;background:#34d399;border-radius:999px;padding:3px 10px}

  .thread-col{background:rgba(8,15,30,.5);border:1px solid #24304c;border-radius:14px;padding:14px;min-width:0}
  .tasks-progress{margin:6px 0 14px}
  .tp-row{display:flex;align-items:center;gap:12px}
  .tp-label{color:#a9baf0;font-weight:700}
  .tp-bar{flex:1;height:10px;background:rgba(255,255,255,.08);border-radius:999px;overflow:hidden}
  .tp-fill{height:100%;width:0;background:linear-gradient(90deg,#22c55e,#10b981);border-radius:999px;transition:width .25s}

  .thread{display:block}
  .thread[hidden]{display:none}
  .thread-title{margin:0 0 10px;font-size:1.1rem;line-height:1.25;word-break:break-word}

  .meta-line{display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;background:rgba(15,23,42,.55);border:1px solid #24304c;margin-bottom:12px}
  .avatar{width:36px;height:36px;border-radius:999px;background:linear-gradient(180deg,#25406e,#1c2a4f);color:#e8edf7;display:flex;align-items:center;justify-content:center;font-weight:800;flex:0 0 36px}
  .opening{background:#0b1533ea;border:1px solid #223050;border-radius:10px;padding:12px}

  .actions{display:flex;gap:14px;padding:10px 0 2px;flex-wrap:wrap}
  .link-btn{background:none;border:none;color:#93c5fd;font-weight:700;cursor:pointer;padding:0}
  .link-btn:hover{text-decoration:underline}
  /* mobile: never show inline Reply link + card under thread */
  @media (max-width:680px){
    .actions .js-reply{ display:none !important; }
    .pane-thread .reply-card{ display:none !important; }
  }

  .reply-card{margin-top:10px;padding:12px;border-radius:12px;border:1px solid #223050;background:rgba(9,16,33,.7)}
  .reply-card .sentence{display:flex;flex-wrap:wrap;align-items:center;gap:10px}
  .reply-card .sentence .answer{min-width:180px;max-width:340px;width:auto;padding:6px 10px;background:#0c1430;color:#e5edff;border:1px solid #2a3a66;border-radius:8px}
  @media (max-width:480px){
    .reply-card .sentence{display:grid;grid-template-columns:1fr 1fr;column-gap:8px;row-gap:6px}
    .reply-card .sentence span{grid-column:1/-1;font-size:.92rem;line-height:1.3}
    .reply-card .sentence .answer{min-width:0;max-width:100%;width:100%;height:34px;padding:4px 8px}
  }
  .reply-actions{display:flex;align-items:center;gap:10px;margin-top:8px;flex-wrap:wrap}
  .primary{background:#4f46e5;color:#fff;border:none;border-radius:10px;padding:8px 11px;font-weight:800;cursor:pointer}
  .primary[disabled]{opacity:.5;cursor:not-allowed}
  .points{color:#a9baf0}

  .email-feedback{margin-top:12px;border-radius:12px;overflow:hidden;border:1px solid #223050;background:#0b1533}
  .ef-head{background:#111a3d;padding:12px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
  .ef-user{display:flex;align-items:center;gap:10px}
  .ef-avatar{width:28px;height:28px;border-radius:999px;background:#334155;display:flex;align-items:center;justify-content:center;font-weight:800}
  .ef-body{padding:12px;background:#0b1533}
  .ef-block{margin-top:10px}
  .ef-block-title{font-weight:800;margin-bottom:6px;color:#a9baf0}
  .ef-quote{background:#09112a;border:1px solid #1e2b4f;border-radius:10px;padding:10px}
  .ef-db{background:#09112a;border:1px solid #1e2b4f;border-radius:10px;padding:10px}

  .forward-view{position:sticky;top:68px;background:rgba(8,15,30,.5);border:1px solid #24304c;border-radius:14px;padding:12px;min-width:0}
  .fv-empty{color:#94a3b8;font-style:italic}
  .fv-card{background:#0b1533ea;border:1px solid #223050;border-radius:10px;padding:12px;max-width:100%}
  .fv-card *{max-width:100%;overflow-wrap:anywhere}

  /* ===== SHEET defaults (hidden on desktop) ===== */
  .sheet, .sheet-backdrop { display:none; }

  /* ===== MOBILE SHEET ===== */
  @media (max-width:680px){
    :root{ --sendH: 56px; }

    .sheet,.sheet-backdrop{display:block}
    .sheet-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.35);opacity:0;pointer-events:none;transition:opacity .2s;z-index:70}
    .sheet{
      position:fixed;inset:0;transform:translateY(100%);background:#081024;z-index:71;
      display:flex;flex-direction:column;transition:transform .25s ease;
    }
    .sheet.open{transform:translateY(0)}
    .sheet-backdrop.open{opacity:1;pointer-events:auto}

    .fit{
      flex:1 1 auto;min-height:0;display:grid;
      grid-template-rows: 1fr auto var(--sendH);
      row-gap:8px;
      padding:8px 10px calc(8px + env(safe-area-inset-bottom,0px));
      overflow:hidden;
    }

    .email-pane{min-height:0;overflow:auto;-webkit-overflow-scrolling:touch}
    .email-card{border:1px solid #24304c;background:#0b1533ea;border-radius:12px;padding:14px 10px;position:relative;width:100%;box-shadow:0 6px 20px rgba(0,0,0,.35)}
    .btn-close-card{
      position:absolute;top:8px;right:8px;z-index:2;
      background:rgba(20,28,60,.9);border:1px solid #3a4569;color:#e6eeff;
      padding:6px 12px;border-radius:9999px;font-weight:800;cursor:pointer;line-height:1;box-shadow:0 4px 14px rgba(0,0,0,.45);backdrop-filter: blur(3px);
    }

    .scale-wrap{width:100%}
    .scale-inner{width:100%!important;display:block}
    .scale-inner *{box-sizing:border-box;max-width:100%}
    .scale-inner img{height:auto}
    .scale-inner table{max-width:100%!important;border-collapse:collapse!important;table-layout:auto!important}
    .scale-inner td,.scale-inner th{word-break:break-word;padding:.35rem .5rem;vertical-align:top}
    .scale-inner h1,.scale-inner h2,.scale-inner h3{margin:.45rem 0 .3rem;line-height:1.25}
    .scale-inner p{margin:.38rem 0}

    .reply-fields{border:1px solid #24304c;background:#09122d;border-radius:12px;padding:8px}
    .reply-fields .reply-card{margin:0;border:0;background:transparent;padding:0}
    .reply-fields .sentence{display:grid;grid-template-columns:1fr 1fr;column-gap:8px;row-gap:6px}
    .reply-fields .sentence span{grid-column:1/-1;font-size:.92rem;line-height:1.25}
    .reply-fields .sentence .answer{min-width:0;max-width:100%;width:100%;height:36px;padding:4px 8px}
    .reply-fields .sentence .js-is{grid-column:1/-1}
    .reply-fields .sentence span:last-child{display:none}

    .feedback-slot{margin-top:8px}
    .send-bar{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px;border:1px solid #24304c;background:#0b1533;border-radius:12px;min-height:var(--sendH)}
    .send-bar .reply-actions{margin:0;gap:10px}
    .send-bar .points{margin-left:auto}

    .link-reveal{
      position:fixed;left:12px;right:12px;
      bottom:calc(12px + var(--sendH) + env(safe-area-inset-bottom,0px));
      z-index:72;background:#0b1533;border:1px solid #2a3a66;border-radius:10px;
      padding:10px;font-weight:700;text-align:center;word-break:break-all;
      box-shadow:0 8px 20px rgba(0,0,0,.5)
    }
    .link-reveal small{display:block;color:#a9baf0;font-weight:500;margin-top:4px}
  }

  @media (max-width:680px){
    .mobile-state-inbox .pane-thread { display: none !important; }
    .mobile-state-task  .pane-inbox  { display: none !important; }
  }
  body.no-scroll{ overflow:hidden; touch-action:none; }

  .fab-back{position:fixed;right:14px;bottom:calc(14px + env(safe-area-inset-bottom,0px));z-index:75;border:1px solid #30406a;background:#1b2550;color:#e8eeff;padding:12px 14px;border-radius:9999px;font-weight:800;box-shadow:0 8px 18px rgba(0,0,0,.45);display:none}
  @media (max-width:680px){body.fab-visible .fab-back{display:inline-flex}}

  .green{color:#22c55e;font-weight:700}
</style>
</head>
<body class="pg-mail">

<header class="pg-mail-top">
  <div class="brand">PhishGuard Mail</div>
  <a class="backToDash" href="dashboard.php">← Back to Dashboard</a>
</header>

<main id="app" class="mail-wrap mobile-state-inbox" data-user-id="<?= $userId; ?>" data-module-id="<?= $moduleId; ?>">
  <!-- LEFT: Inbox -->
  <aside class="inbox pane-inbox">
    <h3 class="section-title">INBOX</h3>
    <div class="pane-scroller">
      <?php if (!$cases): ?>
        <div class="preview" style="opacity:.7;cursor:default">
          <div class="title">No cases yet</div>
          <div class="sub">Add rows to training_mail_cases for this module.</div>
          <div class="time">–</div>
        </div>
      <?php else: foreach ($cases as $i=>$c):
        $isDone = in_array((int)$c['id'],$doneIds,true);
        if ($isDone) $completedCount++;
      ?>
        <button class="preview <?= $isDone?'completed':''; ?>"
          data-case-id="<?= (int)$c['id']; ?>"
          data-target="thread-<?= (int)$c['id']; ?>"
          <?= $i===0?'data-active="1"':''; ?>>
          <div class="title">
            <?= htmlspecialchars($c['subject'],ENT_QUOTES,'UTF-8'); ?>
            <?php if($isDone): ?><span class="pill-done">Done</span><?php endif; ?>
          </div>
          <div class="sub"><?= htmlspecialchars($c['snippet'],ENT_QUOTES,'UTF-8'); ?></div>
          <div class="time"><?= (int)$c['days_ago']; ?> days ago</div>
        </button>
      <?php endforeach; endif; ?>
    </div>
  </aside>

  <!-- MIDDLE: Thread -->
  <section class="thread-col pane-thread" id="threadCol">
    <?php if ($cases): ?>
      <div class="tasks-progress">
        <div class="tp-row">
          <div class="tp-label"><strong id="tpCount"><?= $completedCount; ?></strong> / <?= $totalCount; ?> tasks complete</div>
          <div class="tp-bar"><?php $initialPct=$totalCount?round(($completedCount/$totalCount)*100):0; ?>
            <div id="tpFill" class="tp-fill" style="width:<?= $initialPct; ?>%"></div>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="pane-scroller">
    <?php if ($cases): foreach ($cases as $i=>$c):
      $threadId='thread-'.(int)$c['id']; $active=($i===0);
      $expectedIs=((int)$c['correct_is_phish']===1)?'is':'is_not';
      $expectedClues=array_filter([trim((string)$c['correct_sender']),trim((string)$c['correct_content']),trim((string)$c['correct_extra'])]);
      $expectedStr=implode('|',$expectedClues);
      $maxPoints=(int)$c['points']?:10;
      $explainHtml=(string)($c['explain_html']??'');

      $fromName   = trim((string)$c['from_name']);
      $avatarUrl  = trim((string)$c['from_avatar']);
      $avatarChar = mb_strtoupper(mb_substr($fromName ?: '?', 0, 1, 'UTF-8'),'UTF-8');
    ?>
    <section class="thread" id="<?= $threadId; ?>" data-case-id="<?= (int)$c['id']; ?>" <?= $active?'':'hidden'; ?>
      data-expected-is="<?= htmlspecialchars($expectedIs,ENT_QUOTES,'UTF-8'); ?>"
      data-expected-clues="<?= htmlspecialchars($expectedStr,ENT_QUOTES,'UTF-8'); ?>"
      data-max-points="<?= $maxPoints; ?>">

      <h1 class="thread-title"><?= htmlspecialchars($c['subject'],ENT_QUOTES,'UTF-8'); ?></h1>

      <div class="meta-line">
        <div class="avatar" style="<?php
          if (!empty($avatarUrl)) {
            echo "background:url('".htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8')."') center/cover no-repeat;color:transparent;";
          }
        ?>"><?= empty($avatarUrl)?htmlspecialchars($avatarChar,ENT_QUOTES,'UTF-8'):''; ?></div>
        <div class="from"><?= htmlspecialchars($fromName ?: 'Unknown sender', ENT_QUOTES, 'UTF-8'); ?></div>
        <div>to</div>
        <div class="to"><?= $userName; ?></div>
        <div class="right-time"><?= (int)$c['days_ago']; ?> days ago</div>
      </div>

      <div class="opening"><?php echo $c['requester_email_html']; ?></div>

      <div class="actions">
        <button class="link-btn js-reply">Reply</button>
        <button class="link-btn js-view-fwd">View forwarded email</button>
      </div>

      <!-- Inline reply (desktop only) -->
      <div class="reply-card" hidden>
        <div class="sentence">
          <span>The email you received</span>
          <select class="answer js-is">
            <option value="">— select —</option>
            <option value="is">is</option>
            <option value="is_not">is not</option>
          </select>
          <span>a phishing email, because</span>
          <select class="answer js-who1"><?= $whoOptionsHtml; ?></select>
          <select class="answer js-why1"><?= $whyOptionsHtml; ?></select>
          <span>and</span>
          <select class="answer js-who2"><?= $whoOptionsHtml; ?></select>
          <select class="answer js-why2"><?= $whyOptionsHtml; ?></select>
          <span>.</span>
        </div>

        <div class="reply-actions">
          <button class="primary js-send" disabled>Send</button>
          <span class="points">Points earned (this reply): <strong class="js-points">0</strong></span>
        </div>

        <!-- Feedback -->
        <div class="email-feedback" hidden>
          <div class="ef-head">
            <div class="ef-user">
              <div class="ef-avatar">A</div>
              <div class="ef-name-role">
                <div class="ef-name"><?= htmlspecialchars($fromName ?: 'Sender', ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="ef-role"><?= $userName; ?></div>
              </div>
            </div>
            <div class="ef-title">
              <span class="js-efPoints">You earned 0 points!</span>
              &nbsp;Your analysis was <strong class="js-efQuality">helpful</strong>.
            </div>
            <div class="ef-sub">in a few seconds</div>
          </div>

          <div class="ef-body">
            <div class="ef-db js-efDB" hidden></div>

            <div class="ef-block js-efProvidedWrap">
              <div class="ef-block-title">This is the answer you provided:</div>
              <div class="ef-quote js-efProvided"></div>
            </div>

            <div class="ef-block js-efExpectedWrap">
              <div class="ef-block-title">And this is the answer we expected:</div>
              <div class="ef-quote js-efExpected"></div>
            </div>
          </div>
        </div>
      </div>

      <div class="forward-card" hidden><?php echo $c['forwarded_email_html']; ?></div>
      <div class="expected-html" hidden><?php echo $explainHtml; ?></div>
    </section>
    <?php endforeach; endif; ?>
    </div>
  </section>

  <!-- RIGHT: desktop forwarded viewer -->
  <aside class="forward-view desktop-only" id="forwardViewer">
    <h3 class="section-title">FORWARDED EMAIL</h3>
    <div class="pane-scroller">
      <div class="fv-empty">Click “View forwarded email” to see details here.</div>
      <div class="fv-card" id="forwardViewerBody" hidden></div>
    </div>
  </aside>
</main>

<!-- MOBILE SHEET -->
<div class="sheet-backdrop" id="sheetBackdrop"></div>
<div class="sheet" id="sheet">
  <div class="fit">
    <section class="email-pane">
      <div class="email-card">
        <button class="btn-close-card" id="sheetCloseBtn">Close</button>
        <div class="scale-wrap" id="scaleWrap">
          <div class="fv-card scale-inner" id="scaleInner"></div>
        </div>
      </div>
    </section>

    <section class="reply-fields" id="replyFields">
      <div id="sheetReply"></div>
      <div id="feedbackSlot" class="feedback-slot"></div>
    </section>

    <section class="send-bar">
      <div id="sheetActions"></div>
    </section>
  </div>

  <!-- URL reveal toast (mobile) -->
  <div id="linkReveal" class="link-reveal" hidden aria-live="polite"></div>
</div>

<!-- Floating back-to-inbox (mobile — after feedback) -->
<button id="fabBack" class="fab-back" type="button">← Inbox</button>

<script>
(() => {
  const app      = document.getElementById('app');
  const previews = document.querySelectorAll('.preview');
  const threadCol= document.getElementById('threadCol');
  const threads  = document.querySelectorAll('.thread');

  const fv      = document.getElementById('forwardViewer');
  const fvBody  = document.getElementById('forwardViewerBody');
  const fvEmpty = fv?.querySelector('.fv-empty');

  const isMobile = () => window.matchMedia('(max-width: 680px)').matches;
  const fabBack  = document.getElementById('fabBack');

  function clearRightViewer(){
    if(!fv||!fvBody||!fvEmpty) return;
    fvBody.innerHTML=''; fvBody.hidden=true; fvEmpty.hidden=false;
  }
  function showThreadById(id){ threads.forEach(t=>t.hidden=(t.id!==id)); document.getElementById(id)?.scrollIntoView({behavior:'smooth',block:'start'}); }

  const tpCountEl=document.getElementById('tpCount');
  const tpFillEl =document.getElementById('tpFill');
  function computeProgress(){ const total=document.querySelectorAll('.preview').length; const done=document.querySelectorAll('.preview.completed').length; const pct=total?Math.round((done/total)*100):0; if(tpCountEl) tpCountEl.textContent=String(done); if(tpFillEl) tpFillEl.style.width=pct+'%'; }
  computeProgress();

  function whoText(v){ if(v==='the_sender')return'the sender'; if(v==='the_messages')return"the message's"; if(v==='the_subject')return'the subject'; if(v==='the_attachment')return'the attachment'; return"the message's"; }
  function whyText(v){ if(v==='unknown_untrustworthy')return'is not known or is not trustworthy'; if(v==='suspicious_links')return'contains hyperlinks with suspicious or malicious URLs'; if(v==='urgent_tone')return'has an urgent tone'; if(v==='attachment_risky')return'includes a risky attachment'; if(v==='brand_impersonation')return'impersonates a brand or uses a spoofed domain'; return''; }
  function whoForWhy(why){ switch(why){case'unknown_untrustworthy':case'brand_impersonation':return'the_sender';case'suspicious_links':return'the_messages';case'urgent_tone':return'the_subject';case'attachment_risky':return'the_attachment';default:return'the_messages';} }

  /* ===== MOBILE SHEET ===== */
  const sheetBackdrop   = document.getElementById('sheetBackdrop');
  const sheet           = document.getElementById('sheet');
  const sheetCloseBtn   = document.getElementById('sheetCloseBtn');

  const scaleInner = document.getElementById('scaleInner');
  const sheetReplyHolder = document.getElementById('sheetReply');
  const sheetActions     = document.getElementById('sheetActions');
  const feedbackSlot     = document.getElementById('feedbackSlot');
  const linkReveal       = document.getElementById('linkReveal');

  let replyPlaceholder=null, movedReplyCard=null;
  let actionsPlaceholder=null, movedActions=null;
  let fbPlaceholder=null, movedFeedback=null;
  let lastEmailHTML='';

  function getActiveThread(){ return document.querySelector('.thread:not([hidden])'); }

  // Try to flatten skinny column tables
  function normalizeEmailColumns(root){
    if (!root) return;
    root.querySelectorAll('table').forEach(t=>{
      try{
        const firstRow = t.querySelector('tr');
        if (!firstRow) return;
        const cells = firstRow.querySelectorAll('td,th');
        if (cells.length >= 2) {
          const col1Len = (cells[0].innerText || '').replace(/\s+/g,'').length;
          const col2Len = (cells[1].innerText || '').replace(/\s+/g,'').length;
          if (col1Len > 40 && col2Len < 10) {
            t.style.display='block'; t.style.width='100%';
            t.querySelectorAll('tr').forEach(tr=>{
              tr.style.display='block';
              tr.querySelectorAll('td,th').forEach(td=>{
                td.style.display='block'; td.style.width='100%';
              });
            });
          }
        }
      }catch(_){}
    });
  }

  function isFeedbackVisible() {
    if (!isMobile()) return false;
    if (!app.classList.contains('mobile-state-task')) return false;
    if (!sheet.classList.contains('open')) return false;
    const visibleFb = document.querySelector('#feedbackSlot .email-feedback:not([hidden])');
    return !!visibleFb;
  }
  function updateFabVisibility() {
    document.body.classList.toggle('fab-visible', isFeedbackVisible());
  }
  window.addEventListener('resize', updateFabVisibility);

  function showLinkReveal(raw){
    const url = raw || '';
    try{
      const u = new URL(url, window.location.href);
      linkReveal.innerHTML = `<strong>${u.hostname}</strong><small>${u.href}</small>`;
    }catch{
      linkReveal.textContent = url;
    }
    linkReveal.hidden = false;
    clearTimeout(linkReveal._t);
    linkReveal._t = setTimeout(()=> linkReveal.hidden = true, 3500);
  }
  function hookLinksForReveal(container){
    container.querySelectorAll('a, [role="link"]').forEach(a=>{
      a.addEventListener('click', (ev)=>{
        if (!isMobile()) return;
        ev.preventDefault(); ev.stopPropagation();
        showLinkReveal(a.getAttribute('href') || '');
      });
    });
  }

  function openSheetWithEmail(html){
    lastEmailHTML = html || lastEmailHTML;
    if (!lastEmailHTML) return;

    scaleInner.innerHTML = lastEmailHTML;
    normalizeEmailColumns(scaleInner);
    hookLinksForReveal(scaleInner);

    const active = getActiveThread();
    const rc = active?.querySelector('.reply-card');
    if (rc) {
      rc.hidden = false;
      if (!replyPlaceholder) replyPlaceholder = document.createElement('div');
      if (!rc.nextSibling || rc.nextSibling !== replyPlaceholder) rc.after(replyPlaceholder);
      sheetReplyHolder.appendChild(rc);
      movedReplyCard = rc;

      const actions = rc.querySelector('.reply-actions');
      if (actions) {
        if (!actionsPlaceholder) actionsPlaceholder = document.createElement('div');
        if (!actions.nextSibling || actions.nextSibling !== actionsPlaceholder) actions.after(actionsPlaceholder);
        sheetActions.appendChild(actions);
        actions.style.marginTop='0';
        movedActions = actions;
      }

      const emailFb = rc.querySelector('.email-feedback');
      if (emailFb) {
        if (!fbPlaceholder) fbPlaceholder = document.createElement('div');
        if (!emailFb.nextSibling || emailFb.nextSibling !== fbPlaceholder) emailFb.after(fbPlaceholder);
        feedbackSlot.appendChild(emailFb);
        movedFeedback = emailFb;
      }

      // fire change to refresh enabled state if any defaults were preselected
      rc.querySelectorAll('select').forEach(sel=>{
        const ev = new Event('change', { bubbles:true });
        sel.dispatchEvent(ev);
      });
    }

    sheet.classList.add('open');
    sheetBackdrop.classList.add('open');
    document.body.classList.add('no-scroll');
    app.classList.add('mobile-state-task');
    app.classList.remove('mobile-state-inbox');
    updateFabVisibility();
  }

  function closeSheet(){
    if (movedActions && actionsPlaceholder?.parentNode){
      actionsPlaceholder.replaceWith(movedActions);
    }
    movedActions=null; actionsPlaceholder=null;

    if (movedFeedback && fbPlaceholder?.parentNode){
      fbPlaceholder.replaceWith(movedFeedback);
    }
    movedFeedback=null; fbPlaceholder=null;

    if (movedReplyCard && replyPlaceholder?.parentNode){
      replyPlaceholder.replaceWith(movedReplyCard);
      movedReplyCard.hidden = true; // keep inline reply hidden on mobile
    }
    movedReplyCard = null; replyPlaceholder = null;

    sheet.classList.remove('open');
    sheetBackdrop.classList.remove('open');
    document.body.classList.remove('no-scroll');
    updateFabVisibility();
  }

  function backToInbox(){
    closeSheet();
    app.classList.remove('mobile-state-task');
    app.classList.add('mobile-state-inbox');
    window.scrollTo({ top: 0, behavior:'smooth' });
    updateFabVisibility();
  }

  document.getElementById('sheetCloseBtn')?.addEventListener('click', backToInbox);
  document.getElementById('sheetBackdrop')?.addEventListener('click', backToInbox);
  fabBack?.addEventListener('click', backToInbox);

  // Switch thread
  previews.forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const id=btn.getAttribute('data-target'); if(!id) return;
      closeSheet();
      previews.forEach(p=>p.removeAttribute('data-active')); btn.setAttribute('data-active','1');
      showThreadById(id); clearRightViewer();
      if (isMobile()){
        app.classList.remove('mobile-state-inbox');
        app.classList.add('mobile-state-task');
        window.scrollTo({top:0,behavior:'smooth'});
      }
      updateFabVisibility();
    });
  });

  // Actions
  threadCol.addEventListener('click', (e)=>{
    const replyBtn = e.target.closest('.js-reply');
    const viewBtn  = e.target.closest('.js-view-fwd');
    if (!replyBtn && !viewBtn) return;
    e.preventDefault();
    const section = (replyBtn||viewBtn).closest('.thread');
    const fwdCard = section.querySelector('.forward-card');
    const html = fwdCard?.innerHTML || '';
    if (isMobile()) {
      openSheetWithEmail(html);
    } else {
      if (replyBtn) {
        const rc=section.querySelector('.reply-card'); rc.hidden=false; rc.scrollIntoView({behavior:'smooth',block:'center'});
      } else if (viewBtn) {
        if(!fv||!fvBody||!fvEmpty) return; fvBody.innerHTML=html; fvEmpty.hidden=true; fvBody.hidden=false;
      }
    }
    updateFabVisibility();
  });

  // scoring + feedback
  document.querySelectorAll('.thread').forEach(section=>{
    const sendBtn=section.querySelector('.js-send');
    const ptsEl=section.querySelector('.js-points');

    const selIs=section.querySelector('.js-is');
    const who1=section.querySelector('.js-who1');
    const why1=section.querySelector('.js-why1');
    const who2=section.querySelector('.js-who2');
    const why2=section.querySelector('.js-why2');

    const replyCard = section.querySelector('.reply-card');
    const emailFb   = section.querySelector('.reply-card .email-feedback');
    const efPoints  = section.querySelector('.js-efPoints');
    const efQuality = section.querySelector('.js-efQuality');
    const efProvided= section.querySelector('.js-efProvided');
    const efExpected= section.querySelector('.js-efExpected');
    const efProvidedWrap = section.querySelector('.js-efProvidedWrap');
    const efExpectedWrap = section.querySelector('.js-efExpectedWrap');
    const efDB      = section.querySelector('.js-efDB');

    const expectedIs=section.getAttribute('data-expected-is')||'is';
    const expectedClues=(section.getAttribute('data-expected-clues')||'').split('|').filter(Boolean);
    const maxPoints=Math.max(1, Math.min(10, Number(section.getAttribute('data-max-points')||10)));
    const expectedHtmlEl=section.querySelector('.expected-html');

    function updateSendEnabled(){
      const ok=!!(selIs?.value && who1?.value && why1?.value && who2?.value && why2?.value);
      if (sendBtn) sendBtn.disabled=!ok;
    }
    [selIs,who1,why1,who2,why2].forEach(el=>el?.addEventListener('change',updateSendEnabled));
    updateSendEnabled();

    function sentenceFromSelections(isVal, w1,y1,w2,y2){
      const isText=(isVal==='is')?'is':'is not';
      const parts=[];
      if (w1 && y1) parts.push(`${whoText(w1)} <span class="green">${whyText(y1)}</span>`);
      if (w2 && y2) parts.push(`${whoText(w2)} <span class="green">${whyText(y2)}</span>`);
      const because=parts.length?`, because ${parts.join(' and ')}`:'';
      return `The email you received <span class="green">${isText}</span> a phishing email${because}.`;
    }

    function evaluate(){
      const selectedIs=selIs?.value||'';
      const cluesSet=new Set([why1?.value,why2?.value].filter(Boolean));
      let pts=(selectedIs===expectedIs)?4:0;
      let matched=0; expectedClues.forEach(key=>{ if(cluesSet.has(key)) matched++; });
      pts+=matched*2; pts=Math.max(0,Math.min(maxPoints,Math.round(pts)));

      const userSentence=sentenceFromSelections(selectedIs, who1?.value,why1?.value, who2?.value,why2?.value);
      const expWho1=(expectedClues[0])?whoForWhy(expectedClues[0]):'';
      const expWho2=(expectedClues[1])?whoForWhy(expectedClues[1]):'';
      const expectedSentence=sentenceFromSelections(expectedIs, expWho1,expectedClues[0]||'', expWho2,expectedClues[1]||'');

      return { pts,userSentence,expectedSentence };
    }

    sendBtn?.addEventListener('click', async (e)=>{
      e.preventDefault(); if (sendBtn.disabled) return;
      const { pts,userSentence,expectedSentence }=evaluate();
      if (ptsEl) ptsEl.textContent=String(pts);

      if (emailFb) {
        const dbExplain = expectedHtmlEl ? (expectedHtmlEl.innerHTML || '').trim() : '';
        efPoints.textContent  = `You earned ${pts} point${pts===1?'':'s'}!`;
        if (efQuality) {
          efQuality.textContent = (pts >= maxPoints-1) ? 'spot on'
                               : (pts >= Math.ceil(maxPoints/2)) ? 'helpful' : 'a good try';
        }

        if (dbExplain && efDB) {
          efDB.innerHTML = dbExplain;
          efDB.hidden = false;
          if (efProvidedWrap) efProvidedWrap.hidden = true;
          if (efExpectedWrap) efExpectedWrap.hidden = true;
        } else {
          if (efDB) efDB.hidden = true;
          if (efProvidedWrap) efProvidedWrap.hidden = false;
          if (efExpectedWrap) efExpectedWrap.hidden = false;
          if (efProvided) efProvided.innerHTML = userSentence;
          if (efExpected) efExpected.innerHTML = expectedSentence;
        }

        emailFb.hidden = false;

        if (isMobile()) {
          // make sure it's in the mobile slot and scroll to it
          const slot = document.getElementById('feedbackSlot');
          if (slot && emailFb.parentNode !== slot) slot.appendChild(emailFb);
          setTimeout(()=> emailFb.scrollIntoView({ behavior:'smooth', block:'nearest' }), 60);
        } else {
          replyCard?.scrollIntoView({ behavior:'smooth', block:'start' });
        }
      }

      try{
        const body=new URLSearchParams({module_id:String(<?= $moduleId; ?>),points:String(pts)});
        await fetch('ajax_submit_reply.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});
      }catch{}

      const caseId=Number(section.getAttribute('data-case-id')||0);
      try{
        const body2=new URLSearchParams({case_id:String(caseId),points:String(pts)});
        const res2=await fetch('ajax_progress.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body2});
        await res2.json().catch(()=> ({}));
        const previewBtn=document.querySelector(`.preview[data-case-id="${caseId}"]`);
        if(previewBtn && !previewBtn.classList.contains('completed')){
          previewBtn.classList.add('completed');
          const titleDiv=previewBtn.querySelector('.title');
          if(titleDiv && !titleDiv.querySelector('.pill-done')){
            const pill=document.createElement('span'); pill.className='pill-done'; pill.textContent='Done'; titleDiv.appendChild(pill);
          }
          computeProgress();
        }
      }catch{}

      // update mobile fab visibility only after the feedback is visible
      setTimeout(updateFabVisibility, 80);
    });
  });

  // desktop + mobile open handlers
  threadCol.addEventListener('click', (e)=>{
    const viewBtn=e.target.closest('.js-view-fwd');
    if (!viewBtn) return;
    // on mobile the sheet is already opened by the earlier handler
  });

  function refreshMobileStateOnce(){
    if (isMobile()){
      app.classList.add('mobile-state-inbox');
      app.classList.remove('mobile-state-task');
    }
  }
  refreshMobileStateOnce();
})();
</script>

</body>
</html>
