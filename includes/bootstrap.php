<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/response.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/permissions.php';

if (str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), '/api/')) {
    ini_set('display_errors', '0');
}

cors_headers();
handle_preflight();
