<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/fleet_tracking.php';

require_roles(['admin', 'manager']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    json_error('Method not allowed', 405);
}

try {
    json_ok([
        'depot' => fleet_depot_coords(),
        'fleet' => fleet_active_tracking_payload(),
        'refreshed_at' => date('c'),
    ]);
} catch (Throwable $e) {
    json_error('Could not load fleet map  -  run migration 004_fleet_tracking.sql if tables are missing.', 500);
}
