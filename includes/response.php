<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

function json_response(bool $success, mixed $data = null, ?string $error = null, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'error' => $error,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_ok(mixed $data = null, int $code = 200): void
{
    json_response(true, $data, null, $code);
}

function json_error(string $error, int $code = 400): void
{
    json_response(false, null, $error, $code);
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return $_POST ?: [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function cors_headers(): void
{
    $origin = env('CORS_ORIGIN', '*');
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
}

function handle_preflight(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        cors_headers();
        http_response_code(204);
        exit;
    }
}
