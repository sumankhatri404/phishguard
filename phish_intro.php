<?php
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';
if(!isset($_SESSION['user_id'])){ header('Location: index.php?msg='.urlencode('Please login first.')); exit; }

$module_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// add these 2 lines ↓↓↓
require_once __DIR__ . '/inc/cases_table.php';
$caseTbl = getCasesTableForModule($pdo, $module_id);


// (optional) you can store “hasSeenIntro” if you want
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>PhishGuard Mail — Intro</title>
<style>
:root{
  --bg1:#0b0f1f; --bg2:#0e1226;
  --ink:#e9edfa; --muted:#b6c1e9;
  --panel:#0f172a; --panel2:#101a31; --line:rgba(255,255,255,.09);
  --p1:#6a11cb; --p2:#7c4dff; --p3:#5b5bd6;
  --accent:#82e5ff; --accent2:#0ea5e9; --success:#22c55e;
  --glow: 0 20px 60px rgba(124,77,255,.35);
}
*{box-sizing:border-box}
body{
  margin:0;
  font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Inter, "Helvetica Neue", Arial, "Noto Sans", "Apple Color Emoji", "Segoe UI Emoji";
  color:var(--ink);
  background:
    radial-gradient(1100px 500px at 10% -10%, rgba(124,77,255,.12), transparent 60%),
    radial-gradient(900px 600px at 110% 120%, rgba(14,165,233,.10), transparent 60%),
    linear-gradient(180deg,var(--bg1),var(--bg2));
}

/* Top bar */
.topbar{
  height:56px;
  background:linear-gradient(90deg, #8442ff 0%, #5b5bd6 60%, #4469ff 100%);
  display:flex; align-items:center; justify-content:center;
  border-bottom:1px solid rgba(255,255,255,.08);
  position:sticky; top:0; z-index:10;
}
.topbar .title{font-weight:800; letter-spacing:.3px}
.topbar .right{
  position:absolute; right:14px; top:10px;
}
.skip {
  background:rgba(255,255,255,.12); color:#fff; border:1px solid rgba(255,255,255,.22);
  padding:8px 14px; border-radius:10px; font-weight:700; text-decoration:none; margin-left:320px; 
  box-shadow: 0 10px 28px rgba(0,0,0,.25);
  animation: blink 1s infinite;
}
@keyframes blink {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.3; }
}

/* Canvas */
.shell{ max-width:1260px; margin:28px auto 48px; padding:0 16px; }
.grid{
  display:grid; grid-template-columns: 1.05fr .95fr; gap:26px;
}

/* LEFT monitor */
.monitorWrap{
  background:linear-gradient(180deg,#0f1428,#0a0f22);
  border:1px solid var(--line);
  border-radius:22px; padding:24px; box-shadow: 0 30px 90px rgba(0,0,0,.5);
}
.monitor{
  height:540px; border-radius:18px;
  background: radial-gradient(140% 100% at 50% 0%, rgba(124,77,255,.18), rgba(124,77,255,.05) 45%, rgba(0,0,0,.25) 80%),
              linear-gradient(180deg,#0b1023 40%, #0a0f22 100%);
  position:relative; display:block;
  outline: 2px solid rgba(130,229,255,.25);
  box-shadow: inset 0 0 0 2px rgba(130,229,255,.15), 0 0 0 6px rgba(124,77,255,.08), var(--glow);
  border-radius:16px;
/* Animated PhishGuard text */
.phg-animate {
  font-size:4rem;
  font-weight:900;
  letter-spacing:2px;
  text-align:center;
  margin-bottom:38px;
  background: linear-gradient(90deg,#ffb84d,#ff8a3d,#ffb84d);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  text-shadow: 0 2px 18px rgba(0,0,0,.25);
  opacity:0;
  transform: scale(0.6);
  animation: phgFadeIn 2.2s cubic-bezier(.22,1,.36,1) forwards;
  z-index:100;
}
@keyframes phgFadeIn {
  0% { opacity:0; transform:scale(0.6) translateY(40px); }
  60% { opacity:0.7; transform:scale(1.08) translateY(-8px); }
  100% { opacity:1; transform:scale(1) translateY(0); }
}
}
.loadingBar{
  width:360px; height:22px; border-radius:999px;
  background:rgba(255,255,255,.08); overflow:hidden; position:relative;
}
.loadingBar:before{
  content:""; position:absolute; inset:3px; border-radius:999px;
  background: linear-gradient(90deg,#ffb84d,#ff8a3d,#ffb84d);
  animation: pulse 2.1s ease-in-out infinite;
}
@keyframes pulse{
  0%{ transform: scaleX(.25); transform-origin:left}
  50%{ transform: scaleX(.85)}
  100%{ transform: scaleX(.25); transform-origin:left}
}

/* RIGHT card */
.talk{
  background:linear-gradient(180deg, #0e172e, #0d1429);
  border:1px solid var(--line); border-radius:22px; padding:15px; margin-bottom:20px; margin-left:246px;
  box-shadow:0 20px 60px rgba(0,0,0,.45);
}
/* .talk .poster{
  height:140px; border-radius:16px; margin-bottom:16px; position:relative;
  background: radial-gradient(300px 180px at 86% 30%, #6b27ff, transparent 70%),
              radial-gradient(220px 140px at 76% 18%, #8f77ff, transparent 60%),
              linear-gradient(180deg,#31164f,#1b1737);
  overflow:hidden;
} */

/* Avatar bubble top-right */
.avatarBubble{
  position:absolute; right:20px; top:16px;
  width:120px; height:120px; border-radius:26px;
  background: radial-gradient(65px 45px at 50% 35%, #8f7cff 0%, #6a34ff 60%, #5226c9 100%);
  display:grid; place-items:center;
  box-shadow: 0 16px 48px rgba(108,86,255,.35);
}
.avatarBubble svg{ width:68px; height:68px; }

/* Dialog panel */
.dialog{
  border:1px solid rgba(255,255,255,.09);
  background:linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.02));
  border-radius:16px; padding:18px; min-height:160px;
}
.badge{
  display:inline-block; margin-bottom:8px; padding:6px 10px; border-radius:10px;
  font-size:.9rem; font-weight:900; letter-spacing:.3px;
  color:#04121e; background:linear-gradient(90deg,#82e5ff,#4fd1ff);
  box-shadow:0 10px 26px rgba(79,209,255,.25);
}

/* Buttons */
.actions{ display:flex; gap:10px; justify-content:flex-end; margin-top:16px; }
.btn{
  border:0; padding:12px 18px; border-radius:12px; font-weight:800; cursor:pointer;
  box-shadow:0 16px 40px rgba(124,77,255,.35);
}
.btn.ghost{ background:rgba(255,255,255,.09); color:#cfd8ff; border:1px solid rgba(255,255,255,.15) }
.btn.primary{ background:linear-gradient(90deg,var(--p3),var(--p2)); color:#fff }
.btn.primary:hover{ filter:brightness(1.05); transform:translateY(-1px) }

/* Footer note */
.footerTips{
  margin-top:18px; color:var(--muted); font-size:.95rem;
  opacity:.9;
}

/* Responsive */
@media (max-width: 1080px){
  .grid{ grid-template-columns:1fr; }
  .monitor{ height:420px }
  .avatarBubble{ width:96px; height:96px }
}
/* 
/* ------------ HUD (left panel) ------------- */
.intro-left{
  background:transparent; border:0; padding:0;
}

/* Outer frame */
.hud{
  position:relative;
  border-radius:14px;
  background:#0b1224;            /* deep navy inside the frame edge */
  padding:18px;                   /* inner margin before “screen” */
  box-shadow:
    0 24px 80px rgba(3,7,18,.55),
    0 0 0 1px rgba(100,225,255,.18) inset;
  /* main cyan outline */
  outline:3px solid #86f2ff;
}

/* Clip/cut corner detail (top-left) */
.hud::before{
  content:"";
  position:absolute;
  top:-3px; left:-3px;
  width:60px; height:60px;
  border-top:3px solid #86f2ff;
  border-left:3px solid #86f2ff;
  border-top-left-radius:12px;
  clip-path: polygon(0 0, 100% 0, 100% 20%, 20% 100%, 0 100%);
  background:transparent;
}

/* Inner dark screen */
.hud-screen{
  position:relative;
  border-radius:10px;
  background:#081120;         /* darker “screen” */
  aspect-ratio:16/9;
  display:grid; place-items:center;
  box-shadow: inset 0 0 0 1.5px rgba(100,225,255,.15);
}

/* Faux baseline: thin cyan rule with a small gap left for the bracket */
.hud-baseline{
  position:absolute; left:20px; right:160px; bottom:14px;
  height:4px; background:#86f2ff; border-radius:4px;
}

/* Diagonal-striped bracket bottom-right */
.hud-bracket{
  position:absolute; right:18px; bottom:8px; width:210px; height:44px;
  background:
    repeating-linear-gradient(
      -45deg,
      #c7fbff 0 16px,
      transparent 16px 32px
    );
  /* carve into a beveled bracket */
  clip-path: polygon(10% 0, 100% 0, 100% 100%, 0 100%, 12% 50%);
  box-shadow: 0 0 0 3px #86f2ff inset, 0 6px 24px rgba(100,225,255,.18);
  border-radius:8px;
}

/* Logo sizing */
.phish-logo{
  width:82%; max-width:760px;
  filter: drop-shadow(0 10px 34px rgba(134,242,255,.18));
}

/* Optional glow around the screen edge (subtle) */
.hud-screen::after{
  content:""; position:absolute; inset:-12px; border-radius:14px;
  background: radial-gradient(260px 90px at 75% 10%, rgba(134,242,255,.10), transparent 60%);
  pointer-events:none;
}

/* Make the whole left block stack nicely on small screens */
@media (max-width: 980px){
  .hud{ margin-bottom:14px; }
} */

.pg-logo-tech{
  width: 92%;
  max-width: 880px;
  display:block;
  margin: 19px auto;
  filter: drop-shadow(0 18px 42px rgba(72,225,242,.15));
}




/* hanna design */
.hannah-dialog {
  position: relative;
  background: rgba(0, 0, 0, 0.4);
  border: 2px solid #00e6ff;
  border-radius: 4px;
  padding: 16px 60px 16px 16px; /* space on right for arrows */
  color: #fff;
  max-width: 800px;
  font-size: 1rem;
  margin-top: 10px;
}

.hannah-name {
  background: #00e6ff;
  color: #000;
  font-weight: bold;
  padding: 4px 10px;
  display: inline-block;
  border-radius: 4px;
  margin-bottom: 8px;
}

.nav-arrows {
  position: absolute;
  right: -50px; /* sticks out like in screenshot */
  top: 50%;
  transform: translateY(-50%);
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.arrow-btn {
  background: transparent;
  border: 2px solid #00e6ff;
  border-radius: 3px;
  padding: 6px;
  cursor: pointer;
  color: #00e6ff;
  transition: background 0.3s ease, transform 0.2s ease;
}

.arrow-btn:hover {
  background: rgba(0, 230, 255, 0.15);
  transform: scale(1.05);
}



</style>
</head>
<body>

<header class="topbar">
  <div class="title">PhishGuard Mail</div>
</header>

<div class="shell">
  <div class="grid">

    <!-- LEFT: monitor -->
    <div class="intro-left-pane">
  <img src="assets/img/phishguard-logo-tech.svg" alt="PhishGuard" class="pg-logo-tech">
</div>


<!-- RIGHT: character + dialog -->
<aside class="talk">
      <!-- Use image instead of SVG -->
      <img src="assets/img/ranger.png" alt="Guide Character" style="max-width: 100%; height: 400px;">
</aside>



 <!--  hana message design code   -->
  <!-- Intro wrapper so we can grab the module id in JS -->
<div id="introRoot" data-module-id="<?php echo (int)($_GET['id'] ?? 0); ?>">

  <div class="hannah-dialog">
    <div class="hannah-name">HANNAH</div>

    <!-- The actual line of dialogue -->
    <div class="hannah-text" id="dialogLine">
      <!-- JS fills this -->
    </div>

    <!-- Navigation Arrows -->
    <div class="nav-arrows">
      <button class="arrow-btn back-btn" id="btnBack" aria-label="Previous">
        <svg width="18" height="18" fill="currentColor" viewBox="0 0 20 20">
          <path d="M13 15l-5-5 5-5v10z"/>
        </svg>
      </button>
      <button class="arrow-btn next-btn" id="btnNext" aria-label="Next">
        <svg width="18" height="18" fill="currentColor" viewBox="0 0 20 20">
          <path d="M7 5l5 5-5 5V5z"/>
        </svg>
      </button>
    </div>
  </div>

  
</div>
  <div class="right"><a class="skip" href="module_mail.php?id=<?php echo $module_id; ?>">SKIP</a></div>
</div>



<script>
(() => {
  // --------- configurable bits ----------
  const SPEED_MS = 220;           // delay between words
  const MODE = 'word';            // 'word' or 'char'
  const lines = [
    "Welcome to PhishGuard Mail — your training environment for phishing detection.",
    "Requests will arrive in your inbox. Read them carefully and look for clues.",
    "Use the indicators to decide if it’s legitimate or a phishing attempt.",
    "Ready? Let’s begin."
  ];
  // --------------------------------------

  const root   = document.getElementById('introRoot');
  const modId  = root?.dataset?.moduleId || 0;
  const lineEl = document.getElementById('dialogLine');
  const prevBt = document.getElementById('btnBack');
  const nextBt = document.getElementById('btnNext');

  let idx = 0;
  let timer = null;
  let typing = false;

  function renderLine() {
    clearTimeout(timer);
    lineEl.textContent = '';
    typing = true;

    // button state
    prevBt.disabled = (idx === 0);
    nextBt.disabled = true;

    const text   = lines[idx];
    const tokens = MODE === 'word' ? text.split(' ') : [...text];
    let k = 0;

    (function typeStep(){
      if (k < tokens.length) {
        lineEl.textContent += (MODE === 'word' && k > 0 ? ' ' : '') + tokens[k++];
        timer = setTimeout(typeStep, SPEED_MS);
      } else {
        typing = false;
        nextBt.disabled = false;
      }
    })();
  }

  function finishCurrent() {
    clearTimeout(timer);
    lineEl.textContent = lines[idx];
    typing = false;
    nextBt.disabled = false;
  }

  nextBt.addEventListener('click', () => {
    if (typing) return finishCurrent();
    if (idx < lines.length - 1) {
      idx++;
      renderLine();
    } else {
      // finished intro -> go to your module player
      window.location.href = 'module_mail.php?id=' + encodeURIComponent(modId);
    }
  });

  prevBt.addEventListener('click', () => {
    if (idx > 0) {
      idx--;
      renderLine();
    }
  });

  // keyboard support
  document.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowRight') nextBt.click();
    if (e.key === 'ArrowLeft')  prevBt.click();
  });

  // start
  renderLine();
})();
</script>

</body>
</html>
