<?php
/**
 * Admin Orders Endpoint for Can Picornell Private Guest Shop Module
 * Handles order listing, detailed view, item editing, price overrides, admin notes,
 * state transitions (APPROVE, PURCHASED, DELIVERED, PAID, CANCEL), and supermarket checklist item status.
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
$method = $_SERVER['REQUEST_METHOD'];

function recalculate_admin_order_totals(PDO $db, int $order_id): void {
    $stmt = $db->prepare("SELECT SUM(total_price_cents) FROM shop_order_items WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $subtotal = intval($stmt->fetchColumn() ?: 0);
    $now = date('Y-m-d H:i:s');

    $upd = $db->prepare("UPDATE shop_orders SET subtotal_cents = ?, margin_cents = 0, total_cents = ?, updated_at = ? WHERE id = ?");
    $upd->execute([$subtotal, $subtotal, $now, $order_id]);
}

// GET: List orders OR get order detail
if ($method === 'GET') {
    $order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    // GET SINGLE ORDER DETAIL
    if ($order_id > 0) {
        try {
            $o_stmt = $db->prepare("
                SELECT 
                    o.id, o.booking_id, o.token_id, o.order_number, o.status,
                    o.subtotal_cents, o.margin_cents, o.total_cents,
                    o.guest_notes, o.admin_notes, o.submitted_at, o.approved_at,
                    o.purchased_at, o.delivered_at, o.paid_at, o.cancelled_at,
                    o.created_at, o.updated_at,
                    b.request_number, b.guest_name, b.guest_email, b.guest_phone,
                    b.checkin_date, b.checkout_date, b.preferred_language,
                    t.preferred_language AS token_language
                FROM shop_orders o
                JOIN booking_requests b ON o.booking_id = b.id
                JOIN shop_access_tokens t ON o.token_id = t.id
                WHERE o.id = ?
            ");
            $o_stmt->execute([$order_id]);
            $order = $o_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                send_response(['error' => 'El pedido no existe.'], 404);
            }

            // Calculate nights
            $c_in = new DateTime($order['checkin_date']);
            $c_out = new DateTime($order['checkout_date']);
            $order['nights'] = max(1, $c_in->diff($c_out)->days);

            // Fetch Items
            $i_stmt = $db->prepare("
                SELECT 
                    i.id AS item_id, i.product_id, i.product_name_snapshot,
                    i.quantity, i.unit_price_cents, i.total_price_cents,
                    i.is_purchased, COALESCE(i.purchase_status, 'PENDING') AS purchase_status, i.notes,
                    p.sku, p.sku AS product_code, p.brand, p.image_url, t.format_text
                FROM shop_order_items i
                LEFT JOIN shop_products p ON i.product_id = p.id
                LEFT JOIN shop_product_translations t ON p.id = t.product_id AND t.language = 'es'
                WHERE i.order_id = ?
                ORDER BY i.id ASC
            ");
            $i_stmt->execute([$order_id]);
            $items = $i_stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($items as &$it) {
                $it['unit_price_formatted'] = number_format($it['unit_price_cents'] / 100, 2, ',', '.') . ' €';
                $it['total_price_formatted'] = number_format($it['total_price_cents'] / 100, 2, ',', '.') . ' €';
            }

            $order['total_formatted'] = number_format($order['total_cents'] / 100, 2, ',', '.') . ' €';
            $order['items'] = $items;

            send_response(['success' => true, 'order' => $order]);
        } catch (Exception $e) {
            send_response(['error' => 'Error al cargar detalle del pedido: ' . $e->getMessage()], 500);
        }
    }

    // LIST ORDERS
    try {
        $status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
        $search = isset($_GET['q']) ? trim($_GET['q']) : '';

        $sql = "
            SELECT 
                o.id, o.order_number, o.status, o.total_cents, o.submitted_at, o.created_at,
                b.request_number, b.guest_name, b.guest_email, b.checkin_date, b.checkout_date,
                (SELECT SUM(quantity) FROM shop_order_items WHERE order_id = o.id) AS items_count
            FROM shop_orders o
            JOIN booking_requests b ON o.booking_id = b.id
            WHERE 1=1
        ";

        $params = [];

        if (!empty($status_filter)) {
            $sql .= " AND o.status = :status";
            $params[':status'] = $status_filter;
        } else {
            // Exclude DRAFT by default from main orders view
            $sql .= " AND o.status != 'DRAFT'";
        }

        if (!empty($search)) {
            $sql .= " AND (b.guest_name LIKE :q OR b.request_number LIKE :q OR o.order_number LIKE :q)";
            $params[':q'] = "%{$search}%";
        }

        // Operational priority sorting: PENDING_REVIEW > APPROVED > PURCHASED > DELIVERED > PAID > CANCELLED, then checkin date
        $sql .= "
            ORDER BY 
                CASE o.status
                    WHEN 'PENDING_REVIEW' THEN 1
                    WHEN 'APPROVED' THEN 2
                    WHEN 'PURCHASED' THEN 3
                    WHEN 'DELIVERED' THEN 4
                    WHEN 'PAID' THEN 5
                    ELSE 6
                END ASC,
                b.checkin_date ASC,
                o.id DESC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($orders as &$ord) {
            $ord['total_formatted'] = number_format($ord['total_cents'] / 100, 2, ',', '.') . ' €';
            $ord['items_count'] = intval($ord['items_count'] ?: 0);
        }

        // Pending Review Count
        $p_cnt = $db->query("SELECT COUNT(*) FROM shop_orders WHERE status = 'PENDING_REVIEW'")->fetchColumn();

        send_response([
            'success' => true,
            'pending_review_count' => intval($p_cnt),
            'orders' => $orders
        ]);
    } catch (Exception $e) {
        send_response(['error' => 'Error al listar pedidos: ' . $e->getMessage()], 500);
    }
}

// POST: Actions
if ($method === 'POST') {
    $raw_input = file_get_contents('php://input');
    $input = json_decode($raw_input, true) ?: $_POST;
    $action = isset($input['action']) ? trim($input['action']) : '';
    $order_id = isset($input['order_id']) ? intval($input['order_id']) : 0;

    if ($order_id <= 0) {
        send_response(['error' => 'ID de pedido no válido.'], 400);
    }

    // Fetch current order status
    $o_chk = $db->prepare("SELECT id, status, booking_id FROM shop_orders WHERE id = ?");
    $o_chk->execute([$order_id]);
    $current_order = $o_chk->fetch(PDO::FETCH_ASSOC);

    if (!$current_order) {
        send_response(['error' => 'El pedido especificado no existe.'], 404);
    }

    $current_status = $current_order['status'];
    $now = date('Y-m-d H:i:s');

    // 1. UPDATE ITEM (quantity or unit price override)
    if ($action === 'update_item') {
        if ($current_status !== 'PENDING_REVIEW') {
            send_response(['error' => 'Solo se pueden editar productos de un pedido en estado "Pendiente de revisar".'], 400);
        }
        $item_id = isset($input['item_id']) ? intval($input['item_id']) : 0;
        $quantity = isset($input['quantity']) ? intval($input['quantity']) : 1;
        $unit_price_cents = isset($input['unit_price_cents']) ? intval($input['unit_price_cents']) : 0;

        if ($item_id <= 0 || $quantity <= 0 || $unit_price_cents < 0) {
            send_response(['error' => 'Datos de línea de producto no válidos.'], 400);
        }

        try {
            $db->beginTransaction();
            $line_total = $unit_price_cents * $quantity;
            $upd = $db->prepare("UPDATE shop_order_items SET quantity = ?, unit_price_cents = ?, total_price_cents = ? WHERE id = ? AND order_id = ?");
            $upd->execute([$quantity, $unit_price_cents, $line_total, $item_id, $order_id]);

            recalculate_admin_order_totals($db, $order_id);
            $db->commit();
            send_response(['success' => true, 'message' => 'Línea de producto actualizada.']);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            send_response(['error' => 'Error al actualizar línea: ' . $e->getMessage()], 500);
        }
    }

    // 2. ADD ITEM FROM CATALOG
    if ($action === 'add_item') {
        if ($current_status !== 'PENDING_REVIEW') {
            send_response(['error' => 'Solo se pueden añadir productos a un pedido en "Pendiente de revisar".'], 400);
        }
        $product_id = isset($input['product_id']) ? intval($input['product_id']) : 0;
        $quantity = isset($input['quantity']) ? intval($input['quantity']) : 1;

        if ($product_id <= 0 || $quantity <= 0) {
            send_response(['error' => 'Producto o cantidad no válida.'], 400);
        }

        // Fetch product
        $p_stmt = $db->prepare("
            SELECT 
                p.id, p.reference_price_cents, p.margin_percent, p.manual_final_price_cents,
                t.name AS es_name
            FROM shop_products p
            LEFT JOIN shop_product_translations t ON p.id = t.product_id AND t.language = 'es'
            WHERE p.id = ?
        ");
        $p_stmt->execute([$product_id]);
        $prod = $p_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$prod) {
            send_response(['error' => 'El producto especificado no existe.'], 404);
        }

        $global_margin = 10.0;
        $m_stmt = $db->query("SELECT setting_value FROM shop_settings WHERE setting_key = 'global_margin_percent'");
        $m_val = $m_stmt ? $m_stmt->fetchColumn() : null;
        if ($m_val !== false) $global_margin = floatval($m_val);

        $ref_c = intval($prod['reference_price_cents']);
        $m_p = $prod['margin_percent'] !== null ? floatval($prod['margin_percent']) : null;
        $man_c = $prod['manual_final_price_cents'] !== null ? intval($prod['manual_final_price_cents']) : null;

        $unit_price_cents = ($man_c !== null && $man_c > 0) ? $man_c : intval(round($ref_c * (1.0 + (($m_p !== null ? $m_p : $global_margin) / 100.0))));
        $name = !empty($prod['es_name']) ? $prod['es_name'] : 'Producto #' . $product_id;

        try {
            $db->beginTransaction();
            $line_total = $unit_price_cents * $quantity;
            $ins = $db->prepare("
                INSERT INTO shop_order_items (order_id, product_id, product_name_snapshot, quantity, unit_price_cents, total_price_cents)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([$order_id, $product_id, $name, $quantity, $unit_price_cents, $line_total]);

            recalculate_admin_order_totals($db, $order_id);
            $db->commit();
            send_response(['success' => true, 'message' => 'Producto añadido al pedido.']);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            send_response(['error' => 'Error al añadir producto: ' . $e->getMessage()], 500);
        }
    }

    // 3. REMOVE ITEM
    if ($action === 'remove_item') {
        if ($current_status !== 'PENDING_REVIEW') {
            send_response(['error' => 'Solo se pueden eliminar líneas en pedidos "Pendiente de revisar".'], 400);
        }
        $item_id = isset($input['item_id']) ? intval($input['item_id']) : 0;
        try {
            $db->beginTransaction();
            $del = $db->prepare("DELETE FROM shop_order_items WHERE id = ? AND order_id = ?");
            $del->execute([$item_id, $order_id]);

            recalculate_admin_order_totals($db, $order_id);
            $db->commit();
            send_response(['success' => true, 'message' => 'Línea de producto eliminada.']);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            send_response(['error' => 'Error al eliminar línea: ' . $e->getMessage()], 500);
        }
    }

    // 4. UPDATE ADMIN NOTES
    if ($action === 'update_admin_notes') {
        $notes = isset($input['admin_notes']) ? trim($input['admin_notes']) : '';
        try {
            $upd = $db->prepare("UPDATE shop_orders SET admin_notes = ?, updated_at = ? WHERE id = ?");
            $upd->execute([$notes, $now, $order_id]);
            send_response(['success' => true, 'message' => 'Observaciones internas guardadas.']);
        } catch (Exception $e) {
            send_response(['error' => 'Error al guardar observaciones: ' . $e->getMessage()], 500);
        }
    }

    // 5. STATE TRANSITION: APPROVE (PENDING_REVIEW -> APPROVED)
    if ($action === 'approve') {
        if ($current_status !== 'PENDING_REVIEW') {
            send_response(['error' => 'Solo se pueden aprobar pedidos en estado "Pendiente de revisar".'], 400);
        }
        try {
            $upd = $db->prepare("UPDATE shop_orders SET status = 'APPROVED', approved_at = ?, updated_at = ? WHERE id = ?");
            $upd->execute([$now, $now, $order_id]);
            send_response(['success' => true, 'status' => 'APPROVED', 'message' => 'Pedido aprobado correctamente.']);
        } catch (Exception $e) {
            send_response(['error' => 'Error al aprobar pedido: ' . $e->getMessage()], 500);
        }
    }

    // 6. UPDATE ITEM PURCHASE STATUS (Checklist item toggle)
    if ($action === 'update_item_purchase_status') {
        $item_id = isset($input['item_id']) ? intval($input['item_id']) : 0;
        $status = isset($input['purchase_status']) ? strtoupper(trim($input['purchase_status'])) : 'PENDING';
        if (!in_array($status, ['PENDING', 'PURCHASED', 'UNAVAILABLE'])) {
            $status = 'PENDING';
        }
        $is_purchased = ($status === 'PURCHASED') ? 1 : 0;

        try {
            $upd = $db->prepare("UPDATE shop_order_items SET purchase_status = ?, is_purchased = ? WHERE id = ? AND order_id = ?");
            $upd->execute([$status, $is_purchased, $item_id, $order_id]);
            send_response(['success' => true, 'item_id' => $item_id, 'purchase_status' => $status]);
        } catch (Exception $e) {
            send_response(['error' => 'Error al actualizar estado de compra de la línea: ' . $e->getMessage()], 500);
        }
    }

    // 7. STATE TRANSITION: MARK PURCHASED (APPROVED -> PURCHASED)
    if ($action === 'mark_purchased') {
        if ($current_status !== 'APPROVED') {
            send_response(['error' => 'Solo se pueden marcar como comprados pedidos en estado "Aprobado".'], 400);
        }
        try {
            $upd = $db->prepare("UPDATE shop_orders SET status = 'PURCHASED', purchased_at = ?, updated_at = ? WHERE id = ?");
            $upd->execute([$now, $now, $order_id]);
            send_response(['success' => true, 'status' => 'PURCHASED', 'message' => 'Compra de supermercado finalizada.']);
        } catch (Exception $e) {
            send_response(['error' => 'Error al finalizar compra: ' . $e->getMessage()], 500);
        }
    }

    // 8. STATE TRANSITION: MARK DELIVERED (PURCHASED -> DELIVERED)
    if ($action === 'mark_delivered') {
        if ($current_status !== 'PURCHASED') {
            send_response(['error' => 'Solo se pueden entregar pedidos en estado "Comprado".'], 400);
        }
        try {
            $upd = $db->prepare("UPDATE shop_orders SET status = 'DELIVERED', delivered_at = ?, updated_at = ? WHERE id = ?");
            $upd->execute([$now, $now, $order_id]);
            send_response(['success' => true, 'status' => 'DELIVERED', 'message' => 'Pedido marcado como entregado en la finca.']);
        } catch (Exception $e) {
            send_response(['error' => 'Error al marcar entrega: ' . $e->getMessage()], 500);
        }
    }

    // 9. STATE TRANSITION: MARK PAID (DELIVERED -> PAID)
    if ($action === 'mark_paid') {
        if ($current_status !== 'DELIVERED' && $current_status !== 'PURCHASED') {
            send_response(['error' => 'Solo se pueden marcar como pagados pedidos comprados o entregados.'], 400);
        }
        try {
            $upd = $db->prepare("UPDATE shop_orders SET status = 'PAID', paid_at = ?, updated_at = ? WHERE id = ?");
            $upd->execute([$now, $now, $order_id]);
            send_response(['success' => true, 'status' => 'PAID', 'message' => 'Pedido marcado como pagado.']);
        } catch (Exception $e) {
            send_response(['error' => 'Error al marcar pago: ' . $e->getMessage()], 500);
        }
    }

    // 10. STATE TRANSITION: CANCEL (PENDING_REVIEW or APPROVED -> CANCELLED)
    if ($action === 'cancel') {
        if ($current_status === 'PAID' || $current_status === 'DELIVERED') {
            send_response(['error' => 'No se puede cancelar un pedido entregado o pagado.'], 400);
        }
        try {
            $upd = $db->prepare("UPDATE shop_orders SET status = 'CANCELLED', cancelled_at = ?, updated_at = ? WHERE id = ?");
            $upd->execute([$now, $now, $order_id]);
            send_response(['success' => true, 'status' => 'CANCELLED', 'message' => 'Pedido cancelado.']);
        } catch (Exception $e) {
            send_response(['error' => 'Error al cancelar pedido: ' . $e->getMessage()], 500);
        }
    }

    send_response(['error' => 'Acción no válida.'], 400);
}

send_response(['error' => 'Método no permitido.'], 405);
