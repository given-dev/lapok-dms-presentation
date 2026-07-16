<?php
declare(strict_types=1);

/**
 * Depot product catalog — matches LAPOK book / RDC balancing workbook.
 * Grouped: CSD, ENERGY, JUICE, WATER, OTHER.
 */

/** @return list<string> */
function depot_category_order(): array
{
    return ['CSD', 'ENERGY', 'JUICE', 'WATER', 'OTHER'];
}

/** @return list<array<string, mixed>> */
function depot_rdc_sales_catalog(): array
{
    return [
        // CSD
        ['key' => '300ml', 'label' => '300ML RGB', 'price' => 18500, 'category' => 'CSD'],
        ['key' => 'pet_330', 'label' => '300ML PET', 'price' => 10000, 'category' => 'CSD'],
        ['key' => 'pet_500', 'label' => 'PET-500ML', 'price' => 15000, 'category' => 'CSD'],
        ['key' => 'pet_1l', 'label' => '1L COCA-COLA', 'price' => 12500, 'category' => 'CSD'],
        ['key' => 'pet_2000', 'label' => 'PET-2000ML', 'price' => 25500, 'category' => 'CSD'],
        // ENERGY — one sales row for cadet/RDC; manager tracks variants on OCCD / dispatch
        ['key' => 'energy', 'label' => 'ENERGY', 'price' => 17500, 'category' => 'ENERGY'],
        // JUICE
        ['key' => 'mm_400', 'label' => '400ML M.MAIDS', 'price' => 25500, 'category' => 'JUICE'],
        ['key' => 'mm_1l', 'label' => '1LITRES M/MAIDS', 'price' => 25500, 'category' => 'JUICE'],
        ['key' => 'refresh_250', 'label' => 'REFRESH-250ML', 'price' => 10000, 'category' => 'JUICE'],
        // WATER (Rwenzori + jumbo)
        ['key' => 'rw_500_box', 'label' => 'RWENZORI 500MLS-BOX', 'price' => 17400, 'category' => 'WATER'],
        ['key' => 'rw_500_shrink', 'label' => 'RWENZORI 500MLS-SHRINKS', 'price' => 10000, 'category' => 'WATER'],
        ['key' => 'rw_1500_box', 'label' => 'RWENZORI 1.5MLS-BOX', 'price' => 18600, 'category' => 'WATER'],
        ['key' => 'jumbo_big', 'label' => 'JUMBO 20L', 'price' => 10800, 'category' => 'WATER'],
        ['key' => 'jumbo_small', 'label' => 'JUMBO 10L', 'price' => 5500, 'category' => 'WATER'],
        // OTHER (empties only)
        ['key' => 'bottles', 'label' => 'BOTTLES', 'price' => 400, 'category' => 'OTHER'],
        ['key' => 'shell', 'label' => 'SHELLS', 'price' => 6400, 'category' => 'OTHER'],
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
    // Prefer explicit warehouse SKU → rdc_key from LAPOK BOOK page 1 catalog.
    $skuUp = strtoupper(trim($sku));
    if ($skuUp !== '') {
        foreach (depot_manager_warehouse_catalog() as $row) {
            if (strtoupper((string) $row['sku']) === $skuUp) {
                return (string) $row['rdc_key'];
            }
        }
    }

    $text = strtoupper(trim($name . ' ' . $sku));
    $rules = [
        // CSD — RGB before generic 300 / PET.
        '300ml' => ['300ML RGB', '300 RGB', 'R&B', 'RB', 'RGB', '300-COKE', '300-FANTA', '300-SPRITE'],
        'pet_330' => ['300ML PET', 'PET-330', 'PET 330', '330-', 'CK-330', 'FT-330', 'SP-330'],
        'pet_500' => ['PET-500', 'PET 500', '500ML PET', '500-COKE', '500-FANTA', '500-SPRITE', 'REFRESH-500', 'REFRESH 500', 'CK-500', 'SP-500'],
        'pet_1l' => ['1L-COKE', '1L COCA', '1L COKE', '1 LITRE COKE', 'CK-1L'],
        'pet_2000' => ['PET-2000', 'PET 2L', '2L-', '2 LITRE', '2000', 'CK-2L', 'SP-2L'],
        // ENERGY
        'energy' => [
            'ENERGY',
            'PREDATOR MANGO',
            'PREDATOR GOLD',
            'POWER PLAY',
            'POWERPLAY',
            'EN-MANGO',
            'EN-GOLD',
            'EN-POWERPLAY',
            'EN-PREDATOR',
            'EN-PLAY',
        ],
        // JUICE
        'mm_400' => ['400-MM', '400ML M.MAID', 'MM MANGO', 'MM BERRY', 'MM APPLE', 'MM ORANGE', 'M.MAID', 'MMAID'],
        'mm_1l' => ['1L-MM', '1LITRES M/MAID', 'MM 1'],
        'refresh_250' => ['280-RF', 'REFRESH-250', 'REFRESH 250', 'REFRESH MANGO', 'REFRESH APPLE', 'REFRESH ORANGE', '250ML'],
        // WATER
        'rw_500_shrink' => ['RW-SHRINX', 'SHRINX', 'SHRINK', '500MLS-SHRINK'],
        'rw_500_box' => ['RW-500-X24', 'RWENZORI 500', '500 ML X 24', '500MLS-BOX'],
        'rw_1500_box' => ['RW-1500', '1500ML X 12', '1.5', '1500'],
        // Jumbo sizes live under Rwenzori water (not a separate OTHER brand).
        'jumbo_big' => ['RW-5000', '5000ML', 'JUMBO 20', 'JUMBO-20', 'JUMBO 20L'],
        'jumbo_small' => ['RW-JUMBO', 'JUMBO 10', 'JUMBO-10', 'JUMBO 10L'],
        'bottles' => ['EMPTY-300', 'EMPTIES', 'BOTTLE'],
        'shell' => ['EMPTY-SHELL', 'SHELL'],
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

/** Map legacy catalog keys (old energy SKUs, refresh 500, etc.) onto the current sales book. */
function depot_normalize_rdc_key(string $key): string
{
    $legacy = [
        'predator' => 'energy',
        'predator_mango' => 'energy',
        'powerplay' => 'energy',
        'refresh_500' => 'pet_500',
        'vad_supershake' => '',
    ];
    return $legacy[$key] ?? $key;
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

/**
 * Manager stock book / dispatch — LAPOK BOOK page 1 (Brand + exact flavor SKUs).
 * Cadet/RDC sales still roll flavors into pack rows via rdc_key.
 *
 * @return list<array{sku: string, name: string, brand: string, price: float, category: string, rdc_key: string, min_stock: int, starter_qty: int}>
 */
function depot_manager_warehouse_catalog(): array
{
    $row = static function (
        string $brand,
        string $flavor,
        string $sku,
        string $rdcKey,
        float $price,
        int $min = 40,
        int $starter = 50
    ): array {
        return [
            'sku' => $sku,
            'name' => $flavor,
            'brand' => $brand,
            'price' => $price,
            'category' => $brand,
            'rdc_key' => $rdcKey,
            'min_stock' => $min,
            'starter_qty' => $starter,
        ];
    };

    return [
        // 300ML RGB
        $row('300ML RGB', 'COKE', '300-COKE', '300ml', 18500, 80, 80),
        $row('300ML RGB', 'FANTA', '300-FANTA', '300ml', 18500, 80, 80),
        $row('300ML RGB', 'SPRITE', '300-SPRITE', '300ml', 18500, 80, 80),
        $row('300ML RGB', 'KREST', '300-KREST', '300ml', 18500, 40, 40),
        $row('300ML RGB', 'STONEY', '300-STONEY', '300ml', 18500, 40, 40),
        $row('300ML RGB', 'NOVIDA', '300-NOVIDA', '300ml', 18500, 40, 40),
        // 330ML PET
        $row('330ML', 'COKE', '330-COKE', 'pet_330', 10000, 80, 80),
        $row('330ML', 'COKE ZERO', '330-COKE-Z', 'pet_330', 10000, 40, 40),
        $row('330ML', 'FANTA', '330-FANTA', 'pet_330', 10000, 80, 80),
        $row('330ML', 'FANTA PINEAPPLE', '330-FANTA-B', 'pet_330', 10000, 40, 40),
        $row('330ML', 'STONEY', '330-STONEY', 'pet_330', 10000, 40, 40),
        $row('330ML', 'SPRITE ZERO', '330-SPRITE-Z', 'pet_330', 10000, 40, 40),
        $row('330ML', 'NOVIDA', '330-NOVIDA', 'pet_330', 10000, 40, 40),
        $row('330ML', 'NOVIDA ZERO', '330-NOVIDA-Z', 'pet_330', 10000, 40, 40),
        // ENERGY — Predator Gold + Mango, and Power Play
        $row('ENERGY', 'PREDATOR GOLD', 'EN-GOLD', 'energy', 17500, 40, 60),
        $row('ENERGY', 'PREDATOR MANGO', 'EN-MANGO', 'energy', 17500, 40, 60),
        $row('ENERGY', 'POWER PLAY', 'EN-POWERPLAY', 'energy', 17500, 40, 60),
        // 500ML PET (CSD only — Refresh lives under JUICE)
        $row('500ML', 'COKE', '500-COKE', 'pet_500', 15000, 80, 80),
        $row('500ML', 'FANTA', '500-FANTA', 'pet_500', 15000, 80, 80),
        $row('500ML', 'SPRITE', '500-SPRITE', 'pet_500', 15000, 80, 80),
        $row('500ML', 'KREST', '500-KREST', 'pet_500', 15000, 40, 40),
        $row('500ML', 'STONEY', '500-STONEY', 'pet_500', 15000, 40, 40),
        $row('500ML', 'NOVIDA', '500-NOVIDA', 'pet_500', 15000, 40, 40),
        // 1 LITRE CSD
        $row('1 LITRE', 'COKE', '1L-COKE', 'pet_1l', 12500, 40, 50),
        // JUICE — all Minute Maid + Refresh together
        $row('JUICE', 'MM MANGO 400ML', '400-MM-MANGO', 'mm_400', 25500, 40, 50),
        $row('JUICE', 'MM BERRY 400ML', '400-MM-BERRY', 'mm_400', 25500, 40, 50),
        $row('JUICE', 'MM APPLE 400ML', '400-MM-APPLE', 'mm_400', 25500, 40, 50),
        $row('JUICE', 'MM ORANGE 400ML', '400-MM-ORANGE', 'mm_400', 25500, 40, 50),
        $row('JUICE', 'MM MANGO 1L', '1L-MM-MANGO', 'mm_1l', 25500, 40, 50),
        $row('JUICE', 'MM BERRY 1L', '1L-MM-BERRY', 'mm_1l', 25500, 40, 50),
        $row('JUICE', 'REFRESH MANGO 280ML', '280-RF-MANGO', 'refresh_250', 10000, 40, 50),
        $row('JUICE', 'REFRESH APPLE 280ML', '280-RF-APPLE', 'refresh_250', 10000, 40, 50),
        $row('JUICE', 'REFRESH ORANGE 280ML', '280-RF-ORANGE', 'refresh_250', 10000, 40, 50),
        $row('JUICE', 'REFRESH MANGO 500ML', '500-RF-MANGO', 'pet_500', 15000, 40, 50),
        // 2 LITRE
        $row('2 LITRE', 'COKE', '2L-COKE', 'pet_2000', 25500, 40, 50),
        $row('2 LITRE', 'FANTA', '2L-FANTA', 'pet_2000', 25500, 40, 50),
        $row('2 LITRE', 'SPRITE', '2L-SPRITE', 'pet_2000', 25500, 40, 50),
        // RWENZORI WATER — shrinks, packs, jumbo 20L / 10L
        $row('RWENZORI WATER', 'SHRINX', 'RW-SHRINX', 'rw_500_shrink', 10000, 60, 80),
        $row('RWENZORI WATER', '500 ML X 24', 'RW-500-X24', 'rw_500_box', 17400, 60, 80),
        $row('RWENZORI WATER', '1500ML X 12', 'RW-1500-X12', 'rw_1500_box', 18600, 40, 50),
        $row('RWENZORI WATER', 'JUMBO 20L', 'RW-5000-X4', 'jumbo_big', 10800, 20, 30),
        $row('RWENZORI WATER', 'JUMBO 10L', 'RW-JUMBO', 'jumbo_small', 5500, 20, 30),
        // EMPTIES — bottles + shells
        $row('EMPTIES', 'BOTTLES', 'EMPTY-300', 'bottles', 400, 50, 100),
        $row('EMPTIES', 'SHELLS', 'EMPTY-SHELL', 'shell', 6400, 40, 80),
    ];
}

/** Brand section order on manager stock / dispatch sheet. */
function depot_stock_brand_order(): array
{
    return ['300ML RGB', '330ML', 'ENERGY', '500ML', '1 LITRE', 'JUICE', '2 LITRE', 'RWENZORI WATER', 'EMPTIES'];
}

/**
 * Ensure manager catalog products exist in `products` (+ starter warehouse batch when new).
 * Safe to call often.
 *
 * @return list<array<string, mixed>> product rows keyed for stock/dispatch UIs
 */
function depot_ensure_warehouse_products(): array
{
    require_once __DIR__ . '/stock.php';
    $pdo = db();
    $catalogSkus = [];
    $find = $pdo->prepare('SELECT id, name, sku, unit_price, min_stock, is_active FROM products WHERE sku = ? LIMIT 1');
    $insert = $pdo->prepare(
        'INSERT INTO products (name, sku, unit_price, min_stock, is_active) VALUES (?, ?, ?, ?, 1)'
    );
    $update = $pdo->prepare(
        'UPDATE products SET name = ?, unit_price = ?, min_stock = ?, is_active = 1 WHERE id = ?'
    );
    $batchExists = $pdo->prepare('SELECT id FROM batches WHERE product_id = ? LIMIT 1');
    $insertBatch = $pdo->prepare(
        'INSERT INTO batches (product_id, batch_number, expiry_date, qty_warehouse, qty_on_vehicles, unit_cost)
         VALUES (?, ?, ?, ?, 0, ?)'
    );

    $out = [];
    foreach (depot_manager_warehouse_catalog() as $row) {
        $sku = (string) $row['sku'];
        $catalogSkus[$sku] = true;
        $find->execute([$sku]);
        $existing = $find->fetch();
        if ($existing) {
            $productId = (int) $existing['id'];
            $update->execute([
                (string) $row['name'],
                (float) $row['price'],
                (int) $row['min_stock'],
                $productId,
            ]);
        } else {
            $insert->execute([
                (string) $row['name'],
                $sku,
                (float) $row['price'],
                (int) $row['min_stock'],
            ]);
            $productId = (int) $pdo->lastInsertId();
            $starter = (int) ($row['starter_qty'] ?? 0);
            if ($starter > 0) {
                $insertBatch->execute([
                    $productId,
                    'INIT-' . $sku,
                    date('Y-m-d', strtotime('+180 days')),
                    $starter,
                    round((float) $row['price'] * 0.6, 2),
                ]);
            }
        }

        $batchExists->execute([$productId]);
        if (!$batchExists->fetch()) {
            $starter = max(0, (int) ($row['starter_qty'] ?? 0));
            $insertBatch->execute([
                $productId,
                'INIT-' . $sku,
                date('Y-m-d', strtotime('+180 days')),
                $starter,
                round((float) $row['price'] * 0.6, 2),
            ]);
        }

        $out[] = [
            'product_id' => $productId,
            'name' => (string) $row['name'],
            'sku' => $sku,
            'unit_price' => (float) $row['price'],
            'min_stock' => (int) $row['min_stock'],
            'category' => (string) ($row['brand'] ?? $row['category'] ?? 'OTHER'),
            'brand' => (string) ($row['brand'] ?? $row['category'] ?? ''),
            'rdc_key' => (string) ($row['rdc_key'] ?? ''),
        ];
    }

    // Hide previous non-flavor / demo SKUs that are not on LAPOK BOOK page 1.
    $legacySkus = [
        'CK-500', 'FT-OR', 'SP-500', 'SP-1L', 'NV-OR', 'CK-1L',
        'RGB-300', 'PET-300', 'PET-500', 'PET-2000',
        'EN-PREDATOR', 'EN-PLAY',
        'MM-400', 'MM-1L', 'RF-250',
        'RW-500-BOX', 'RW-500-SHR', 'RW-1500',
        'JUMBO-20', 'JUMBO-10', 'BOTTLES', 'SHELLS',
    ];
    $deactivate = $pdo->prepare('UPDATE products SET is_active = 0 WHERE sku = ?');
    foreach ($legacySkus as $legacy) {
        if (!isset($catalogSkus[$legacy])) {
            $deactivate->execute([$legacy]);
        }
    }

    return $out;
}
