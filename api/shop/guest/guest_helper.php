<?php
/**
 * Guest Helper utilities for Can Picornell Guest Shop Module
 * Handles token validation, rate limiting, order calculations, and multi-language fallback.
 */

date_default_timezone_set('Europe/Madrid');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../db.php';

function send_guest_json($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow', true);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Simple IP-based Rate Limiting (Max 60 requests per minute)
function apply_guest_rate_limit() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $time_window = 60;
    $max_requests = 60;
    
    if (!isset($_SESSION['rate_limit_ip']) || $_SESSION['rate_limit_ip'] !== $ip) {
        $_SESSION['rate_limit_ip'] = $ip;
        $_SESSION['rate_limit_time'] = time();
        $_SESSION['rate_limit_count'] = 1;
        return;
    }
    
    if (time() - $_SESSION['rate_limit_time'] < $time_window) {
        $_SESSION['rate_limit_count']++;
        if ($_SESSION['rate_limit_count'] > $max_requests) {
            send_guest_json(['error' => 'Demasiadas solicitudes. Por favor, espera un momento.'], 429);
        }
    } else {
        $_SESSION['rate_limit_time'] = time();
        $_SESSION['rate_limit_count'] = 1;
    }
}

function validate_guest_token(PDO $db, string $raw_token) {
    apply_guest_rate_limit();

    $raw_token = trim($raw_token);
    if (empty($raw_token) || strlen($raw_token) < 16) {
        send_guest_json(['error' => 'Enlace no válido o expirado.'], 403);
    }

    $token_hash = hash('sha256', $raw_token);
    $now = date('Y-m-d H:i:s');

    try {
        $stmt = $db->prepare("
            SELECT 
                t.id AS token_id,
                t.booking_id,
                t.token_hash,
                t.preferred_language,
                t.is_active,
                t.expires_at,
                t.last_accessed_at,
                b.request_number,
                b.guest_name,
                b.checkin_date,
                b.checkout_date,
                b.adults,
                b.children,
                b.babies
            FROM shop_access_tokens t
            JOIN booking_requests b ON t.booking_id = b.id
            WHERE t.token_hash = ?
        ");
        $stmt->execute([$token_hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            send_guest_json(['error' => 'Enlace no válido o expirado.'], 403);
        }

        if (intval($row['is_active']) !== 1 || $row['expires_at'] < $now) {
            send_guest_json(['error' => 'Enlace no activo o caducado.'], 403);
        }

        // Touch last_accessed_at
        $upd = $db->prepare("UPDATE shop_access_tokens SET last_accessed_at = ? WHERE id = ?");
        $upd->execute([$now, $row['token_id']]);

        // Calculate nights
        $c_in = new DateTime($row['checkin_date']);
        $c_out = new DateTime($row['checkout_date']);
        $diff = $c_in->diff($c_out);
        $row['nights'] = max(1, $diff->days);

        return $row;
    } catch (Exception $e) {
        error_log("Guest token validation error: " . $e->getMessage());
        send_guest_json(['error' => 'Ocurrió un error de conexión.'], 500);
    }
}

function resolve_language(?string $url_lang, string $token_pref_lang): string {
    $url_lang = strtolower(trim($url_lang ?? ''));
    if (in_array($url_lang, ['es', 'en', 'de'])) {
        return $url_lang;
    }
    $token_pref_lang = strtolower(trim($token_pref_lang));
    if (in_array($token_pref_lang, ['es', 'en', 'de'])) {
        return $token_pref_lang;
    }
    return 'es';
}

function get_global_margin(PDO $db): float {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM shop_settings WHERE setting_key = 'global_margin_percent'");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        return ($val !== false) ? floatval($val) : 10.0;
    } catch (Exception $e) {
        return 10.0;
    }
}

function calculate_final_price_cents(int $ref_cents, ?float $margin_percent, ?int $manual_price_cents, float $global_margin): int {
    if ($manual_price_cents !== null && $manual_price_cents > 0) {
        return $manual_price_cents;
    }
    $margin = ($margin_percent !== null) ? floatval($margin_percent) : $global_margin;
    return intval(round($ref_cents * (1.0 + ($margin / 100.0))));
}

function get_or_create_draft_order(PDO $db, int $booking_id, int $token_id) {
    try {
        $db->beginTransaction();

        // Check for any existing order (DRAFT or SUBMITTED/PENDING_REVIEW/etc.)
        $stmt = $db->prepare("
            SELECT id, order_number, status, subtotal_cents, margin_cents, total_cents, guest_notes, submitted_at, created_at
            FROM shop_orders
            WHERE booking_id = ? AND token_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$booking_id, $token_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($order) {
            $db->commit();
            return $order;
        }

        // Create new DRAFT order
        $now = date('Y-m-d H:i:s');
        $order_number = 'ORD-CP-' . date('Y') . '-' . sprintf('%05d', rand(1000, 99999));

        $ins = $db->prepare("
            INSERT INTO shop_orders (
                booking_id, token_id, order_number, status, subtotal_cents, margin_cents, total_cents, created_at, updated_at
            ) VALUES (?, ?, ?, 'DRAFT', 0, 0, 0, ?, ?)
        ");
        $ins->execute([$booking_id, $token_id, $order_number, $now, $now]);
        $order_id = $db->lastInsertId();

        $db->commit();

        return [
            'id' => $order_id,
            'order_number' => $order_number,
            'status' => 'DRAFT',
            'subtotal_cents' => 0,
            'margin_cents' => 0,
            'total_cents' => 0,
            'guest_notes' => '',
            'submitted_at' => null,
            'created_at' => $now
        ];
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        // Fallback retry query if another request created it during race condition
        $stmt = $db->prepare("
            SELECT id, order_number, status, subtotal_cents, margin_cents, total_cents, guest_notes, submitted_at, created_at
            FROM shop_orders
            WHERE booking_id = ? AND token_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$booking_id, $token_id]);
        $retryOrder = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($retryOrder) {
            return $retryOrder;
        }

        error_log("Error getting/creating draft order: " . $e->getMessage());
        send_guest_json(['error' => 'No se pudo inicializar el pedido.'], 500);
    }
}

function recalculate_order_totals(PDO $db, int $order_id): void {
    try {
        $stmt = $db->prepare("
            SELECT SUM(total_price_cents) AS subtotal
            FROM shop_order_items
            WHERE order_id = ?
        ");
        $stmt->execute([$order_id]);
        $subtotal = intval($stmt->fetchColumn() ?: 0);

        $now = date('Y-m-d H:i:s');
        $upd = $db->prepare("
            UPDATE shop_orders SET
                subtotal_cents = ?,
                margin_cents = 0,
                total_cents = ?,
                updated_at = ?
            WHERE id = ?
        ");
        $upd->execute([$subtotal, $subtotal, $now, $order_id]);
    } catch (Exception $e) {
        error_log("Error recalculating order totals: " . $e->getMessage());
    }
}
