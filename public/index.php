<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

start_session();

$user = current_user();
if ($user === null) {
    header('Location: index.html');
    exit;
}

header('Location: app.html');
exit;
