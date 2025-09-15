<?php
// admin_metrics_api.php
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';
header('Content-Type: application/json');

if (!is_admin($pdo)) { echo json_encode(['ok'=>false,'message'=>'Forbidden']); exit; }

$rows = $pdo->query("SELECT * FROM v_user_improvement")->fetchAll(PDO::FETCH_ASSOC);

// Buckets: improved / same-or-worse / no post-test
$improved=0; $worse=0; $noPost=0;
foreach ($rows as $r) {
  if (is_null($r['post_acc'])) { $noPost++; continue; }
  $delta = (float)$r['delta_acc'];
  if ($delta > 0.001) $improved++; else $worse++;
}

// Age group awareness (avg)
$ageRows = $pdo->query("
  SELECT 
    CASE 
      WHEN age IS NULL THEN 'Unknown'
      WHEN age < 20 THEN '<20'
      WHEN age BETWEEN 20 AND 24 THEN '20-24'
      WHEN age BETWEEN 25 AND 34 THEN '25-34'
      WHEN age BETWEEN 35 AND 44 THEN '35-44'
      ELSE '45+'
    END AS age_band,
    AVG(pre_acc)  AS avg_pre,
    AVG(post_acc) AS avg_post
  FROM v_user_improvement
  GROUP BY age_band
  ORDER BY 
    CASE age_band
      WHEN '<20' THEN 1
      WHEN '20-24' THEN 2
      WHEN '25-34' THEN 3
      WHEN '35-44' THEN 4
      WHEN '45+' THEN 5
      ELSE 6
    END
")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
  'ok'=>true,
  'summary'=>['improved'=>$improved,'no_change_or_worse'=>$worse,'no_post'=>$noPost],
  'age_bands'=>$ageRows,
  'raw'=>$rows
]);
