<?php
/**
 * Guest Order Submission Endpoint for Can Picornell Guest Shop Module
 * Handles final list submission (DRAFT -> PENDING_REVIEW) and sends owner notification email.
 */

require_once __DIR__ . '/guest_helper.php';
require_once __DIR__ . '/../../mail_helper.php';

$raw_token = $_REQUEST['t'] ?? '';
$url_lang = $_REQUEST['lang'] ?? null;

$db = get_db_connection();
$context = validate_guest_token($db, $raw_token);
$lang = resolve_language($url_lang, $context['preferred_language']);

$order = get_or_create_draft_order($db, $context['booking_id'], $context['token_id']);
$order_id = $order['id'];

if ($order['status'] !== 'DRAFT') {
    send_guest_json([
        'success' => true,
        'status' => $order['status'],
        'message' => 'La lista de compra ya ha sido enviada previamente.'
    ]);
}

// 1. Recalculate totals and check items / notes
recalculate_order_totals($db, $order_id);

$c_stmt = $db->prepare("
    SELECT i.product_name_snapshot, i.quantity, i.unit_price_cents, i.total_price_cents
    FROM shop_order_items i
    WHERE i.order_id = ?
    ORDER BY i.id ASC
");
$c_stmt->execute([$order_id]);
$items = $c_stmt->fetchAll(PDO::FETCH_ASSOC);

$guest_notes = trim($order['guest_notes'] ?? '');

if (count($items) === 0 && empty($guest_notes)) {
    send_guest_json(['error' => 'Tu carrito está vacío. Añade productos o una solicitud en el campo libre antes de enviar.'], 400);
}

$now = date('Y-m-d H:i:s');

try {
    $db->beginTransaction();

    $upd = $db->prepare("
        UPDATE shop_orders SET
            status = 'PENDING_REVIEW',
            submitted_at = ?,
            updated_at = ?
        WHERE id = ?
    ");
    $upd->execute([$now, $now, $order_id]);

    $db->commit();

    // Send Notification Email to Owner
    send_shop_order_notification_to_owner($context, $order, $items, $guest_notes);

    send_guest_json([
        'success' => true,
        'status' => 'PENDING_REVIEW',
        'submitted_at' => $now,
        'message' => '¡Tu lista de compra ha sido enviada correctamente a Can Picornell!'
    ]);
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log("Guest order submission error: " . $e->getMessage());
    send_guest_json(['error' => 'Ocurrió un error al enviar tu lista de compra. Inténtalo de nuevo.'], 500);
}

function send_shop_order_notification_to_owner(array $context, array $order, array $items, string $notes): void {
    $contact_email = get_env_var('CONTACT_EMAIL', 'info@canpicornell.com');
    $from_name = get_env_var('SMTP_FROM_NAME', 'Can Picornell');
    $from_email = get_env_var('SMTP_FROM_EMAIL', $contact_email);
    $mail_params = "-f" . $from_email;

    $guest_name_esc = htmlspecialchars($context['guest_name'], ENT_QUOTES, 'UTF-8');
    $req_num_esc = htmlspecialchars($context['request_number'], ENT_QUOTES, 'UTF-8');
    $ord_num_esc = htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8');

    $subject = "[LISTA DE COMPRA] Reserva {$req_num_esc} - {$guest_name_esc}";

    $items_html = "";
    $total_cents = 0;
    foreach ($items as $item) {
        $p_name = htmlspecialchars($item['product_name_snapshot'], ENT_QUOTES, 'UTF-8');
        $qty = intval($item['quantity']);
        $unit_eur = number_format($item['unit_price_cents'] / 100, 2, ',', '.');
        $tot_eur = number_format($item['total_price_cents'] / 100, 2, ',', '.');
        $total_cents += intval($item['total_price_cents']);

        $items_html .= "
        <tr>
            <td style='padding:8px; border-bottom:1px solid #eee;'>{$qty} x {$p_name}</td>
            <td style='padding:8px; border-bottom:1px solid #eee; text-align:right;'>{$unit_eur} €</td>
            <td style='padding:8px; border-bottom:1px solid #eee; text-align:right;'><strong>{$tot_eur} €</strong></td>
        </tr>";
    }

    $total_formatted = number_format($total_cents / 100, 2, ',', '.') . ' €';

    $notes_html = !empty($notes) ? "
    <div style='background:#fef3c7; border-left:4px solid #f59e0b; padding:12px; margin-top:15px; border-radius:4px;'>
        <strong style='color:#b45309;'>Solicitud de productos adicionales:</strong><br>
        " . nl2br(htmlspecialchars($notes, ENT_QUOTES, 'UTF-8')) . "
    </div>" : "";

    $body = "
    <div style='font-family: Arial, sans-serif; color:#333; max-width:600px; margin:0 auto; padding:20px; border:1px solid #e2e8f0; border-radius:8px;'>
        <h2 style='color:#165D81;'>Nueva Lista de Compra Recibida</h2>
        <p>El huésped <strong>{$guest_name_esc}</strong> ha enviado su lista de compra para su estancia en Can Picornell.</p>
        
        <table style='width:100%; border-collapse:collapse; font-size:0.9rem; margin-top:15px;'>
            <tr><td><strong>Nº Reserva:</strong> {$req_num_esc}</td><td><strong>Nº Pedido:</strong> {$ord_num_esc}</td></tr>
            <tr><td><strong>Entrada:</strong> {$context['checkin_date']}</td><td><strong>Salida:</strong> {$context['checkout_date']}</td></tr>
        </table>

        <h3 style='color:#165D81; margin-top:20px;'>Productos Solicitados:</h3>
        <table style='width:100%; border-collapse:collapse; font-size:0.9rem;'>
            <thead>
                <tr style='background:#f8fafc; text-align:left;'>
                    <th style='padding:8px;'>Producto</th>
                    <th style='padding:8px; text-align:right;'>Precio Unit.</th>
                    <th style='padding:8px; text-align:right;'>Total</th>
                </tr>
            </thead>
            <tbody>
                {$items_html}
            </tbody>
        </table>

        <p style='text-align:right; font-size:1.1rem; margin-top:15px;'>
            <strong>Importe Total Estimado: <span style='color:#165D81;'>{$total_formatted}</span></strong>
        </p>

        {$notes_html}

        <p style='margin-top:25px; font-size:0.85rem; color:#666;'>
            Puedes revisar y aprobar este pedido desde la pestaña <strong>Tienda Privada</strong> del panel de administración.
        </p>
    </div>";

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$from_name} <{$from_email}>\r\n";

    @mail($contact_email, $subject, $body, $headers, $mail_params);
}
