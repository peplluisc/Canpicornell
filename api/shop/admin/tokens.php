<?php
/**
 * Admin Tokens Endpoint for Can Picornell Private Guest Shop Module
 * Handles token generation, regeneration, toggling, and listing for bookings.
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
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

// Security Check: Must be logged in as admin
if (!isset($_SESSION['admin_authenticated']) || $_SESSION['admin_authenticated'] !== true) {
    send_response(['error' => 'Acceso no autorizado. Debe iniciar sesión como administrador.'], 401);
}

$db = get_db_connection();
$method = $_SERVER['REQUEST_METHOD'];

// GET: List all tokens with associated booking information
if ($method === 'GET') {
    try {
        $stmt = $db->query("
            SELECT 
                t.id AS token_id,
                t.booking_id,
                t.raw_token,
                t.preferred_language,
                t.is_active,
                t.created_at,
                t.expires_at,
                t.last_accessed_at,
                b.request_number,
                b.guest_name,
                b.guest_email,
                b.checkin_date,
                b.checkout_date
            FROM shop_access_tokens t
            JOIN booking_requests b ON t.booking_id = b.id
            ORDER BY t.id DESC
        ");
        $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $base_url = get_env_var('SITE_URL', 'https://canpicornell.com');
        $base_url = rtrim($base_url, '/');

        foreach ($tokens as &$tk) {
            if (!empty($tk['raw_token'])) {
                $tk['link'] = "{$base_url}/guest-shop/?t={$tk['raw_token']}&lang={$tk['preferred_language']}";
            } else {
                $tk['link'] = null;
            }
        }

        send_response(['success' => true, 'tokens' => $tokens]);
    } catch (Exception $e) {
        send_response(['error' => 'Error al consultar tokens: ' . $e->getMessage()], 500);
    }
}

// POST: Actions (generate, regenerate, toggle)
if ($method === 'POST') {
    $raw_input = file_get_contents('php://input');
    $input = json_decode($raw_input, true) ?: $_POST;
    
    $action = isset($input['action']) ? trim($input['action']) : 'generate';

    // ACTION: GENERATE OR REGENERATE
    if ($action === 'generate' || $action === 'regenerate') {
        $booking_id = isset($input['booking_id']) ? intval($input['booking_id']) : 0;
        $lang = isset($input['preferred_language']) ? strtolower(trim($input['preferred_language'])) : 'es';
        if (!in_array($lang, ['es', 'en', 'de'])) {
            $lang = 'es';
        }

        if ($booking_id <= 0) {
            send_response(['error' => 'ID de reserva no válido.'], 400);
        }

        // Fetch booking to verify existence & default expiration date
        $b_stmt = $db->prepare("SELECT id, request_number, checkin_date FROM booking_requests WHERE id = ?");
        $b_stmt->execute([$booking_id]);
        $booking = $b_stmt->fetch();
        if (!$booking) {
            send_response(['error' => 'La reserva especificada no existe.'], 404);
        }

        $expires_at = isset($input['expires_at']) ? trim($input['expires_at']) : '';
        if (empty($expires_at)) {
            $expires_at = $booking['checkin_date'] . ' 23:59:59';
        }

        // Generate cryptographically secure random token (64 hex characters)
        $raw_token = bin2hex(random_bytes(32));
        $token_hash = hash('sha256', $raw_token);
        $now = date('Y-m-d H:i:s');

        try {
            $db->beginTransaction();

            // If action is regenerate, deactivate all previous tokens for this booking
            if ($action === 'regenerate') {
                $deact_stmt = $db->prepare("UPDATE shop_access_tokens SET is_active = 0 WHERE booking_id = ?");
                $deact_stmt->execute([$booking_id]);
            }

            $ins_stmt = $db->prepare("
                INSERT INTO shop_access_tokens (booking_id, token_hash, raw_token, preferred_language, is_active, created_at, expires_at)
                VALUES (?, ?, ?, ?, 1, ?, ?)
            ");
            $ins_stmt->execute([$booking_id, $token_hash, $raw_token, $lang, $now, $expires_at]);
            $new_token_id = $db->lastInsertId();

            $db->commit();

            $base_url = get_env_var('SITE_URL', 'https://canpicornell.com');
            $base_url = rtrim($base_url, '/');
            $full_link = "{$base_url}/guest-shop/?t={$raw_token}&lang={$lang}";

            send_response([
                'success' => true,
                'token_id' => $new_token_id,
                'raw_token' => $raw_token,
                'link' => $full_link,
                'preferred_language' => $lang,
                'expires_at' => $expires_at,
                'request_number' => $booking['request_number'],
                'message' => ($action === 'regenerate') ? 'Token regenerado correctamente. El enlace anterior ha sido desactivado.' : 'Enlace privado de compra generado correctamente.'
            ]);
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            send_response(['error' => 'Error al guardar token: ' . $e->getMessage()], 500);
        }
    }

    // ACTION: TOGGLE ACTIVE STATUS
    if ($action === 'toggle') {
        $token_id = isset($input['token_id']) ? intval($input['token_id']) : 0;
        if ($token_id <= 0) {
            send_response(['error' => 'ID de token no válido.'], 400);
        }

        try {
            $t_stmt = $db->prepare("SELECT id, is_active FROM shop_access_tokens WHERE id = ?");
            $t_stmt->execute([$token_id]);
            $tok = $t_stmt->fetch();
            if (!$tok) {
                send_response(['error' => 'El token especificado no existe.'], 404);
            }

            $new_status = ($tok['is_active'] == 1) ? 0 : 1;
            $upd_stmt = $db->prepare("UPDATE shop_access_tokens SET is_active = ? WHERE id = ?");
            $upd_stmt->execute([$new_status, $token_id]);

            send_response([
                'success' => true,
                'token_id' => $token_id,
                'is_active' => $new_status,
                'message' => $new_status ? 'Token activado' : 'Token desactivado'
            ]);
        } catch (Exception $e) {
            send_response(['error' => 'Error al modificar estado del token: ' . $e->getMessage()], 500);
        }
    }

    send_response(['error' => 'Acción no válida.'], 400);
}

send_response(['error' => 'Método no permitido.'], 405);
