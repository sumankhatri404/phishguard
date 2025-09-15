<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/inc/db.php';   // must set $pdo (PDO)

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['user_id'])) {
  header('Location: auth/login.php'); exit;
}

$userId  = (int)$_SESSION['user_id'];
$first   = 'Agent';
$initial = 'A';

$fullName = $_SESSION['full_name'] ?? $_SESSION['name'] ?? null;

if (!$fullName && isset($pdo)) {
  $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
  $stmt->execute([$userId]);
  if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $fullName = trim(implode(' ', array_filter([
      $row['full_name']  ?? null,
      $row['first_name'] ?? null,
      $row['last_name']  ?? null
    ])));
    if ($fullName === '') {
      $fullName = $row['name'] ?? $row['username'] ?? $row['email'] ?? null;
    }
    if ($fullName) $_SESSION['full_name'] = $fullName;
  }
}
if ($fullName) {
  $parts = preg_split('/\s+/', trim($fullName));
  $first = $parts[0] ?: 'Agent';
  $initial = function_exists('mb_substr')
    ? mb_strtoupper(mb_substr($first, 0, 1, 'UTF-8'), 'UTF-8')
    : strtoupper(substr($first, 0, 1));
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>HackerOS (simulated)</title>
  <!-- Favicon -->
  <link rel="icon" type="image/svg+xml"
        href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='12' fill='%236d28d9'/%3E%3Cpath d='M18 38l14-20 14 20-14 8z' fill='white'/%3E%3C/svg%3E">

  <link rel="stylesheet" href="assets/css/story_sim.css">
</head>
<body>

<?php
  $PAGE = ['title'=>'Dashboard','active'=>'dashboard','base'=>''];
  include __DIR__ . '/inc/app_topbar.php';
?>

<!-- Floating actions -->
<aside class="fab">
  <button id="btnStart" class="btn primary">▶ Start</button>
  <button id="btnSave"  class="btn">💾 Save</button>
  <button id="btnReset" class="btn danger">↺ Reset</button>
</aside>

<main id="workspace" class="workspace"></main>

<footer class="dock">
  <button class="dock-btn" data-app="terminal">📟 Terminal</button>
  <button class="dock-btn" data-app="scanner">📡 Scanner</button>
  <button class="dock-btn" data-app="wifi">📶 Wi-Fi</button>
  <button class="dock-btn" data-app="files">🗂 Files</button>
  <button class="dock-btn" data-app="labguide">📘 Lab Guide</button>
  <button class="dock-btn" data-app="log">📒 Log</button>
  <button class="dock-btn" data-app="help">❓ Help</button>
</footer>

<div id="toast" class="toast"></div>

<aside id="logPanel" class="logpanel">
  <div class="logbar">
    <div class="title">Mission Log</div>
    <div class="actions">
      <button class="xbtn" id="logExport">Export</button>
      <button class="xbtn" id="logClear">Clear</button>
      <button class="xbtn" id="logClose">✕</button>
    </div>
  </div>
  <div id="logList" class="loglist"></div>
</aside>

<script src="assets/js/story_sim.js"></script>
</body>
</html>
