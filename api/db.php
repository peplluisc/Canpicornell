<?php
require_once __DIR__ . '/env_helper.php';
require_once __DIR__ . '/db_migrations.php';

function get_db_connection() {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $driver = get_env_var('DB_DRIVER', 'sqlite');

    try {
        if ($driver === 'sqlite') {
            $db_path = get_env_var('DB_PATH', 'api/database.sqlite');
            // If relative path, prepend root dir
            if (strpos($db_path, '/') !== 0 && strpos($db_path, '\\') !== 1) {
                $db_absolute_path = __DIR__ . '/../' . $db_path;
            } else {
                $db_absolute_path = $db_path;
            }

            // Ensure parent directory exists
            $parent_dir = dirname($db_absolute_path);
            if (!is_dir($parent_dir)) {
                mkdir($parent_dir, 0755, true);
            }

            $pdo = new PDO("sqlite:" . $db_absolute_path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            // Execute versioned migrations and foreign keys enforcement
            run_db_migrations($pdo, 'sqlite');

        } else if ($driver === 'mysql') {
            $host = get_env_var('MYSQL_HOST', 'localhost');
            $port = get_env_var('MYSQL_PORT', '3306');
            $dbname = get_env_var('MYSQL_DATABASE', 'canpicornell_db');
            $user = get_env_var('MYSQL_USER', 'db_user');
            $pass = get_env_var('MYSQL_PASSWORD', 'db_password');

            $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            // Execute versioned migrations for MySQL
            run_db_migrations($pdo, 'mysql');
        } else {
            throw new Exception("Unsupported database driver: " . $driver);
        }
    } catch (Exception $e) {
        // Log detailed error for admin diagnostics
        error_log("Database connection error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(["error" => "Database connection error: " . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    return $pdo;
}
?>
