<?php
declare(strict_types=1);

/**
 * Load key=value pairs from config/.env into $_ENV.
 */
function load_env(string $path): void
{
    if (!is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        $value = trim($value, " \t\"'");

        if ($key === '') {
            continue;
        }

        // Prefer project-local .env values over machine-level environment variables.
        $_ENV[$key] = $value;
        putenv("$key=$value");
    }
}

function env(string $key, ?string $default = null): ?string
{
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

load_env(dirname(__DIR__) . '/config/.env');
