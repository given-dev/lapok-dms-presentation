<?php
declare(strict_types=1);

/**
 * Depot product catalog  -  matches LAPOK book / RDC balancing workbook.
 * Grouped: CSD, ENERGY, JUICE, VAD, WATER, OTHER.
 */

/** @return list<string> */
function depot_category_order(): array
{
    return ['CSD', 'ENERGY', 'JUICE', 'VAD', 'WATER', 'OTHER'];
}

/** @return list<array<string, mixed>> */
function depot_rdc_sales_catalog(): array
{
    return [
        ['key' => '300ml', 'label' => '300ML', 'price' => 18500, 'category' => 'CSD'],
        ['key' => 'pet_330', 'label' => 'PET-330ML', 'price' => 10000, 'category' => 'CSD'],
        ['key' => 'pet_500', 'label' => 'PET-500ML', 'price' => 15000, 'category' => 'CSD'],
        ['key' => 'pet_2000', 'label' => 'PET-2000ML', 'price' => 25500, 'category' => 'CSD'],
        ['key' => 'predator', 'label' => 'PREDATOR', 'price' => 17500, 'category' => 'ENERGY'],
        ['key' => 'mm_400', 'label' => '400ML M.MAIDS', 'price' => 25000, 'category' => 'JUICE'],
        ['key' => 'mm_1l', 'label' => '1LITRES M/MAIDS', 'price' => 25500, 'category' => 'JUICE'],
        ['key' => 'refresh_250', 'label' => 'REFRESH-250ML', 'price' => 10000, 'category' => 'JUICE'],
        ['key' => 'refresh_500', 'label' => 'REFRESH-500ML', 'price' => 15000, 'category' => 'JUICE'],
        ['key' => 'vad_supershake', 'label' => '400ML SUPERSHAKE', 'price' => 22000, 'category' => 'VAD'],
        ['key' => 'rw_500_box', 'label' => 'RWENZORI 500MLS-BOX', 'price' => 17400, 'category' => 'WATER'],
        ['key' => 'rw_500_shrink', 'label' => 'RWENZORI 500MLS-SHRINKS', 'price' => 10000, 'category' => 'WATER'],
        ['key' => 'rw_1500_box', 'label' => 'RWENZORI 1.5MLS-BOX', 'price' => 18600, 'category' => 'WATER'],
        ['key' => 'jumbo_big', 'label' => 'JUMBO-BIG', 'price' => 10800, 'category' => 'OTHER'],
        ['key' => 'jumbo_small', 'label' => 'JUMBO-SMALL', 'price' => 8000, 'category' => 'OTHER'],
        ['key' => 'bottles', 'label' => 'BOTTLES', 'price' => 400, 'category' => 'OTHER'],
        ['key' => 'shell', 'label' => 'SHELL', 'price' => 6400, 'category' => 'OTHER'],
    ];
}

/** @return array<string, array<string, mixed>> */
function depot_catalog_by_key(): array
{
    $map = [];
    foreach (depot_rdc_sales_catalog() as $row) {
        $map[$row['key']] = $row;
    }
    return $map;
}

/** @return array<string, array<string, mixed>> */
function depot_catalog_by_label(): array
{
    $map = [];
    foreach (depot_rdc_sales_catalog() as $row) {
        $map[strtoupper($row['label'])] = $row;
    }
    return $map;
}

function depot_category_for_product(string $name, string $sku = ''): string
{
    $key = depot_map_product_to_rdc_key($name, $sku);
    if ($key) {
        return depot_catalog_by_key()[$key]['category'] ?? 'OTHER';
    }
    return 'OTHER';
}

function depot_map_product_to_rdc_key(string $name, string $sku = ''): ?string
{
    $text = strtoupper(trim($name . ' ' . $sku));
    $rules = [
        '300ml' => ['300', 'R&B', 'RB'],
        'pet_330' => ['330', 'CK-330', 'FT-330', 'SP-330'],
        'pet_500' => ['500ML', 'CK-500', 'SP-500', '500'],
        'pet_2000' => ['1L', '2L', '2000', 'CK-1L', 'SP-1L'],
        'predator' => ['PREDATOR', 'PRED'],
        'mm_400' => ['400', 'M.MAID', 'MMAID', 'MAID'],
        'mm_1l' => ['1LITRE', '1 LITRE', 'MM 1'],
        'refresh_250' => ['REFRESH-250', 'REFRESH 250', '250ML NP'],
        'refresh_500' => ['REFRESH-500', 'REFRESH 500', 'NOVIDA', 'NV-'],
        'vad_supershake' => ['SUPERSHAKE', 'SHAKE'],
        'rw_500_box' => ['RWENZORI 500', 'RW 500', 'WATER 500'],
        'rw_500_shrink' => ['SHRINK', '500MLS-SHRINK'],
        'rw_1500_box' => ['1.5', '1500', 'RWENZORI 1'],
        'jumbo_big' => ['JUMBO-BIG', 'JUMBO BIG'],
        'jumbo_small' => ['JUMBO-SMALL', 'JUMBO SMALL'],
        'bottles' => ['BOTTLE'],
        'shell' => ['SHELL'],
    ];

    foreach ($rules as $key => $needles) {
        foreach ($needles as $needle) {
            if (str_contains($text, strtoupper($needle))) {
                return $key;
            }
        }
    }

    $nameNorm = preg_replace('/[^A-Z0-9]/', '', strtoupper($name));
    foreach (depot_rdc_sales_catalog() as $row) {
        $labelNorm = preg_replace('/[^A-Z0-9]/', '', strtoupper($row['label']));
        if ($nameNorm === '' || $labelNorm === '') {
            continue;
        }
        if (str_contains($nameNorm, $labelNorm) || str_contains($labelNorm, $nameNorm)) {
            return $row['key'];
        }
    }

    return null;
}

/** @return array<string, int> rdc_key => qty_loaded */
function depot_trip_loaded_by_rdc_key(int $tripId): array
{
    if ($tripId <= 0) {
        return [];
    }
    $stmt = db()->prepare(
        'SELECT tli.qty_loaded, p.name, p.sku
         FROM trip_load_items tli
         JOIN products p ON p.id = tli.product_id
         WHERE tli.trip_id = ?'
    );
    $stmt->execute([$tripId]);
    $loaded = [];
    foreach ($stmt->fetchAll() as $row) {
        $key = depot_map_product_to_rdc_key((string) $row['name'], (string) $row['sku']);
        if (!$key) {
            continue;
        }
        $loaded[$key] = ($loaded[$key] ?? 0) + (int) $row['qty_loaded'];
    }
    return $loaded;
}

/** @return list<array<string, mixed>> */
function depot_cadet_product_groups(?int $tripId): array
{
    $loaded = $tripId ? depot_trip_loaded_by_rdc_key($tripId) : [];
    $grouped = [];
    foreach (depot_category_order() as $cat) {
        $grouped[$cat] = ['category' => $cat, 'products' => []];
    }

    foreach (depot_rdc_sales_catalog() as $row) {
        $cat = $row['category'];
        if (!isset($grouped[$cat])) {
            $grouped[$cat] = ['category' => $cat, 'products' => []];
        }
        $grouped[$cat]['products'][] = [
            'rdc_key' => $row['key'],
            'label' => $row['label'],
            'unit_price' => (float) $row['price'],
            'qty_loaded' => (int) ($loaded[$row['key']] ?? 0),
        ];
    }

    return array_values(array_filter(
        $grouped,
        fn($g) => count($g['products']) > 0
    ));
}
