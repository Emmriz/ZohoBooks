<?php
// ============================================================
//  ZOHOBOOKS - Database Configuration — EXAMPLE
//  Copy this file to "database.php" in the same folder and
//  fill in your real values. "database.php" is gitignored so
//  your production credentials never get committed.
// ============================================================

define('APP_ENV', 'production'); // 'development' shows detailed errors; NEVER use in production

define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
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
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            $message = (defined('APP_ENV') && APP_ENV === 'development')
                ? 'Database connection failed: ' . $e->getMessage()
                : 'A database error occurred. Please try again later.';
            http_response_code(500);
            die(json_encode(['error' => $message]));
        }
    }
    return $pdo;
}
