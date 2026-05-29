<?php
require_once __DIR__ . '/../config/database.php';

function startSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        session_start();
    }
}

function regenerateSession() {
    startSession();
    if (!headers_sent()) {
        session_regenerate_id(true);
    }
}

function isLoggedIn() {
    startSession();
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function isLoginLockedOut() {
    startSession();
    $attempts = $_SESSION['login_attempts'] ?? 0;
    $lastAttempt = $_SESSION['last_login_attempt'] ?? 0;
    if ($attempts >= 5 && (time() - $lastAttempt) < 900) {
        return true;
    }
    if ((time() - $lastAttempt) >= 900) {
        unset($_SESSION['login_attempts'], $_SESSION['last_login_attempt']);
    }
    return false;
}

function incrementLoginAttempts() {
    startSession();
    $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
    $_SESSION['last_login_attempt'] = time();
}

function resetLoginAttempts() {
    startSession();
    unset($_SESSION['login_attempts'], $_SESSION['last_login_attempt']);
}

function requireLogin($redirect = '../index.php') {
    if (!isLoggedIn()) {
        header("Location: $redirect");
        exit;
    }
}

function requireRole($roles, $redirect = '../index.php') {
    requireLogin($redirect);
    startSession();
    $userRole = $_SESSION['user_role'] ?? '';
    if (!in_array($userRole, (array)$roles)) {
        header("Location: $redirect");
        exit;
    }
}

function getCurrentUser() {
    startSession();
    return [
        'id'         => $_SESSION['user_id'] ?? null,
        'name'       => $_SESSION['user_name'] ?? '',
        'email'      => $_SESSION['user_email'] ?? '',
        'role'       => $_SESSION['user_role'] ?? '',
        'first_name' => $_SESSION['first_name'] ?? '',
        'last_name'  => $_SESSION['last_name'] ?? '',
    ];
}

function logout() {
    startSession();
    $userId = $_SESSION['user_id'] ?? null;
    if ($userId) {
        logAudit($userId, 'User logout', 'LOGOUT', 'users', $userId, null, null, 'INFO', 'User logged out');
    }
    session_destroy();
    header('Location: login.php');
    exit;
}

function logAudit($userId, $action, $actionType, $affectedTable = null, $recordId = null, $oldValues = null, $newValues = null, $severity = 'INFO', $notes = null) {
    $db = getDB();
    $user = getCurrentUser();
    $userName = $user['name'] ?: 'System';
    $userRole = $user['role'] ?: 'system';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $auditId = 'A-' . strtoupper(substr(uniqid(), -6));
    $oldJson = $oldValues ? json_encode($oldValues) : null;
    $newJson = $newValues ? json_encode($newValues) : null;

    $stmt = $db->prepare("INSERT INTO audit_logs (audit_id, user_id, user_name, user_role, action, action_type, severity, affected_table, record_id, old_values, new_values, ip_address, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('sissssssissss', $auditId, $userId, $userName, $userRole, $action, $actionType, $severity, $affectedTable, $recordId, $oldJson, $newJson, $ip, $notes);
    $stmt->execute();
}

function getNotificationCount($userId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result['cnt'] ?? 0;
}

function formatCurrency($amount) {
    return '₱' . number_format($amount, 2);
}

function formatDate($date) {
    if (!$date) return '—';
    return date('M d, Y', strtotime($date));
}

function formatDateTime($dt) {
    if (!$dt) return '—';
    return date('M d, Y h:i A', strtotime($dt));
}

function getStockStatus($qty, $reorderLevel) {
    if ($qty <= 0) return ['label' => 'Out of Stock', 'class' => 'danger'];
    if ($qty <= $reorderLevel) return ['label' => 'Low Stock', 'class' => 'warning'];
    return ['label' => 'In Stock', 'class' => 'success'];
}

function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}
?>
