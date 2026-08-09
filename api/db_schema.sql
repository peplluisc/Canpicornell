-- Can Picornell Complete Baseline Database Schema (Booking + Shop Module V1)

-- 1. Table for Booking Requests
CREATE TABLE IF NOT EXISTS booking_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_number TEXT NOT NULL UNIQUE,
    checkin_date TEXT NOT NULL,
    checkout_date TEXT NOT NULL,
    adults INTEGER NOT NULL,
    children INTEGER NOT NULL,
    babies INTEGER NOT NULL,
    guest_name TEXT NOT NULL,
    guest_email TEXT NOT NULL,
    guest_phone TEXT NOT NULL,
    guest_country TEXT NOT NULL,
    preferred_language TEXT NOT NULL,
    contact_channel TEXT NOT NULL,
    arrival_time TEXT,
    special_requests TEXT,
    discovery_channel TEXT,
    amount_accommodation REAL NOT NULL,
    amount_cleaning REAL NOT NULL,
    amount_tax REAL NOT NULL,
    amount_total REAL NOT NULL,
    amount_deposit REAL NOT NULL,
    amount_balance REAL NOT NULL,
    balance_due_date TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'solicitud_recibida',
    stripe_session_id TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

-- Indexing for fast search and validation queries
CREATE INDEX IF NOT EXISTS idx_booking_dates ON booking_requests(checkin_date, checkout_date);
CREATE INDEX IF NOT EXISTS idx_booking_status ON booking_requests(status);
CREATE INDEX IF NOT EXISTS idx_booking_stripe_session ON booking_requests(stripe_session_id);

-- 2. Table for Booking History Logs
CREATE TABLE IF NOT EXISTS booking_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    booking_id INTEGER NOT NULL,
    status TEXT NOT NULL,
    notes TEXT,
    changed_at TEXT NOT NULL,
    FOREIGN KEY(booking_id) REFERENCES booking_requests(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_history_booking_id ON booking_history(booking_id);

-- 3. Can Picornell Private Guest Shop Module - Database Schema V1
-- Amounts stored in INTEGER cents (2.95€ = 295 cents)

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

CREATE TABLE IF NOT EXISTS shop_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    display_order INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS shop_category_translations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id INTEGER NOT NULL,
    language TEXT NOT NULL,
    name TEXT NOT NULL,
    UNIQUE(category_id, language),
    FOREIGN KEY (category_id) REFERENCES shop_categories(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_shop_cat_trans_lang ON shop_category_translations(category_id, language);

CREATE TABLE IF NOT EXISTS shop_products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id INTEGER NOT NULL,
    sku TEXT UNIQUE,
    brand TEXT,
    supplier_name TEXT,
    reference_price_cents INTEGER NOT NULL DEFAULT 0,
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
