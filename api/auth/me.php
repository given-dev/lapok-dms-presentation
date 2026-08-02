<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$user = require_login();
json_ok(['user' => $user]);
