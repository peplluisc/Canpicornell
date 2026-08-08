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
    $json = [
        'moneda' => 'EUR',
        'idioma' => 'es-ES',
        'zona_horaria' => 'Europe/Madrid',
        'precio_noche_defecto' => 120,
        'precios_diarios' => []
    ];
    $target_create = __DIR__ . '/configuracion_precios.json';
    @file_put_contents($target_create, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
} else {
    $content = file_get_contents($config_file);
    $json = json_decode($content, true) ?: [];
}

$config_json_file = __DIR__ . '/config.json';
if (file_exists($config_json_file)) {
    $cfg_data = json_decode(file_get_contents($config_json_file), true);
    if (!empty($cfg_data['ical_url'])) {
        $json['ical_url'] = $cfg_data['ical_url'];
    }
}

send_json($json);

