<?php
/**
 * Guest Catalog Endpoint for Can Picornell Guest Shop Module
 * Returns active categories and available products with multi-language fallback (Requested -> ES).
 */

require_once __DIR__ . '/guest_helper.php';

$raw_token = $_GET['t'] ?? '';
$url_lang = $_GET['lang'] ?? null;
$category_filter = $_GET['category'] ?? null;
$search_query = trim($_GET['q'] ?? '');

$db = get_db_connection();
$context = validate_guest_token($db, $raw_token);
$lang = resolve_language($url_lang, $context['preferred_language']);
$global_margin = get_global_margin($db);

// 1. Fetch active categories
try {
    $c_stmt = $db->query("
        SELECT id, slug, display_order 
        FROM shop_categories 
        WHERE is_active = 1 
        ORDER BY display_order ASC, id ASC
    ");
    $raw_categories = $c_stmt->fetchAll(PDO::FETCH_ASSOC);

    $ct_stmt = $db->prepare("
        SELECT category_id, language, name 
        FROM shop_category_translations
    ");
    $ct_stmt->execute();
    $cat_trans = $ct_stmt->fetchAll(PDO::FETCH_ASSOC);

    $cat_trans_map = [];
    foreach ($cat_trans as $tr) {
        $cat_trans_map[$tr['category_id']][$tr['language']] = $tr['name'];
    }

    $categories = [];
    foreach ($raw_categories as $cat) {
        $name = $cat_trans_map[$cat['id']][$lang] ?? ($cat_trans_map[$cat['id']]['es'] ?? $cat['slug']);
        $categories[] = [
            'id' => $cat['id'],
            'slug' => $cat['slug'],
            'name' => $name
        ];
    }

    // 2. Fetch active and available products
    $sql = "
        SELECT 
            p.id, p.category_id, p.sku, p.brand,
            p.reference_price_cents, p.margin_percent, p.manual_final_price_cents,
            p.image_url, p.display_order, p.is_featured,
            c.slug AS category_slug,
            t_req.name AS req_name, t_req.description AS req_desc, t_req.format_text AS req_fmt,
            t_es.name AS es_name, t_es.description AS es_desc, t_es.format_text AS es_fmt
        FROM shop_products p
        JOIN shop_categories c ON p.category_id = c.id
        LEFT JOIN shop_product_translations t_req ON p.id = t_req.product_id AND t_req.language = :req_lang
        LEFT JOIN shop_product_translations t_es  ON p.id = t_es.product_id  AND t_es.language = 'es'
        WHERE p.is_active = 1 AND p.is_available = 1 AND c.is_active = 1
    ";

    $params = [':req_lang' => $lang];

    if (!empty($category_filter)) {
        $sql .= " AND c.slug = :cat_slug";
        $params[':cat_slug'] = $category_filter;
    }

    $sql .= " ORDER BY p.is_featured DESC, p.display_order ASC, p.id DESC";

    $p_stmt = $db->prepare($sql);
    $p_stmt->execute($params);
    $raw_products = $p_stmt->fetchAll(PDO::FETCH_ASSOC);

    $products = [];
    foreach ($raw_products as $p) {
        $name = !empty($p['req_name']) ? $p['req_name'] : (!empty($p['es_name']) ? $p['es_name'] : 'Producto');
        $desc = !empty($p['req_desc']) ? $p['req_desc'] : ($p['es_desc'] ?? '');
        $fmt = !empty($p['req_fmt']) ? $p['req_fmt'] : ($p['es_fmt'] ?? '');

        // Search filter if provided
        if (!empty($search_query)) {
            $haystack = mb_strtolower($name . ' ' . $desc . ' ' . ($p['brand'] ?? '') . ' ' . $fmt, 'UTF-8');
            if (mb_strpos($haystack, mb_strtolower($search_query, 'UTF-8')) === false) {
                continue;
            }
        }

        $ref_cents = intval($p['reference_price_cents']);
        $m_pct = ($p['margin_percent'] !== null) ? floatval($p['margin_percent']) : null;
        $man_cents = ($p['manual_final_price_cents'] !== null) ? intval($p['manual_final_price_cents']) : null;

        $final_cents = calculate_final_price_cents($ref_cents, $m_pct, $man_cents, $global_margin);

        $products[] = [
            'id' => $p['id'],
            'category_id' => $p['category_id'],
            'category_slug' => $p['category_slug'],
            'name' => $name,
            'brand' => $p['brand'] ?? '',
            'format' => $fmt,
            'description' => $desc,
            'price_cents' => $final_cents,
            'price_formatted' => number_format($final_cents / 100, 2, ',', '.') . ' €',
            'image_url' => $p['image_url'] ?? '',
            'is_featured' => intval($p['is_featured']) === 1
        ];
    }

    send_guest_json([
        'success' => true,
        'language' => $lang,
        'categories' => $categories,
        'products' => $products
    ]);
} catch (Exception $e) {
    error_log("Guest catalog fetch error: " . $e->getMessage());
    send_guest_json(['error' => 'No se pudo cargar el catálogo.'], 500);
}
