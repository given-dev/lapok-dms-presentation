<?php
declare(strict_types=1);

/**
 * Reset all demo user passwords to password123
 * Run (local only): php scripts/setup_passwords.php --confirm-local-reset
 */
require_once dirname(__DIR__) . '/config/database.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script can only run from CLI.\n");
    exit(1);
}

$isLocalEnv = in_array(strtolower((string) getenv('APP_ENV')), ['local', 'development', 'dev'], true)
    || in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
if (!$isLocalEnv) {
    fwrite(STDERR, "Blocked: demo password reset is allowed only in local/dev environments.\n");
    exit(1);
}

$confirm = $argv[1] ?? '';
if ($confirm !== '--confirm-local-reset') {
    fwrite(STDERR, "Refusing to run without explicit confirmation flag: --confirm-local-reset\n");
    exit(1);
}

$hash = password_hash('password123', PASSWORD_BCRYPT);
$stmt = db()->prepare('UPDATE users SET password_hash = ?');
$stmt->execute([$hash]);
$count = $stmt->rowCount();

echo "Updated {$count} user passwords to demo default in local/dev.\n";
echo "Hash: {$hash}\n";
