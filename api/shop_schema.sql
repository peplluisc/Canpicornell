-- Can Picornell Private Guest Shop Module - Database Schema V1
-- Amounts stored in INTEGER cents (2.95€ = 295 cents)

-- 1. Tokens de Acceso Privados para Huéspedes (con hash SHA-256)
CREATE TABLE IF NOT EXISTS shop_access_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    booking_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    preferred_language TEXT NOT NULL DEFAULT 'es',
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    last_accessed_at TEXT,
    FOREIGN KEY (booking_id) REFERENCES booking_requests(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_shop_tokens_hash ON shop_access_tokens(token_hash);
CREATE INDEX IF NOT EXISTS idx_shop_tokens_booking ON shop_access_tokens(booking_id);

-- 2. Categorías de Productos
CREATE TABLE IF NOT EXISTS shop_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_id INTEGER DEFAULT NULL,
    slug TEXT NOT NULL UNIQUE,
    display_order INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    FOREIGN KEY (parent_id) REFERENCES shop_categories(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_shop_cat_parent ON shop_categories(parent_id);

-- 3. Traducciones de Categorías (ES, EN, DE)
CREATE TABLE IF NOT EXISTS shop_category_translations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id INTEGER NOT NULL,
    language TEXT NOT NULL,
    name TEXT NOT NULL,
    UNIQUE(category_id, language),
    FOREIGN KEY (category_id) REFERENCES shop_categories(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_shop_cat_trans_lang ON shop_category_translations(category_id, language);

-- 4. Catálogo de Productos
CREATE TABLE IF NOT EXISTS shop_products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id INTEGER NOT NULL,
    sku TEXT UNIQUE,
    brand TEXT,
    supplier_name TEXT,
    supplier_product_id TEXT,
    gtin TEXT,
    reference_price_cents INTEGER NOT NULL DEFAULT 0,
    currency TEXT DEFAULT 'EUR',
    priority TEXT DEFAULT 'A',
    margin_percent REAL DEFAULT NULL,
    manual_final_price_cents INTEGER DEFAULT NULL,
    image_url TEXT,
    display_order INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    is_available INTEGER NOT NULL DEFAULT 1,
    is_featured INTEGER NOT NULL DEFAULT 0,
    last_imported_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (category_id) REFERENCES shop_categories(id) ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_shop_products_cat ON shop_products(category_id);
CREATE INDEX IF NOT EXISTS idx_shop_products_active ON shop_products(is_active);

-- 5. Traducciones de Productos y URLs de Origen por Idioma (ES, EN, DE)
CREATE TABLE IF NOT EXISTS shop_product_translations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    language TEXT NOT NULL,
    name TEXT NOT NULL,
    description TEXT,
    format_text TEXT,
    additional_information TEXT,
    source_url TEXT,
    UNIQUE(product_id, language),
    FOREIGN KEY (product_id) REFERENCES shop_products(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_shop_prod_trans_lang ON shop_product_translations(product_id, language);

-- 6. Cabecera de Pedidos de Huéspedes
CREATE TABLE IF NOT EXISTS shop_orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    booking_id INTEGER NOT NULL,
    token_id INTEGER NOT NULL,
    order_number TEXT NOT NULL UNIQUE,
    status TEXT NOT NULL DEFAULT 'DRAFT',
    subtotal_cents INTEGER NOT NULL DEFAULT 0,
    margin_cents INTEGER NOT NULL DEFAULT 0,
    total_cents INTEGER NOT NULL DEFAULT 0,
    guest_notes TEXT,
    admin_notes TEXT,
    submitted_at TEXT,
    approved_at TEXT,
    purchased_at TEXT,
    delivered_at TEXT,
    paid_at TEXT,
    cancelled_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (booking_id) REFERENCES booking_requests(id) ON DELETE RESTRICT,
    FOREIGN KEY (token_id) REFERENCES shop_access_tokens(id) ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_shop_orders_booking ON shop_orders(booking_id);
CREATE INDEX IF NOT EXISTS idx_shop_orders_status ON shop_orders(status);

-- 7. Líneas de Pedido (con preservación de precio histórico en céntimos)
CREATE TABLE IF NOT EXISTS shop_order_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL,
    product_id INTEGER,
    product_name_snapshot TEXT NOT NULL,
    quantity INTEGER NOT NULL DEFAULT 1,
    unit_price_cents INTEGER NOT NULL DEFAULT 0,
    total_price_cents INTEGER NOT NULL DEFAULT 0,
    is_purchased INTEGER NOT NULL DEFAULT 0,
    purchase_status TEXT NOT NULL DEFAULT 'PENDING',
    notes TEXT,
    FOREIGN KEY (order_id) REFERENCES shop_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES shop_products(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_shop_order_items_order ON shop_order_items(order_id);

-- 8. Configuración General de la Tienda y Versión de Esquema
CREATE TABLE IF NOT EXISTS shop_settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

INSERT INTO shop_settings (setting_key, setting_value, updated_at)
SELECT 'global_margin_percent', '10.00', CURRENT_TIMESTAMP
WHERE NOT EXISTS (SELECT 1 FROM shop_settings WHERE setting_key = 'global_margin_percent');

INSERT INTO shop_settings (setting_key, setting_value, updated_at)
SELECT 'schema_version', '1', CURRENT_TIMESTAMP
WHERE NOT EXISTS (SELECT 1 FROM shop_settings WHERE setting_key = 'schema_version');
