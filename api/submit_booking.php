<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Access-Control-Allow-Methods: POST');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail_helper.php';

function send_json_error($message, $code = 400) {
    http_response_code($code);
    echo json_encode(["error" => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_error("Method Not Allowed", 405);
}

// 1. Parse Input Data (JSON or POST Form data)
$raw_input = file_get_contents('php://input');
$input = json_decode($raw_input, true);
if (!$input) {
    $input = $_POST;
}

// 2. Honeypot Anti-Spam Check
// If the hidden honeypot field 'website' has any value, discard the request silently
if (!empty($input['website'])) {
    // Return a fake success response to trick the bot
    echo json_encode([
        "success" => true,
        "request_number" => "CP-" . date('Y') . "-" . rand(1000, 9999),
        "message" => "Solicitud procesada correctamente."
    ]);
    exit;
}

// 3. CSRF Verification
$client_csrf = isset($input['csrf_token']) ? trim($input['csrf_token']) : '';
$session_csrf = isset($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
if (empty($client_csrf) || empty($session_csrf) || $client_csrf !== $session_csrf) {
    send_json_error("Sesión de seguridad inválida o expirada. Por favor, recarga la página.");
}

// 4. Extract and Clean Inputs
$checkin = isset($input['checkin']) ? trim($input['checkin']) : '';
$checkout = isset($input['checkout']) ? trim($input['checkout']) : '';
$adults = isset($input['adults']) ? intval($input['adults']) : 0;
$children = isset($input['children']) ? intval($input['children']) : 0;
$babies = isset($input['babies']) ? intval($input['babies']) : 0;
$country = isset($input['country']) ? trim(strip_tags($input['country'])) : '';

$name = isset($input['name']) ? trim(strip_tags($input['name'])) : '';
$email = isset($input['email']) ? filter_var(trim($input['email']), FILTER_SANITIZE_EMAIL) : '';
$phone = isset($input['phone']) ? trim(strip_tags($input['phone'])) : '';
$language = isset($input['language']) ? trim(strtolower(strip_tags($input['language']))) : 'es';
$contact_channel = isset($input['contact_channel']) ? trim(strip_tags($input['contact_channel'])) : 'email';

$arrival_time = isset($input['arrival_time']) ? trim(strip_tags($input['arrival_time'])) : '';
$special_requests = isset($input['special_requests']) ? trim(strip_tags($input['special_requests'])) : '';
$how_knew = isset($input['how_knew']) ? trim(strip_tags($input['how_knew'])) : '';

// 5. Validation Rules
if (empty($name)) send_json_error("El nombre completo es obligatorio.");
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) send_json_error("El correo electrónico no es válido.");
if (empty($phone)) send_json_error("El número de teléfono es obligatorio.");
if (empty($country)) send_json_error("El país de residencia es obligatorio.");

$date_format = '/^\d{4}-\d{2}-\d{2}$/';
if (!preg_match($date_format, $checkin) || !preg_match($date_format, $checkout)) {
    send_json_error("Formato de fecha de entrada o salida no válido.");
}

$checkin_time = strtotime($checkin);
$checkout_time = strtotime($checkout);
$today_time = strtotime(date('Y-m-d'));

if ($checkin_time === false || $checkout_time === false) send_json_error("Fechas incorrectas.");
if ($checkin_time < $today_time) send_json_error("No puedes seleccionar fechas pasadas.");
if ($checkout_time <= $checkin_time) send_json_error("La fecha de salida debe ser posterior a la de entrada.");

// Max guests check (maximum 6 guests, babies are counted separately but cannot exceed 4)
$total_guests = $adults + $children;
if ($total_guests < 1) send_json_error("Debe seleccionar al menos 1 adulto.");
if ($total_guests > 6) send_json_error("La capacidad máxima de alojamiento es de 6 personas.");
if ($babies > 4) send_json_error("El número de bebés no puede ser superior a 4.");

// Load pricing configuration
$config_file = __DIR__ . '/configuracion_precios.json';
if (!file_exists($config_file)) {
    $config_file = __DIR__ . '/booking_config.json';
}
if (!file_exists($config_file)) send_json_error("Configuración del servidor no encontrada.", 500);
$config = json_decode(file_get_contents($config_file), true);

$min_stay = isset($config['reglas_estancia']['minimo_noches']) ? intval($config['reglas_estancia']['minimo_noches']) : (isset($config['min_stay']) ? intval($config['min_stay']) : 5);
$max_stay = isset($config['reglas_estancia']['maximo_noches']) ? intval($config['reglas_estancia']['maximo_noches']) : (isset($config['max_stay']) ? intval($config['max_stay']) : 30);

$diff_seconds = $checkout_time - $checkin_time;
$nights = round($diff_seconds / (60 * 60 * 24));

if ($nights < $min_stay) send_json_error("La estancia mínima es de {$min_stay} noches.");
if ($nights > $max_stay) send_json_error("La estancia máxima es de {$max_stay} noches.");

// 6. Double-Booking Prevention Check (iCal Airbnb + Local DB)
// 6.1. iCal Block Check
$cache_file = __DIR__ . '/../scratch/ical_cache.json';
if (file_exists($cache_file)) {
    $ical_ranges = json_decode(file_get_contents($cache_file), true);
    if (is_array($ical_ranges)) {
        foreach ($ical_ranges as $range) {
            $range_start = strtotime($range['start']);
            $range_end = strtotime($range['end']);
            
            // Check for overlap: requested range overlaps if (StartA < EndB) and (EndA > StartB)
            // check-out day is a free check-in day, so check-out is excluded
            if ($checkin_time < $range_end && $checkout_time > $range_start) {
                send_json_error("Las fechas seleccionadas ya están ocupadas en el calendario de Airbnb. Por favor, selecciona otras fechas.");
            }
        }
    }
}

// 6.2. Local DB Overlap Check
$db = get_db_connection();
$now_str = date('Y-m-d H:i:s');
$expire_cutoff = date('Y-m-d H:i:s', strtotime('-24 hours'));

// 6.2.a Auto-expire unconfirmed pending requests older than 24h
try {
    $db->prepare("UPDATE booking_requests SET status = 'caducada', updated_at = ? WHERE status = 'solicitud_recibida' AND created_at < ?")
       ->execute([$now_str, $expire_cutoff]);
} catch (Exception $e) {
    error_log("Error auto-expiring pending bookings: " . $e->getMessage());
}

// 6.2.b If guest is re-submitting with the same email, cancel previous unconfirmed 'solicitud_recibida' request
try {
    $db->prepare("UPDATE booking_requests SET status = 'caducada', updated_at = ? WHERE status = 'solicitud_recibida' AND guest_email = ?")
       ->execute([$now_str, $email]);
} catch (Exception $e) {
    error_log("Error replacing previous pending booking for {$email}: " . $e->getMessage());
}

$stmt = $db->prepare("
    SELECT COUNT(*) as count 
    FROM booking_requests 
    WHERE status NOT IN ('cancelada', 'caducada')
      AND checkin_date < :checkout
      AND checkout_date > :checkin
");
$stmt->execute([
    ':checkin' => $checkin,
    ':checkout' => $checkout
]);
$row = $stmt->fetch();
if ($row && intval($row['count']) > 0) {
    send_json_error("Las fechas seleccionadas ya han sido bloqueadas o solicitadas por otro huésped. Por favor, intenta con otro rango.");
}

// 7. Calculate Pricing details
$daily_prices_map = [];
if (isset($config['precios_diarios']) && is_array($config['precios_diarios'])) {
    foreach ($config['precios_diarios'] as $pd) {
        if (isset($pd['fecha']) && isset($pd['precio'])) {
            $daily_prices_map[$pd['fecha']] = floatval($pd['precio']);
        }
    }
}

$base_accommodation = 0;
$temp_time = $checkin_time;
$seasons = isset($config['seasons']) ? $config['seasons'] : [];

while ($temp_time < $checkout_time) {
    $temp_date = date('Y-m-d', $temp_time);
    $month = intval(date('n', $temp_time)) - 1; // 0-indexed month
    
    if (isset($daily_prices_map[$temp_date])) {
        $rate = $daily_prices_map[$temp_date];
    } else {
        $rate = 120;
        foreach ($seasons as $season) {
            if (in_array($month, $season['months'])) {
                $rate = $season['rate'];
                break;
            }
        }
    }
    $base_accommodation += $rate;
    $temp_time = strtotime("+1 day", $temp_time);
}

// Non-cumulative discount (highest applicable)
$discount_pct = 0;
if (isset($config['descuentos']['reglas']) && is_array($config['descuentos']['reglas'])) {
    foreach ($config['descuentos']['reglas'] as $rule) {
        if ($nights >= intval($rule['noches_minimas'])) {
            $rule_pct = floatval($rule['porcentaje']);
            if ($rule_pct > $discount_pct) {
                $discount_pct = $rule_pct;
            }
        }
    }
} else {
    if ($nights >= 28) {
        $discount_pct = 12;
    } elseif ($nights >= 7) {
        $discount_pct = 3;
    }
}

$discount_amount = round(($base_accommodation * $discount_pct) / 100, 2);
$total_accommodation = round($base_accommodation - $discount_amount, 2);

$cleaning_fee = round(isset($config['cleaning_fee']) ? floatval($config['cleaning_fee']) : 120.00, 2);
$tax_rate = round(isset($config['tourist_tax']['rate_per_adult_per_night']) ? floatval($config['tourist_tax']['rate_per_adult_per_night']) : 2.20, 2);
$tax_total = round($adults * $nights * $tax_rate, 2);

$total_cost = round($total_accommodation + $cleaning_fee + (isset($config['tourist_tax']['included_in_total']) && $config['tourist_tax']['included_in_total'] ? $tax_total : 0), 2);

$deposit_pct = isset($config['deposit_percentage']) ? intval($config['deposit_percentage']) : 30;
$deposit_required = round(($total_cost * $deposit_pct) / 100, 2);
$pending_balance = round($total_cost - $deposit_required, 2);

$due_days = isset($config['second_payment_days_before_checkin']) ? intval($config['second_payment_days_before_checkin']) : 30;
$due_time = strtotime("-{$due_days} days", $checkin_time);
if ($due_time < $today_time) {
    $due_time = $today_time;
}
$balance_due_date = date('Y-m-d', $due_time);

// 8. Generate Request Number
$year = date('Y');
$rand_suffix = rand(1000, 9999);
$request_number = "CP-{$year}-{$rand_suffix}";

// Save request to Database
try {
    $db->beginTransaction();
    
    $now = date('Y-m-d H:i:s');
    $stmt = $db->prepare("
        INSERT INTO booking_requests (
            request_number, checkin_date, checkout_date, adults, children, babies,
            guest_name, guest_email, guest_phone, guest_country, preferred_language, contact_channel,
            arrival_time, special_requests, discovery_channel,
            amount_accommodation, amount_cleaning, amount_tax, amount_total, amount_deposit, amount_balance,
            balance_due_date, status, created_at, updated_at
        ) VALUES (
            :request_number, :checkin_date, :checkout_date, :adults, :children, :babies,
            :guest_name, :guest_email, :guest_phone, :guest_country, :preferred_language, :contact_channel,
            :arrival_time, :special_requests, :discovery_channel,
            :amount_accommodation, :amount_cleaning, :amount_tax, :amount_total, :amount_deposit, :amount_balance,
            :balance_due_date, 'solicitud_recibida', :created_at, :updated_at
        )
    ");
    
    $stmt->execute([
        ':request_number' => $request_number,
        ':checkin_date' => $checkin,
        ':checkout_date' => $checkout,
        ':adults' => $adults,
        ':children' => $children,
        ':babies' => $babies,
        ':guest_name' => $name,
        ':guest_email' => $email,
        ':guest_phone' => $phone,
        ':guest_country' => $country,
        ':preferred_language' => $language,
        ':contact_channel' => $contact_channel,
        ':arrival_time' => $arrival_time,
        ':special_requests' => $special_requests,
        ':discovery_channel' => $how_knew,
        ':amount_accommodation' => $total_accommodation,
        ':amount_cleaning' => $cleaning_fee,
        ':amount_tax' => $tax_total,
        ':amount_total' => $total_cost,
        ':amount_deposit' => $deposit_required,
        ':amount_balance' => $pending_balance,
        ':balance_due_date' => $balance_due_date,
        ':created_at' => $now,
        ':updated_at' => $now
    ]);
    
    $booking_id = $db->lastInsertId();
    
    // Log history
    $stmt_history = $db->prepare("
        INSERT INTO booking_history (booking_id, status, notes, changed_at)
        VALUES (:booking_id, 'solicitud_recibida', 'Solicitud creada a través de la web.', :changed_at)
    ");
    $stmt_history->execute([
        ':booking_id' => $booking_id,
        ':changed_at' => $now
    ]);
    
    $db->commit();
    
    // 9. Send Notification Emails (Runs in background asynchronously)
    $booking_data = [
        'request_number' => $request_number,
        'guest_name' => $name,
        'guest_email' => $email,
        'guest_phone' => $phone,
        'guest_country' => $country,
        'preferred_language' => $language,
        'checkin_date' => $checkin,
        'checkout_date' => $checkout,
        'nights' => $nights,
        'adults' => $adults,
        'children' => $children,
        'babies' => $babies,
        'amount_accommodation' => $total_accommodation,
        'amount_cleaning' => $cleaning_fee,
        'amount_tax' => $tax_total,
        'amount_total' => $total_cost,
        'amount_deposit' => $deposit_required,
        'amount_balance' => $pending_balance,
        'balance_due_date' => $balance_due_date,
        'status' => 'solicitud_recibida'
    ];
    
    send_booking_email($booking_data, 'solicitud_recibida');
    
    echo json_encode([
        "success" => true,
        "request_number" => $request_number,
        "checkin" => $checkin,
        "checkout" => $checkout,
        "nights" => $nights,
        "total_guests" => $total_guests,
        "estimated_total" => $total_cost,
        "guest_email" => $email,
        "message" => "Solicitud enviada correctamente."
    ]);
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Error saving booking request: " . $e->getMessage());
    send_json_error("Ocurrió un error al procesar tu reserva en el servidor. Por favor, contacta con nosotros directamente.");
}
?>
