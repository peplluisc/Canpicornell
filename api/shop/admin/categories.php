<?php
/**
 * Admin Categories Endpoint for Can Picornell Private Guest Shop Module
 * Handles category CRUD, ordering, status toggling, and multi-language translations (ES/EN/DE).
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

// GET: List all categories with translations
if ($method === 'GET') {
    try {
        $c_stmt = $db->query("
            SELECT id, slug, display_order, is_active, created_at 
            FROM shop_categories 
            ORDER BY display_order ASC, id ASC
        ");
        $categories = $c_stmt->fetchAll(PDO::FETCH_ASSOC);

        $t_stmt = $db->query("
            SELECT category_id, language, name 
            FROM shop_category_translations
        ");
        $translations = $t_stmt->fetchAll(PDO::FETCH_ASSOC);

        $trans_by_cat = [];
        foreach ($translations as $tr) {
            $cat_id = $tr['category_id'];
            if (!isset($trans_by_cat[$cat_id])) {
                $trans_by_cat[$cat_id] = [];
            }
            $trans_by_cat[$cat_id][$tr['language']] = [
                'name' => $tr['name']
            ];
        }

        foreach ($categories as &$cat) {
            $cat['translations'] = $trans_by_cat[$cat['id']] ?? [];
        }

        send_response(['success' => true, 'categories' => $categories]);
    } catch (Exception $e) {
        send_response(['error' => 'Error al obtener categorías: ' . $e->getMessage()], 500);
    }
}

// POST: Create, update, toggle, delete
if ($method === 'POST') {
    $raw_input = file_get_contents('php://input');
    $input = json_decode($raw_input, true) ?: $_POST;
    $action = isset($input['action']) ? trim($input['action']) : '';

    // CREATE OR UPDATE
    if ($action === 'create' || $action === 'update') {
        $category_id = isset($input['id']) ? intval($input['id']) : 0;
        $slug = isset($input['slug']) ? strtolower(trim(preg_replace('/[^a-z0-9\-]/i', '', $input['slug']))) : '';
        $display_order = isset($input['display_order']) ? intval($input['display_order']) : 0;
        $is_active = isset($input['is_active']) ? intval($input['is_active']) : 1;
        $translations = isset($input['translations']) && is_array($input['translations']) ? $input['translations'] : [];

        if (empty($slug)) {
            send_response(['error' => 'El identificador (slug) de la categoría es obligatorio.'], 400);
        }

        // Validate Spanish name exists
        $es_name = isset($translations['es']['name']) ? trim($translations['es']['name']) : '';
        if (empty($es_name)) {
            send_response(['error' => 'El nombre de la categoría en castellano es obligatorio.'], 400);
        }

        try {
            $db->beginTransaction();

            if ($action === 'create') {
                // Check slug unique
                $chk = $db->prepare("SELECT id FROM shop_categories WHERE slug = ?");
                $chk->execute([$slug]);
                if ($chk->fetch()) {
                    $db->rollBack();
                    send_response(['error' => 'Ya existe una categoría con ese slug.'], 400);
                }

                $now = date('Y-m-d H:i:s');
                $ins = $db->prepare("INSERT INTO shop_categories (slug, display_order, is_active, created_at) VALUES (?, ?, ?, ?)");
                $ins->execute([$slug, $display_order, $is_active, $now]);
                $category_id = $db->lastInsertId();
            } else {
                // Update
                $chk = $db->prepare("SELECT id FROM shop_categories WHERE slug = ? AND id != ?");
                $chk->execute([$slug, $category_id]);
                if ($chk->fetch()) {
                    $db->rollBack();
                    send_response(['error' => 'Ya existe otra categoría con ese slug.'], 400);
                }

                $upd = $db->prepare("UPDATE shop_categories SET slug = ?, display_order = ?, is_active = ? WHERE id = ?");
                $upd->execute([$slug, $display_order, $is_active, $category_id]);
            }

            // Save translations (ES, EN, DE)
            foreach (['es', 'en', 'de'] as $lang) {
                if (isset($translations[$lang]) && !empty(trim($translations[$lang]['name'] ?? ''))) {
                    $name = trim($translations[$lang]['name']);
                    
                    $t_upd = $db->prepare("UPDATE shop_category_translations SET name = ? WHERE category_id = ? AND language = ?");
                    $t_upd->execute([$name, $category_id, $lang]);
                    if ($t_upd->rowCount() === 0) {
                        $t_ins = $db->prepare("INSERT INTO shop_category_translations (category_id, language, name) VALUES (?, ?, ?)");
                        $t_ins->execute([$category_id, $lang, $name]);
                    }
                }
            }

            $db->commit();
            send_response(['success' => true, 'category_id' => $category_id, 'message' => 'Categoría guardada correctamente.']);
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            send_response(['error' => 'Error al guardar categoría: ' . $e->getMessage()], 500);
        }
    }

    // TOGGLE ACTIVE STATUS
    if ($action === 'toggle') {
        $cat_id = isset($input['id']) ? intval($input['id']) : 0;
        if ($cat_id <= 0) {
            send_response(['error' => 'ID de categoría no válido.'], 400);
        }
        $c_stmt = $db->prepare("SELECT id, is_active FROM shop_categories WHERE id = ?");
        $c_stmt->execute([$cat_id]);
        $cat = $c_stmt->fetch();
        if (!$cat) {
            send_response(['error' => 'Categoría no encontrada.'], 404);
        }
        $new_st = ($cat['is_active'] == 1) ? 0 : 1;
        $db->prepare("UPDATE shop_categories SET is_active = ? WHERE id = ?")->execute([$new_st, $cat_id]);
        send_response(['success' => true, 'is_active' => $new_st, 'message' => 'Estado actualizado.']);
    }

    // DELETE (Only if no products assigned)
    if ($action === 'delete') {
        $cat_id = isset($input['id']) ? intval($input['id']) : 0;
        if ($cat_id <= 0) {
            send_response(['error' => 'ID no válido.'], 400);
        }

        $p_check = $db->prepare("SELECT COUNT(*) FROM shop_products WHERE category_id = ?");
        $p_check->execute([$cat_id]);
        if (intval($p_check->fetchColumn()) > 0) {
            send_response(['error' => 'No se puede eliminar la categoría porque contiene productos asignados. Desactívela en su lugar.'], 400);
        }

        $db->prepare("DELETE FROM shop_categories WHERE id = ?")->execute([$cat_id]);
        send_response(['success' => true, 'message' => 'Categoría eliminada correctamente.']);
    }

    send_response(['error' => 'Acción no válida.'], 400);
}

send_response(['error' => 'Método no permitido.'], 405);
