<?php
/**
 * Guest Cart Endpoint for Can Picornell Guest Shop Module
 * Handles cart item additions, quantity updates, item removals, guest notes, and strict server-side price enforcement.
 */

require_once __DIR__ . '/guest_helper.php';

$raw_token = $_REQUEST['t'] ?? '';
$url_lang = $_REQUEST['lang'] ?? null;

$db = get_db_connection();
$context = validate_guest_token($db, $raw_token);
$lang = resolve_language($url_lang, $context['preferred_language']);
$global_margin = get_global_margin($db);

$order = get_or_create_draft_order($db, $context['booking_id'], $context['token_id']);
$order_id = $order['id'];
$is_locked = ($order['status'] !== 'DRAFT');

$method = $_SERVER['REQUEST_METHOD'];

// GET: Read Cart Contents
if ($method === 'GET') {
    try {
        $stmt = $db->prepare("
            SELECT 
                i.id AS item_id,
                i.product_id,
                i.product_name_snapshot,
                i.quantity,
                i.unit_price_cents,
                i.total_price_cents,
                p.image_url,
                t_req.name AS req_name, t_req.format_text AS req_fmt,
                t_es.name AS es_name, t_es.format_text AS es_fmt
            FROM shop_order_items i
            LEFT JOIN shop_products p ON i.product_id = p.id
            LEFT JOIN shop_product_translations t_req ON p.id = t_req.product_id AND t_req.language = :req_lang
            LEFT JOIN shop_product_translations t_es  ON p.id = t_es.product_id  AND t_es.language = 'es'
            WHERE i.order_id = :order_id
            ORDER BY i.id ASC
        ");
        $stmt->execute([':req_lang' => $lang, ':order_id' => $order_id]);
        $items_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        $total_cents = 0;
        foreach ($items_raw as $item) {
            $name = !empty($item['req_name']) ? $item['req_name'] : (!empty($item['es_name']) ? $item['es_name'] : $item['product_name_snapshot']);
            $fmt = !empty($item['req_fmt']) ? $item['req_fmt'] : ($item['es_fmt'] ?? '');
            
            $unit_cents = intval($item['unit_price_cents']);
            $line_total = intval($item['total_price_cents']);
            $total_cents += $line_total;

            $items[] = [
                'item_id' => $item['item_id'],
                'product_id' => $item['product_id'],
                'name' => $name,
                'format' => $fmt,
                'quantity' => intval($item['quantity']),
                'unit_price_cents' => $unit_cents,
                'unit_price_formatted' => number_format($unit_cents / 100, 2, ',', '.') . ' €',
                'total_price_cents' => $line_total,
                'total_price_formatted' => number_format($line_total / 100, 2, ',', '.') . ' €',
                'image_url' => $item['image_url'] ?? ''
            ];
        }

        send_guest_json([
            'success' => true,
            'is_locked' => $is_locked,
            'status' => $order['status'],
            'order_number' => $order['order_number'],
            'guest_notes' => $order['guest_notes'] ?? '',
            'items' => $items,
            'items_count' => count($items),
            'total_cents' => $total_cents,
            'total_formatted' => number_format($total_cents / 100, 2, ',', '.') . ' €'
        ]);
    } catch (Exception $e) {
        error_log("Error reading guest cart: " . $e->getMessage());
        send_guest_json(['error' => 'No se pudo cargar el carrito.'], 500);
    }
}

// POST: Cart Modifications
if ($method === 'POST') {
    if ($is_locked) {
        send_guest_json(['error' => 'La lista de compra ya ha sido enviada a Can Picornell y no se puede modificar.'], 403);
    }

    $raw_input = file_get_contents('php://input');
    $input = json_decode($raw_input, true) ?: $_POST;
    $action = isset($input['action']) ? trim($input['action']) : '';

    // ACTION: ADD OR UPDATE ITEM QUANTITY
    if ($action === 'add' || $action === 'update') {
        $product_id = isset($input['product_id']) ? intval($input['product_id']) : 0;
        $quantity = isset($input['quantity']) ? intval($input['quantity']) : 1;

        if ($product_id <= 0) {
            send_guest_json(['error' => 'Producto no válido.'], 400);
        }

        // Limit quantity bounds between 1 and 99
        if ($action === 'add' && $quantity <= 0) $quantity = 1;
        if ($quantity > 99) $quantity = 99;

        // Fetch product from DB to ensure active & available and calculate price strictly on server
        $p_stmt = $db->prepare("
            SELECT 
                p.id, p.reference_price_cents, p.margin_percent, p.manual_final_price_cents, p.is_active, p.is_available,
                t_req.name AS req_name, t_es.name AS es_name
            FROM shop_products p
            LEFT JOIN shop_product_translations t_req ON p.id = t_req.product_id AND t_req.language = :req_lang
            LEFT JOIN shop_product_translations t_es  ON p.id = t_es.product_id  AND t_es.language = 'es'
            WHERE p.id = :pid AND p.is_active = 1 AND p.is_available = 1
        ");
        $p_stmt->execute([':req_lang' => $lang, ':pid' => $product_id]);
        $product = $p_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            send_guest_json(['error' => 'El producto seleccionado no está disponible en este momento.'], 400);
        }

        $unit_price_cents = calculate_final_price_cents(
            intval($product['reference_price_cents']),
            $product['margin_percent'] !== null ? floatval($product['margin_percent']) : null,
            $product['manual_final_price_cents'] !== null ? intval($product['manual_final_price_cents']) : null,
            $global_margin
        );

        $name = !empty($product['req_name']) ? $product['req_name'] : (!empty($product['es_name']) ? $product['es_name'] : 'Producto');

        try {
            $db->beginTransaction();

            // Check if item already exists in current order
            $chk = $db->prepare("SELECT id, quantity FROM shop_order_items WHERE order_id = ? AND product_id = ?");
            $chk->execute([$order_id, $product_id]);
            $existing_item = $chk->fetch(PDO::FETCH_ASSOC);

            if ($existing_item) {
                $new_qty = ($action === 'add') ? ($existing_item['quantity'] + $quantity) : $quantity;
                if ($new_qty <= 0) {
                    // Remove if 0
                    $del = $db->prepare("DELETE FROM shop_order_items WHERE id = ?");
                    $del->execute([$existing_item['id']]);
                } else {
                    if ($new_qty > 99) $new_qty = 99;
                    $line_total = $unit_price_cents * $new_qty;
                    $upd = $db->prepare("UPDATE shop_order_items SET quantity = ?, unit_price_cents = ?, total_price_cents = ? WHERE id = ?");
                    $upd->execute([$new_qty, $unit_price_cents, $line_total, $existing_item['id']]);
                }
            } else if ($quantity > 0) {
                $line_total = $unit_price_cents * $quantity;
                $ins = $db->prepare("
                    INSERT INTO shop_order_items (order_id, product_id, product_name_snapshot, quantity, unit_price_cents, total_price_cents)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([$order_id, $product_id, $name, $quantity, $unit_price_cents, $line_total]);
            }

            recalculate_order_totals($db, $order_id);
            $db->commit();

            send_guest_json(['success' => true, 'message' => 'Carrito actualizado correctamente.']);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log("Cart item update error: " . $e->getMessage());
            send_guest_json(['error' => 'No se pudo actualizar el carrito.'], 500);
        }
    }

    // ACTION: REMOVE ITEM
    if ($action === 'remove') {
        $product_id = isset($input['product_id']) ? intval($input['product_id']) : 0;
        try {
            $db->beginTransaction();
            $del = $db->prepare("DELETE FROM shop_order_items WHERE order_id = ? AND product_id = ?");
            $del->execute([$order_id, $product_id]);
            recalculate_order_totals($db, $order_id);
            $db->commit();

            send_guest_json(['success' => true, 'message' => 'Producto eliminado del carrito.']);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            send_guest_json(['error' => 'No se pudo eliminar el producto.'], 500);
        }
    }

    // ACTION: UPDATE GUEST NOTES ("¿Necesitas algo que no aparece en la tienda?")
    if ($action === 'update_notes') {
        $notes = isset($input['guest_notes']) ? trim(strip_tags($input['guest_notes'])) : '';
        try {
            $upd = $db->prepare("UPDATE shop_orders SET guest_notes = ?, updated_at = ? WHERE id = ?");
            $upd->execute([$notes, date('Y-m-d H:i:s'), $order_id]);

            send_guest_json(['success' => true, 'message' => 'Notas adicionales guardadas.']);
        } catch (Exception $e) {
            send_guest_json(['error' => 'No se pudieron guardar las notas.'], 500);
        }
    }

    send_guest_json(['error' => 'Acción no válida.'], 400);
}

send_guest_json(['error' => 'Método no permitido.'], 405);
