<?php
/**
 * Admin Authentication Endpoint for Can Picornell
 * Handles Google OAuth ID Token verification and session management
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

require_once __DIR__ . '/env_helper.php';

function send_json_response($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = isset($_GET['action']) ? trim($_GET['action']) : '';
$method = $_SERVER['REQUEST_METHOD'];

$allowed_email = strtolower(trim(get_env_var('ADMIN_ALLOWED_EMAIL', '')));
$google_client_id = trim(get_env_var('GOOGLE_CLIENT_ID', ''));

// ACTION: STATUS (GET)
if ($method === 'GET' || $action === 'status') {
    $is_auth = isset($_SESSION['admin_authenticated']) && $_SESSION['admin_authenticated'] === true;
    send_json_response([
        'authenticated' => $is_auth,
        'email' => $is_auth ? ($_SESSION['admin_email'] ?? '') : null,
        'google_client_id' => $google_client_id,
        'allowed_email_configured' => !empty($allowed_email)
    ]);
}

// ACTION: LOGOUT (POST)
if ($action === 'logout') {
    $_SESSION['admin_authenticated'] = false;
    unset($_SESSION['admin_authenticated']);
    unset($_SESSION['admin_email']);
    session_destroy();
    send_json_response(['success' => true, 'message' => 'Sesión cerrada correctamente']);
}

// ACTION: LOGIN WITH GOOGLE ID TOKEN (POST)
if ($method === 'POST') {
    $raw_input = file_get_contents('php://input');
    $input = json_decode($raw_input, true);
    if (!$input) {
        $input = $_POST;
    }

    $id_token = isset($input['id_token']) ? trim($input['id_token']) : '';

    if (empty($id_token)) {
        send_json_response(['error' => 'Token de identificación no proporcionado.'], 400);
    }

    // Verify token with Google's tokeninfo API
    $token_info_url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($id_token);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $token_info_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || !$response) {
        // Fallback using file_get_contents if curl fails or disabled
        $response = @file_get_contents($token_info_url);
        if (!$response) {
            send_json_response(['error' => 'No se pudo verificar el token de Google con el servidor de autenticación.'], 401);
        }
    }

    $payload = json_decode($response, true);
    if (!$payload || !isset($payload['email'])) {
        send_json_response(['error' => 'Token de Google no válido o exppirado.'], 401);
    }

    $user_email = strtolower(trim($payload['email']));
    $email_verified = isset($payload['email_verified']) && ($payload['email_verified'] === true || $payload['email_verified'] === 'true');

    if (!$email_verified) {
        send_json_response(['error' => 'El correo electrónico de Google no está verificado.'], 403);
    }

    // Check if client ID verification is needed and client_id matches when set
    if (!empty($google_client_id) && isset($payload['aud']) && $payload['aud'] !== $google_client_id) {
        send_json_response(['error' => 'El token de Google no pertenece a la aplicación configurada.'], 403);
    }

    // Check if email matches allowed email
    if (!empty($allowed_email) && $user_email !== $allowed_email) {
        send_json_response([
            'error' => "El correo {$user_email} no tiene permisos de administración."
        ], 403);
    }

    // Authentication successful
    $_SESSION['admin_authenticated'] = true;
    $_SESSION['admin_email'] = $user_email;

    send_json_response([
        'success' => true,
        'authenticated' => true,
        'email' => $user_email,
        'message' => 'Autenticación exitosa'
    ]);
}

send_json_response(['error' => 'Solicitud no válida'], 400);
