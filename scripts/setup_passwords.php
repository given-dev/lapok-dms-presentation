<?php
declare(strict_types=1);

/**
 * Reset all demo user passwords to password123
 * Run: php scripts/setup_passwords.php
 */
require_once dirname(__DIR__) . '/config/database.php';

$hash = password_hash('password123', PASSWORD_BCRYPT);
$stmt = db()->prepare('UPDATE users SET password_hash = ?');
$stmt->execute([$hash]);
$count = $stmt->rowCount();

echo "Updated {$count} user passwords to: password123\n";
echo "Hash: {$hash}\n";
