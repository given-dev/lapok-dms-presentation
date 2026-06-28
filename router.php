<?php
declare(strict_types=1);

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

if ($uri === '/' || $uri === '') {
    header('Location: /login.html');
    exit;
}

if (preg_match('#^/api/.+\.php$#', $uri)) {
    $apiFile = __DIR__ . '/public' . $uri;
    if (is_file($apiFile)) {
        require $apiFile;
        return true;
    }
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'API endpoint not found']);
    return true;
}

$static = __DIR__ . '/public' . $uri;
if ($uri !== '/' && is_file($static)) {
    return false;
}

http_response_code(404);
echo 'Not found';
return true;
