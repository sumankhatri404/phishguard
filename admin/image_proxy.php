<?php
// /admin/image_proxy.php
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
admin_require_login();

/* 1) Input + normalise (decode %2F etc, make site-root relative) */
$raw = (string)($_GET['p'] ?? '');
$rel = ltrim(str_replace('\\', '/', urldecode($raw)), '/');

if (
  $rel === '' ||
  str_contains($rel, '..') ||                                  // no path traversal
  preg_match('~^[a-z][a-z0-9+.-]*://~i', $rel)                 // no schemes
) {
  http_response_code(400);
  exit('Bad path');
}

/* 2) Resolve under typical roots */
$roots = [];
$docroot = $_SERVER['DOCUMENT_ROOT'] ?? '';
if ($docroot && is_dir($docroot)) $roots[] = rtrim($docroot, '/'); // e.g. /home/xxx/htdocs
$roots[] = rtrim(dirname(__DIR__), '/');                            // parent of /admin
$roots[] = __DIR__;                                                 // /admin (if assets live here)

$full = '';
foreach ($roots as $root) {
  $candidate = $root . '/' . $rel;
  $rp = @realpath($candidate);
  if (($rp && is_file($rp)) || is_file($candidate)) { $full = $rp ?: $candidate; break; }
}

/* 3) Optional debug */
if (isset($_GET['debug'])) {
  header('Content-Type: text/plain; charset=UTF-8');
  echo "raw:       $raw\n";
  echo "rel:       $rel\n";
  echo "roots:\n- " . implode("\n- ", $roots) . "\n";
  echo "resolved:  " . ($full ?: '(none)') . "\n";
  echo "exists:    " . ($full && is_file($full) ? 'yes' : 'no') . "\n";
  exit;
}

/* 4) 404 if not found */
if (!$full || !is_file($full)) {
  http_response_code(404);
  exit('Not found');
}

/* 5) Correct MIME (don’t force PNG) */
$ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
$map = [
  'svg'  => 'image/svg+xml',
  'png'  => 'image/png',
  'jpg'  => 'image/jpeg',
  'jpeg' => 'image/jpeg',
  'gif'  => 'image/gif',
  'webp' => 'image/webp',
  'ico'  => 'image/x-icon',
  'bmp'  => 'image/bmp',
];
$mime = $map[$ext] ?? (function_exists('mime_content_type') ? (mime_content_type($full) ?: 'application/octet-stream') : 'application/octet-stream');

/* 6) Stream inline with real filename */
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($full) . '"');
header('Cache-Control: public, max-age=86400, immutable');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($full));
readfile($full);
