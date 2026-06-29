<?php
declare(strict_types=1);

require_once __DIR__ . '/stock.php';

function ccba_portal_url(): string
{
    try {
        $stmt = db()->query("SELECT config_value FROM ccba_config WHERE config_key = 'portal_url_uganda' LIMIT 1");
        $row = $stmt->fetch();
        if ($row && trim((string) $row['config_value']) !== '') {
            return trim((string) $row['config_value']);
        }
    } catch (Throwable) {
        // Table may not exist before migration.
    }
    return 'https://uganda.myccba.africa/';
}

function generate_lapok_ccba_ref(): string
{
    return 'ORD-' . date('Ymd') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
}

function ccba_log_status(int $orderId, string $status, string $source, ?int $userId, ?string $ccbaLabel = null, ?array $payload = null): void
{
    $stmt = db()->prepare(
        'INSERT INTO ccba_status_events (ccba_order_id, status, ccba_status_label, source, payload_json, recorded_by)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $orderId,
        $status,
        $ccbaLabel,
        $source,
        $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
        $userId,
    ]);
}

function ccba_store_ref(int $orderId, string $refType, string $refValue, string $source = 'lapok'): void
{
    $stmt = db()->prepare(
        'INSERT INTO ccba_refs (ccba_order_id, ref_type, ref_value, source) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$orderId, $refType, $refValue, $source]);
}

/** @return array<int, array<string, mixed>> */
function ccba_suggest_order_lines(): array
{
    $sql = stock_summary_query();
    $rows = db()->query($sql)->fetchAll();
    $lines = [];

    foreach ($rows as $row) {
        $warehouse = (int) $row['warehouse_qty'];
        $min = (int) $row['min_stock'];
        if ($warehouse >= $min) {
            continue;
        }
        $suggested = max($min - $warehouse, (int) ceil($min * 0.5));
        $unitCost = (float) $row['unit_price'] * 0.6;

        $mapStmt = db()->prepare('SELECT ccba_sku_code FROM ccba_product_map WHERE product_id = ? AND is_active = 1 LIMIT 1');
        $mapStmt->execute([(int) $row['product_id']]);
        $map = $mapStmt->fetch();

        $lines[] = [
            'product_id' => (int) $row['product_id'],
            'name' => $row['name'],
            'sku' => $row['sku'],
            'warehouse_qty' => $warehouse,
            'min_stock' => $min,
            'qty_requested' => $suggested,
            'unit_cost_estimate' => $unitCost,
            'ccba_sku_code' => $map['ccba_sku_code'] ?? null,
        ];
    }

    return $lines;
}

function ccba_fetch_order(int $orderId): ?array
{
    $stmt = db()->prepare(
        'SELECT o.*, u.full_name AS created_by_name
         FROM ccba_orders o
         JOIN users u ON u.id = o.created_by
         WHERE o.id = ?'
    );
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) {
        return null;
    }

    $items = db()->prepare(
        'SELECT i.*, p.name AS product_name, p.sku
         FROM ccba_order_items i
         JOIN products p ON p.id = i.product_id
         WHERE i.ccba_order_id = ?
         ORDER BY p.name'
    );
    $items->execute([$orderId]);
    $order['items'] = $items->fetchAll();

    $events = db()->prepare(
        'SELECT e.*, u.full_name AS recorded_by_name
         FROM ccba_status_events e
         LEFT JOIN users u ON u.id = e.recorded_by
         WHERE e.ccba_order_id = ?
         ORDER BY e.recorded_at ASC'
    );
    $events->execute([$orderId]);
    $order['events'] = $events->fetchAll();

    return $order;
}

function ccba_editable_status(string $status): bool
{
    return in_array($status, ['draft', 'ready_for_ccba'], true);
}
