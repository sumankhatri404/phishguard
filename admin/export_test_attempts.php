<?php
declare(strict_types=1);
require_once __DIR__ . '/boot.php';

// Admin auth (uses PG_ADMINSESS cookie scoped to /admin)
admin_require_login();
/** @var PDO $pdo */
// $pdo is provided by boot.php via inc/db.php

try { $pdo->exec("SET time_zone = '+00:00'"); } catch (Throwable $e) {}

header_remove('Content-Type');
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="test_attempts.csv"');

$out = fopen('php://output', 'w');
// Header
fputcsv($out, [
  'attempt_id','user_id','username','first_name','last_name',
  'kind','started_at','submitted_at','ms_elapsed','score','total','accuracy_pct'
]);

$sql = "
  SELECT ta.id AS attempt_id,
         ta.user_id,
         u.username,
         u.first_name,
         u.last_name,
         ta.kind,
         ta.started_at,
         ta.submitted_at,
         ta.ms_elapsed,
         ta.score,
         ta.total,
         ta.accuracy_pct
  FROM test_attempts ta
  JOIN users u ON u.id = ta.user_id
  ORDER BY ta.user_id, ta.kind, ta.submitted_at, ta.id
";

foreach ($pdo->query($sql, PDO::FETCH_ASSOC) as $row) {
  // Cast numeric strings where helpful
  $row['ms_elapsed']    = (int)$row['ms_elapsed'];
  $row['score']         = (int)$row['score'];
  $row['total']         = (int)$row['total'];
  $row['accuracy_pct']  = is_null($row['accuracy_pct']) ? null : (float)$row['accuracy_pct'];
  fputcsv($out, $row);
}

fclose($out);
exit;
<?php
