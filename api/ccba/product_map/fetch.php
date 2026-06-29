<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/bootstrap.php';

require_roles(['admin', 'manager']);

$rows = db()->query(
    'SELECT p.id AS product_id, p.name, p.sku,
            m.id AS map_id, m.ccba_sku_code, m.ccba_pack_desc, m.is_active
     FROM products p
     LEFT JOIN ccba_product_map m ON m.product_id = p.id
     WHERE p.is_active = 1
     ORDER BY p.name'
)->fetchAll();

json_ok(['mappings' => $rows]);
