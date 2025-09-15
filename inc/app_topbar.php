<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();

// Dynamically set the active page based on the current URL path
$PAGE = $PAGE ?? [];
$ACTIVE = 'dashboard'; // default to 'dashboard' page

// Check the current page and set ACTIVE accordingly
$current_page = basename($_SERVER['PHP_SELF']); // Get the current page's filename

if ($current_page == 'dashboard.php') {
    $ACTIVE = 'dashboard';
// } elseif ($current_page == 'story_sim.php') {
//     $ACTIVE = 'simulator';
} elseif ($current_page == 'leaderboard.php') {
    $ACTIVE = 'leaderboard';
}

// Compute a safe base path for links when running in a subfolder on localhost
function pg_base_url(): string {
    $sn = $_SERVER['SCRIPT_NAME'] ?? '';
    $base = rtrim(str_replace('\\', '/', dirname($sn)), '/');
    if ($base === '/' || $base === '.') $base = '';
    return $base;
}

$BASE_URL = isset($PAGE['base']) ? (string)$PAGE['base'] : '';
$BASE_URL = trim($BASE_URL);
if ($BASE_URL === '') { $BASE_URL = pg_base_url(); }
$first = htmlspecialchars($_SESSION['user_name'] ?? 'User', ENT_QUOTES, 'UTF-8');
$initial = strtoupper(substr($first, 0, 1));

function pg_is_active($key, $ACTIVE) {
    return $key === $ACTIVE ? 'active' : '';  // Return active class if page matches
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PhishGuard</title>
    <style>
        /* ===== Topbar ===== */
        .pg-topbar { position: sticky; top: 0; z-index: 1000; background: #0f172a; border-bottom: 1px solid rgba(148, 163, 184, .15); }
        .pg-topbar .inner {
            max-width: 1200px; margin: 0 auto; padding: 10px 16px;
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
        }
        .pg-brand { display: flex; align-items: center; gap: 10px; color: #fff; text-decoration: none; font-weight: 800; }
        .pg-brand .logo { display: grid; place-items: center; width: 28px; height: 28px; border-radius: 8px; background: linear-gradient(135deg, #6366f1, #06b6d4); }

        /* Desktop nav */
        .pg-nav.desktop { display: flex; gap: 8px; }
        .pg-nav.desktop a { color: #cbd5e1; text-decoration: none; padding: 8px 12px; border-radius: 10px; font-weight: 700; transition: .2s; }
        .pg-nav.desktop a.active, .pg-nav.desktop a:hover { background: #181f36; color: #fff; }

        /* Desktop user */
        .pg-user { display: flex; align-items: center; gap: 10px; color: #cbd5e1; position: relative; }
        .pg-avatar { width: 32px; height: 32px; border-radius: 50%; display: grid; place-items: center; background: #181f36; border: 1px solid rgba(148, 163, 184, .25); font-weight: 800; color: #fff; cursor: pointer; }
        #pgUserDropdown { display: none; position: absolute; right: 0; top: 48px; background: #181f36; border-radius: 10px; box-shadow: 0 4px 24px rgba(31, 38, 135, 0.17); min-width: 140px; z-index: 5000; }
        #pgUserDropdown a { display: block; padding: 12px 18px; color: #fff; text-decoration: none; font-weight: 600; }

        /* Burger (mobile only) */
        .pg-burger { display: none; background: #181f36; border: 1px solid rgba(148, 163, 184, .25); color: #e2e8f0; border-radius: 8px; padding: 6px 10px; cursor: pointer; }

        /* ===== Mobile drawer ===== */
        .pg-drawer-backdrop {
            position: fixed; inset: 0; background: rgba(2, 6, 23, .6);
            opacity: 0; pointer-events: none; transition: opacity .2s ease; z-index: 999;
        }
        .pg-drawer {
            position: fixed; top: 0; right: 0; height: 100dvh; width: 280px; max-width: 84vw;
            background: #0f172a; border-left: 1px solid rgba(148, 163, 184, .15);
            transform: translateX(100%); transition: transform .25s ease; z-index: 1000;
            display: flex; flex-direction: column;
        }
        .pg-drawer header {
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
            padding: 14px 16px; border-bottom: 1px solid rgba(148, 163, 184, .12);
        }
        .pg-drawer .title { color: #e2e8f0; font-weight: 800; }
        .pg-drawer .close { background: #181f36; border: 1px solid rgba(148, 163, 184, .25); color: #e2e7eb; border-radius: 8px; padding: 6px 10px; cursor: pointer; }
        .pg-drawer nav { padding: 10px 12px; display: flex; flex-direction: column; gap: 6px; }
        .pg-drawer nav a {
            display: block; color: #cbd5e1; text-decoration: none; padding: 10px 12px; border-radius: 10px; font-weight: 700;
        }
        .pg-drawer nav a.active { background: #181f36; color: #fff; }
        .pg-drawer .mobile-user {
            margin-top: auto; padding: 14px 16px; border-top: 1px solid rgba(148, 163, 184, .12); color: #cbd5e1;
        }
        .pg-drawer .mobile-user a { display: inline-block; margin-top: 8px; color: #fff; text-decoration: none; font-weight: 600; }

        /* Drawer open state */
        .pg-drawer-open .pg-drawer { transform: none; }
        .pg-drawer-open .pg-drawer-backdrop { opacity: 1; pointer-events: auto; }
        .pg-drawer-open { overflow: hidden; } /* prevent body scroll */

        /* Responsive */
        @media (max-width: 760px) {
            .pg-nav.desktop { display: none; }
            .pg-user { display: none; } /* hide desktop user block on mobile topbar */
            .pg-burger { display: block; }
        }
    </style>
</head>
<body>

<header class="pg-topbar">
  <div class="inner">
    <!-- Left: brand -->
    <a class="pg-brand" href="<?= $BASE_URL ?>/dashboard.php">
      <span class="logo">🛡️</span> PhishGuard
    </a>

    <!-- Center (desktop only) -->
    <nav class="pg-nav desktop" aria-label="Primary">
      <a href="<?= $BASE_URL ?>/dashboard.php" class="<?= pg_is_active('dashboard', $ACTIVE) ?>">Dashboard</a>
      <!-- <a href="<?= $BASE_URL ?>/story_sim.php" class="<?= pg_is_active('simulator', $ACTIVE) ?>">Simulator</a> -->
      <a href="<?= $BASE_URL ?>/leaderboard.php" class="<?= pg_is_active('leaderboard', $ACTIVE) ?>">Leaderboard</a>
    </nav>

    <!-- Right (desktop only) -->
    <div class="pg-user">
      <span>Welcome, <?= $first ?></span>
      <span class="pg-avatar" id="pgAvatar" aria-haspopup="true" aria-expanded="false"><?= $initial ?></span>
      <div id="pgUserDropdown">
        <a href="<?= $BASE_URL ?>/auth/logout.php">Logout</a>
      </div>
    </div>

    <!-- Burger (mobile) -->
    <button class="pg-burger" id="pgBurger" aria-label="Open menu" aria-expanded="false" aria-controls="pgDrawer">☰</button>
  </div>
</header>

<!-- Drawer & backdrop (mobile) -->
<div class="pg-drawer-backdrop" id="pgDrawerBackdrop" hidden></div>
<aside class="pg-drawer" id="pgDrawer" aria-hidden="true" aria-label="Mobile menu">
  <header>
    <div class="title">Menu</div>
    <button class="close" id="pgDrawerClose" aria-label="Close menu">✕</button>
  </header>
  <nav>
    <a href="<?= $BASE_URL ?>/dashboard.php" class="<?= pg_is_active('dashboard', $ACTIVE) ?>">Dashboard</a>
    <!-- <a href="<?= $BASE_URL ?>/story_sim.php" class="<?= pg_is_active('simulator', $ACTIVE) ?>">Simulator</a> -->
    <a href="<?= $BASE_URL ?>/leaderboard.php" class="<?= pg_is_active('leaderboard', $ACTIVE) ?>">Leaderboard</a>
  </nav>
  <div class="mobile-user">
    <div>Welcome, <?= $first ?></div>
    <a href="<?= $BASE_URL ?>/auth/logout.php">Logout</a>
  </div>
</aside>

<script>
// Debugging: Check which page is being loaded
console.log("Active Page: <?= $ACTIVE ?>");

// ---- Desktop avatar dropdown ----
(function() {
  const avatar = document.getElementById('pgAvatar');
  const dd = document.getElementById('pgUserDropdown');
  if (!avatar || !dd) return;

  // Open/close dropdown on avatar click
  avatar.addEventListener('click', (e) => {
    console.log("Avatar clicked!");  // Debug message for click
    e.stopPropagation();  // Prevents triggering the document click listener
    const open = dd.style.display === 'block';
    dd.style.display = open ? 'none' : 'block';
    avatar.setAttribute('aria-expanded', String(!open));
    console.log("Dropdown display: " + dd.style.display);  // Debug message for dropdown state
  });

  // Stop event propagation on dropdown itself to prevent immediate closing
  dd.addEventListener('click', (e) => e.stopPropagation());

  // Close dropdown if clicking outside of avatar or dropdown
  document.addEventListener('click', (e) => {
    if (!dd.contains(e.target) && e.target !== avatar) {
      dd.style.display = 'none';
      avatar.setAttribute('aria-expanded', 'false');
    }
  });
})();
</script>

<script>
// ---- Mobile burger/drawer ----
(function(){
  const burger = document.getElementById('pgBurger');
  const drawer = document.getElementById('pgDrawer');
  const backdrop = document.getElementById('pgDrawerBackdrop');
  const closeBtn = document.getElementById('pgDrawerClose');
  if(!burger || !drawer || !backdrop) return;

  function openDrawer(){
    document.body.classList.add('pg-drawer-open');
    drawer.setAttribute('aria-hidden','false');
    burger.setAttribute('aria-expanded','true');
    backdrop.hidden = false;
  }
  function closeDrawer(){
    document.body.classList.remove('pg-drawer-open');
    drawer.setAttribute('aria-hidden','true');
    burger.setAttribute('aria-expanded','false');
    backdrop.hidden = true;
  }

  burger.addEventListener('click', ()=>{
    const isOpen = document.body.classList.contains('pg-drawer-open');
    if(isOpen) closeDrawer(); else openDrawer();
  });
  if(closeBtn) closeBtn.addEventListener('click', closeDrawer);
  backdrop.addEventListener('click', closeDrawer);
  drawer.addEventListener('click', (e)=>{
    const a = e.target.closest('a');
    if(a) closeDrawer();
  });
  document.addEventListener('keydown', (e)=>{
    if(e.key === 'Escape') closeDrawer();
  });
})();
</script>

</body>
</html>
