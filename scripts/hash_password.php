<?php
declare(strict_types=1);

/**
 * Regenerate password hashes in seed data.
 * Usage: php scripts/hash_password.php [password]
 */

$password = $argv[1] ?? 'lapok123';
echo password_hash($password, PASSWORD_BCRYPT) . PHP_EOL;
