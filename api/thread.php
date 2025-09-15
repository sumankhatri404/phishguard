<?php
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/db.php';

if (!isset($_SESSION['user_id'])) { http_response_code(401); exit; }

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM help_requests WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); exit; }

echo json_encode([
  'id' => (int)$row['id'],
  'from_name' => $row['from_name'],
  'from_avatar' => $row['from_avatar'],
  'subject' => $row['subject'],
  'requester_html' => $row['requester_email_html'],
  'forwarded_html' => $row['forwarded_email_html'],
  'headers' => [
    'from' => $row['hdr_from'],
    'to' => $row['hdr_to'],
    'subject' => $row['hdr_subject'],
    'mailed_by' => $row['hdr_mailed_by'],
    'signed_by' => $row['hdr_signed_by']
  ]
]);
