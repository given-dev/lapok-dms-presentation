<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$user = require_roles(['admin', 'manager']);

$stmt = db()->query(
    "SELECT er.*, o.order_ref, u.full_name AS user_name
     FROM edit_requests er
     JOIN orders o ON o.id = er.order_id
     JOIN users u ON u.id = er.user_id
     WHERE er.status = 'pending'
     ORDER BY er.created_at DESC"
);

json_ok(['requests' => $stmt->fetchAll()]);
