<?php
declare(strict_types=1);
require_once __DIR__ . '/boot.php';

// Admin auth
admin_require_login();
/** @var PDO $pdo */

try { $pdo->exec("SET time_zone = '+00:00'"); } catch (Throwable $e) {}

header_remove('Content-Type');
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="paired_scores_ident.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['user_id','username','first_name','last_name','pre_pct','post_pct','delta_pct']);

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
  $delta = (int)round($post - $pre);
  fputcsv($out, [
    (int)$r['user_id'],
    (string)($r['username'] ?? ''),
    (string)($r['first_name'] ?? ''),
    (string)($r['last_name'] ?? ''),
    (int)round($pre),
    (int)round($post),
    $delta,
  ]);
}

fclose($out);
exit;

