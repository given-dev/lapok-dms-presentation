<?php
declare(strict_types=1);

/**
 * Role-based access matrix (Phase 4)
 */
const ROLE_PERMISSIONS = [
    'admin' => ['*'],
    'executive' => [
        'dashboard', 'stock_view', 'reports', 'reports_financial',
        'reports_sales', 'customers_balance', 'audit_view',
    ],
    'manager' => [
        'dashboard', 'stock', 'stock_view', 'delivery', 'dispatch', 'orders',
        'orders_confirm', 'edit_requests', 'customers', 'customers_write',
        'routes', 'routes_write', 'vehicles', 'vehicles_write', 'reports',
        'reports_sales', 'users_view', 'rdc_view',
    ],
    'accountant' => [
        'dashboard', 'stock_view', 'reports', 'reports_financial',
        'reports_sales', 'cash_confirm', 'customers_balance', 'rdc_balancing',
    ],
    'cadet' => [
        'dashboard_own', 'orders_own', 'orders_create', 'edit_requests_own',
        'customers_route', 'route_own', 'eod', 'receipt',
    ],
    'driver' => [
        'dashboard_own', 'route_own', 'eod',
    ],
    'field_user' => [
        'dashboard_own', 'orders_own', 'orders_create', 'edit_requests_own',
        'customers_route', 'route_own', 'eod', 'receipt',
    ],
];

function role_can(string $role, string $permission): bool
{
    $perms = ROLE_PERMISSIONS[$role] ?? [];
    return in_array('*', $perms, true) || in_array($permission, $perms, true);
}

function require_permission(string $permission): array
{
    $user = require_login();
    if (!role_can($user['role'], $permission)) {
        json_error('Insufficient permissions', 403);
    }
    return $user;
}

function is_field_role(string $role): bool
{
    return in_array($role, ['field_user', 'cadet', 'driver'], true);
}

/** Resolve route id for a field user from active trip or default_route name */
function user_route_id(int $userId, ?string $defaultRoute): ?int
{
    $stmt = db()->prepare(
        "SELECT route_id FROM delivery_trips
         WHERE (cadet_id = ? OR driver_id = ?) AND status IN ('dispatched','on_route')
         ORDER BY dispatched_at DESC LIMIT 1"
    );
    $stmt->execute([$userId, $userId]);
    $trip = $stmt->fetch();
    if ($trip && $trip['route_id']) {
        return (int) $trip['route_id'];
    }
    if ($defaultRoute) {
        $r = db()->prepare('SELECT id FROM routes WHERE name = ? AND is_active = 1 LIMIT 1');
        $r->execute([$defaultRoute]);
        $row = $r->fetch();
        if ($row) {
            return (int) $row['id'];
        }
    }
    return null;
}
