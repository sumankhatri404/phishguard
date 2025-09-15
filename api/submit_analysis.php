<?php
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/db.php';

if (!isset($_SESSION['user_id'])) { http_response_code(401); exit; }

$user_id   = (int)$_SESSION['user_id'];
$request_id= (int)($_POST['request_id'] ?? 0);
$choice_sender  = $_POST['choice_sender']  ?? '';
$choice_content = $_POST['choice_content'] ?? '';
$choice_extra   = $_POST['choice_extra']   ?? '';

$stmt = $pdo->prepare("SELECT module_id, correct_sender, correct_content, correct_extra, points
                       FROM help_requests WHERE id=?");
$stmt->execute([$request_id]);
$req = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$req) { http_response_code(404); exit; }

$score = 0;
$explain = [];

if ($choice_sender === $req['correct_sender']) {
  $score += 3;
} else {
  $explain[] = "Sender trust: expected <b>{$req['correct_sender']}</b>.";
}
if ($choice_content === $req['correct_content']) {
  $score += 3;
} else {
  $explain[] = "Content issue: expected <b>{$req['correct_content']}</b>.";
}
if ($choice_extra === $req['correct_extra']) {
  $score += 2;
} else {
  $explain[] = "Extra clue: expected <b>{$req['correct_extra']}</b>.";
}

$max = 8;
$feedback = ($score === $max)
  ? "Perfect! You spotted all the clues."
  : ("Nice attempt! ".implode(' ', $explain));

/* upsert the attempt (one per user/request) */
$stmt = $pdo->prepare("INSERT INTO user_request_attempts
  (user_id, request_id, choice_sender, choice_content, choice_extra, score, feedback)
  VALUES (?,?,?,?,?,?,?)
  ON DUPLICATE KEY UPDATE choice_sender=VALUES(choice_sender),
                          choice_content=VALUES(choice_content),
                          choice_extra=VALUES(choice_extra),
                          score=VALUES(score), feedback=VALUES(feedback)");
$stmt->execute([$user_id,$request_id,$choice_sender,$choice_content,$choice_extra,$score,$feedback]);

/* add points (you can replace with your own XP system) */
if ($score > 0) {
  $stmt = $pdo->prepare("INSERT INTO user_points (user_id, points) VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE points = points + VALUES(points)");
  $stmt->execute([$user_id, $score]);
}

/* bump module progress if you want (simple approach) */
$stmt = $pdo->prepare("INSERT INTO user_training_progress
  (user_id, module_id, progress_percent, status, completed_at)
  VALUES (?, ?, LEAST(100, 30), 'In Progress', NULL)
  ON DUPLICATE KEY UPDATE progress_percent = LEAST(100, progress_percent + 30)");
$stmt->execute([$user_id, (int)$req['module_id']]);

echo json_encode(['score'=>$score, 'max'=>$max, 'feedback'=>$feedback]);
