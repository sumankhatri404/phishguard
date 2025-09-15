<?php
declare(strict_types=1);
require_once __DIR__ . '/boot.php';

// Admin auth (uses PG_ADMINSESS cookie scoped to /admin)
admin_require_login();
/** @var PDO $pdo */
// $pdo is provided by boot.php via inc/db.php

try { $pdo->exec("SET time_zone = '+00:00'"); } catch (Throwable $e) {}

// Use anonymized participant labels (no names/emails) for authenticity and ethics
$labelMode = 'anon';

header_remove('Content-Type');
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="paired_scores.csv"');

$out = fopen('php://output', 'w');
// Minimal SPSS-ready wide format: participant + pre, post only
fputcsv($out, ['participant','pre_pct','post_pct']);

// Use scalar subselects to get each user's latest pre and post accuracy_pct
$stmt = $pdo->query(<<<SQL
  SELECT
    u.id           AS user_id,
    u.username     AS username,
    u.first_name   AS first_name,
    u.last_name    AS last_name,
    (
      SELECT ta.accuracy_pct
      FROM test_attempts ta
      WHERE ta.user_id = u.id AND ta.kind = 'pre'
      ORDER BY ta.submitted_at DESC, ta.id DESC
      LIMIT 1
    ) AS pre_pct,
    (
      SELECT tb.accuracy_pct
      FROM test_attempts tb
      WHERE tb.user_id = u.id AND tb.kind = 'post'
      ORDER BY tb.submitted_at DESC, tb.id DESC
      LIMIT 1
    ) AS post_pct
  FROM users u
  WHERE EXISTS (SELECT 1 FROM test_attempts x WHERE x.user_id=u.id AND x.kind='pre')
    AND EXISTS (SELECT 1 FROM test_attempts y WHERE y.user_id=u.id AND y.kind='post')
  ORDER BY u.id
SQL);

while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $pre  = is_null($r['pre_pct'])  ? null : (float)$r['pre_pct'];
  $post = is_null($r['post_pct']) ? null : (float)$r['post_pct'];
  if ($pre === null || $post === null) continue; // only paired

  // Participant label per selected mode
  $u = trim((string)($r['username']   ?? ''));
  $f = trim((string)($r['first_name'] ?? ''));
  $l = trim((string)($r['last_name']  ?? ''));
  switch ($labelMode) {
    case 'username': $label = ($u !== '' ? $u : ('User '.(int)$r['user_id'])); break;
    case 'first':    $label = ($f !== '' ? $f : ($u !== '' ? $u : ('User '.(int)$r['user_id']))); break;
    case 'last':     $label = ($l !== '' ? $l : ($f !== '' ? $f : ($u !== '' ? $u : ('User '.(int)$r['user_id'])))); break;
    case 'full':     $label = trim(($f.' '.$l)); if ($label==='') $label = ($u !== ''?$u:('User '.(int)$r['user_id'])); break;
    case 'id':       $label = 'User '.(int)$r['user_id']; break;
    case 'anon':     $label = 'Participant '.str_pad((string)((int)$r['user_id']), 2, '0', STR_PAD_LEFT); break;
    case 'auto':
    default:
      $label = ($l !== '' ? $l : ($f !== '' ? $f : ($u !== '' ? $u : ('User '.(int)$r['user_id']))));
  }

  // Round to whole percent for supervisor-friendly presentation
  $preR   = (int)round($pre);
  $postR  = (int)round($post);

  fputcsv($out, [ $label, $preR, $postR ]);
}

fclose($out);
exit;
// end
