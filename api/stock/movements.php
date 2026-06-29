<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_login();

$productId = (int) ($_GET['product_id'] ?? 0);
$limit = min(200, max(10, (int) ($_GET['limit'] ?? 50)));

$sql = 'SELECT sm.*, p.name AS product_name, u.full_name AS user_name
        FROM stock_movements sm
        JOIN products p ON p.id = sm.product_id
        LEFT JOIN users u ON u.id = sm.user_id
        WHERE 1=1';
$params = [];

if ($productId > 0) {
    $sql .= ' AND sm.product_id = ?';
    $params[] = $productId;
}

$sql .= ' ORDER BY sm.created_at DESC LIMIT ' . $limit;

$stmt = db()->prepare($sql);
$stmt->execute($params);
$movements = $stmt->fetchAll();

json_ok(['movements' => $movements]);
