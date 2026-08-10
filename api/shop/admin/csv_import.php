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

    // Detect delimiter (; or , or \t)
    $firstLine = strtok($content, "\r\n");
    $delimiter = (substr_count($firstLine, ';') >= substr_count($firstLine, ',')) ? ';' : ',';
    if (substr_count($firstLine, "\t") > substr_count($firstLine, $delimiter)) {
        $delimiter = "\t";
    }

    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $content);
    rewind($stream);

    $header = fgetcsv($stream, 0, $delimiter);
    if (!$header) {
        throw new Exception("El archivo CSV no contiene un encabezado válido.");
    }

    $headerClean = [];
    foreach ($header as $h) {
        $hClean = trim($h);
        if (function_exists('mb_strtolower')) {
            $hClean = mb_strtolower($hClean, 'UTF-8');
        } else {
            $hClean = strtolower($hClean);
        }
        $headerClean[] = $hClean;
    }

    $rows = [];
    $lineNumber = 1;
    while (($data = fgetcsv($stream, 0, $delimiter)) !== false) {
        $lineNumber++;
        if (count($data) < 2) continue; // Skip empty lines
        $row = ['_line_number' => $lineNumber];
        foreach ($headerClean as $idx => $colName) {
            $val = isset($data[$idx]) ? trim($data[$idx]) : '';
            $row[$colName] = $val;

            // Also save unaccented ASCII key for fallback matching (e.g. categoría_es -> categoria_es, código -> codigo)
            $unaccentedKey = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $colName) ?: strtolower($colName);
            if ($unaccentedKey !== $colName) {
                $row[$unaccentedKey] = $val;
            }
        }
        $rows[] = $row;
    }
    fclose($stream);
    return $rows;
}

function get_row_val(array $row, array $aliases, string $default = ''): string {
    foreach ($aliases as $a) {
        $key = trim($a);
        if (function_exists('mb_strtolower')) {
            $keyLower = mb_strtolower($key, 'UTF-8');
        } else {
            $keyLower = strtolower($key);
        }
        if (isset($row[$keyLower]) && trim($row[$keyLower]) !== '') {
            return trim($row[$keyLower]);
        }
        $unaccented = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $keyLower) ?: strtolower($keyLower);
        if (isset($row[$unaccented]) && trim($row[$unaccented]) !== '') {
            return trim($row[$unaccented]);
        }
    }
    return $default;
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

        $catAliases = ['category_es', 'categoría_es', 'categoria_es', 'category_key', 'category_slug', 'cat_key', 'category', 'categoría', 'categoria', 'cat', 'category_name_es', 'cat_nombre_es', 'nombre_categoria_es', 'nombre_categoria'];
        $subcatAliases = ['subcategory_es', 'subcategoría_es', 'subcategoria_es', 'subcategory_key', 'subcategory_slug', 'subcat_key', 'subcategory', 'subcategoría', 'subcategoria', 'subcat', 'subcategory_name_es', 'subcat_nombre_es', 'nombre_subcategoria_es', 'nombre_subcategoria'];

        $previewRows = [];
        foreach ($rows as $r) {
            $lineNum = $r['_line_number'];
            $code = get_row_val($r, ['product_code', 'código', 'codigo', 'sku', 'code']);
            $catKey = get_row_val($r, ['category_key', 'category_slug', 'cat_key', 'key_category']);
            $cat = get_row_val($r, $catAliases);
            if (empty($cat) && !empty($catKey)) $cat = ucfirst($catKey);

            $subKey = get_row_val($r, ['subcategory_key', 'subcategory_slug', 'subcat_key', 'key_subcategory']);
            $subcat = get_row_val($r, $subcatAliases);
            if (empty($subcat) && !empty($subKey)) $subcat = ucfirst($subKey);

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
                    'brand' => get_row_val($r, ['brand', 'marca']),
                    'format' => get_row_val($r, ['format', 'formato']),
                    'price_cents' => intval(get_row_val($r, ['price_cents', 'precio_cents', 'price', 'precio', 'reference_price_cents'], '0')),
                    'priority' => get_row_val($r, ['priority', 'prioridad'], 'A'),
                    'name_es' => get_row_val($r, ['name_es', 'nombre_es', 'name', 'nombre', 'title_es', 'titulo_es', 'title', 'titulo', 'product_name']),
                    'name_en' => get_row_val($r, ['name_en', 'nombre_en', 'title_en', 'titulo_en']),
                    'name_de' => get_row_val($r, ['name_de', 'nombre_de', 'title_de', 'titulo_de']),
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

        $catCache = []; // key -> id
        $subCatCache = []; // parent_id + sub_key -> id

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
            $code = trim(get_row_val($r, ['product_code', 'código', 'codigo', 'sku', 'code']));

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
            $priceRaw = trim(get_row_val($r, ['price_cents', 'precio_cents', 'price', 'precio', 'reference_price_cents']));
            $priceCents = 0;
            if ($priceRaw !== '') {
                if (!is_numeric($priceRaw) || strpos($priceRaw, '.') !== false || strpos($priceRaw, ',') !== false) {
                    $detallesError[] = ['fila' => $lineNum, 'product_code' => $code, 'motivo' => "price_cents '{$priceRaw}' no es un número entero válido."];
                    continue;
                }
                $priceCents = intval($priceRaw);
            }

            // Rule 11. Validation 3: active must be 0 or 1
            $activeRaw = trim(get_row_val($r, ['active', 'activo', 'is_active'], '1'));
            if (!in_array($activeRaw, ['0', '1', 0, 1], true)) {
                $detallesError[] = ['fila' => $lineNum, 'product_code' => $code, 'motivo' => "El campo active '{$activeRaw}' no puede interpretarse como 0 o 1."];
                continue;
            }
            $isActive = intval($activeRaw);

            // Rule 11. Validation 4: name_es missing check
            $nameEs = trim(get_row_val($r, ['name_es', 'nombre_es', 'name', 'nombre', 'title_es', 'titulo_es', 'title', 'titulo', 'product_name']));
            if (empty($nameEs)) {
                $detallesError[] = ['fila' => $lineNum, 'product_code' => $code, 'motivo' => 'Falta name_es en la fila (nombre en español obligatorio).'];
                continue;
            }

            $catKey = trim(get_row_val($r, ['category_key', 'category_slug', 'cat_key', 'key_category']));
            $subKey = trim(get_row_val($r, ['subcategory_key', 'subcategory_slug', 'subcat_key', 'key_subcategory']));

            $catEs = trim(get_row_val($r, ['category_es', 'categoría_es', 'categoria_es', 'cat_es', 'category', 'categoría', 'categoria', 'cat', 'category_name_es', 'cat_nombre_es', 'nombre_categoria_es', 'nombre_categoria']));
            $catEn = trim(get_row_val($r, ['category_en', 'categoría_en', 'categoria_en', 'cat_en', 'category_name_en', 'cat_nombre_en', 'nombre_categoria_en']));
            $catDe = trim(get_row_val($r, ['category_de', 'categoría_de', 'categoria_de', 'cat_de', 'category_name_de', 'cat_nombre_de', 'nombre_categoria_de']));

            $subEs = trim(get_row_val($r, ['subcategory_es', 'subcategoría_es', 'subcategoria_es', 'subcat_es', 'subcategory', 'subcategoría', 'subcategoria', 'subcat', 'subcategory_name_es', 'subcat_nombre_es', 'nombre_subcategoria_es', 'nombre_subcategoria']));
            $subEn = trim(get_row_val($r, ['subcategory_en', 'subcategoría_en', 'subcategoria_en', 'subcat_en', 'subcategory_name_en', 'subcat_nombre_en', 'nombre_subcategoria_en']));
            $subDe = trim(get_row_val($r, ['subcategory_de', 'subcategoría_de', 'subcategoria_de', 'subcat_de', 'subcategory_name_de', 'subcat_nombre_de', 'nombre_subcategoria_de']));

            if (empty($catEs) && !empty($catKey)) $catEs = ucfirst($catKey);
            if (empty($catEs)) $catEs = 'General';

            $mainCatCacheKey = md5(($catKey ?: 'none') . '_' . $catEs . '_' . $catEn . '_' . $catDe);

            // A. Process Main Category
            if (!isset($catCache[$mainCatCacheKey])) {
                $catSlugsToSearch = array_unique(array_filter([
                    slugify($catKey),
                    slugify($catEs),
                    slugify($catEn),
                    slugify($catDe)
                ]));

                $mainCatId = null;
                if (!empty($catSlugsToSearch)) {
                    $placeholders = implode(',', array_fill(0, count($catSlugsToSearch), '?'));
                    $cStmt = $db->prepare("
                        SELECT c.id 
                        FROM shop_categories c 
                        LEFT JOIN shop_category_translations t ON c.id = t.category_id 
                        WHERE c.parent_id IS NULL AND (
                            c.slug IN ($placeholders) OR t.name = ? OR t.name = ? OR t.name = ? OR t.name = ?
                        )
                        LIMIT 1
                    ");
                    $queryParams = array_merge($catSlugsToSearch, [$catEs, $catKey, $catEn, $catDe]);
                    $cStmt->execute($queryParams);
                    $mainCatId = $cStmt->fetchColumn();
                }

                if (!$mainCatId) {
                    $primaryCatSlug = !empty($catKey) ? slugify($catKey) : slugify($catEs);
                    $insC = $db->prepare("INSERT INTO shop_categories (parent_id, slug, display_order, is_active, created_at) VALUES (NULL, ?, 10, 1, ?)");
                    $insC->execute([$primaryCatSlug, $now]);
                    $mainCatId = $db->lastInsertId();
                    $createdCategories++;
                }
                $catCache[$mainCatCacheKey] = $mainCatId;
            }
            $mainCatId = $catCache[$mainCatCacheKey];

            // Update/Upsert Main Category Translations (ES, EN, DE)
            $mainCatLangs = ['es' => $catEs, 'en' => $catEn, 'de' => $catDe];
            foreach ($mainCatLangs as $langCode => $val) {
                if ($val === '') continue; // Rule 12: preserve existing if CSV value is empty

                $chkT = $db->prepare("SELECT id, name FROM shop_category_translations WHERE category_id = ? AND language = ?");
                $chkT->execute([$mainCatId, $langCode]);
                $existingT = $chkT->fetch(PDO::FETCH_ASSOC);

                if ($existingT) {
                    if ($existingT['name'] !== $val) {
                        $updT = $db->prepare("UPDATE shop_category_translations SET name = ? WHERE id = ?");
                        $updT->execute([$val, $existingT['id']]);
                    }
                } else {
                    $insT = $db->prepare("INSERT INTO shop_category_translations (category_id, language, name) VALUES (?, ?, ?)");
                    $insT->execute([$mainCatId, $langCode, $val]);
                }
            }

            $targetCatId = $mainCatId;

            // B. Process Subcategory if present
            if (!empty($subKey) || !empty($subEs)) {
                if (empty($subEs) && !empty($subKey)) $subEs = ucfirst($subKey);
                $subCatCacheKey = md5($mainCatId . '_' . ($subKey ?: 'none') . '_' . $subEs . '_' . $subEn . '_' . $subDe);

                if (!isset($subCatCache[$subCatCacheKey])) {
                    $subSlugsToSearch = array_unique(array_filter([
                        slugify($subKey),
                        slugify($subEs),
                        slugify($catEs . '-' . $subEs),
                        slugify($catKey . '-' . $subKey),
                        slugify($catKey . '-' . $subEs),
                        slugify($catEs . '-' . $subKey)
                    ]));

                    $subCatId = null;
                    if (!empty($subSlugsToSearch)) {
                        $placeholders = implode(',', array_fill(0, count($subSlugsToSearch), '?'));
                        $scStmt = $db->prepare("
                            SELECT c.id, c.parent_id 
                            FROM shop_categories c 
                            LEFT JOIN shop_category_translations t ON c.id = t.category_id 
                            WHERE (c.parent_id = ? OR c.parent_id IS NULL) AND (
                                c.slug IN ($placeholders) OR t.name = ? OR t.name = ? OR t.name = ? OR t.name = ?
                            )
                            LIMIT 1
                        ");
                        $queryParams = array_merge([$mainCatId], $subSlugsToSearch, [$subEs, $subKey, $subEn, $subDe]);
                        $scStmt->execute($queryParams);
                        $subRow = $scStmt->fetch(PDO::FETCH_ASSOC);

                        if ($subRow) {
                            $subCatId = $subRow['id'];
                            if (empty($subRow['parent_id']) || $subRow['parent_id'] != $mainCatId) {
                                $updSC = $db->prepare("UPDATE shop_categories SET parent_id = ? WHERE id = ?");
                                $updSC->execute([$mainCatId, $subCatId]);
                            }
                        }
                    }

                    if (!$subCatId) {
                        $primarySubSlug = !empty($subKey) ? slugify($subKey) : slugify($catEs . '-' . $subEs);
                        $insSC = $db->prepare("INSERT INTO shop_categories (parent_id, slug, display_order, is_active, created_at) VALUES (?, ?, 10, 1, ?)");
                        $insSC->execute([$mainCatId, $primarySubSlug, $now]);
                        $subCatId = $db->lastInsertId();
                        $createdSubcategories++;
                    }
                    $subCatCache[$subCatCacheKey] = $subCatId;
                }
                $subCatId = $subCatCache[$subCatCacheKey];

                // Update/Upsert Subcategory Translations (ES, EN, DE)
                $subCatLangs = ['es' => $subEs, 'en' => $subEn, 'de' => $subDe];
                foreach ($subCatLangs as $langCode => $val) {
                    if ($val === '') continue; // Rule 12: preserve existing if CSV value is empty

                    $chkST = $db->prepare("SELECT id, name FROM shop_category_translations WHERE category_id = ? AND language = ?");
                    $chkST->execute([$subCatId, $langCode]);
                    $existingST = $chkST->fetch(PDO::FETCH_ASSOC);

                    if ($existingST) {
                        if ($existingST['name'] !== $val) {
                            $updST = $db->prepare("UPDATE shop_category_translations SET name = ? WHERE id = ?");
                            $updST->execute([$val, $existingST['id']]);
                        }
                    } else {
                        $insST = $db->prepare("INSERT INTO shop_category_translations (category_id, language, name) VALUES (?, ?, ?)");
                        $insST->execute([$subCatId, $langCode, $val]);
                    }
                }

                $targetCatId = $subCatId;
            }

            // C. Rule 2: Auto-detect image at /images/products/{product_code}.jpg
            $relImage = find_matching_product_image($code);
            if ($relImage) {
                $imagenesEncontradas++;
            } else {
                $imagenesNoEncontradas++;
            }

            $dispOrder = intval(get_row_val($r, ['display_order', 'orden', 'displayorder'], '10'));
            $brand = trim(get_row_val($r, ['brand', 'marca']));
            $currency = trim(get_row_val($r, ['currency', 'moneda'], 'EUR'));
            if (empty($currency)) $currency = 'EUR';
            $priority = trim(get_row_val($r, ['priority', 'prioridad'], 'A'));
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
            $fmt = trim(get_row_val($r, ['format', 'formato']));
            $langsMap = [
                'es' => [
                    'name' => $nameEs,
                    'desc' => trim(get_row_val($r, ['description_es', 'descripcion_es', 'desc_es', 'description', 'descripcion']))
                ],
                'en' => [
                    'name' => trim(get_row_val($r, ['name_en', 'nombre_en', 'title_en', 'titulo_en'])),
                    'desc' => trim(get_row_val($r, ['description_en', 'descripcion_en', 'desc_en']))
                ],
                'de' => [
                    'name' => trim(get_row_val($r, ['name_de', 'nombre_de', 'title_de', 'titulo_de'])),
                    'desc' => trim(get_row_val($r, ['description_de', 'descripcion_de', 'desc_de']))
                ]
            ];

            foreach ($langsMap as $langCode => $tData) {
                $tName = trim($tData['name']);
                $tDesc = trim($tData['desc']);
                if (empty($tName) && $langCode !== 'es') continue;

                $chkT = $db->prepare("SELECT id, name, description, format_text FROM shop_product_translations WHERE product_id = ? AND language = ?");
                $chkT->execute([$prodId, $langCode]);
                $existingT = $chkT->fetch(PDO::FETCH_ASSOC);

                if ($existingT) {
                    if ($existingT['name'] !== $tName || ($existingT['description'] ?? '') !== $tDesc || ($existingT['format_text'] ?? '') !== $fmt) {
                        $tUpd = $db->prepare("UPDATE shop_product_translations SET name = ?, description = ?, format_text = ? WHERE id = ?");
                        $tUpd->execute([$tName, $tDesc, $fmt, $existingT['id']]);
                    }
                } else {
                    $tIns = $db->prepare("INSERT INTO shop_product_translations (product_id, language, name, description, format_text) VALUES (?, ?, ?, ?, ?)");
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
