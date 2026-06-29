<?php
declare(strict_types=1);

/**
 * Phase 1 smoke test — run: php scripts/test_connection.php
 */
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';

echo "LAPOK DMS — Connection & auth test\n";
echo str_repeat('-', 40) . "\n";

try {
    $pdo = db();
    echo "[OK] Database connected\n";

    $tables = ['users', 'products', 'batches', 'orders', 'customers', 'vehicles', 'audit_log'];
    foreach ($tables as $t) {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
        echo "[OK] Table {$t}: {$count} rows\n";
    }

    $admin = $pdo->query("SELECT email, password_hash, role FROM users WHERE role = 'admin' LIMIT 1")->fetch();
    if ($admin && password_verify('password123', $admin['password_hash'])) {
        echo "[OK] Admin password verifies (password123)\n";
    } else {
        echo "[WARN] Run: php scripts/setup_passwords.php to reset demo passwords\n";
    }

    echo "\nAll Phase 1 checks passed.\n";
    exit(0);
} catch (Throwable $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    echo "\nEnsure MySQL is running and you have imported:\n";
    echo "  mysql -u root -p < database/schema.sql\n";
    echo "  mysql -u root -p < database/seed.sql\n";
    exit(1);
}
