<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/env_helper.php';

function send_checkout_error($message, $code = 400) {
    http_response_code($code);
    echo json_encode(["error" => $message]);
    exit;
}

// 1. Get Secret Keys from environment
$stripe_secret_key = get_env_var('STRIPE_SECRET_KEY', '');
$base_url = get_env_var('BASE_URL', 'https://canpicornell.com/');

if (empty($stripe_secret_key)) {
    send_checkout_error("Pasarela de pago no configurada en el servidor.", 500);
}

// 2. Retrieve Booking ID or Request Number
$request_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$request_num = isset($_GET['request_number']) ? trim($_GET['request_number']) : '';

if ($request_id <= 0 && empty($request_num)) {
    send_checkout_error("Identificador de reserva no válido.");
}

// 3. Query Database for Request details
try {
    $db = get_db_connection();
    
    if ($request_id > 0) {
        $stmt = $db->prepare("SELECT * FROM booking_requests WHERE id = :id");
        $stmt->execute([':id' => $request_id]);
    } else {
        $stmt = $db->prepare("SELECT * FROM booking_requests WHERE request_number = :req_num");
        $stmt->execute([':req_num' => $request_num]);
    }
    
    $booking = $stmt->fetch();
    
    if (!$booking) {
        send_checkout_error("Solicitud de reserva no encontrada.");
    }
    
    // Check if status is eligible for payment (prohibits paying cancelled, expired, or already paid bookings)
    $allowed_statuses = ['solicitud_recibida', 'pendiente_revision', 'presupuesto_enviado', 'pendiente_anticipo'];
    if (!in_array($booking['status'], $allowed_statuses)) {
        send_checkout_error("Esta solicitud no está en estado de pago pendiente (Estado actual: " . $booking['status'] . ").");
    }
    
    // Double booking check before allowing payment page creation
    // Check against Airbnb iCal cache
    $checkin_time = strtotime($booking['checkin_date']);
    $checkout_time = strtotime($booking['checkout_date']);
    $cache_file = __DIR__ . '/../scratch/ical_cache.json';
    if (file_exists($cache_file)) {
        $ical_ranges = json_decode(file_get_contents($cache_file), true);
        if (is_array($ical_ranges)) {
            foreach ($ical_ranges as $range) {
                $range_start = strtotime($range['start']);
                $range_end = strtotime($range['end']);
                if ($checkin_time < $range_end && $checkout_time > $range_start) {
                    send_checkout_error("Lo sentimos, estas fechas han quedado reservadas en Airbnb durante la revisión. Por favor, selecciona otras fechas.");
                }
            }
        }
    }
    
    // Check against other CONFIRMED/PAID reservations in DB
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
    if ($row_check && intval($row_check['count']) > 0) {
        send_checkout_error("Lo sentimos, estas fechas ya han sido confirmadas por otro huésped.");
    }

    // 4. Calculate amount in cents (Stripe requires integers in cents)
    $deposit_amount = floatval($booking['amount_deposit']);
    $amount_in_cents = intval(round($deposit_amount * 100));
    
    // 5. Construct Checkout Session parameters
    $lang = strtolower($booking['preferred_language']);
    $success_page = $base_url . $lang . "/reserva.html?status=success&req=" . $booking['request_number'];
    $cancel_page = $base_url . $lang . "/reserva.html?status=cancel&req=" . $booking['request_number'];
    
    $product_name = "Anticipo de Reserva Can Picornell";
    $product_desc = "Reserva: " . $booking['request_number'] . " | Entrada: " . $booking['checkin_date'] . " - Salida: " . $booking['checkout_date'];
    
    if ($lang === 'en') {
        $product_name = "Deposit Payment - Can Picornell";
        $product_desc = "Booking: " . $booking['request_number'] . " | Check-in: " . $booking['checkin_date'] . " - Check-out: " . $booking['checkout_date'];
    } else if ($lang === 'de') {
        $product_name = "Anzahlung - Can Picornell";
        $product_desc = "Buchung: " . $booking['request_number'] . " | Anreise: " . $booking['checkin_date'] . " - Abreise: " . $booking['checkout_date'];
    }

    $post_fields = [
        'mode' => 'payment',
        'success_url' => $success_page,
        'cancel_url' => $cancel_page,
        'customer_email' => $booking['guest_email'],
        'client_reference_id' => $booking['request_number'],
        'line_items[0][price_data][currency]' => 'eur',
        'line_items[0][price_data][product_data][name]' => $product_name,
        'line_items[0][price_data][product_data][description]' => $product_desc,
        'line_items[0][price_data][unit_amount]' => $amount_in_cents,
        'line_items[0][price_data][product_data][images][0]' => $base_url . 'assets/images/logo.png',
        'line_items[0][quantity]' => 1,
        'metadata[booking_id]' => $booking['id'],
        'metadata[request_number]' => $booking['request_number'],
        'metadata[checkin]' => $booking['checkin_date'],
        'metadata[checkout]' => $booking['checkout_date']
    ];

    // 6. Execute Call to Stripe REST API using cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/checkout/sessions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_fields));
    curl_setopt($ch, CURLOPT_USERPWD, $stripe_secret_key . ':'); // Basic auth using secret key as user
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        $error_data = json_decode($response, true);
        $err_msg = isset($error_data['error']['message']) ? $error_data['error']['message'] : "Error al conectar con la pasarela Stripe.";
        send_checkout_error($err_msg, 500);
    }
    
    $session = json_decode($response, true);
    $session_id = $session['id'];
    $session_url = $session['url'];
    
    // 7. Update database with Stripe Session ID and transition status to 'pendiente_anticipo'
    $now = date('Y-m-d H:i:s');
    $db->beginTransaction();
    
    $stmt_update = $db->prepare("
        UPDATE booking_requests 
        SET stripe_session_id = :session_id, 
            status = 'pendiente_anticipo', 
            updated_at = :now 
        WHERE id = :id
    ");
    $stmt_update->execute([
        ':session_id' => $session_id,
        ':now' => $now,
        ':id' => $booking['id']
    ]);
    
    $stmt_hist = $db->prepare("
        INSERT INTO booking_history (booking_id, status, notes, changed_at)
        VALUES (:id, 'pendiente_anticipo', 'Enlace de pago Stripe Checkout generado.', :now)
    ");
    $stmt_hist->execute([
        ':id' => $booking['id'],
        ':now' => $now
    ]);
    
    $db->commit();
    
    // Redirect guest directly to Stripe Checkout
    header("Location: " . $session_url);
    exit;
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Error creating checkout session: " . $e->getMessage());
    send_checkout_error("Error interno del servidor al procesar la sesión de pago.", 500);
}
?>
