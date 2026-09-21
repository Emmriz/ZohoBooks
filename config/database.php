<?php
// ============================================================
//  ZOHOBOOKS - Database Configuration
//  Edit these settings to match your server
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'zohobooks');
define('DB_USER', 'root');        // Change to your MySQL username
define('DB_PASS', '');            // Change to your MySQL password
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'ZohoBooks');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'http://localhost/zohobooks');
define('APP_CURRENCY', 'NGN');
define('APP_CURRENCY_SYMBOL', '₦');
define('APP_DATE_FORMAT', 'd M Y');
define('SESSION_TIMEOUT', 7200); // 2 hours

// ─── PDO Connection ─────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            // $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $dsn = "mysql:host=" . DB_HOST . ";port=3307;dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}
