<?php
/**
 * Guest Context Endpoint for Can Picornell Guest Shop Module
 * Validates token and returns guest stay details + current order status.
 */

require_once __DIR__ . '/guest_helper.php';

$raw_token = $_GET['t'] ?? '';
$url_lang = $_GET['lang'] ?? null;

$db = get_db_connection();
$context = validate_guest_token($db, $raw_token);
$active_lang = resolve_language($url_lang, $context['preferred_language']);

// Retrieve current order if exists
$order = get_or_create_draft_order($db, $context['booking_id'], $context['token_id']);

// Get items count
$items_count = 0;
if ($order && isset($order['id'])) {
    $c_stmt = $db->prepare("SELECT SUM(quantity) FROM shop_order_items WHERE order_id = ?");
    $c_stmt->execute([$order['id']]);
    $items_count = intval($c_stmt->fetchColumn() ?: 0);
}

send_guest_json([
    'success' => true,
    'language' => $active_lang,
    'guest' => [
        'name' => $context['guest_name'],
        'checkin' => $context['checkin_date'],
        'checkout' => $context['checkout_date'],
        'nights' => $context['nights'],
        'adults' => $context['adults'],
        'children' => $context['children'],
        'babies' => $context['babies'],
        'request_number' => $context['request_number']
    ],
    'order' => [
        'id' => $order['id'],
        'order_number' => $order['order_number'],
        'status' => $order['status'],
        'total_cents' => intval($order['total_cents']),
        'total_formatted' => number_format($order['total_cents'] / 100, 2, ',', '.') . ' €',
        'items_count' => $items_count,
        'guest_notes' => $order['guest_notes'] ?? '',
        'submitted_at' => $order['submitted_at']
    ]
]);
