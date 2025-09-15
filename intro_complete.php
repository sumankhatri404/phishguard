<?php
// intro_complete.php
require_once __DIR__ . '/inc/functions.php';
header('Content-Type: application/json');

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
  http_response_code(405); echo json_encode(['ok'=>false]); exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$moduleId = isset($input['module_id']) ? (int)$input['module_id'] : 0;

if(!$moduleId){
  http_response_code(400); echo json_encode(['ok'=>false]); exit;
}

if(!isset($_SESSION['intro_seen'])) $_SESSION['intro_seen'] = [];
$_SESSION['intro_seen'][$moduleId] = true;

echo json_encode(['ok'=>true]);
