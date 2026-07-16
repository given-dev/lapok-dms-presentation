<?php
declare(strict_types=1);

/**
 * Phase 4 smoke test  -  run: php scripts/test_phase4.php
 */
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/permissions.php';

echo "LAPOK DMS  -  Phase 4 tests\n";
echo str_repeat('-', 40) . "\n";

try {
    $pdo = db();

    $routes = (int) $pdo->query('SELECT COUNT(*) FROM routes')->fetchColumn();
    $stops = (int) $pdo->query('SELECT COUNT(*) FROM route_stops')->fetchColumn();
    echo "[OK] Routes: {$routes}, stops: {$stops}\n";

    $customers = (int) $pdo->query('SELECT COUNT(*) FROM customers WHERE is_active = 1')->fetchColumn();
    echo "[OK] Active customers: {$customers}\n";

    $roles = $pdo->query("SELECT role, COUNT(*) c FROM users GROUP BY role")->fetchAll();
    foreach ($roles as $r) {
        echo "[OK] Role {$r['role']}: {$r['c']} users\n";
    }

    $audit = (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
    echo "[OK] Audit log entries: {$audit}\n";

    $returned = (int) $pdo->query("SELECT COUNT(*) FROM delivery_trips WHERE status = 'returned'")->fetchColumn();
    echo "[OK] Trips awaiting cash confirm: {$returned}\n";

    assert(role_can('admin', 'audit') || role_can('admin', '*'));
    assert(role_can('cadet', 'route_own'));
    assert(!role_can('cadet', 'customers'));
    echo "[OK] Permission matrix checks passed\n";

    echo "\nPhase 4 checks passed.\n";
    exit(0);
} catch (Throwable $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    exit(1);
}
