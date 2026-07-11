<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/env_helper.php';
require_once __DIR__ . '/mail_helper.php';

function send_webhook_response($message, $code = 200) {
    http_response_code($code);
    echo json_encode(["status" => $code === 200 ? "success" : "error", "message" => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_webhook_response("Method Not Allowed", 405);
}

// 1. Get Secret Keys from environment
$webhook_secret = get_env_var('STRIPE_WEBHOOK_SECRET', '');
if (empty($webhook_secret)) {
    send_webhook_response("Firma de Webhook no configurada en el servidor.", 500);
}

// 2. Read the raw request body and signature header
$payload = file_get_contents('php://input');
$sig_header = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';

if (empty($payload) || empty($sig_header)) {
    send_webhook_response("Falta el payload o la firma del Webhook.", 400);
}

// 3. Verify Stripe Webhook Signature manually in pure PHP
// Format of Stripe-Signature: t=1672531199,v1=sig1[,v0=sig0]
$timestamp = 0;
$signatures = [];

$parts = explode(',', $sig_header);
foreach ($parts as $part) {
    $subparts = explode('=', $part, 2);
    if (count($subparts) === 2) {
        $key = trim($subparts[0]);
        $val = trim($subparts[1]);
        if ($key === 't') {
            $timestamp = intval($val);
        } else if ($key === 'v1') {
            $signatures[] = $val;
        }
    }
}

if ($timestamp === 0 || empty($signatures)) {
    send_webhook_response("Formato de firma del Webhook inválido.", 400);
}

// Replay attack prevention (tolerance: 5 minutes)
$tolerance = 300; 
if (abs(time() - $timestamp) > $tolerance) {
    send_webhook_response("El timestamp del Webhook difiere demasiado del tiempo del servidor (posible ataque de repetición).", 400);
}

// Compute the expected signature: timestamp . '.' . payload
$signed_payload = $timestamp . '.' . $payload;
$expected_signature = hash_hmac('sha256', $signed_payload, $webhook_secret);

$signature_match = false;
foreach ($signatures as $signature) {
    if (hash_equals($expected_signature, $signature)) {
        $signature_match = true;
        break;
    }
}

if (!$signature_match) {
    send_webhook_response("Firma del Webhook inválida. Verificación fallida.", 400);
}

// 4. Parse JSON Webhook Event
$event = json_decode($payload, true);
if (!$event) {
    send_webhook_response("Error al decodificar JSON del evento.", 400);
}

// 5. Process Successful Payment Completion
if ($event['type'] === 'checkout.session.completed') {
    $session = $event['data']['object'];
    $metadata = isset($session['metadata']) ? $session['metadata'] : [];
    
    $booking_id = isset($metadata['booking_id']) ? intval($metadata['booking_id']) : 0;
    $request_number = isset($metadata['request_number']) ? trim($metadata['request_number']) : '';
    
    if ($booking_id <= 0 || empty($request_number)) {
        // Fallback to client reference if metadata is empty
        $request_number = isset($session['client_reference_id']) ? trim($session['client_reference_id']) : '';
    }

    if (empty($request_number)) {
        send_webhook_response("Datos de referencia de reserva ausentes en la sesión de Stripe.", 400);
    }

    try {
        $db = get_db_connection();
        
        // Query current booking
        if ($booking_id > 0) {
            $stmt = $db->prepare("SELECT * FROM booking_requests WHERE id = :id");
            $stmt->execute([':id' => $booking_id]);
        } else {
            $stmt = $db->prepare("SELECT * FROM booking_requests WHERE request_number = :req_num");
            $stmt->execute([':req_num' => $request_number]);
        }
        $booking = $stmt->fetch();
        
        if (!$booking) {
            send_webhook_response("No se encontró la reserva en la base de datos local para la confirmación de Stripe.", 404);
        }

        // Avoid double processing
        if (in_array($booking['status'], ['anticipo_recibido', 'reserva_confirmada', 'pagada'])) {
            send_webhook_response("La reserva ya ha sido marcada como confirmada/pagada anteriormente.");
        }

        // 5.1. Perform final double-booking check before committing payment status
        $stmt_check = $db->prepare("
            SELECT COUNT(*) as count 
            FROM booking_requests 
            WHERE id != :id 
              AND status IN ('anticipo_recibido', 'reserva_confirmada', 'pendiente_saldo', 'pagada')
              AND checkin_date < :checkout
              AND checkout_date > :checkin
        ");
        $stmt_check->execute([
            ':id' => $booking['id'],
            ':checkin' => $booking['checkin_date'],
            ':checkout' => $booking['checkout_date']
        ]);
        $row_check = $stmt_check->fetch();
        
        $now = date('Y-m-d H:i:s');
        $db->beginTransaction();

        if ($row_check && intval($row_check['count']) > 0) {
            // CONFLICT RESOLUTION: Payment received but dates were booked by someone else!
            // Change status to conflict/warning state so the owner can review or refund manually.
            $stmt_update = $db->prepare("
                UPDATE booking_requests 
                SET status = 'pendiente_revision', 
                    updated_at = :now 
                WHERE id = :id
            ");
            $stmt_update->execute([':now' => $now, ':id' => $booking['id']]);

            $stmt_hist = $db->prepare("
                INSERT INTO booking_history (booking_id, status, notes, changed_at)
                VALUES (:id, 'pendiente_revision', 'ALERTA: Pago de anticipo completado en Stripe, pero las fechas ya se encuentran reservadas por otra confirmación. Requiere revisión manual o reembolso.', :now)
            ");
            $stmt_hist->execute([':id' => $booking['id'], ':now' => $now]);
            $db->commit();
            
            // Alert owner via error log / custom email
            $contact_email = get_env_var('CONTACT_EMAIL', 'info@canpicornell.com');
            mail(
                $contact_email,
                "CONFLICTO DE RESERVA: Pago Stripe recibido para " . $booking['request_number'],
                "El huesped " . $booking['guest_name'] . " ha pagado el anticipo para " . $booking['checkin_date'] . " a " . $booking['checkout_date'] . ", pero las fechas ya estan ocupadas por otra reserva confirmada. Por favor, revisa el panel de reservas e inicia el reembolso en Stripe si procede.",
                "From: Can Picornell Web <noreply@canpicornell.com>\r\n"
            );
            
            send_webhook_response("Pago registrado, pero se detectó conflicto de fechas de reserva.");
        } else {
            // STANDARD PATHWAY: Confirm booking
            $stmt_update = $db->prepare("
                UPDATE booking_requests 
                SET status = 'reserva_confirmada', 
                    updated_at = :now 
                WHERE id = :id
            ");
            $stmt_update->execute([':now' => $now, ':id' => $booking['id']]);

            $stmt_hist = $db->prepare("
                INSERT INTO booking_history (booking_id, status, notes, changed_at)
                VALUES (:id, 'reserva_confirmada', 'Anticipo recibido en Stripe Checkout. Reserva confirmada formalmente.', :now)
            ");
            $stmt_hist->execute([':id' => $booking['id'], ':now' => $now]);
            
            $db->commit();

            // Clear local cached iCal files to force calendar date reload on next visit
            $cache_file = __DIR__ . '/../scratch/ical_cache.json';
            if (file_exists($cache_file)) {
                unlink($cache_file);
            }

            // Send confirmation email
            $updated_booking = array_merge($booking, ['status' => 'reserva_confirmada']);
            send_booking_email($updated_booking, 'reserva_confirmada');

            send_webhook_response("Reserva confirmada y notificaciones enviadas correctamente.");
        }
        
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Webhook database error: " . $e->getMessage());
        send_webhook_response("Error de base de datos interno del servidor.", 500);
    }
} else {
    // Unhandled event types
    send_webhook_response("Tipo de evento no procesado por este endpoint: " . $event['type']);
}
?>
