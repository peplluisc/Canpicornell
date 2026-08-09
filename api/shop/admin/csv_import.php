<?php
/**
 * CSV Product Importer API for Can Picornell Guest Shop
 * Implements Product Importer 3.0.2 specification:
 * - Product Code format: ^B\d{15}$
 * - Image auto-resolution: /images/products/{product_code}.jpg
 * - Integer cents handling (price_cents)
 * - Multi-language translation upsert (ES, EN, DE) with fallback
 * - Category & Subcategory normalization
 * - Granular row-level error reporting
 */

header('Content-Type: application/json; charset=utf-8');

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
    send_response(['error' => 'Acceso no autorizado. Debe iniciar sesión como administrador.'], 401);
}

$db = get_db_connection();

// Ensure upload & images directories exist
$imgDir1 = __DIR__ . '/../../../images/products';
$imgDir2 = __DIR__ . '/../../../uploads/products';
if (!is_dir($imgDir1)) mkdir($imgDir1, 0755, true);
if (!is_dir($imgDir2)) mkdir($imgDir2, 0755, true);

$action = $_REQUEST['action'] ?? '';

function slugify(string $text): string {
    $text = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text) ?: strtolower($text);
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = trim($text, '-');
    return $text ?: 'cat-' . substr(md5(uniqid()), 0, 6);
}

function find_matching_product_image(string $productCode): ?string {
    $imgDir1 = __DIR__ . '/../../../images/products';
    $imgDir2 = __DIR__ . '/../../../uploads/products';

    $extensions = ['jpg', 'jpeg', 'png', 'webp'];
    foreach ($extensions as $ext) {
        if (file_exists($imgDir1 . '/' . $productCode . '.' . $ext)) {
            return '/images/products/' . $productCode . '.' . $ext;
        }
        if (file_exists($imgDir2 . '/' . $productCode . '.' . $ext)) {
            return 'uploads/products/' . $productCode . '.' . $ext;
        }
    }
    return null;
}

function parse_csv_data(string $content): array {
    // Strip UTF-8 BOM if present
    $content = preg_replace('/^[\x{FEFF}\x{FFFE}]/u', '', $content);

    // Detect delimiter (; or ,)
    $firstLine = strtok($content, "\r\n");
    $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $content);
    rewind($stream);

    $header = fgetcsv($stream, 0, $delimiter);
    if (!$header) {
        throw new Exception("El archivo CSV no contiene un encabezado válido.");
    }

    $header = array_map(function($h) { return trim(strtolower($h)); }, $header);

    $rows = [];
    $lineNumber = 1;
    while (($data = fgetcsv($stream, 0, $delimiter)) !== false) {
        $lineNumber++;
        if (count($data) < 2) continue; // Skip empty lines
        $row = ['_line_number' => $lineNumber];
        foreach ($header as $idx => $colName) {
            $row[$colName] = isset($data[$idx]) ? trim($data[$idx]) : '';
        }
        $rows[] = $row;
    }
    fclose($stream);
    return $rows;
}

// ------------------------------------------------------------------
// 1. ACTION: UPLOAD IMAGES
// ------------------------------------------------------------------
if ($action === 'upload_images') {
    if (empty($_FILES['images'])) {
        send_response(['error' => 'No se recibieron archivos de imagen.'], 400);
    }

    $uploaded = 0;
    $linked = 0;
    $errors = [];

    $files = $_FILES['images'];
    $fileCount = is_array($files['name']) ? count($files['name']) : 1;

    for ($i = 0; $i < $fileCount; $i++) {
        $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
        $tmpName = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
        $error = is_array($files['error']) ? $files['error'][$i] : $files['error'];

        if ($error !== UPLOAD_ERR_OK) continue;

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            $errors[] = "Formato no permitido para el archivo {$name}";
            continue;
        }

        $destFile = $imgDir1 . '/' . $name;
        if (move_uploaded_file($tmpName, $destFile)) {
            $uploaded++;
            $productCode = pathinfo($name, PATHINFO_FILENAME);
            $relPath = '/images/products/' . $name;

            // Auto link to existing product with matching SKU/Code
            $upd = $db->prepare("UPDATE shop_products SET image_url = ?, updated_at = CURRENT_TIMESTAMP WHERE sku = ? OR supplier_product_id = ?");
            $upd->execute([$relPath, $productCode, $productCode]);
            if ($upd->rowCount() > 0) {
                $linked += $upd->rowCount();
            }
        }
    }

    send_response([
        'success' => true,
        'uploaded_count' => $uploaded,
        'linked_count' => $linked,
        'errors' => $errors
    ]);
}

// Read CSV Content from uploaded file or raw input
$csvContent = '';
if (!empty($_FILES['csv_file']['tmp_name'])) {
    $csvContent = file_get_contents($_FILES['csv_file']['tmp_name']);
} else {
    $rawInput = file_get_contents('php://input');
    $jsonData = json_decode($rawInput, true);
    if (!empty($jsonData['csv_text'])) {
        $csvContent = $jsonData['csv_text'];
    }
}

if (empty($csvContent) && $action !== 'upload_images') {
    send_response(['error' => 'Debe adjuntar o proporcionar un archivo CSV con datos.'], 400);
}

// ------------------------------------------------------------------
// 2. ACTION: PREVIEW CSV
// ------------------------------------------------------------------
if ($action === 'preview') {
    try {
        $rows = parse_csv_data($csvContent);
        
        $categoriesMap = [];
        $subcategoriesMap = [];
        $existingSkus = [];
        $matchedImages = 0;
        $invalidRows = [];

        $skuStmt = $db->query("SELECT sku FROM shop_products WHERE sku IS NOT NULL");
        while ($skuRow = $skuStmt->fetch(PDO::FETCH_ASSOC)) {
            $existingSkus[$skuRow['sku']] = true;
        }

        $previewRows = [];
        foreach ($rows as $r) {
            $lineNum = $r['_line_number'];
            $code = $r['product_code'] ?? '';
            $cat = $r['category'] ?? '';
            $subcat = $r['subcategory'] ?? '';

            // Validate product_code: ^B\d{15}$
            if (empty($code) || !preg_match('/^B\d{15}$/', $code)) {
                $invalidRows[] = [
                    'line' => $lineNum,
                    'product_code' => $code ?: '-',
                    'reason' => "product_code '" . ($code ?: 'VACÍO') . "' no cumple el formato ^B\\d{15}$ (Ej. B001018839900216)."
                ];
                continue;
            }

            if (!empty($cat)) $categoriesMap[$cat] = true;
            if (!empty($subcat)) $subcategoriesMap[$cat . ' > ' . $subcat] = true;

            $hasImage = find_matching_product_image($code) !== null;
            if ($hasImage) $matchedImages++;

            $isNew = !isset($existingSkus[$code]);

            if (count($previewRows) < 50) {
                $previewRows[] = [
                    'line' => $lineNum,
                    'product_code' => $code,
                    'category' => $cat,
                    'subcategory' => $subcat,
                    'brand' => $r['brand'] ?? '',
                    'format' => $r['format'] ?? '',
                    'price_cents' => intval($r['price_cents'] ?? 0),
                    'priority' => $r['priority'] ?? 'A',
                    'name_es' => $r['name_es'] ?? '',
                    'name_en' => $r['name_en'] ?? '',
                    'name_de' => $r['name_de'] ?? '',
                    'is_new' => $isNew,
                    'has_image' => $hasImage
                ];
            }
        }

        send_response([
            'success' => true,
            'total_rows' => count($rows),
            'valid_rows_count' => count($rows) - count($invalidRows),
            'invalid_rows_count' => count($invalidRows),
            'categories_count' => count($categoriesMap),
            'subcategories_count' => count($subcategoriesMap),
            'matched_images' => $matchedImages,
            'errors' => $invalidRows,
            'preview_samples' => $previewRows
        ]);
    } catch (Exception $e) {
        send_response(['error' => $e->getMessage()], 400);
    }
}

// ------------------------------------------------------------------
// 3. ACTION: EXECUTE IMPORT (IMPORTER 3.0.2 SPECIFICATION)
// ------------------------------------------------------------------
if ($action === 'execute') {
    try {
        $rows = parse_csv_data($csvContent);
        $now = date('Y-m-d H:i:s');

        $db->beginTransaction();

        $catCache = []; // slug -> id
        $subCatCache = []; // parent_id + sub_slug -> id

        $filasLeidas = count($rows);
        $productosNuevos = 0;
        $productosActualizados = 0;
        $imagenesEncontradas = 0;
        $imagenesNoEncontradas = 0;
        $createdCategories = 0;
        $createdSubcategories = 0;

        $detallesError = [];

        foreach ($rows as $r) {
            $lineNum = $r['_line_number'];
            $code = trim($r['product_code'] ?? '');

            // Rule 11. Validation 1: product_code format (^B\d{15}$)
            if (empty($code)) {
                $detallesError[] = ['fila' => $lineNum, 'product_code' => '-', 'motivo' => 'El campo product_code está vacío.'];
                continue;
            }
            if (!preg_match('/^B\d{15}$/', $code)) {
                $detallesError[] = ['fila' => $lineNum, 'product_code' => $code, 'motivo' => "El product_code '{$code}' no cumple el formato B + 15 dígitos (^B\\d{15}$)."];
                continue;
            }

            // Rule 11. Validation 2: price_cents must be integer if provided
            $priceRaw = trim($r['price_cents'] ?? '');
            $priceCents = 0;
            if ($priceRaw !== '') {
                if (!is_numeric($priceRaw) || strpos($priceRaw, '.') !== false || strpos($priceRaw, ',') !== false) {
                    $detallesError[] = ['fila' => $lineNum, 'product_code' => $code, 'motivo' => "price_cents '{$priceRaw}' no es un número entero válido."];
                    continue;
                }
                $priceCents = intval($priceRaw);
            }

            // Rule 11. Validation 3: active must be 0 or 1
            $activeRaw = trim($r['active'] ?? '1');
            if (!in_array($activeRaw, ['0', '1', 0, 1], true)) {
                $detallesError[] = ['fila' => $lineNum, 'product_code' => $code, 'motivo' => "El campo active '{$activeRaw}' no puede interpretarse como 0 o 1."];
                continue;
            }
            $isActive = intval($activeRaw);

            // Rule 11. Validation 4: name_es missing check
            $nameEs = trim($r['name_es'] ?? '');
            if (empty($nameEs)) {
                $detallesError[] = ['fila' => $lineNum, 'product_code' => $code, 'motivo' => 'Falta name_es en la fila (nombre en español obligatorio).'];
                continue;
            }

            $catName = trim($r['category'] ?? 'General');
            if (empty($catName)) $catName = 'General';
            $subCatName = trim($r['subcategory'] ?? '');

            // A. Process Main Category
            $catSlug = slugify($catName);
            if (!isset($catCache[$catSlug])) {
                $cStmt = $db->prepare("SELECT id FROM shop_categories WHERE slug = ? AND parent_id IS NULL");
                $cStmt->execute([$catSlug]);
                $catId = $cStmt->fetchColumn();

                if (!$catId) {
                    $insC = $db->prepare("INSERT INTO shop_categories (parent_id, slug, display_order, is_active, created_at) VALUES (NULL, ?, 10, 1, ?)");
                    $insC->execute([$catSlug, $now]);
                    $catId = $db->lastInsertId();
                    $createdCategories++;

                    // Translation ES
                    $insCT = $db->prepare("INSERT INTO shop_category_translations (category_id, language, name) VALUES (?, 'es', ?)");
                    $insCT->execute([$catId, $catName]);
                }
                $catCache[$catSlug] = $catId;
            }
            $mainCatId = $catCache[$catSlug];
            $targetCatId = $mainCatId;

            // B. Process Subcategory if present
            if (!empty($subCatName)) {
                $subSlug = slugify($catName . '-' . $subCatName);
                $singleSubSlug = slugify($subCatName);
                $subKey = $mainCatId . '_' . $subSlug;

                if (!isset($subCatCache[$subKey])) {
                    $scStmt = $db->prepare("
                        SELECT c.id, c.parent_id 
                        FROM shop_categories c 
                        LEFT JOIN shop_category_translations t ON c.id = t.category_id 
                        WHERE (c.slug = ? OR c.slug = ? OR LOWER(t.name) = LOWER(?))
                        LIMIT 1
                    ");
                    $scStmt->execute([$subSlug, $singleSubSlug, $subCatName]);
                    $subRow = $scStmt->fetch(PDO::FETCH_ASSOC);

                    if ($subRow) {
                        $subId = $subRow['id'];
                        if (empty($subRow['parent_id']) || $subRow['parent_id'] != $mainCatId) {
                            $updSC = $db->prepare("UPDATE shop_categories SET parent_id = ? WHERE id = ?");
                            $updSC->execute([$mainCatId, $subId]);
                        }
                    } else {
                        $insSC = $db->prepare("INSERT INTO shop_categories (parent_id, slug, display_order, is_active, created_at) VALUES (?, ?, 10, 1, ?)");
                        $insSC->execute([$mainCatId, $subSlug, $now]);
                        $subId = $db->lastInsertId();
                        $createdSubcategories++;

                        // Translation ES
                        $insSCT = $db->prepare("INSERT INTO shop_category_translations (category_id, language, name) VALUES (?, 'es', ?)");
                        $insSCT->execute([$subId, $subCatName]);
                    }
                    $subCatCache[$subKey] = $subId;
                }
                $targetCatId = $subCatCache[$subKey];
            }

            // C. Rule 2: Auto-detect image at /images/products/{product_code}.jpg
            $relImage = find_matching_product_image($code);
            if ($relImage) {
                $imagenesEncontradas++;
            } else {
                $imagenesNoEncontradas++;
            }

            $dispOrder = intval($r['display_order'] ?? 10);
            $brand = trim($r['brand'] ?? '');
            $currency = trim($r['currency'] ?? 'EUR');
            if (empty($currency)) $currency = 'EUR';
            $priority = trim($r['priority'] ?? 'A');
            if (empty($priority)) $priority = 'A';

            // D. Upsert Product in shop_products
            $pStmt = $db->prepare("SELECT id, image_url FROM shop_products WHERE sku = ? OR supplier_product_id = ?");
            $pStmt->execute([$code, $code]);
            $prod = $pStmt->fetch(PDO::FETCH_ASSOC);

            if ($prod) {
                $prodId = $prod['id'];
                $finalImage = $relImage ? $relImage : $prod['image_url'];

                $uP = $db->prepare("
                    UPDATE shop_products SET 
                        category_id = ?, supplier_product_id = ?, brand = ?, reference_price_cents = ?, 
                        currency = ?, priority = ?, image_url = ?, display_order = ?, 
                        is_active = ?, updated_at = ?
                    WHERE id = ?
                ");
                $uP->execute([$targetCatId, $code, $brand, $priceCents, $currency, $priority, $finalImage, $dispOrder, $isActive, $now, $prodId]);
                $productosActualizados++;
            } else {
                $inP = $db->prepare("
                    INSERT INTO shop_products (
                        category_id, sku, supplier_product_id, brand, reference_price_cents, 
                        currency, priority, image_url, display_order, is_active, is_available, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
                ");
                $inP->execute([$targetCatId, $code, $code, $brand, $priceCents, $currency, $priority, $relImage, $dispOrder, $isActive, $now, $now]);
                $prodId = $db->lastInsertId();
                $productosNuevos++;
            }

            // E. Rule 4: Upsert Translations for ES, EN, DE
            $fmt = trim($r['format'] ?? '');
            $langsMap = [
                'es' => ['name' => $nameEs, 'desc' => trim($r['description_es'] ?? '')],
                'en' => ['name' => trim($r['name_en'] ?? ''), 'desc' => trim($r['description_en'] ?? '')],
                'de' => ['name' => trim($r['name_de'] ?? ''), 'desc' => trim($r['description_de'] ?? '')]
            ];

            foreach ($langsMap as $langCode => $tData) {
                $tName = trim($tData['name']);
                $tDesc = trim($tData['desc']);
                if (empty($tName) && $langCode !== 'es') continue;

                $tUpd = $db->prepare("
                    UPDATE shop_product_translations SET 
                        name = ?, description = ?, format_text = ? 
                    WHERE product_id = ? AND language = ?
                ");
                $tUpd->execute([$tName, $tDesc, $fmt, $prodId, $langCode]);

                if ($tUpd->rowCount() === 0) {
                    $tIns = $db->prepare("
                        INSERT INTO shop_product_translations (
                            product_id, language, name, description, format_text
                        ) VALUES (?, ?, ?, ?, ?)
                    ");
                    $tIns->execute([$prodId, $langCode, $tName, $tDesc, $fmt]);
                }
            }
        }

        $db->commit();

        send_response([
            'success' => true,
            'message' => "Importación de catálogo finalizada con éxito.",
            'filas_leidas' => $filasLeidas,
            'productos_nuevos' => $productosNuevos,
            'productos_actualizados' => $productosActualizados,
            'filas_error' => count($detallesError),
            'detalles_error' => $detallesError,
            'imagenes_encontradas' => $imagenesEncontradas,
            'imagenes_no_encontradas' => $imagenesNoEncontradas,
            'created_categories' => $createdCategories,
            'created_subcategories' => $createdSubcategories
        ]);

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        send_response(['error' => 'Error durante la importación CSV: ' . $e->getMessage()], 500);
    }
}

send_response(['error' => 'Acción no válida.'], 400);
