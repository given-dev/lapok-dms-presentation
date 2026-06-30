<?php
declare(strict_types=1);

function cadet_parse_report_note(?string $notes): ?array
{
    if (!$notes || !str_contains($notes, '[CADET_REPORT]')) {
        return null;
    }
    if (!preg_match('/\[CADET_REPORT\]\s*(\{.*\})/s', $notes, $m)) {
        return null;
    }
    $data = json_decode($m[1], true);
    return is_array($data) ? $data : null;
}

/**
 * @param array<string, array<string, mixed>> $catalogByKey
 * @return list<array<string, mixed>>
 */
function cadet_normalize_sales_lines(array $lines, array $catalogByKey): array
{
    require_once __DIR__ . '/depot_catalog.php';
    $byLabel = depot_catalog_by_label();
    $normalized = [];

    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $key = (string) ($line['rdc_key'] ?? '');
        if ($key === '' && !empty($line['rdc_label'])) {
            $catRow = $byLabel[strtoupper((string) $line['rdc_label'])] ?? null;
            $key = $catRow['key'] ?? '';
        }
        $qtySold = max(0, (int) ($line['qty_sold'] ?? 0));
        if ($key === '' || $qtySold <= 0 || !isset($catalogByKey[$key])) {
            continue;
        }
        $catalog = $catalogByKey[$key];
        $unitPrice = (float) ($catalog['unit_price'] ?? $catalog['price'] ?? 0);
        $amount = array_key_exists('amount', $line)
            ? max(0, (float) $line['amount'])
            : $qtySold * $unitPrice;
        $normalized[] = [
            'rdc_key' => $key,
            'rdc_label' => (string) ($catalog['label'] ?? ''),
            'category' => (string) ($catalog['category'] ?? ''),
            'qty_loaded' => (int) ($line['qty_loaded'] ?? $catalog['qty_loaded'] ?? 0),
            'qty_sold' => $qtySold,
            'unit_price' => $unitPrice,
            'amount' => $amount,
        ];
    }
    return $normalized;
}

/**
 * @param list<array<string, mixed>> $salesLines
 */
function cadet_compute_flags(float $sales, float $cash, float $fuel, float $other, ?string $note, array $salesLines = []): array
{
    $flags = [];
    $expenses = $fuel + $other;
    if ($sales > 0 && abs($sales - $cash) > 5000) {
        $flags[] = 'cash_variance';
    }
    if ($sales > 0 && $expenses > ($sales * 0.35)) {
        $flags[] = 'high_expense';
    }
    if ($sales <= 0) {
        $flags[] = 'missing_sales';
    }
    foreach ($salesLines as $line) {
        $loaded = (int) ($line['qty_loaded'] ?? 0);
        $sold = (int) ($line['qty_sold'] ?? 0);
        if ($loaded > 0 && $sold > $loaded) {
            $flags[] = 'oversold';
            break;
        }
    }
    $hour = (int) date('G');
    $min = (int) date('i');
    if ($hour * 60 + $min > (19 * 60 + 30)) {
        $flags[] = 'late_submit';
    }
    if (in_array('cash_variance', $flags, true) && trim((string) $note) === '') {
        $flags[] = 'needs_note';
    }
    return array_values(array_unique($flags));
}

function cadet_flag_labels(array $flags): string
{
    $map = [
        'cash_variance' => 'Cash vs sales mismatch',
        'high_expense' => 'High expenses vs sales',
        'missing_sales' => 'Sales not recorded',
        'oversold' => 'Sold more than loaded',
        'late_submit' => 'Submitted after 7:30 PM',
        'needs_note' => 'Explanation required',
    ];
    return implode('; ', array_map(fn($f) => $map[$f] ?? $f, $flags));
}

function cadet_sales_summary(array $salesLines): string
{
    if (!$salesLines) {
        return 'No product sales';
    }
    $parts = [];
    foreach ($salesLines as $line) {
        $name = (string) ($line['rdc_label'] ?? $line['product_name'] ?? 'Product');
        $qty = (int) ($line['qty_sold'] ?? 0);
        $parts[] = $name . ' ×' . $qty;
    }
    return implode(', ', $parts);
}

/** @param list<array<string, mixed>> $salesLines */
function cadet_apply_trip_sales(PDO $pdo, int $tripId, array $salesLines): void
{
    require_once __DIR__ . '/depot_catalog.php';
    if (!$salesLines) {
        return;
    }

    $loadStmt = $pdo->prepare(
        'SELECT tli.product_id, tli.qty_loaded, p.name, p.sku
         FROM trip_load_items tli
         JOIN products p ON p.id = tli.product_id
         WHERE tli.trip_id = ?'
    );
    $loadStmt->execute([$tripId]);
    $loads = $loadStmt->fetchAll();
    if (!$loads) {
        return;
    }

    $soldByKey = [];
    foreach ($salesLines as $line) {
        $soldByKey[$line['rdc_key']] = (int) ($line['qty_sold'] ?? 0);
    }

    $applied = [];
    foreach ($loads as $row) {
        $key = depot_map_product_to_rdc_key((string) $row['name'], (string) $row['sku']);
        if (!$key || !isset($soldByKey[$key]) || isset($applied[$key])) {
            continue;
        }
        $qtySold = $soldByKey[$key];
        $qtyLoaded = (int) $row['qty_loaded'];
        $pdo->prepare(
            'UPDATE trip_load_items SET qty_sold = ?, qty_returned = ? WHERE trip_id = ? AND product_id = ?'
        )->execute([
            $qtySold,
            max(0, $qtyLoaded - $qtySold),
            $tripId,
            (int) $row['product_id'],
        ]);
        $applied[$key] = true;
    }
}
