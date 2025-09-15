<?php
// inc/db.php — PDO connection for InfinityFree + session
declare(strict_types=1);

// Secure session cookie before session_start
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
    try {
        $dsn  = "mysql:host=sql305.infinityfree.com;port=3306;dbname=if0_39778445_phishguard;charset=utf8mb4";
        $user = "if0_39778445";
        $pass = "sumankhatri111"; // update after you rotate!

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // ✅ Keep PHP & MySQL on UTC to avoid time skew
        date_default_timezone_set('UTC');
        $pdo->exec("SET time_zone = '+00:00'");

        $GLOBALS['pdo'] = $pdo;
    } catch (Throwable $e) {
        http_response_code(500);
        error_log("DB connection failed: " . $e->getMessage());
        die("Database connection failed.");
    }
} else {
    /** @var PDO $pdo */
    $pdo = $GLOBALS['pdo'];
}

// Helper
if (!function_exists('db')) {
    function db(): PDO { return $GLOBALS['pdo']; }
}
