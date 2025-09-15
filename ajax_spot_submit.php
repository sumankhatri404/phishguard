<?php
/**
 * ajax_spot_submit.php — Spot-the-Phish submit endpoint
 * - Validates inputs and enforces that a real choice ('phish'|'legit') is made
 * - Loads the user's pending session, checks deadline/locks
 * - Grades against task truth (correct_answer OR is_phish)
 * - Saves the result to user_spot_sessions (only the columns that exist)
 * - Applies XP exactly once per (user, task) via pg_apply_spot_result (idempotent)
 * - Returns JSON with grading + XP + streak info
 */

declare(strict_types=1);
header('Content-Type: application/json');                      // JSON responses only

// --- Bootstrap ---------------------------------------------------------------
require_once __DIR__ . '/inc/db.php';                          // $pdo connection
require_once __DIR__ . '/inc/functions.php';                   // helper functions used below
if (session_status() !== PHP_SESSION_ACTIVE) session_start();  // ensure session is started

// --- Auth guard --------------------------------------------------------------
if (empty($_SESSION['user_id'])) {                             // must be signed in
  echo json_encode(['ok'=>false,'code'=>'AUTH','message'=>'Not logged in']); exit;
}

// --- Inputs ------------------------------------------------------------------
// Read POST safely and coerce to expected types
$userId    = (int)$_SESSION['user_id'];                        // current user id
$taskId    = (int)($_POST['task_id']    ?? 0);                 // task being answered
$sessionId = (int)($_POST['session_id'] ?? 0);                 // specific attempt row
$choiceRaw = (string)($_POST['choice']   ?? '');               // raw choice from UI

// Quick structural validation (ids + non-empty choice)
if (!$taskId || !$sessionId || $choiceRaw === '') {
  echo json_encode(['ok'=>false,'code'=>'BAD_REQ','message'=>'Missing fields']); exit;
}

// Always write/read timestamps in UTC inside this request
try { $pdo->exec("SET time_zone = '+00:00'"); } catch (Throwable $e) { /* ignore */ }

// --- Helpers -----------------------------------------------------------------
// Normalise various user-entered labels into canonical 'phish' or 'legit'
function norm_label(string $s): string {
  // lower, collapse whitespace
  $s = preg_replace('/\s+/u',' ', trim(mb_strtolower($s)));
  // strip punctuation except letters/digits/space
  $s = preg_replace('/[^\p{L}\p{N}\s]/u','', $s);

  // map common synonyms to canonical values
  if (preg_match('/\b(phish|phishing|scam|fake|suspicious|fraud)\b/u',$s)) return 'phish';
  if (preg_match('/\b(legit|legitimate|safe|authentic|genuine|real)\b/u',$s)) return 'legit';
  if ($s === 'p') return 'phish';
  if ($s === 'l') return 'legit';

  // otherwise return the cleaned string (will be rejected below if not valid)
  return $s;
}

// --- Validate the choice (THIS is what stops "No selection" rows) ------------
if ($choiceRaw === 'timeout') {
    $choiceNorm = 'timeout';
} else {
    $choiceNorm = norm_label($choiceRaw);
}

if ($choiceNorm !== 'phish' && $choiceNorm !== 'legit' && $choiceNorm !== 'timeout') {
  http_response_code(400);
  echo json_encode(['ok'=>false,'code'=>'NO_CHOICE','message'=>'No choice selected']); exit;
}

// --- Load the pending session row --------------------------------------------
try {
  // Pull the attempt row for this user+session and check basic constraints
  $q=$pdo->prepare("
    SELECT id, user_id, task_id,
           UNIX_TIMESTAMP(deadline_at) AS exp,
           UNIX_TIMESTAMP(started_at)  AS started_at_unix,
           submitted_at
    FROM user_spot_sessions
    WHERE id=? AND user_id=? LIMIT 1
  ");
  $q->execute([$sessionId,$userId]);
  $sess = $q->fetch(PDO::FETCH_ASSOC);

  // Must exist and belong to the user
  if (!$sess) { echo json_encode(['ok'=>false,'code'=>'SESSION_NOT_FOUND','message'=>'Failed to load session']); exit; }

  // Prevent re-submission (idempotency at the session level)
  if (!empty($sess['submitted_at'])) { echo json_encode(['ok'=>false,'code'=>'LOCKED','message'=>'Already answered this one']); exit; }

  // Must match the intended task
  if ((int)$sess['task_id'] !== $taskId) { echo json_encode(['ok'=>false,'code'=>'TASK_MISMATCH','message'=>'Session/task mismatch']); exit; }

  // Enforce countdown deadline if present
  $now = (int)$pdo->query("SELECT UNIX_TIMESTAMP(NOW())")->fetchColumn();
  $exp = (int)($sess['exp'] ?? 0);
  if ($exp && $now > $exp) {
    // Time has expired. Lock the session to prevent further submissions.
    try {
      $lock_stmt = $pdo->prepare("UPDATE user_spot_sessions SET submitted_at = NOW() WHERE id = ? AND user_id = ? AND submitted_at IS NULL");
      $lock_stmt->execute([$sessionId, $userId]);
    } catch (Throwable $e) {
      // If lock fails, something is very wrong, but we still must deny the submission.
    }
    echo json_encode(['ok'=>false,'code'=>'EXPIRED','message'=>'Time over']);
    exit;
  }

} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'code'=>'SESSION_LOAD','message'=>'Failed to load session']); exit;
}

// --- Load task to know the ground-truth + scoring ----------------------------
try {
  $t = $pdo->prepare("
    SELECT id,
           TRIM(LOWER(COALESCE(correct_answer,''))) AS correct_answer,  -- explicit truth if present
           is_phish,                                                   -- fallback truth
           COALESCE(correct_rationale,'')          AS rationale,       -- feedback text
           COALESCE(points_right,6)                AS points_right,    -- XP for correct
           COALESCE(points_wrong,-2)               AS points_wrong     -- XP for wrong
    FROM spot_tasks
    WHERE id=? LIMIT 1
  ");
  $t->execute([$taskId]);
  $task = $t->fetch(PDO::FETCH_ASSOC);
  if (!$task) { echo json_encode(['ok'=>false,'code'=>'TASK_NOT_FOUND','message'=>'Task not found']); exit; }

} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'code'=>'TASK_LOAD','message'=>'Failed to load task']); exit;
}

// --- Grade -------------------------------------------------------------------
if ($choiceNorm === 'timeout') {
    $isCorrect = false;
    $awardXp = 0;
    $truthNorm = ''; // No correct answer to show
    $task['rationale'] = 'The timer ran out.'; // Override rationale
} else {
    // Decide the truth: explicit correct_answer beats is_phish flag
    $truth = $task['correct_answer'] !== ''
      ? $task['correct_answer']
      : (((int)$task['is_phish']===1) ? 'phish' : 'legit');

    $truthNorm   = norm_label((string)$truth);                       // canonical truth
    $isCorrect   = ($choiceNorm === $truthNorm);                     // compare
    $awardXp     = $isCorrect ? (int)$task['points_right'] : (int)$task['points_wrong'];  // XP delta
}

// Optional extra fields if your table supports them
$choiceIsPhish = (int)($choiceNorm === 'phish');
$truthIsPhish  = (int)($truthNorm  === 'phish');

// Decision time metrics (nice analytics)
$started       = (int)($sess['started_at_unix'] ?? $now);
$decisionSec   = max(0, $now - $started);
$decisionMs    = $decisionSec * 1000;

// --- Persist to user_spot_sessions -------------------------------------------
try {
  $pdo->beginTransaction();

  // Build a dynamic UPDATE that only writes columns that exist in your schema.
  // (pg_col_exists comes from inc/functions.php)
  $sets=['submitted_at = NOW()'];  // always stamp submission time
  $vals=[];                        // bound values for the prepared UPDATE

  if (pg_col_exists($pdo,'user_spot_sessions','is_correct'))        { $sets[]='is_correct=?';        $vals[]=$isCorrect?1:0; }
  if (pg_col_exists($pdo,'user_spot_sessions','points_awarded'))    { $sets[]='points_awarded=?';    $vals[]=$awardXp; }
  if (pg_col_exists($pdo,'user_spot_sessions','choice'))            { $sets[]='choice=?';            $vals[]=$choiceNorm; } // <- never empty now
  if (pg_col_exists($pdo,'user_spot_sessions','choice_is_phish'))   { $sets[]='choice_is_phish=?';   $vals[]=$choiceIsPhish; }
  if (pg_col_exists($pdo,'user_spot_sessions','truth_is_phish'))    { $sets[]='truth_is_phish=?';    $vals[]=$truthIsPhish; }
  if (pg_col_exists($pdo,'user_spot_sessions','decision_time_sec')) { $sets[]='decision_time_sec=?'; $vals[]=$decisionSec; }
  if (pg_col_exists($pdo,'user_spot_sessions','decision_ms'))       { $sets[]='decision_ms=?';       $vals[]=$decisionMs; }

  // Final UPDATE with LIMIT 1 for safety (MySQL/MariaDB support this)
  $sql="UPDATE user_spot_sessions SET ".implode(', ',$sets)." WHERE id=? AND user_id=? LIMIT 1";
  $vals[]=$sessionId; $vals[]=$userId;
  $pdo->prepare($sql)->execute($vals);

  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  echo json_encode(['ok'=>false,'code'=>'COMMIT_FAIL','message'=>'Could not save']); exit;
}

// --- Idempotent XP award ------------------------------------------------------
// pg_apply_spot_result should grant XP once per (user,task) and report if it was already applied
$apply=['applied'=>false,'already'=>false,'total_xp'=>null];
try {
  $apply = pg_apply_spot_result(
    $pdo, $userId, $taskId, $isCorrect,
    (int)$task['points_right'],
    (int)$task['points_wrong']
  );
} catch (Throwable $e) {
  // Don't fail the UI on XP write issues; we recompute totals below
}

// --- Totals & streaks for UI refresh -----------------------------------------
try {
  $tot=$pdo->prepare("SELECT COALESCE(SUM(points),0) FROM user_xp WHERE user_id=?");
  $tot->execute([$userId]);
  $totalXp=(int)$tot->fetchColumn();
} catch (Throwable $e) {
  $totalXp=(int)($apply['total_xp'] ?? 0);                       // fallback if helper gave us one
}

$streakCur=null; $streakBest=null;
try {
  $s=$pdo->prepare("SELECT streak_current,streak_best FROM user_streaks WHERE user_id=? LIMIT 1");
  $s->execute([$userId]);
  if($r=$s->fetch(PDO::FETCH_ASSOC)){
    $streakCur=(int)$r['streak_current'];
    $streakBest=(int)$r['streak_best'];
  }
} catch (Throwable $e) { /* ignore */ }

// --- Response ----------------------------------------------------------------
echo json_encode([
  'ok'             => true,
  'correct'        => $isCorrect,
  'points'         => $awardXp,
  'correct_answer' => strtoupper($truthNorm),
  'rationale'      => (string)$task['rationale'],
  'total_xp'       => $totalXp,
  'applied'        => !empty($apply['applied']),
  'already'        => !empty($apply['already']),
  'round_id'       => pg_round_id($pdo),   // helps the UI show “today’s round”
  'streak'         => $streakCur,
  'best_streak'    => $streakBest,
]);
