<?php
/**
 * Admin Log Viewer Endpoint for Can Picornell Shop Module
 * Securely fetches the last entries from error_log in production.
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../db.php';

if (!isset($_SESSION['admin_authenticated']) || $_SESSION['admin_authenticated'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Acceso no autorizado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$logFiles = [
    __DIR__ . '/error_log',
    __DIR__ . '/../error_log',
    __DIR__ . '/../../error_log',
    __DIR__ . '/../../../error_log',
    ini_get('error_log')
];

$logLines = [];
foreach ($logFiles as $lf) {
    if ($lf && file_exists($lf) && is_readable($lf)) {
        $lines = file($lf);
        if (!empty($lines)) {
            $slice = array_slice($lines, -50);
            foreach ($slice as $line) {
                $logLines[] = trim($line);
            }
        }
    }
}

echo json_encode([
    'success' => true,
    'log_lines' => array_values(array_unique($logLines)),
    'php_version' => PHP_VERSION,
    'curl_version' => function_exists('curl_version') ? curl_version() : 'Not installed',
    'memory_limit' => ini_get('memory_limit')
], JSON_UNESCAPED_UNICODE);
