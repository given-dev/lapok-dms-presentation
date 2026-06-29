<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

class AuthException extends RuntimeException
{
    public function __construct(
        string $message,
        public int $httpStatus = 401
    ) {
        parent::__construct($message);
    }
}

function json_success(mixed $data = null, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'data'    => $data,
        'error'   => null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_fail(string $message, int $status = 400): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'data'    => null,
        'error'   => $message,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** @deprecated Use json_success/json_fail */
function json_response(mixed $data, int $status = 200): never
{
    json_success($data, $status);
}

/** @deprecated Use json_fail */
function json_error(string $message, int $status = 400): never
{
    json_fail($message, $status);
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function sanitize_string(?string $value): string
{
    return trim((string) $value);
}

function role_label(string $role): string
{
    return match ($role) {
        'admin'      => 'Admin',
        'manager'    => 'Manager',
        'accountant' => 'Accountant',
        'driver'     => 'Driver',
        'cadet'      => 'Cadet',
        'field_user' => 'Field User',
        default      => ucfirst(str_replace('_', ' ', $role)),
    };
}

function detect_session_path(): string
{
    $configured = env('SESSION_PATH');
    if ($configured !== null && $configured !== '') {
        return $configured;
    }

    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if (str_contains($script, '/public/')) {
        return substr($script, 0, (int) strpos($script, '/public/') + 1);
    }

    return '/';
}
