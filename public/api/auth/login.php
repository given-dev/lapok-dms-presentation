<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('Method not allowed', 405);
}

$body = read_json_body();
$email = sanitize_string($body['email'] ?? $_POST['email'] ?? '');
$password = (string) ($body['password'] ?? $_POST['password'] ?? '');

if ($email === '' || $password === '') {
    json_fail('Email and password are required', 422);
}

try {
    $user = login_user($email, $password);
    json_success(['user' => $user]);
} catch (AuthException $e) {
    json_fail($e->getMessage(), $e->httpStatus);
}
