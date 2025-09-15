<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db.php';  // adjust path if needed

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
