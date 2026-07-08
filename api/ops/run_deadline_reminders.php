<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/deadline_reminders.php';

$user = require_login();

try {
    $run = deadline_reminders_run();
    $status = deadline_status_for_user($user);
} catch (Throwable $e) {
    json_error('Deadline reminders unavailable: ' . $e->getMessage(), 500);
}

json_ok([
    'run' => $run,
    'status' => $status,
]);
