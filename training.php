<?php
require_once __DIR__ . '/inc/user.php';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Training · PhishGuard</title>

  <!-- Your styles -->
  <link rel="stylesheet" href="assets/css/pg.css">
  <link rel="stylesheet" href="assets/css/nav.css">
</head>
<Style>
    .pg-user {
  position: relative;
  display: flex;
  align-items: center;
  gap: 8px;
}

.pg-avatar {
  background: #101827;
  color: #fff;
  border-radius: 50%;
  width: 32px;
  height: 32px;
  display: grid;
  place-items: center;
  font-weight: 700;
  cursor: pointer;
  border: 1px solid rgba(255,255,255,0.15);
}

.pg-user-menu {
  display: none; /* hidden by default */
  position: absolute;
  right: 0;
  top: 40px; /* just below avatar */
  min-width: 150px;
  background: #0b1323;
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: 10px;
  box-shadow: 0 8px 20px rgba(0,0,0,0.45);
  z-index: 100;
}

.pg-user-menu a {
  display: block;
  padding: 10px 14px;
  color: #e5e7eb;
  text-decoration: none;
  font-weight: 600;
}

.pg-user-menu a:hover {
  background: rgba(255,255,255,0.06);
}
</style>

<body class="pg-page">

  <!-- Top bar -->
  <header class="pg-topbar">
    <div class="inner">
      <!-- Brand -->
      <a class="pg-brand" href="dashboard.php">
        <span class="logo">🛡️</span> PhishGuard
      </a>

      <!-- Navigation -->
      <nav class="pg-nav">
        <a href="dashboard.php">Dashboard</a>
        <a href="training.php" class="active">Training</a>
        <a href="story_sim.php">Simulator</a>
        <a href="leaderboard.php">Leaderboard</a>
      </nav>

      <!-- User -->
      <div class="pg-user">
        <span class="hi">Welcome, <?= h($first) ?></span>
        <button class="pg-avatar" id="pgAvatar"><?= h($initial) ?></button>
        <div id="pgUserDropdown" class="pg-user-menu" style="display:none">
          <a href="auth/logout.php">Logout</a>
        </div>
      </div>
    </div>
  </header>

  <!-- Page content -->
  <main style="padding: 96px 16px 72px">
    <!-- Put your training content here -->
    <h1>Training</h1>
    <p class="hint">Choose a module to begin.</p>
  </main>

  <!-- Optional footer -->
  <footer style="height:56px"></footer>

  <!-- Mini dropdown for avatar -->
  <script>
    (function () {
      const avatar = document.getElementById('pgAvatar');
      const menu   = document.getElementById('pgUserDropdown');

      if (!avatar || !menu) return;

      const toggle = (show) => {
        menu.style.display = (typeof show === 'boolean')
          ? (show ? 'block' : 'none')
          : (menu.style.display === 'block' ? 'none' : 'block');
      };

      avatar.addEventListener('click', (e) => {
        e.stopPropagation();
        toggle();
      });

      // Close when clicking anywhere else
      document.addEventListener('click', () => toggle(false));

      // Keep clicks inside the menu from closing it
      menu.addEventListener('click', (e) => e.stopPropagation());
    })();
  </script>
</body>
</html>
