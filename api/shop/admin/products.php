<?php
/**
 * Admin Products Endpoint for Can Picornell Private Guest Shop Module
 * Handles product CRUD, status toggling, backend price calculations, and multi-language translations.
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

$method = $_SERVER['REQUEST_METHOD'];

// GET: List all products with category info, calculated final price, and translations
if ($method === 'GET') {
    try {
        $global_margin = get_global_margin($db);

        $p_stmt = $db->query("
            SELECT 
                p.id, p.category_id, p.sku, p.brand, p.supplier_name,
                p.reference_price_cents, p.margin_percent, p.manual_final_price_cents,
                p.image_url, p.display_order, p.is_active, p.is_available, p.is_featured,
                p.last_imported_at, p.created_at, p.updated_at,
                c.slug AS category_slug
            FROM shop_products p
            LEFT JOIN shop_categories c ON p.category_id = c.id
            ORDER BY p.display_order ASC, p.id DESC
        ");
        $products = $p_stmt->fetchAll(PDO::FETCH_ASSOC);

        $t_stmt = $db->query("
            SELECT product_id, language, name, description, format_text, additional_information, source_url
            FROM shop_product_translations
        ");
        $translations = $t_stmt->fetchAll(PDO::FETCH_ASSOC);

        $trans_by_prod = [];
        foreach ($translations as $tr) {
            $pid = $tr['product_id'];
            if (!isset($trans_by_prod[$pid])) {
                $trans_by_prod[$pid] = [];
            }
            $trans_by_prod[$pid][$tr['language']] = [
                'name' => $tr['name'],
                'description' => $tr['description'] ?? '',
                'format_text' => $tr['format_text'] ?? '',
                'additional_information' => $tr['additional_information'] ?? '',
                'source_url' => $tr['source_url'] ?? ''
            ];
        }

        foreach ($products as &$p) {
            $ref_cents = intval($p['reference_price_cents']);
            $m_pct = ($p['margin_percent'] !== null) ? floatval($p['margin_percent']) : null;
            $man_cents = ($p['manual_final_price_cents'] !== null) ? intval($p['manual_final_price_cents']) : null;

            $p['calculated_final_price_cents'] = calculate_final_price_cents($ref_cents, $m_pct, $man_cents, $global_margin);
            $p['effective_margin_percent'] = ($m_pct !== null) ? $m_pct : $global_margin;
            $p['translations'] = $trans_by_prod[$p['id']] ?? [];
        }

        send_response([
            'success' => true,
            'global_margin_percent' => $global_margin,
            'products' => $products
        ]);
    } catch (Exception $e) {
        send_response(['error' => 'Error al obtener catálogo de productos: ' . $e->getMessage()], 500);
    }
}

// POST: Actions
if ($method === 'POST') {
    $raw_input = file_get_contents('php://input');
    $input = json_decode($raw_input, true) ?: $_POST;
    $action = isset($input['action']) ? trim($input['action']) : '';

    // CREATE OR UPDATE
    if ($action === 'create' || $action === 'update') {
        $product_id = isset($input['id']) ? intval($input['id']) : 0;
        $category_id = isset($input['category_id']) ? intval($input['category_id']) : 0;
        $sku = isset($input['sku']) ? trim($input['sku']) : null;
        $brand = isset($input['brand']) ? trim($input['brand']) : null;
        $supplier_name = isset($input['supplier_name']) ? trim($input['supplier_name']) : null;

        $reference_price_cents = isset($input['reference_price_cents']) ? intval($input['reference_price_cents']) : 0;
        if ($reference_price_cents < 0) {
            send_response(['error' => 'El precio de referencia no puede ser negativo.'], 400);
        }

        $margin_percent = (isset($input['margin_percent']) && $input['margin_percent'] !== '' && $input['margin_percent'] !== null) ? floatval($input['margin_percent']) : null;
        $manual_final_price_cents = (isset($input['manual_final_price_cents']) && $input['manual_final_price_cents'] !== '' && $input['manual_final_price_cents'] !== null) ? intval($input['manual_final_price_cents']) : null;

        $image_url = isset($input['image_url']) ? trim($input['image_url']) : null;
        $display_order = isset($input['display_order']) ? intval($input['display_order']) : 0;
        $is_active = isset($input['is_active']) ? intval($input['is_active']) : 1;
        $is_available = isset($input['is_available']) ? intval($input['is_available']) : 1;
        $is_featured = isset($input['is_featured']) ? intval($input['is_featured']) : 0;

        $translations = isset($input['translations']) && is_array($input['translations']) ? $input['translations'] : [];

        if ($category_id <= 0) {
            send_response(['error' => 'Debe seleccionar una categoría válida.'], 400);
        }

        // Validate Category Exists
        $c_chk = $db->prepare("SELECT id FROM shop_categories WHERE id = ?");
        $c_chk->execute([$category_id]);
        if (!$c_chk->fetch()) {
            send_response(['error' => 'La categoría seleccionada no existe.'], 400);
        }

        // Validate Spanish Name
        $es_name = isset($translations['es']['name']) ? trim($translations['es']['name']) : '';
        if (empty($es_name)) {
            send_response(['error' => 'El nombre del producto en castellano es obligatorio.'], 400);
        }

        $now = date('Y-m-d H:i:s');

        try {
            $db->beginTransaction();

            if ($action === 'create') {
                $ins = $db->prepare("
                    INSERT INTO shop_products (
                        category_id, sku, brand, supplier_name, reference_price_cents,
                        margin_percent, manual_final_price_cents, image_url, display_order,
                        is_active, is_available, is_featured, created_at, updated_at
                    ) VALUES (
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, ?, ?
                    )
                ");
                $ins->execute([
                    $category_id, $sku, $brand, $supplier_name, $reference_price_cents,
                    $margin_percent, $manual_final_price_cents, $image_url, $display_order,
                    $is_active, $is_available, $is_featured, $now, $now
                ]);
                $product_id = $db->lastInsertId();
            } else {
                $upd = $db->prepare("
                    UPDATE shop_products SET
                        category_id = ?, sku = ?, brand = ?, supplier_name = ?, reference_price_cents = ?,
                        margin_percent = ?, manual_final_price_cents = ?, image_url = ?, display_order = ?,
                        is_active = ?, is_available = ?, is_featured = ?, updated_at = ?
                    WHERE id = ?
                ");
                $upd->execute([
                    $category_id, $sku, $brand, $supplier_name, $reference_price_cents,
                    $margin_percent, $manual_final_price_cents, $image_url, $display_order,
                    $is_active, $is_available, $is_featured, $now,
                    $product_id
                ]);
            }

            // Save translations (ES, EN, DE)
            foreach (['es', 'en', 'de'] as $lang) {
                if (isset($translations[$lang]) && !empty(trim($translations[$lang]['name'] ?? ''))) {
                    $t_name = trim($translations[$lang]['name']);
                    $t_desc = trim($translations[$lang]['description'] ?? '');
                    $t_fmt = trim($translations[$lang]['format_text'] ?? '');
                    $t_add = trim($translations[$lang]['additional_information'] ?? '');
                    $t_src = trim($translations[$lang]['source_url'] ?? '');

                    $t_upd = $db->prepare("
                        UPDATE shop_product_translations SET
                            name = ?, description = ?, format_text = ?, additional_information = ?, source_url = ?
                        WHERE product_id = ? AND language = ?
                    ");
                    $t_upd->execute([$t_name, $t_desc, $t_fmt, $t_add, $t_src, $product_id, $lang]);
                    if ($t_upd->rowCount() === 0) {
                        $t_ins = $db->prepare("
                            INSERT INTO shop_product_translations (
                                product_id, language, name, description, format_text, additional_information, source_url
                            ) VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        $t_ins->execute([$product_id, $lang, $t_name, $t_desc, $t_fmt, $t_add, $t_src]);
                    }
                }
            }

            $db->commit();
            send_response(['success' => true, 'product_id' => $product_id, 'message' => 'Producto guardado correctamente.']);
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            send_response(['error' => 'Error al guardar producto: ' . $e->getMessage()], 500);
        }
    }

    // TOGGLE FLAGS
    if (in_array($action, ['toggle_active', 'toggle_available', 'toggle_featured'])) {
        $pid = isset($input['id']) ? intval($input['id']) : 0;
        if ($pid <= 0) send_response(['error' => 'ID no válido.'], 400);

        $col = ($action === 'toggle_active') ? 'is_active' : (($action === 'toggle_available') ? 'is_available' : 'is_featured');
        $p_stmt = $db->prepare("SELECT {$col} FROM shop_products WHERE id = ?");
        $p_stmt->execute([$pid]);
        $val = $p_stmt->fetchColumn();
        if ($val === false) send_response(['error' => 'Producto no encontrado.'], 404);

        $new_val = ($val == 1) ? 0 : 1;
        $db->prepare("UPDATE shop_products SET {$col} = ?, updated_at = ? WHERE id = ?")->execute([$new_val, date('Y-m-d H:i:s'), $pid]);
        send_response(['success' => true, 'field' => $col, 'new_value' => $new_val, 'message' => 'Estado actualizado.']);
    }

    // DELETE (Safely prevent if referenced in order items)
    if ($action === 'delete') {
        $pid = isset($input['id']) ? intval($input['id']) : 0;
        if ($pid <= 0) send_response(['error' => 'ID no válido.'], 400);

        $o_chk = $db->prepare("SELECT COUNT(*) FROM shop_order_items WHERE product_id = ?");
        $o_chk->execute([$pid]);
        if (intval($o_chk->fetchColumn()) > 0) {
            send_response(['error' => 'No se puede eliminar físicamente este producto porque pertenece al historial de algún pedido. Desactívelo en su lugar.'], 400);
        }

        $db->prepare("DELETE FROM shop_products WHERE id = ?")->execute([$pid]);
        send_response(['success' => true, 'message' => 'Producto eliminado correctamente.']);
    }

    send_response(['error' => 'Acción no válida.'], 400);
}

send_response(['error' => 'Método no permitido.'], 405);
