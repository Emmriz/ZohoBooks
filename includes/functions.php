<?php
// ============================================================
//  ZOHOBOOKS - Core Functions v2
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/mailer.php';
session_start();

// ─── Auth ────────────────────────────────────────────────────
function isLoggedIn(): bool {
    return isset($_SESSION['user_id'], $_SESSION['last_activity'])
        && (time() - $_SESSION['last_activity'] < SESSION_TIMEOUT);
}

function requireLogin(): void {
    if (!isLoggedIn()) { $_SESSION = []; session_destroy();
        header('Location: ' . APP_URL . '/login.php'); exit; }
    $_SESSION['last_activity'] = time();
}

function currentUser(): array { return $_SESSION['user'] ?? []; }

function hasPermission(string $module): bool {
    $user  = currentUser();
    if (empty($user)) return false;
    $perms = $user['permissions'] ?? [];
    if (is_string($perms)) $perms = json_decode($perms, true);
    return !empty($perms['all']) || !empty($perms[$module]);
}

function login(string $email, string $password): array {
    $db   = getDB();
    $stmt = $db->prepare("SELECT u.*, r.name as role_name, r.permissions
                          FROM users u JOIN roles r ON u.role_id=r.id
                          WHERE u.email=? AND u.status='active'");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password']))
        return ['success' => false, 'message' => 'Invalid email or password'];
    $db->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$user['id']]);
    $_SESSION['user_id']       = $user['id'];
    $_SESSION['user']          = $user;
    $_SESSION['last_activity'] = time();
    return ['success' => true, 'user' => $user];
}

function logout(): void {
    $_SESSION = []; session_destroy();
    header('Location: ' . APP_URL . '/login.php'); exit;
}

// ─── App Settings ─────────────────────────────────────────────
function getSetting(string $key, string $default = ''): string {
    static $cache = null;
    if ($cache === null) {
        try {
            $rows  = getDB()->query("SELECT setting_key, setting_value FROM app_settings")->fetchAll();
            $cache = array_column($rows, 'setting_value', 'setting_key');
        } catch (Exception $e) { $cache = []; }
    }
    return $cache[$key] ?? $default;
}

function saveSetting(string $key, string $value): void {
    getDB()->prepare("INSERT INTO app_settings (setting_key, setting_value)
                      VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")
           ->execute([$key, $value, $value]);
}

// ─── Theme ────────────────────────────────────────────────────
function getTheme(): array {
    $color  = getSetting('brand_color', 'blue');
    $themes = [
        'blue'   => ['primary'=>'#2563eb','hover'=>'#1d4ed8','light'=>'#dbeafe','sidebar'=>'#0f172a','accent'=>'#3b82f6','name'=>'Ocean Blue'],
        'green'  => ['primary'=>'#16a34a','hover'=>'#15803d','light'=>'#dcfce7','sidebar'=>'#052e16','accent'=>'#22c55e','name'=>'Forest Green'],
        'purple' => ['primary'=>'#7c3aed','hover'=>'#6d28d9','light'=>'#ede9fe','sidebar'=>'#2e1065','accent'=>'#8b5cf6','name'=>'Royal Purple'],
        'red'    => ['primary'=>'#dc2626','hover'=>'#b91c1c','light'=>'#fee2e2','sidebar'=>'#1c0606','accent'=>'#ef4444','name'=>'Crimson Red'],
        'orange' => ['primary'=>'#ea580c','hover'=>'#c2410c','light'=>'#ffedd5','sidebar'=>'#431407','accent'=>'#f97316','name'=>'Sunset Orange'],
        'teal'   => ['primary'=>'#0d9488','hover'=>'#0f766e','light'=>'#ccfbf1','sidebar'=>'#042f2e','accent'=>'#14b8a6','name'=>'Teal'],
        'rose'   => ['primary'=>'#e11d48','hover'=>'#be123c','light'=>'#ffe4e6','sidebar'=>'#1c0010','accent'=>'#f43f5e','name'=>'Rose'],
        'slate'  => ['primary'=>'#475569','hover'=>'#334155','light'=>'#f1f5f9','sidebar'=>'#020617','accent'=>'#64748b','name'=>'Slate Gray'],
        'olive'  => ['primary'=>'#4C5135','hover'=>'#3a3e27','light'=>'#F4EDE3','sidebar'=>'#2b2e1c','accent'=>'#6b7048','name'=>'Olive & Cream'],
    ];
    return $themes[$color] ?? $themes['blue'];
}

// ─── Helpers ──────────────────────────────────────────────────
function formatCurrency(float $amount): string {
    return APP_CURRENCY_SYMBOL . number_format($amount, 2);
}
function formatDate(string $date): string {
    return $date ? date(APP_DATE_FORMAT, strtotime($date)) : '-';
}
function generateNumber(string $prefix, string $table): string {
    $cnt = (int)getDB()->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    return $prefix . '-' . date('Y') . '-' . str_pad($cnt + 1, 5, '0', STR_PAD_LEFT);
}
function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code); header('Content-Type: application/json');
    echo json_encode($data); exit;
}
function auditLog(string $action, string $module, int $id = 0, array $old = [], array $new = []): void {
    try {
        getDB()->prepare("INSERT INTO audit_log (user_id,action,module,record_id,old_data,new_data,ip_address) VALUES (?,?,?,?,?,?,?)")
               ->execute([$_SESSION['user_id']??null,$action,$module,$id,json_encode($old),json_encode($new),$_SERVER['REMOTE_ADDR']??null]);
    } catch (Exception $e) {}
}
function clean(mixed $v): string { return htmlspecialchars(trim((string)$v), ENT_QUOTES, 'UTF-8'); }
function post(string $k, mixed $d = ''): mixed { return $_POST[$k] ?? $d; }
function get(string $k,  mixed $d = ''): mixed  { return $_GET[$k]  ?? $d; }
function paginate(int $total, int $page, int $pp = 20): array {
    $totalPages = max(1, ceil($total / $pp));
    $page       = max(1, min($page, $totalPages));
    return ['total'=>$total,'page'=>$page,'totalPages'=>$totalPages,'perPage'=>$pp,'offset'=>($page-1)*$pp];
}
// ─── Staff Leave Actions (with email notifications) ────────────
function activateStaffLeave(int $staffId, string $start, string $end, string $reason, int $createdBy): void {
    $db = getDB();
    $db->prepare("UPDATE staff_leaves SET status='cancelled' WHERE staff_id=? AND status='active'")->execute([$staffId]);
    $db->prepare("INSERT INTO staff_leaves (staff_id, leave_start, leave_end, leave_reason, status, created_by) VALUES (?,?,?,?,'active',?)")
       ->execute([$staffId, $start, $end, $reason, $createdBy]);
    $db->prepare("UPDATE staff SET status='on_leave' WHERE id=?")->execute([$staffId]);

    $stmt = $db->prepare("SELECT * FROM staff WHERE id=?"); $stmt->execute([$staffId]); $staff = $stmt->fetch();
    if ($staff) notifyLeaveActivated($staff, $start, $end, $reason, $_SESSION['user'] ?? null);
}

function revokeStaffLeave(int $staffId): void {
    $db = getDB();
    $leaveStmt = $db->prepare("SELECT * FROM staff_leaves WHERE staff_id=? AND status='active' ORDER BY id DESC LIMIT 1");
    $leaveStmt->execute([$staffId]); $leave = $leaveStmt->fetch();

    $db->prepare("UPDATE staff_leaves SET status='cancelled' WHERE staff_id=? AND status='active'")->execute([$staffId]);
    $db->prepare("UPDATE staff SET status='active' WHERE id=?")->execute([$staffId]);

    $stmt = $db->prepare("SELECT * FROM staff WHERE id=?"); $stmt->execute([$staffId]); $staff = $stmt->fetch();
    if ($staff && $leave) notifyLeaveRevoked($staff, $leave['leave_start'], $leave['leave_end'], $_SESSION['user'] ?? null);
}

function statusBadge(string $status): string {
    $map = [
        'draft'=>'bg-gray-100 text-gray-600','sent'=>'bg-blue-100 text-blue-700',
        'paid'=>'bg-green-100 text-green-700','partially_paid'=>'bg-yellow-100 text-yellow-700',
        'overdue'=>'bg-red-100 text-red-700','cancelled'=>'bg-gray-200 text-gray-500',
        'void'=>'bg-gray-200 text-gray-400','active'=>'bg-green-100 text-green-700',
        'inactive'=>'bg-gray-100 text-gray-500','pending'=>'bg-yellow-100 text-yellow-700',
        'approved'=>'bg-green-100 text-green-700','suspended'=>'bg-red-100 text-red-700',
        'published'=>'bg-blue-100 text-blue-700','terminated'=>'bg-red-100 text-red-700',
        'on_leave'=>'bg-orange-100 text-orange-700',
    ];
    $cls = $map[$status] ?? 'bg-gray-100 text-gray-600';
    return "<span class=\"inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium $cls\">"
         . ucwords(str_replace('_',' ',$status)) . "</span>";
}
