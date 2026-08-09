<?php
/**
 * Import API Endpoint for Can Picornell Product Importer
 * Handles URL analysis, SSRF checks, duplicate detection, preview generation,
 * and saving new products or adding translations to existing products.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/ElCorteInglesImporter.php';

function send_response($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['admin_authenticated']) || $_SESSION['admin_authenticated'] !== true) {
    send_response(['error' => 'Acceso no autorizado. Debe iniciar sesión como administrador.'], 401);
}

$db = get_db_connection();
$raw_input = file_get_contents('php://input');
$input = json_decode($raw_input, true) ?: $_POST;
$action = isset($input['action']) ? trim($input['action']) : '';

// 1. ACTION: ANALYZE URL
if ($action === 'analyze') {
    $url = isset($input['url']) ? trim($input['url']) : '';
    $supplier = isset($input['supplier']) ? trim($input['supplier']) : 'El Corte Inglés';
    $target_lang = isset($input['language']) ? strtolower(trim($input['language'])) : 'es';
    if (!in_array($target_lang, ['es', 'en', 'de'])) {
        $target_lang = 'es';
    }

    if (empty($url)) {
        send_response(['error' => 'Debe proporcionar una URL válida de producto.'], 400);
    }

    try {
        // Instantiate appropriate importer based on supplier
        if ($supplier !== 'El Corte Inglés') {
            send_response(['error' => 'Proveedor no soportado actualmente.'], 400);
        }

        $importer = new ElCorteInglesImporter();
        $parsed = $importer->parseUrl($url, $target_lang);

        // Check for duplicates in DB
        $duplicateWarning = null;
        $existingProduct = null;

        // Check 1: Same supplier_name + supplier_product_id
        if (!empty($parsed['supplier_product_id'])) {
            $stmt = $db->prepare("SELECT id, brand, reference_price_cents, image_url FROM shop_products WHERE supplier_name = ? AND supplier_product_id = ?");
            $stmt->execute([$parsed['supplier_name'], $parsed['supplier_product_id']]);
            $existingProduct = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Check 2: Same GTIN
        if (!$existingProduct && !empty($parsed['gtin'])) {
            $stmt = $db->prepare("SELECT id, brand, reference_price_cents, image_url FROM shop_products WHERE gtin = ?");
            $stmt->execute([$parsed['gtin']]);
            $existingProduct = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Check 3: Same source_url
        if (!$existingProduct) {
            $stmt = $db->prepare("SELECT product_id FROM shop_product_translations WHERE source_url = ?");
            $stmt->execute([$parsed['source_url']]);
            $pid = $stmt->fetchColumn();
            if ($pid) {
                $stmt2 = $db->prepare("SELECT id, brand, reference_price_cents, image_url FROM shop_products WHERE id = ?");
                $stmt2->execute([$pid]);
                $existingProduct = $stmt2->fetch(PDO::FETCH_ASSOC);
            }
        }

        if ($existingProduct) {
            // Get Spanish name of existing product
            $t_stmt = $db->prepare("SELECT name FROM shop_product_translations WHERE product_id = ? AND language = 'es'");
            $t_stmt->execute([$existingProduct['id']]);
            $exName = $t_stmt->fetchColumn() ?: 'Producto #' . $existingProduct['id'];

            $duplicateWarning = [
                'existing_product_id' => $existingProduct['id'],
                'name' => $exName,
                'current_reference_price_cents' => $existingProduct['reference_price_cents'],
                'current_price_formatted' => number_format($existingProduct['reference_price_cents'] / 100, 2, ',', '.') . ' €',
                'current_image_url' => $existingProduct['image_url'],
                'message' => "Se ha detectado un producto existente con el mismo identificador o código GTIN: '{$exName}' (ID #{$existingProduct['id']})."
            ];
        }

        // Global Margin
        $global_margin = 10.0;
        $m_stmt = $db->query("SELECT setting_value FROM shop_settings WHERE setting_key = 'global_margin_percent'");
        $m_val = $m_stmt ? $m_stmt->fetchColumn() : null;
        if ($m_val !== false) $global_margin = floatval($m_val);

        $parsed['global_margin_percent'] = $global_margin;
        $parsed['calculated_final_price_cents'] = intval(round($parsed['reference_price_cents'] * (1.0 + ($global_margin / 100.0))));
        $parsed['calculated_final_price_formatted'] = number_format($parsed['calculated_final_price_cents'] / 100, 2, ',', '.') . ' €';

        send_response([
            'success' => true,
            'parsed' => $parsed,
            'duplicate_warning' => $duplicateWarning
        ]);
    } catch (Exception $e) {
        send_response(['error' => $e->getMessage()], 400);
    }
}

// 2. ACTION: SAVE IMPORTED PRODUCT
if ($action === 'save') {
    $mode = isset($input['mode']) ? trim($input['mode']) : 'new_product'; // 'new_product' | 'add_translation'
    $existing_product_id = isset($input['existing_product_id']) ? intval($input['existing_product_id']) : 0;
    
    $category_id = isset($input['category_id']) ? intval($input['category_id']) : 0;
    $brand = isset($input['brand']) ? trim($input['brand']) : null;
    $supplier_name = isset($input['supplier_name']) ? trim($input['supplier_name']) : 'El Corte Inglés';
    $supplier_product_id = isset($input['supplier_product_id']) ? trim($input['supplier_product_id']) : null;
    $gtin = isset($input['gtin']) ? trim($input['gtin']) : null;

    $reference_price_cents = isset($input['reference_price_cents']) ? intval($input['reference_price_cents']) : 0;
    $margin_percent = (isset($input['margin_percent']) && $input['margin_percent'] !== '') ? floatval($input['margin_percent']) : null;
    $manual_final_price_cents = (isset($input['manual_final_price_cents']) && $input['manual_final_price_cents'] !== '') ? intval($input['manual_final_price_cents']) : null;

    $image_url = isset($input['image_url']) ? trim($input['image_url']) : null;
    $source_url = isset($input['source_url']) ? trim($input['source_url']) : null;
    $language = isset($input['language']) ? strtolower(trim($input['language'])) : 'es';

    $name = isset($input['name']) ? trim($input['name']) : '';
    $description = isset($input['description']) ? trim($input['description']) : '';
    $format_text = isset($input['format_text']) ? trim($input['format_text']) : '';

    if (empty($name)) {
        send_response(['error' => 'El nombre del producto es obligatorio.'], 400);
    }

    if ($reference_price_cents < 0) {
        send_response(['error' => 'El precio de referencia no puede ser negativo.'], 400);
    }

    $now = date('Y-m-d H:i:s');

    try {
        $db->beginTransaction();

        if ($mode === 'new_product') {
            if ($category_id <= 0) {
                send_response(['error'] = 'Debe seleccionar una categoría para el nuevo producto.', 400);
            }

            $ins = $db->prepare("
                INSERT INTO shop_products (
                    category_id, brand, supplier_name, supplier_product_id, gtin,
                    reference_price_cents, margin_percent, manual_final_price_cents,
                    image_url, display_order, is_active, is_available, is_featured, last_imported_at, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, 0, 1, 1, 0, ?, ?, ?
                )
            ");
            $ins->execute([
                $category_id, $brand, $supplier_name, $supplier_product_id, $gtin,
                $reference_price_cents, $margin_percent, $manual_final_price_cents,
                $image_url, $now, $now, $now
            ]);
            $product_id = $db->lastInsertId();
        } else {
            // Mode: add_translation to existing_product_id
            if ($existing_product_id <= 0) {
                send_response(['error' => 'ID de producto existente no válido.'], 400);
            }
            $product_id = $existing_product_id;

            // Optionally update reference_price_cents or image_url if user explicitly confirmed in UI
            $update_price = !empty($input['update_price_override']);
            $update_image = !empty($input['update_image_override']);

            if ($update_price || $update_image) {
                $sqlUpd = "UPDATE shop_products SET updated_at = :now, last_imported_at = :now";
                $paramsUpd = [':now' => $now, ':pid' => $product_id];

                if ($update_price) {
                    $sqlUpd .= ", reference_price_cents = :ref_c";
                    $paramsUpd[':ref_c'] = $reference_price_cents;
                }
                if ($update_image) {
                    $sqlUpd .= ", image_url = :img";
                    $paramsUpd[':img'] = $image_url;
                }
                $sqlUpd .= " WHERE id = :pid";

                $updP = $db->prepare($sqlUpd);
                $updP->execute($paramsUpd);
            }
        }

        // Save Translation
        try {
            $tr_stmt = $db->prepare("
                INSERT INTO shop_product_translations (
                    product_id, language, name, description, format_text, source_url
                ) VALUES (?, ?, ?, ?, ?, ?)
                ON CONFLICT(product_id, language) DO UPDATE SET
                    name = EXCLUDED.name,
                    description = EXCLUDED.description,
                    format_text = EXCLUDED.format_text,
                    source_url = EXCLUDED.source_url
            ");
            $tr_stmt->execute([$product_id, $language, $name, $description, $format_text, $source_url]);
        } catch (Exception $ex) {
            // MySQL fallback
            $tr_stmt = $db->prepare("
                INSERT INTO shop_product_translations (
                    product_id, language, name, description, format_text, source_url
                ) VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    description = VALUES(description),
                    format_text = VALUES(format_text),
                    source_url = VALUES(source_url)
            ");
            $tr_stmt->execute([$product_id, $language, $name, $description, $format_text, $source_url]);
        }

        $db->commit();

        send_response([
            'success' => true,
            'product_id' => $product_id,
            'message' => ($mode === 'new_product') ? 'Nuevo producto importado y guardado correctamente.' : "Traducción '{$language}' añadida correctamente al producto #{$product_id}."
        ]);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        send_response(['error' => 'Error al guardar producto importado: ' . $e->getMessage()], 500);
    }
}

send_response(['error' => 'Acción no válida.'], 400);
