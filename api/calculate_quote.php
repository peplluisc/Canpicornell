<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

require_once __DIR__ . '/db.php'; // Include DB for double-booking checking if needed

// Helper to return JSON errors
function send_error($message, $code = 400) {
    http_response_code($code);
    echo json_encode(["error" => $message]);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_error("Method Not Allowed", 405);
}

// Parse input parameters
$raw_input = file_get_contents('php://input');
$input = json_decode($raw_input, true);

if (!$input) {
    // Fallback to standard POST parameters if JSON decoding failed
    $input = $_POST;
}

$checkin = isset($input['checkin']) ? trim($input['checkin']) : '';
$checkout = isset($input['checkout']) ? trim($input['checkout']) : '';
$adults = isset($input['adults']) ? intval($input['adults']) : 0;
$children = isset($input['children']) ? intval($input['children']) : 0;
$babies = isset($input['babies']) ? intval($input['babies']) : 0;

// Basic validation
if (empty($checkin) || empty($checkout)) {
    send_error("Las fechas de entrada y salida son obligatorias.");
}

$date_format = '/^\d{4}-\d{2}-\d{2}$/';
if (!preg_match($date_format, $checkin) || !preg_match($date_format, $checkout)) {
    send_error("El formato de fecha debe ser AAAA-MM-DD.");
}

$checkin_time = strtotime($checkin);
$checkout_time = strtotime($checkout);
$today_time = strtotime(date('Y-m-d'));

if ($checkin_time === false || $checkout_time === false) {
    send_error("Fechas no válidas.");
}

if ($checkin_time < $today_time) {
    send_error("No puedes reservar fechas pasadas.");
}

if ($checkout_time <= $checkin_time) {
    send_error("La fecha de salida debe ser posterior a la de entrada.");
}

// Calculate nights
$diff_seconds = $checkout_time - $checkin_time;
$nights = round($diff_seconds / (60 * 60 * 24));

// Load configuration
$config_file = __DIR__ . '/booking_config.json';
if (!file_exists($config_file)) {
    send_error("Error de configuración en el servidor.", 500);
}
$config = json_decode(file_get_contents($config_file), true);
if (!$config) {
    send_error("Error al cargar la configuración de precios.", 500);
}

// Validate min stay
$min_stay = isset($config['min_stay']) ? intval($config['min_stay']) : 3;
if ($nights < $min_stay) {
    send_error("La estancia mínima es de {$min_stay} noches.");
}

// Validate guest limits (max 6 guests excluding babies)
$total_guests = $adults + $children;
if ($total_guests < 1) {
    send_error("Debe seleccionar al menos 1 adulto.");
}
if ($total_guests > 6) {
    send_error("La capacidad máxima de Can Picornell es de 6 personas.");
}

// Calculate accommodation price day by day (seasonal pricing)
$total_accommodation = 0;
$temp_time = $checkin_time;
$seasons = $config['seasons'];
$nightly_breakdown = [];

while ($temp_time < $checkout_time) {
    $temp_date = date('Y-m-d', $temp_time);
    $month = intval(date('n', $temp_time)) - 1; // 0-indexed month
    
    $rate = 120; // default low season
    $season_name = 'low';
    
    foreach ($seasons as $key => $season) {
        if (in_array($month, $season['months'])) {
            $rate = $season['rate'];
            $season_name = $key;
            break;
        }
    }
    
    $total_accommodation += $rate;
    $nightly_breakdown[] = [
        "date" => $temp_date,
        "rate" => $rate,
        "season" => $season_name
    ];
    
    $temp_time = strtotime("+1 day", $temp_time);
}

$cleaning_fee = isset($config['cleaning_fee']) ? floatval($config['cleaning_fee']) : 120.00;

// Sustainable Tourism Tax (EcoTasa)
$tax_config = $config['tourist_tax'];
$tax_rate = isset($tax_config['rate_per_adult_per_night']) ? floatval($tax_config['rate_per_adult_per_night']) : 2.20;
$tax_total = $adults * $nights * $tax_rate;

// Total calculation
$total_cost = $total_accommodation + $cleaning_fee; // Exclude EcoTasa from online total if paid in cash, or include depending on setting
if (isset($tax_config['included_in_total']) && $tax_config['included_in_total']) {
    $total_cost += $tax_total;
}

// Deposit and balance
$deposit_pct = isset($config['deposit_percentage']) ? intval($config['deposit_percentage']) : 30;
$deposit_required = round(($total_cost * $deposit_pct) / 100, 2);
$pending_balance = $total_cost - $deposit_required;

// Balance due date (e.g. 30 days before check-in, or today if check-in is close)
$due_days = isset($config['second_payment_days_before_checkin']) ? intval($config['second_payment_days_before_checkin']) : 30;
$due_time = strtotime("-{$due_days} days", $checkin_time);
if ($due_time < $today_time) {
    $due_time = $today_time;
}
$balance_due_date = date('Y-m-d', $due_time);

// Return response JSON
echo json_encode([
    "checkin" => $checkin,
    "checkout" => $checkout,
    "nights" => $nights,
    "adults" => $adults,
    "children" => $children,
    "babies" => $babies,
    "pricing" => [
        "base_accommodation" => $total_accommodation,
        "cleaning_fee" => $cleaning_fee,
        "tourist_tax" => [
            "rate" => $tax_rate,
            "total" => $tax_total,
            "included_in_total" => $tax_config['included_in_total'],
            "message" => [
                "es" => $tax_config['notes_es'],
                "en" => $tax_config['notes_en'],
                "de" => $tax_config['notes_de']
            ]
        ],
        "total" => $total_cost,
        "deposit_percentage" => $deposit_pct,
        "deposit_required" => $deposit_required,
        "pending_balance" => $pending_balance,
        "balance_due_date" => $balance_due_date,
        "currency" => $config['currency'],
        "cancellation_policy" => $config['cancellation_policy']
    ],
    "nightly_breakdown" => $nightly_breakdown,
    "disclaimer" => "Presupuesto sujeto a comprobación final de disponibilidad."
]);
?>
