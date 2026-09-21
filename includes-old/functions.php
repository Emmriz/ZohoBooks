<?php
// ============================================================
//  ZOHOBOOKS - Core Functions & Authentication
// ============================================================

require_once __DIR__ . '/../config/database.php';

session_start();

// ─── Authentication ─────────────────────────────────────────
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && isset($_SESSION['last_activity'])
        && (time() - $_SESSION['last_activity'] < SESSION_TIMEOUT);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        $_SESSION = [];
        session_destroy();
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

function currentUser(): array {
    return $_SESSION['user'] ?? [];
}

function hasPermission(string $module): bool {
    $user = currentUser();
    if (empty($user)) return false;
    $perms = $user['permissions'] ?? [];
    if (is_string($perms)) $perms = json_decode($perms, true);
    return !empty($perms['all']) || !empty($perms[$module]);
}

function login(string $email, string $password): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT u.*, r.name as role_name, r.permissions
        FROM users u JOIN roles r ON u.role_id = r.id
        WHERE u.email = ? AND u.status = 'active'
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        return ['success' => false, 'message' => 'Invalid email or password'];
    }

    $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user'] = $user;
    $_SESSION['last_activity'] = time();

    return ['success' => true, 'user' => $user];
}

function logout(): void {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

// ─── Number & Currency Helpers ───────────────────────────────
function formatCurrency(float $amount, string $symbol = null): string {
    $sym = $symbol ?? APP_CURRENCY_SYMBOL;
    return $sym . number_format($amount, 2);
}

function formatDate(string $date): string {
    return $date ? date(APP_DATE_FORMAT, strtotime($date)) : '-';
}

// ─── Auto-number Generator ───────────────────────────────────
function generateNumber(string $prefix, string $table, string $column): string {
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM $table");
    $stmt->execute();
    $row = $stmt->fetch();
    $num = str_pad($row['cnt'] + 1, 5, '0', STR_PAD_LEFT);
    return $prefix . '-' . date('Y') . '-' . $num;
}

// ─── JSON Response Helper ────────────────────────────────────
function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ─── Audit Logger ────────────────────────────────────────────
function auditLog(string $action, string $module, int $recordId = 0, array $old = [], array $new = []): void {
    try {
        $db = getDB();
        $userId = $_SESSION['user_id'] ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $db->prepare("INSERT INTO audit_log (user_id, action, module, record_id, old_data, new_data, ip_address) VALUES (?,?,?,?,?,?,?)")
           ->execute([$userId, $action, $module, $recordId, json_encode($old), json_encode($new), $ip]);
    } catch (Exception $e) { /* silent */ }
}

// ─── Sanitize Input ──────────────────────────────────────────
function clean(mixed $val): string {
    return htmlspecialchars(trim((string)$val), ENT_QUOTES, 'UTF-8');
}

function post(string $key, mixed $default = ''): mixed {
    return $_POST[$key] ?? $default;
}

function get(string $key, mixed $default = ''): mixed {
    return $_GET[$key] ?? $default;
}

// ─── Pagination ──────────────────────────────────────────────
function paginate(int $total, int $page, int $perPage = 20): array {
    $totalPages = max(1, ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * $perPage;
    return compact('total', 'page', 'totalPages', 'perPage', 'offset');
}

// ─── Status Badge HTML ───────────────────────────────────────
function statusBadge(string $status): string {
    $map = [
        'draft'          => 'bg-gray-100 text-gray-600',
        'sent'           => 'bg-blue-100 text-blue-700',
        'paid'           => 'bg-green-100 text-green-700',
        'partially_paid' => 'bg-yellow-100 text-yellow-700',
        'overdue'        => 'bg-red-100 text-red-700',
        'cancelled'      => 'bg-gray-200 text-gray-500',
        'void'           => 'bg-gray-200 text-gray-400',
        'active'         => 'bg-green-100 text-green-700',
        'inactive'       => 'bg-gray-100 text-gray-500',
        'pending'        => 'bg-yellow-100 text-yellow-700',
        'approved'       => 'bg-green-100 text-green-700',
        'suspended'      => 'bg-red-100 text-red-700',
        'published'      => 'bg-blue-100 text-blue-700',
        'terminated'     => 'bg-red-100 text-red-700',
        'on_leave'       => 'bg-orange-100 text-orange-700',
    ];
    $cls = $map[$status] ?? 'bg-gray-100 text-gray-600';
    $label = ucwords(str_replace('_', ' ', $status));
    return "<span class=\"inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium $cls\">$label</span>";
}
