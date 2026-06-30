<?php
declare(strict_types=1);

require_once __DIR__ . '/response.php';

const EXECUTIVE_SESSION_IDLE_TIMEOUT = 1800; // 30 minutes
const DEFAULT_SESSION_IDLE_TIMEOUT = 28800; // 8 hours

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        json_error('Authentication required', 401);
    }
    $lastSeen = (int) ($_SESSION['last_seen_at'] ?? 0);
    $timeout = ($user['role'] ?? '') === 'executive'
        ? EXECUTIVE_SESSION_IDLE_TIMEOUT
        : DEFAULT_SESSION_IDLE_TIMEOUT;
    if ($lastSeen > 0 && (time() - $lastSeen) > $timeout) {
        logout_user();
        json_error('Session expired. Please sign in again.', 401);
    }
    $_SESSION['last_seen_at'] = time();
    return $user;
}

function require_roles(array $roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        json_error('Insufficient permissions', 403);
    }
    return $user;
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'full_name' => $user['full_name'],
        'email' => $user['email'],
        'role' => $user['role'],
        'vehicle_id' => $user['vehicle_id'] ? (int) $user['vehicle_id'] : null,
        'default_route' => $user['default_route'],
    ];
    $_SESSION['last_seen_at'] = time();
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function audit_log(?int $userId, string $table, ?int $recordId, string $action, ?array $old = null, ?array $new = null): void
{
    $stmt = db()->prepare(
        'INSERT INTO audit_log (user_id, table_name, record_id, action, old_values, new_values)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $userId,
        $table,
        $recordId,
        $action,
        $old ? json_encode($old) : null,
        $new ? json_encode($new) : null,
    ]);
}

function generate_order_ref(): string
{
    return 'RCP-' . date('md') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
}

function password_meets_policy(string $password): bool
{
    if (strlen($password) < 10) {
        return false;
    }
    $hasUpper = preg_match('/[A-Z]/', $password) === 1;
    $hasLower = preg_match('/[a-z]/', $password) === 1;
    $hasDigit = preg_match('/\d/', $password) === 1;
    return $hasUpper && $hasLower && $hasDigit;
}
