<?php
declare(strict_types=1);

require_once __DIR__ . '/response.php';

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
