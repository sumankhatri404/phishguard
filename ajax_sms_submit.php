<?php
// ajax_sms_submit.php — atomic-enough without transactions to avoid rollback errors
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
  echo json_encode(['ok'=>false,'message'=>'Not logged in']); exit;
}
$userId = (int)$_SESSION['user_id'];

$caseId = (int)($_POST['case_id'] ?? 0);
$choice = trim($_POST['choice'] ?? '');
$reason = trim($_POST['reason'] ?? '');

if ($caseId<=0 || ($choice!=='phish' && $choice!=='legit') || $reason==='') {
  echo json_encode(['ok'=>false,'message'=>'Bad request']); exit;
}

try {
  // Load case
  $c = $pdo->prepare("
    SELECT id, module_id,
           COALESCE(points_max,10)                   AS points_max,
           COALESCE(correct_choice,'phish')          AS correct_choice,
           COALESCE(correct_reason,'suspicious_links') AS correct_reason,
           feedback_html,
           is_active
    FROM training_sms_cases
    WHERE id=? LIMIT 1
  ");
  $c->execute([$caseId]);
  $case = $c->fetch(PDO::FETCH_ASSOC);
  if (!$case || (int)$case['is_active'] !== 1) {
    echo json_encode(['ok'=>false,'message'=>'Case not found']); exit;
  }

  $moduleId      = (int)$case['module_id'];
  $maxPoints     = max(1, (int)$case['points_max']);
  $correctChoice = $case['correct_choice'];
  $correctReason = $case['correct_reason'];

  // Score
  $score = 0;
  if ($choice === $correctChoice) $score += 6;
  if ($reason === $correctReason) $score += 4;
  if ($score === 0) $score = 2;
  if ($score > $maxPoints) $score = $maxPoints;
  $isCorrect = ($choice === $correctChoice);

  // Upsert progress (requires UNIQUE KEY (user_id,case_id))
  $ins = $pdo->prepare("
    INSERT INTO training_sms_progress
      (user_id, case_id, choice, reason, is_correct, points_awarded, answered_at)
    VALUES
      (?, ?, ?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE
      choice=VALUES(choice),
      reason=VALUES(reason),
      is_correct=VALUES(is_correct),
      points_awarded=VALUES(points_awarded),
      answered_at=VALUES(answered_at)
  ");
  $ins->execute([$userId, $caseId, $choice, $reason, $isCorrect ? 1 : 0, $score]);

  // Award XP (no TX)
  pg_add_xp_no_tx($pdo, $userId, $moduleId, $score);

  // Progress counts
  $tot = $pdo->prepare("SELECT COUNT(*) FROM training_sms_cases WHERE module_id=? AND is_active=1");
  $tot->execute([$moduleId]);
  $totalCount = (int)$tot->fetchColumn();

  $done = $pdo->prepare("
    SELECT COUNT(DISTINCT p.case_id)
    FROM training_sms_progress p
    JOIN training_sms_cases c ON c.id = p.case_id
    WHERE p.user_id=? AND c.module_id=? AND c.is_active=1
  ");
  $done->execute([$userId, $moduleId]);
  $completedCount = (int)$done->fetchColumn();

  // Also compute total completed tasks across channels (mail, sms, web)
  $completedAll = 0;
  try {
    $st = $pdo->prepare("SELECT COUNT(DISTINCT case_id) FROM training_mail_progress WHERE user_id=?"); $st->execute([$userId]); $completedAll += (int)$st->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(DISTINCT case_id) FROM training_sms_progress WHERE user_id=?");  $st->execute([$userId]); $completedAll += (int)$st->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(DISTINCT case_id) FROM training_web_progress WHERE user_id=?");  $st->execute([$userId]); $completedAll += (int)$st->fetchColumn();
  } catch (Throwable $e) { /* ignore */ }

  // Feedback
  $explain = $case['feedback_html'];
  if (!$explain) {
    if ($correctChoice === 'phish') {
      $explain = "<p><b>Why this looks like phishing:</b> suspicious URL, urgency/scare language, unknown sender, brand spoofing. Don’t tap links—use the official app/site.</p>";
    } else {
      $explain = "<p><b>Why this looks legitimate:</b> no red flags and consistent with prior messages. For sensitive actions, still confirm in the official app.</p>";
    }
    $explain .= "<p><i>Expected reason:</i> <code>".htmlspecialchars($correctReason)."</code></p>";
  }

  echo json_encode([
    'ok'               => true,
    'correct'          => $isCorrect,
    'points'           => $score,
    'explain_html'     => $explain,
    'completed_count'  => $completedCount,
    'total_count'      => $totalCount
  , 'completed_all'   => $completedAll
  ]);

} catch (Throwable $e) {
  // IMPORTANT: no rollback here (to avoid “no active transaction”)
  echo json_encode(['ok'=>false,'message'=>'Submit failed','debug'=>$e->getMessage()]);
}
