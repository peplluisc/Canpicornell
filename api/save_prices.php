<?php
/**
 * Admin Save Prices Endpoint for Can Picornell
 * Receives full or partial price configuration and updates configuracion_precios.json safely
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function send_response($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_response(['error' => 'Método no permitido'], 405);
}

// Security Check: Must be logged in as admin
if (!isset($_SESSION['admin_authenticated']) || $_SESSION['admin_authenticated'] !== true) {
    send_response(['error' => 'Acceso no autorizado. Debe iniciar sesión.'], 401);
}

$raw_input = file_get_contents('php://input');
if (empty($raw_input)) {
    send_response(['error' => 'No se han recibido datos en la solicitud.'], 400);
}

$new_config = json_decode($raw_input, true);
if (!$new_config || !is_array($new_config)) {
    send_response(['error' => 'Formato JSON no válido o corrupto.'], 400);
}

// Basic structural validation
if (!isset($new_config['precios_diarios']) || !is_array($new_config['precios_diarios'])) {
    send_response(['error' => 'La configuración debe contener un listado válido de "precios_diarios".'], 400);
}

// Validate each price entry
foreach ($new_config['precios_diarios'] as $index => $item) {
    if (!isset($item['fecha']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $item['fecha'])) {
        send_response(['error' => "Fecha no válida en el índice {$index}."], 400);
    }
    if (!isset($item['precio']) || !is_numeric($item['precio']) || floatval($item['precio']) < 0) {
        send_response(['error' => "Precio no válido para la fecha {$item['fecha']}."], 400);
    }
}

$target_file = __DIR__ . '/configuracion_precios.json';
$backup_file = __DIR__ . '/configuracion_precios.json.bak';

// Backup existing file if it exists
if (file_exists($target_file)) {
    @copy($target_file, $backup_file);
}

// Encode JSON nicely formatted
$json_content = json_encode($new_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
if ($json_content === false) {
    send_response(['error' => 'Error al codificar la configuración JSON.'], 500);
}

// Write to target file
$bytes = file_put_contents($target_file, $json_content, LOCK_EX);
if ($bytes === false) {
    send_response(['error' => 'No se pudo guardar la configuración en el servidor. Compruebe los permisos de escritura.'], 500);
}

// Secondary backup sync if booking_config.json exists
$alt_file = __DIR__ . '/booking_config.json';
if (file_exists($alt_file)) {
    @file_put_contents($alt_file, $json_content, LOCK_EX);
}

// Update ical_url in api/config.json if provided
if (isset($new_config['ical_url']) && !empty(trim($new_config['ical_url']))) {
    $cfg_file = __DIR__ . '/config.json';
    $cfg_arr = [];
    if (file_exists($cfg_file)) {
        $cfg_arr = json_decode(file_get_contents($cfg_file), true) ?: [];
    }
    $cfg_arr['ical_url'] = trim($new_config['ical_url']);
    file_put_contents($cfg_file, json_encode($cfg_arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

    // Clear iCal cache file to force fresh sync
    $cache_file = __DIR__ . '/../scratch/ical_cache.json';
    if (file_exists($cache_file)) {
        @unlink($cache_file);
    }
}

send_response([
    'success' => true,
    'message' => 'Configuración de precios y enlace iCal de Airbnb guardados correctamente.',
    'bytes_written' => $bytes,
    'total_dias' => count($new_config['precios_diarios'])
]);

