<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/cadet_reports.php';

$user = require_login();
if (!in_array($user['role'], ['cadet', 'field_user'], true)) {
    json_error('Cadet access only', 403);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT dt.*, v.registration, v.vehicle_type, r.name AS route_name
     FROM delivery_trips dt
     JOIN vehicles v ON v.id = dt.vehicle_id
     LEFT JOIN routes r ON r.id = dt.route_id
     WHERE (dt.cadet_id = ? OR dt.driver_id = ?)
       AND dt.status IN ('dispatched','on_route')
       AND DATE(dt.dispatched_at) = ?
     ORDER BY dt.dispatched_at DESC
     LIMIT 1"
);
$stmt->execute([(int) $user['id'], (int) $user['id'], cadet_today_date()]);
$trip = $stmt->fetch();
if (!$trip) {
    json_error('No dispatch to confirm. Ask the manager to dispatch your vehicle first.');
}

$tripId = (int) $trip['id'];
if (($trip['status'] ?? '') === 'dispatched') {
    $pdo->prepare(
        "UPDATE delivery_trips SET status = 'on_route', acknowledged_at = NOW() WHERE id = ?"
    )->execute([$tripId]);

    audit_log((int) $user['id'], 'delivery_trips', $tripId, 'confirm_receive', [
        'status' => 'dispatched', 'acknowledged_at' => null,
    ], [
        'status' => 'on_route', 'acknowledged_at' => date('c'),
    ]);

    try {
        require_once dirname(__DIR__, 2) . '/includes/notifications.php';
        $bodyText = sprintf(
            '%s confirmed load on %s (%s). Trip #%d is now on route.',
            $user['full_name'],
            $trip['registration'],
            $trip['route_name'] ?? ($trip['route_area'] ?: 'route'),
            $tripId
        );
        $stmt = $pdo->query(
            "SELECT id FROM users WHERE role IN ('manager','admin') AND is_active = 1"
        );
        foreach ($stmt->fetchAll() as $nRow) {
            notify_user((int) $nRow['id'], 'Dispatch confirmed', $bodyText, [
                'sender_id' => (int) $user['id'],
                'sender_role' => $user['role'],
                'severity' => 'info',
                'link_page' => 'manager-stock',
            ]);
        }
    } catch (Throwable) {
    }
}

json_ok([
    'trip_id' => $tripId,
    'status' => 'on_route',
    'acknowledged_at' => $trip['acknowledged_at'] ?? date('c'),
    'message' => 'Load confirmed. Trip is now on route.',
]);
