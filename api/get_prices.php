<?php
/**
 * Admin Get Prices Configuration Endpoint
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function send_json($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Check admin authentication
if (!isset($_SESSION['admin_authenticated']) || $_SESSION['admin_authenticated'] !== true) {
    send_json(['error' => 'Acceso denegado. Se requiere iniciar sesión como administrador.'], 401);
}

$config_file = __DIR__ . '/configuracion_precios.json';
if (!file_exists($config_file)) {
    $config_file = __DIR__ . '/booking_config.json';
}

if (!file_exists($config_file)) {
    send_json(['error' => 'No se encontró el archivo de configuración de precios.'], 404);
}

$content = file_get_contents($config_file);
$json = json_decode($content, true);

if (!$json) {
    send_json(['error' => 'El archivo de configuración de precios contiene un JSON no válido.'], 500);
}

send_json($json);
