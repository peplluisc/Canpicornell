<?php
/**
 * Database Migration Engine for Can Picornell (SQLite & MySQL)
 * Manages driver-aware table checks, PRAGMA foreign keys, and versioned schema migrations.
 */

function run_db_migrations(PDO $pdo, string $driver) {
    static $already_run = false;
    if ($already_run) {
        return;
    }
    $already_run = true;

    // 1. Ensure foreign keys and encoding are enforced per driver
    if ($driver === 'sqlite') {
        $pdo->exec("PRAGMA foreign_keys = ON;");
    } else if ($driver === 'mysql') {
        $pdo->exec("SET NAMES utf8mb4;");
    }

    // 2. Ensure baseline booking tables exist
    $has_booking = check_table_exists($pdo, $driver, 'booking_requests');
    if (!$has_booking) {
        execute_baseline_schema($pdo, $driver);
    }

    // 3. Ensure shop_settings table exists
    $has_shop_settings = check_table_exists($pdo, $driver, 'shop_settings');
    if (!$has_shop_settings) {
        execute_shop_v1_schema($pdo, $driver);
    }

    // 4. Check & record schema version in shop_settings
    $current_version = get_shop_schema_version($pdo);
    if ($current_version < 1) {
        set_shop_schema_version($pdo, 1);
        $current_version = 1;
    }

    // 5. Version 2 Migration: Add purchase_status column to shop_order_items
    if ($current_version < 2) {
        migrate_schema_v2($pdo, $driver);
        set_shop_schema_version($pdo, 2);
        $current_version = 2;
    }

    // 6. Version 3 Migration: Add supplier_product_id and gtin to shop_products
    if ($current_version < 3) {
        migrate_schema_v3($pdo, $driver);
        set_shop_schema_version($pdo, 3);
    }
}

function migrate_schema_v3(PDO $pdo, string $driver): void {
    try {
        if ($driver === 'sqlite') {
            $cols = $pdo->query("PRAGMA table_info(shop_products)")->fetchAll(PDO::FETCH_ASSOC);
            $has_spid = false;
            $has_gtin = false;
            foreach ($cols as $c) {
                if ($c['name'] === 'supplier_product_id') $has_spid = true;
                if ($c['name'] === 'gtin') $has_gtin = true;
            }
            if (!$has_spid) {
                $pdo->exec("ALTER TABLE shop_products ADD COLUMN supplier_product_id TEXT;");
            }
            if (!$has_gtin) {
                $pdo->exec("ALTER TABLE shop_products ADD COLUMN gtin TEXT;");
            }
        } else if ($driver === 'mysql') {
            $cols = $pdo->query("SHOW COLUMNS FROM shop_products LIKE 'supplier_product_id'")->fetchAll();
            if (empty($cols)) {
                $pdo->exec("ALTER TABLE shop_products ADD COLUMN supplier_product_id VARCHAR(100);");
            }
            $cols2 = $pdo->query("SHOW COLUMNS FROM shop_products LIKE 'gtin'")->fetchAll();
            if (empty($cols2)) {
                $pdo->exec("ALTER TABLE shop_products ADD COLUMN gtin VARCHAR(100);");
            }
        }
    } catch (Exception $e) {
        error_log("Schema v3 migration error: " . $e->getMessage());
    }
}

function migrate_schema_v2(PDO $pdo, string $driver): void {
    try {
        if ($driver === 'sqlite') {
            // Check if column exists
            $cols = $pdo->query("PRAGMA table_info(shop_order_items)")->fetchAll(PDO::FETCH_ASSOC);
            $has_col = false;
            foreach ($cols as $c) {
                if ($c['name'] === 'purchase_status') {
                    $has_col = true;
                    break;
                }
            }
            if (!$has_col) {
                $pdo->exec("ALTER TABLE shop_order_items ADD COLUMN purchase_status TEXT NOT NULL DEFAULT 'PENDING';");
                $pdo->exec("UPDATE shop_order_items SET purchase_status = 'PURCHASED' WHERE is_purchased = 1;");
            }
        } else if ($driver === 'mysql') {
            $cols = $pdo->query("SHOW COLUMNS FROM shop_order_items LIKE 'purchase_status'")->fetchAll();
            if (empty($cols)) {
                $pdo->exec("ALTER TABLE shop_order_items ADD COLUMN purchase_status VARCHAR(20) NOT NULL DEFAULT 'PENDING';");
                $pdo->exec("UPDATE shop_order_items SET purchase_status = 'PURCHASED' WHERE is_purchased = 1;");
            }
        }
    } catch (Exception $e) {
        error_log("Schema v2 migration error: " . $e->getMessage());
    }
}

function check_table_exists(PDO $pdo, string $driver, string $table_name): bool {
    try {
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name = ?");
            $stmt->execute([$table_name]);
            return intval($stmt->fetchColumn()) > 0;
        } else if ($driver === 'mysql') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
            $stmt->execute([$table_name]);
            return intval($stmt->fetchColumn()) > 0;
        }
    } catch (PDOException $e) {
        error_log("Error checking table existence for {$table_name}: " . $e->getMessage());
    }
    return false;
}

function get_shop_schema_version(PDO $pdo): int {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM shop_settings WHERE setting_key = 'schema_version'");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        return ($val !== false) ? intval($val) : 0;
    } catch (PDOException $e) {
        return 0;
    }
}

function set_shop_schema_version(PDO $pdo, int $version): void {
    $now = date('Y-m-d H:i:s');
    try {
        $stmt = $pdo->prepare("
            INSERT INTO shop_settings (setting_key, setting_value, updated_at)
            VALUES ('schema_version', ?, ?)
            ON CONFLICT(setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = EXCLUDED.updated_at
        ");
        $stmt->execute([strval($version), $now]);
    } catch (PDOException $e) {
        // Fallback for MySQL syntax
        $stmt = $pdo->prepare("
            INSERT INTO shop_settings (setting_key, setting_value, updated_at)
            VALUES ('schema_version', ?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)
        ");
        $stmt->execute([strval($version), $now]);
    }
}

function execute_baseline_schema(PDO $pdo, string $driver): void {
    if ($driver === 'sqlite') {
        $schema_file = __DIR__ . '/db_schema.sql';
        if (file_exists($schema_file)) {
            $sql = file_get_contents($schema_file);
            $pdo->exec($sql);
        }
    } else if ($driver === 'mysql') {
        execute_mysql_baseline_schema($pdo);
    }
}

function execute_shop_v1_schema(PDO $pdo, string $driver): void {
    if ($driver === 'sqlite') {
        $schema_file = __DIR__ . '/shop_schema.sql';
        if (file_exists($schema_file)) {
            $sql = file_get_contents($schema_file);
            $pdo->exec($sql);
        }
    } else if ($driver === 'mysql') {
        execute_mysql_shop_v1_schema($pdo);
    }
}

function execute_mysql_baseline_schema(PDO $pdo): void {
    $sql = "
    CREATE TABLE IF NOT EXISTS booking_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        request_number VARCHAR(100) NOT NULL UNIQUE,
        checkin_date VARCHAR(20) NOT NULL,
        checkout_date VARCHAR(20) NOT NULL,
        adults INT NOT NULL,
        children INT NOT NULL,
        babies INT NOT NULL,
        guest_name VARCHAR(255) NOT NULL,
        guest_email VARCHAR(255) NOT NULL,
        guest_phone VARCHAR(100) NOT NULL,
        guest_country VARCHAR(100) NOT NULL,
        preferred_language VARCHAR(10) NOT NULL,
        contact_channel VARCHAR(50) NOT NULL,
        arrival_time VARCHAR(50),
        special_requests TEXT,
        discovery_channel VARCHAR(100),
        amount_accommodation DECIMAL(10,2) NOT NULL,
        amount_cleaning DECIMAL(10,2) NOT NULL,
        amount_tax DECIMAL(10,2) NOT NULL,
        amount_total DECIMAL(10,2) NOT NULL,
        amount_deposit DECIMAL(10,2) NOT NULL,
        amount_balance DECIMAL(10,2) NOT NULL,
        balance_due_date VARCHAR(20) NOT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'solicitud_recibida',
        stripe_session_id VARCHAR(255),
        created_at VARCHAR(30) NOT NULL,
        updated_at VARCHAR(30) NOT NULL,
        INDEX idx_booking_dates (checkin_date, checkout_date),
        INDEX idx_booking_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS booking_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        booking_id INT NOT NULL,
        status VARCHAR(50) NOT NULL,
        notes TEXT,
        changed_at VARCHAR(30) NOT NULL,
        FOREIGN KEY (booking_id) REFERENCES booking_requests(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($sql);
    execute_mysql_shop_v1_schema($pdo);
}

function execute_mysql_shop_v1_schema(PDO $pdo): void {
    $sql = "
    CREATE TABLE IF NOT EXISTS shop_access_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        booking_id INT NOT NULL,
        token_hash VARCHAR(64) NOT NULL UNIQUE,
        preferred_language VARCHAR(10) NOT NULL DEFAULT 'es',
        is_active TINYINT NOT NULL DEFAULT 1,
        created_at VARCHAR(30) NOT NULL,
        expires_at VARCHAR(30) NOT NULL,
        last_accessed_at VARCHAR(30),
        FOREIGN KEY (booking_id) REFERENCES booking_requests(id) ON DELETE CASCADE,
        INDEX idx_shop_tokens_hash (token_hash),
        INDEX idx_shop_tokens_booking (booking_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS shop_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(100) NOT NULL UNIQUE,
        display_order INT NOT NULL DEFAULT 0,
        is_active TINYINT NOT NULL DEFAULT 1,
        created_at VARCHAR(30) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS shop_category_translations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category_id INT NOT NULL,
        language VARCHAR(10) NOT NULL,
        name VARCHAR(255) NOT NULL,
        UNIQUE KEY uq_cat_lang (category_id, language),
        FOREIGN KEY (category_id) REFERENCES shop_categories(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS shop_products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category_id INT NOT NULL,
        sku VARCHAR(100) UNIQUE,
        brand VARCHAR(150),
        supplier_name VARCHAR(150),
        reference_price_cents INT NOT NULL DEFAULT 0,
        margin_percent DECIMAL(5,2) DEFAULT NULL,
        manual_final_price_cents INT DEFAULT NULL,
        image_url TEXT,
        display_order INT NOT NULL DEFAULT 0,
        is_active TINYINT NOT NULL DEFAULT 1,
        is_available TINYINT NOT NULL DEFAULT 1,
        is_featured TINYINT NOT NULL DEFAULT 0,
        last_imported_at VARCHAR(30),
        created_at VARCHAR(30) NOT NULL,
        updated_at VARCHAR(30) NOT NULL,
        FOREIGN KEY (category_id) REFERENCES shop_categories(id) ON DELETE RESTRICT,
        INDEX idx_shop_products_cat (category_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS shop_product_translations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        language VARCHAR(10) NOT NULL,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        format_text VARCHAR(150),
        additional_information TEXT,
        source_url TEXT,
        UNIQUE KEY uq_prod_lang (product_id, language),
        FOREIGN KEY (product_id) REFERENCES shop_products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS shop_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        booking_id INT NOT NULL,
        token_id INT NOT NULL,
        order_number VARCHAR(100) NOT NULL UNIQUE,
        status VARCHAR(50) NOT NULL DEFAULT 'DRAFT',
        subtotal_cents INT NOT NULL DEFAULT 0,
        margin_cents INT NOT NULL DEFAULT 0,
        total_cents INT NOT NULL DEFAULT 0,
        guest_notes TEXT,
        admin_notes TEXT,
        submitted_at VARCHAR(30),
        approved_at VARCHAR(30),
        purchased_at VARCHAR(30),
        delivered_at VARCHAR(30),
        paid_at VARCHAR(30),
        cancelled_at VARCHAR(30),
        created_at VARCHAR(30) NOT NULL,
        updated_at VARCHAR(30) NOT NULL,
        FOREIGN KEY (booking_id) REFERENCES booking_requests(id) ON DELETE RESTRICT,
        FOREIGN KEY (token_id) REFERENCES shop_access_tokens(id) ON DELETE RESTRICT,
        INDEX idx_shop_orders_booking (booking_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS shop_order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        product_id INT,
        product_name_snapshot VARCHAR(255) NOT NULL,
        quantity INT NOT NULL DEFAULT 1,
        unit_price_cents INT NOT NULL DEFAULT 0,
        total_price_cents INT NOT NULL DEFAULT 0,
        is_purchased TINYINT NOT NULL DEFAULT 0,
        notes TEXT,
        FOREIGN KEY (order_id) REFERENCES shop_orders(id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES shop_products(id) ON DELETE SET NULL,
        INDEX idx_shop_order_items_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS shop_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_at VARCHAR(30) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    INSERT IGNORE INTO shop_settings (setting_key, setting_value, updated_at)
    VALUES ('global_margin_percent', '10.00', NOW());

    INSERT IGNORE INTO shop_settings (setting_key, setting_value, updated_at)
    VALUES ('schema_version', '1', NOW());
    ";
    $pdo->exec($sql);
}
