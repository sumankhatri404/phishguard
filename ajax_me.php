<?php
// ajax_me.php — HUD refresh: total XP + streak
declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';

if (empty($_SESSION['user_id'])) {
  echo json_encode(['ok'=>false,'code'=>'AUTH','message'=>'Not logged in']); exit;
}
$uid = (int)$_SESSION['user_id'];

try { $pdo->exec("SET time_zone = '+00:00'"); } catch (Throwable $e) {}

try {
  $st = $pdo->prepare("SELECT COALESCE(SUM(points),0) FROM user_xp WHERE user_id=?");
  $st->execute([$uid]); $xp = (int)$st->fetchColumn();
} catch (Throwable $e) { $xp = 0; }

$cur=0; $best=0;
try {
  $s=$pdo->prepare("SELECT streak_current, streak_best FROM user_streaks WHERE user_id=? LIMIT 1");
  $s->execute([$uid]);
  if ($r=$s->fetch(PDO::FETCH_ASSOC)) { $cur=(int)$r['streak_current']; $best=(int)$r['streak_best']; }
} catch (Throwable $e) {}

echo json_encode(['ok'=>true,'total_xp'=>$xp,'streak_current'=>$cur,'streak_best'=>$best]);
