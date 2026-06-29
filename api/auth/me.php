<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$user = current_user();
if (!$user) {
    json_error('Not authenticated', 401);
}

json_ok(['user' => $user]);
