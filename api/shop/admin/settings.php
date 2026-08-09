<?php
/**
 * Admin Settings Endpoint for Can Picornell Private Guest Shop Module
 * Manages store configuration key-value settings (such as global_margin_percent).
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../db.php';

function send_response($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['admin_authenticated']) || $_SESSION['admin_authenticated'] !== true) {
    send_response(['error' => 'Acceso no autorizado.'], 401);
}

$db = get_db_connection();
$method = $_SERVER['REQUEST_METHOD'];

// GET: Read settings
if ($method === 'GET') {
    try {
        $stmt = $db->query("SELECT setting_key, setting_value, updated_at FROM shop_settings");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        if (!isset($settings['global_margin_percent'])) {
            $settings['global_margin_percent'] = '10.00';
        }

        send_response(['success' => true, 'settings' => $settings]);
    } catch (Exception $e) {
        send_response(['error' => 'Error al leer la configuración de la tienda: ' . $e->getMessage()], 500);
    }
}

// POST: Save settings
if ($method === 'POST') {
    $raw_input = file_get_contents('php://input');
    $input = json_decode($raw_input, true) ?: $_POST;

    $global_margin = isset($input['global_margin_percent']) ? floatval($input['global_margin_percent']) : null;
    if ($global_margin !== null) {
        if ($global_margin < 0 || $global_margin > 500) {
            send_response(['error' => 'El porcentaje de margen global debe estar entre 0% y 500%.'], 400);
        }
    }

    try {
        $db->beginTransaction();
        $now = date('Y-m-d H:i:s');

        if ($global_margin !== null) {
            $formatted_margin = number_format($global_margin, 2, '.', '');
            try {
                $stmt = $db->prepare("
                    INSERT INTO shop_settings (setting_key, setting_value, updated_at)
                    VALUES ('global_margin_percent', ?, ?)
                    ON CONFLICT(setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = EXCLUDED.updated_at
                ");
                $stmt->execute([$formatted_margin, $now]);
            } catch (Exception $ex) {
                // MySQL fallback
                $stmt = $db->prepare("
                    INSERT INTO shop_settings (setting_key, setting_value, updated_at)
                    VALUES ('global_margin_percent', ?, ?)
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)
                ");
                $stmt->execute([$formatted_margin, $now]);
            }
        }

        $db->commit();
        send_response(['success' => true, 'message' => 'Configuración de la tienda actualizada correctamente.']);
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        send_response(['error' => 'Error al guardar la configuración: ' . $e->getMessage()], 500);
    }
}

send_response(['error' => 'Método no permitido.'], 405);
