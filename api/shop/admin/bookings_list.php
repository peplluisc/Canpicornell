<?php
/**
 * Admin Bookings Helper Endpoint for Can Picornell Shop Module
 * Lists existing bookings so admin can select one to generate a guest shop token.
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET');

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

try {
    $stmt = $db->query("
        SELECT id, request_number, guest_name, guest_email, checkin_date, checkout_date, preferred_language, status
        FROM booking_requests
        ORDER BY checkin_date DESC
        LIMIT 100
    ");
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    send_response(['success' => true, 'bookings' => $bookings]);
} catch (Exception $e) {
    send_response(['error' => 'Error al consultar reservas: ' . $e->getMessage()], 500);
}
