<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/response.php';
require_once dirname(__DIR__) . '/includes/auth.php';

cors_headers();
handle_preflight();
