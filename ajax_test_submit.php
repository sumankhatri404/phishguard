<?php
// ajax_test_submit.php — final version: requires all answers, idempotent, stores only q1..q10
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/inc/db.php';

function fail($msg, $code='ERR', $http=200, $extra=[]){
  http_response_code($http);
  echo json_encode(['ok'=>false,'message'=>$msg,'code'=>$code]+$extra, JSON_UNESCAPED_UNICODE);
  exit;
}
function ok($obj=[]){
  echo json_encode(['ok'=>true]+$obj, JSON_UNESCAPED_UNICODE);
  exit;
}

/* ---------- helpers ---------- */
function canon_token(string $s): string {
  $s = strtolower(trim(preg_replace('/\s+/', ' ', $s)));
  static $aliases = ['social media'=>'social','telephone'=>'phone','phone call'=>'phone','e-mail'=>'email'];
  return $aliases[$s] ?? $s;
}
function canon_scalar(string $s): string { return canon_token($s); }
function normalize_set_string(string $csv): string {
  if ($csv==='') return '';
  $parts = array_values(array_unique(array_filter(array_map('canon_token', explode(',', $csv)), 'strlen')));
  sort($parts, SORT_NATURAL);
  return implode(',', $parts);
}

/* ---------- auth ---------- */
if (empty($_SESSION['user_id'])) fail('Not signed in.','AUTH',401);
$uid = (int)$_SESSION['user_id'];

/* ---------- input ---------- */
$kind      = strtolower((string)($_POST['kind'] ?? 'pre')); // 'pre' | 'post'
$answersJs = (string)($_POST['answers'] ?? '');
$startedMs = (int)($_POST['started_ms'] ?? 0);
if (!in_array($kind, ['pre','post'], true)) $kind = 'pre';

$answers = json_decode($answersJs, true);
if (!is_array($answers) || !$answers) fail('No answers sent.','NO_ANS');

/* ---------- eligibility ---------- */
$eligOK = ($kind==='pre')
  ? (($answers['elig_18'] ?? '')==='yes' && ($answers['elig_consent'] ?? '')==='yes')
  : (($answers['pq_elig_18'] ?? '')==='yes' && ($answers['pq_consent'] ?? '')==='yes');
if (!$eligOK) fail('Eligibility/consent not confirmed.','GATE');

/* ---------- minimal schema (no exotic privileges) ---------- */
try{
  $pdo->exec("CREATE TABLE IF NOT EXISTS test_attempts(
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    kind ENUM('pre','post') NOT NULL,
    started_at DATETIME NULL,
    submitted_at DATETIME NULL,
    ms_elapsed INT NOT NULL DEFAULT 0,
    score INT NOT NULL DEFAULT 0,
    total INT NOT NULL DEFAULT 0,
    accuracy_pct DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    answers_json JSON NULL,
    UNIQUE KEY uniq_user_kind_once (user_id, kind)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
  $pdo->exec("CREATE TABLE IF NOT EXISTS test_answers(
    attempt_id INT NOT NULL,
    qid INT NOT NULL,
    answer VARCHAR(255) NOT NULL,
    is_correct TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (attempt_id, qid)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Throwable $e) { fail('DB init failed.','DBINIT',200,['detail'=>$e->getMessage()]); }

/* ---------- idempotency check (DO NOT write yet) ---------- */
try{
  $sel=$pdo->prepare("SELECT id, submitted_at, score, total, accuracy_pct, ms_elapsed
                        FROM test_attempts WHERE user_id=? AND kind=? LIMIT 1");
  $sel->execute([$uid,$kind]);
  $prev=$sel->fetch(PDO::FETCH_ASSOC);
  if ($prev && !empty($prev['submitted_at']) && (int)$prev['total'] > 0) {
    ok([
      'attempt_id'        => (int)$prev['id'],
      'kind'              => $kind,
      'score'             => (int)$prev['score'],
      'total'             => (int)$prev['total'],
      'accuracy_pct'      => (float)$prev['accuracy_pct'],
      'ms_elapsed'        => (int)$prev['ms_elapsed'],
      'already_submitted' => 1,
      'redirect'          => ($kind==='pre' ? '/dashboard.php?pre=done' : '/dashboard.php?post=done'),
    ]);
  }
}catch(Throwable $e){ /* continue; if this fails we'll error later */ }

/* ---------- collect incoming (q/pq → qid) ---------- */
$incoming = [];
foreach ($answers as $k => $v){
  if(!preg_match('/^(?:q|pq)(\d+)$/i',(string)$k,$m)) continue;
  $qid = (int)$m[1]; if($qid < 1) continue;
  $incoming[$qid] = is_array($v) ? implode(',', array_map('strval', $v)) : (string)$v;
}

/* ---------- correct maps (match your HTML) ---------- */
$correctPre  = [1 => 'b', 2 => ['email', 'sms', 'phone', 'social'], 3 => 'b', 4 => 'c', 5 => 'b', 6 => 'b', 7 => 'b', 8 => 'b', 9 => 'b', 10 => 'd'];
$correctPost = [1 => 'legit', 2 => 'phish', 3 => 'phish', 4 => 'legit', 5 => 'phish', 6 => 'phish', 7 => 'legit', 8 => 'phish', 9 => 'phish', 10 => 'legit'];
$map = ($kind === 'pre') ? $correctPre : $correctPost;

/* ---------- completeness guard BEFORE any write ---------- */
$missing = [];
foreach($map as $qid => $_){
  $val = $incoming[$qid] ?? '';
  if ($kind === 'pre' && $qid === 2) { if ($val === '') $missing[] = "q$qid"; }
  else { if ($val === '') $missing[] = ($kind === 'pre' ? "q$qid" : "pq$qid"); }
}
if ($missing) fail('Please answer all questions before submitting.','INCOMPLETE',200,['missing' => $missing]);

/* ---------- create or reuse attempt row (now safe to write) ---------- */
try{
  if (!$prev || empty($prev['id'])) {
    $startAt = $startedMs > 0 ? date('Y-m-d H:i:s', (int)floor($startedMs / 1000)) : date('Y-m-d H:i:s');
    $ins = $pdo->prepare("INSERT INTO test_attempts(user_id, kind, started_at) VALUES(?,?,?)");
    $ins->execute([$uid, $kind, $startAt]);
    $attemptId = (int)$pdo->lastInsertId();
  } else {
    $attemptId = (int)$prev['id'];
  }
}catch(Throwable $e){ fail('Could not start attempt.','ATTEMPT',200,['detail'=>$e->getMessage()]); }

/* ---------- score & persist (only q1..q10; never blanks) ---------- */
$total = 0; $score = 0;
try{
  $ins = $pdo->prepare("
    INSERT INTO test_answers (attempt_id, qid, answer, is_correct)
    VALUES (?,?,?,?)
    ON DUPLICATE KEY UPDATE answer=VALUES(answer), is_correct=VALUES(is_correct)
  ");
  foreach ($map as $qid => $right) {
    $userRaw = (string)$incoming[$qid]; // non-empty by guard
    if (is_array($right)){
      $total++;
      $ansNorm = normalize_set_string($userRaw);
      $keyNorm = normalize_set_string(implode(',', $right));
      $isc = (int)($ansNorm === $keyNorm);
      $ins->execute([$attemptId, $qid, $ansNorm, $isc]);
      if ($isc) $score++;
    } else {
      $total++;
      $isc = (canon_scalar((string)$right) === canon_scalar($userRaw)) ? 1 : 0;
      $ins->execute([$attemptId, $qid, canon_scalar($userRaw), $isc]);
      if ($isc) $score++;
    }
  }
}catch(Throwable $e){ fail('Failed to save answers.','ANS',200,['detail'=>$e->getMessage()]); }

/* ---------- finalize attempt ---------- */
$TOTAL_QUESTIONS = 10; // keep in sync with q1..q10
$acc = round(($score / $TOTAL_QUESTIONS) * 100, 2);
$ms  = $startedMs > 0 ? max(0, (int)(microtime(true) * 1000 - $startedMs)) : 0;

// Ensure newer columns exist on legacy installs; ignore errors if they already exist
try{ $pdo->exec("ALTER TABLE test_attempts ADD COLUMN answers_json JSON NULL"); }catch(Throwable $e){}

try{
  // Try with accuracy_pct (works when it's a normal column)
  $upd = $pdo->prepare("
    UPDATE test_attempts
       SET submitted_at = UTC_TIMESTAMP(),
           ms_elapsed = ?,
           score = ?,
           total = {$TOTAL_QUESTIONS},
           accuracy_pct = ?,    -- if it's GENERATED, this will throw; we fallback below
           answers_json = ?
     WHERE id = ? AND user_id = ?
  ");
  $upd->execute([$ms, $score, $acc, json_encode($answers, JSON_UNESCAPED_UNICODE), $attemptId, $uid]);
}catch(Throwable $e){
  // … fallback without accuracy_pct (works when it's GENERATED)
  try{
    $upd = $pdo->prepare("
      UPDATE test_attempts
         SET submitted_at = UTC_TIMESTAMP(),
             ms_elapsed = ?,
             score = ?,
              total = {$TOTAL_QUESTIONS},
             answers_json = ?
       WHERE id = ? AND user_id = ?
    ");
    $upd->execute([$ms, $score, json_encode($answers, JSON_UNESCAPED_UNICODE), $attemptId, $uid]);
  }catch(Throwable $e2){
  // Fallback 2: very old schemas may not have answers_json
    try{
      $upd = $pdo->prepare("\n        UPDATE test_attempts\n           SET submitted_at = UTC_TIMESTAMP(),\n               ms_elapsed = ?,\n               score = ?,\n               total = {$TOTAL_QUESTIONS}\n         WHERE id = ? AND user_id = ?\n      ");
      $upd->execute([$ms, $score, $attemptId, $uid]);
    }catch(Throwable $e3){
      fail('Failed to finalize attempt.','FINALIZE',200,['detail'=>$e3->getMessage()]);
    }
  }
}

/* ---------- flags & XP (lightweight) ---------- */
try{ $pdo->exec("ALTER TABLE users ADD COLUMN has_pretest TINYINT(1) NOT NULL DEFAULT 0"); }catch(Throwable $e){}
try{ $pdo->exec("ALTER TABLE users ADD COLUMN has_posttest TINYINT(1) NOT NULL DEFAULT 0"); }catch(Throwable $e){}
try{
  if ($kind === 'pre')  $pdo->prepare("UPDATE users SET has_pretest = 1 WHERE id = ?")->execute([$uid]);
  if ($kind === 'post') $pdo->prepare("UPDATE users SET has_posttest = 1 WHERE id = ?")->execute([$uid]);
}catch(Throwable $e){}

$awarded = 0; $xpTotal = 0;
$calcPoints = function(int $score, int $ms): int{
  $p = max(5, $score * 2);
  if ($ms < 180000) $p += 5;
  return (int)$p;
};
try{
  $module = ($kind === 'pre') ? 900001 : 900002;
  $exists = $pdo->prepare("SELECT COUNT(*) FROM user_xp WHERE user_id = ? AND module_id = ?");
  $exists->execute([$uid, $module]);
  if ((int)$exists->fetchColumn() === 0) {
    $points = $calcPoints($score, $ms);
    $pdo->prepare("INSERT INTO user_xp (user_id, module_id, points) VALUES (?, ?, ?)")->execute([$uid, $module, $points]);
    $awarded = $points;
    $pdo->prepare("INSERT INTO user_points (user_id, points) VALUES (?, ?) ON DUPLICATE KEY UPDATE points = points + VALUES(points)")
        ->execute([$uid, $points]);
  }
  $tot = $pdo->prepare("SELECT COALESCE(SUM(points), 0) FROM user_xp WHERE user_id = ?");
  $tot->execute([$uid]);
  $xpTotal = (int)$tot->fetchColumn();
}catch(Throwable $e){ /* ignore XP issues in response */ }

/* ---------- response ---------- */
ok([
  'attempt_id'   => $attemptId,
  'kind'         => $kind,
  'score'        => $score,
  'total'        => $TOTAL_QUESTIONS,
  'accuracy_pct' => $acc,
  'ms_elapsed'   => $ms,
  'awarded_xp'   => $awarded,
  'xp_total'     => $xpTotal,
  'redirect'     => ($kind === 'pre' ? '/dashboard.php?pre=done' : '/dashboard.php?post=done'),
]);
?>


