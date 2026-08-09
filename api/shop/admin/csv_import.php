<?php
/**
 * CSV Product Importer API for Can Picornell Guest Shop
 * Handles parsing, validation, category/subcategory normalization,
 * image linking outside git, multi-language translation upserting, and batch image uploads.
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

// Ensure upload directory exists
$uploadDir = __DIR__ . '/../../../uploads/products';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$action = $_REQUEST['action'] ?? '';

function slugify(string $text): string {
    $text = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text) ?: strtolower($text);
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = trim($text, '-');
    return $text ?: 'cat-' . substr(md5(uniqid()), 0, 6);
}

function find_matching_image(string $productCode, string $uploadDir): ?string {
    $extensions = ['jpg', 'jpeg', 'png', 'webp'];
    foreach ($extensions as $ext) {
        $filename = $productCode . '.' . $ext;
        if (file_exists($uploadDir . '/' . $filename)) {
            return 'uploads/products/' . $filename;
        }
    }
    return null;
}

function parse_csv_data(string $content): array {
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

    // Clean BOM & trim header column names
    $header[0] = preg_replace('/[\x{FEFF}\x{FFFE}]/u', '', $header[0]);
    $header = array_map(function($h) { return trim(strtolower($h)); }, $header);

    $rows = [];
    $lineNumber = 1;
    while (($data = fgetcsv($stream, 0, $delimiter)) !== false) {
        $lineNumber++;
        if (count($data) < 2) continue; // Skip empty lines
        $row = [];
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

        $destFile = $uploadDir . '/' . $name;
        if (move_uploaded_file($tmpName, $destFile)) {
            $uploaded++;
            $productCode = pathinfo($name, PATHINFO_FILENAME);
            $relPath = 'uploads/products/' . $name;

            // Auto link to existing product with matching SKU/Code
            $upd = $db->prepare("UPDATE shop_products SET image_url = ?, updated_at = CURRENT_TIMESTAMP WHERE sku = ?");
            $upd->execute([$relPath, $productCode]);
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

        $skuStmt = $db->query("SELECT sku FROM shop_products WHERE sku IS NOT NULL");
        while ($skuRow = $skuStmt->fetch(PDO::FETCH_ASSOC)) {
            $existingSkus[$skuRow['sku']] = true;
        }

        $previewRows = [];
        foreach ($rows as $idx => $r) {
            $code = $r['product_code'] ?? '';
            $cat = $r['category'] ?? '';
            $subcat = $r['subcategory'] ?? '';

            if (!empty($cat)) $categoriesMap[$cat] = true;
            if (!empty($subcat)) $subcategoriesMap[$cat . ' > ' . $subcat] = true;

            $hasImage = find_matching_image($code, $uploadDir) !== null;
            if ($hasImage) $matchedImages++;

            $isNew = !isset($existingSkus[$code]);

            if ($idx < 50) {
                $previewRows[] = [
                    'line' => $idx + 2,
                    'product_code' => $code,
                    'category' => $cat,
                    'subcategory' => $subcat,
                    'brand' => $r['brand'] ?? '',
                    'format' => $r['format'] ?? '',
                    'price_cents' => intval($r['price_cents'] ?? 0),
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
            'categories_count' => count($categoriesMap),
            'subcategories_count' => count($subcategoriesMap),
            'matched_images' => $matchedImages,
            'preview_samples' => $previewRows
        ]);
    } catch (Exception $e) {
        send_response(['error' => $e->getMessage()], 400);
    }
}

// ------------------------------------------------------------------
// 3. ACTION: EXECUTE IMPORT
// ------------------------------------------------------------------
if ($action === 'execute') {
    try {
        $rows = parse_csv_data($csvContent);
        $now = date('Y-m-d H:i:s');

        $db->beginTransaction();

        $catCache = []; // slug -> id
        $subCatCache = []; // parent_id + sub_slug -> id

        $importedProducts = 0;
        $createdCategories = 0;
        $createdSubcategories = 0;
        $linkedImages = 0;

        foreach ($rows as $r) {
            $code = trim($r['product_code'] ?? '');
            if (empty($code)) continue;

            $catName = trim($r['category'] ?? 'General');
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
                $subKey = $mainCatId . '_' . $subSlug;

                if (!isset($subCatCache[$subKey])) {
                    $scStmt = $db->prepare("SELECT id FROM shop_categories WHERE slug = ? AND parent_id = ?");
                    $scStmt->execute([$subSlug, $mainCatId]);
                    $subId = $scStmt->fetchColumn();

                    if (!$subId) {
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

            // C. Auto-detect image
            $relImage = find_matching_image($code, $uploadDir);
            if ($relImage) {
                $linkedImages++;
            }

            $priceCents = intval($r['price_cents'] ?? 0);
            $dispOrder = intval($r['display_order'] ?? 10);
            $isActive = intval($r['active'] ?? 1);
            $brand = trim($r['brand'] ?? '');

            // D. Upsert Product in shop_products
            $pStmt = $db->prepare("SELECT id, image_url FROM shop_products WHERE sku = ?");
            $pStmt->execute([$code]);
            $prod = $pStmt->fetch(PDO::FETCH_ASSOC);

            if ($prod) {
                $prodId = $prod['id'];
                $finalImage = $relImage ? $relImage : $prod['image_url'];

                $uP = $db->prepare("
                    UPDATE shop_products SET 
                        category_id = ?, brand = ?, reference_price_cents = ?, 
                        manual_final_price_cents = ?, image_url = ?, display_order = ?, 
                        is_active = ?, updated_at = ?
                    WHERE id = ?
                ");
                $uP->execute([$targetCatId, $brand, $priceCents, $priceCents, $finalImage, $dispOrder, $isActive, $now, $prodId]);
            } else {
                $inP = $db->prepare("
                    INSERT INTO shop_products (
                        category_id, sku, brand, reference_price_cents, manual_final_price_cents, 
                        image_url, display_order, is_active, is_available, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
                ");
                $inP->execute([$targetCatId, $code, $brand, $priceCents, $priceCents, $relImage, $dispOrder, $isActive, $now, $now]);
                $prodId = $db->lastInsertId();
            }

            // E. Upsert Translations for ES, EN, DE
            $fmt = trim($r['format'] ?? '');
            $langsMap = [
                'es' => ['name' => $r['name_es'] ?? '', 'desc' => $r['description_es'] ?? ''],
                'en' => ['name' => $r['name_en'] ?? '', 'desc' => $r['description_en'] ?? ''],
                'de' => ['name' => $r['name_de'] ?? '', 'desc' => $r['description_de'] ?? '']
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

            $importedProducts++;
        }

        $db->commit();

        send_response([
            'success' => true,
            'message' => "Importación completada con éxito. {$importedProducts} productos procesados.",
            'imported_products' => $importedProducts,
            'created_categories' => $createdCategories,
            'created_subcategories' => $createdSubcategories,
            'linked_images' => $linkedImages
        ]);

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        send_response(['error' => 'Error durante la importación CSV: ' . $e->getMessage()], 500);
    }
}

send_response(['error' => 'Acción no válida.'], 400);
