<?php
declare(strict_types=1);

/** Default header shown on both physical boards. */
function occd_board_header_defaults(): array
{
    return [
        'occd_name' => 'LAPOK VENTURES COMPANY LTD',
        'region' => 'NORTHERN',
    ];
}

/** @return array<string, mixed> */
function occd_inventory_board_template(): array
{
    $lines = [
        // CSD
        ['key' => 'csd_pet_1000', 'category' => 'CSD', 'sku' => 'pet 1000ml', 'row_type' => 'sku'],
        ['key' => 'csd_2l_np', 'category' => 'CSD', 'sku' => '2L NP x 6 SW', 'row_type' => 'sku'],
        ['key' => 'csd_300_rb', 'category' => 'CSD', 'sku' => '300ml R&B x 24', 'row_type' => 'sku'],
        ['key' => 'csd_330_np', 'category' => 'CSD', 'sku' => '330ml NP x 12', 'row_type' => 'sku'],
        ['key' => 'csd_330_zero', 'category' => 'CSD', 'sku' => '330ml NP x 12 Zero', 'row_type' => 'sku'],
        ['key' => 'csd_500_np', 'category' => 'CSD', 'sku' => '500ml NP x 12 SW', 'row_type' => 'sku'],
        ['key' => 'csd_330_pine', 'category' => 'CSD', 'sku' => '330ml NP x 12 Fresh Pineapple', 'row_type' => 'sku'],
        ['key' => 'csd_total', 'category' => 'CSD', 'sku' => 'CSD TOTAL', 'row_type' => 'section_total'],
        // ENERGY
        ['key' => 'en_pred_gold', 'category' => 'ENERGY', 'sku' => '350ml NP 1x12 Predator Gold', 'row_type' => 'sku'],
        ['key' => 'en_pred_mango', 'category' => 'ENERGY', 'sku' => '350ml NP 1x12 Predator Mango', 'row_type' => 'sku'],
        ['key' => 'en_play', 'category' => 'ENERGY', 'sku' => '350ml NP Play 1x12 SW', 'row_type' => 'sku'],
        ['key' => 'energy_total', 'category' => 'ENERGY', 'sku' => 'ENERGY TOTAL', 'row_type' => 'section_total'],
        // JUICE
        ['key' => 'ju_1l', 'category' => 'JUICE', 'sku' => '1L NP x 6', 'row_type' => 'sku'],
        ['key' => 'ju_250', 'category' => 'JUICE', 'sku' => '250ml NP Juice 12', 'row_type' => 'sku'],
        ['key' => 'ju_400_mid', 'category' => 'JUICE', 'sku' => '400ml m/mid NP x 12', 'row_type' => 'sku'],
        ['key' => 'ju_400_fruity', 'category' => 'JUICE', 'sku' => '400ml Fruity Boost', 'row_type' => 'sku'],
        ['key' => 'ju_500', 'category' => 'JUICE', 'sku' => '500ml NP Juice 12', 'row_type' => 'sku'],
        ['key' => 'juice_total', 'category' => 'JUICE', 'sku' => 'JUICE TOTAL', 'row_type' => 'section_total'],
        // VAD
        ['key' => 'vad_supershake', 'category' => 'VAD', 'sku' => '400ml S/W Supershake', 'row_type' => 'sku'],
        ['key' => 'vad_total', 'category' => 'VAD', 'sku' => 'VAD TOTAL', 'row_type' => 'section_total'],
        // WATER
        ['key' => 'wa_1500', 'category' => 'WATER', 'sku' => '1500ml 12 BOX NP', 'row_type' => 'sku'],
        ['key' => 'wa_2000', 'category' => 'WATER', 'sku' => '2000ml BULK NP', 'row_type' => 'sku'],
        ['key' => 'wa_500_15', 'category' => 'WATER', 'sku' => '500ml 15 S/W NP', 'row_type' => 'sku'],
        ['key' => 'wa_500_24', 'category' => 'WATER', 'sku' => '500ml 24 BOX NP', 'row_type' => 'sku'],
        ['key' => 'water_total', 'category' => 'WATER', 'sku' => 'WATER TOTAL', 'row_type' => 'section_total'],
        ['key' => 'grand_total', 'category' => '', 'sku' => 'GRAND TOTAL', 'row_type' => 'grand_total'],
    ];

    return [
        'header' => occd_board_header_defaults(),
        'lines' => $lines,
        'values' => occd_inventory_empty_values($lines),
    ];
}

/** @param array<int, array<string, mixed>> $lines */
function occd_inventory_empty_values(array $lines): array
{
    $values = [];
    foreach ($lines as $line) {
        if (($line['row_type'] ?? '') === 'sku') {
            $values[$line['key']] = [
                'recommended' => '',
                'opening' => '',
                'on_order' => '',
                'comments' => '',
            ];
        }
    }
    return $values;
}

/** @return array<string, mixed> */
function occd_dashboard_template(): array
{
    $outletChannels = ['coke', 'kad', 'stockists', 'superettes', 'pfns', 'horeca', 'bars_pubs', 'education'];
    $outletTiers = ['gold', 'silver', 'bronze', 'tin'];

    $salesCategories = ['csd', 'water', 'juice', 'energy', 'total'];
    $salesSections = ['current_month', 'ytd'];

    $serviceRows = [
        'total_outlet_universe', 'new_outlets', 'red_outlets_total',
        'tier_gold', 'tier_silver', 'tier_bronze', 'tier_tin',
        'call_adherence', 'strike_rate', 'presale_pct', 'presale_delivery_pct',
        'myccba_delivery_pct', 'warehouse_sqm', 'capitalization', 'adca_score',
    ];

    $executionRows = [
        'digitized_outlets', 'active_digitized_outlets', 'red_score', 'unforgivable_nd',
        'buying_customers', 'nps', 'obe_cwm', 'obe_sprite_otg', 'obe_santa_snacking',
    ];

    $unforgivableSkus = [
        ['key' => 'uf_300_coke', 'sku' => '300ml COKE'],
        ['key' => 'uf_300_fanta', 'sku' => '300ml FANTA'],
        ['key' => 'uf_330_coke', 'sku' => '330ml COKE'],
        ['key' => 'uf_330_fanta', 'sku' => '330ml FANTA'],
        ['key' => 'uf_500_coke', 'sku' => '500ml COKE'],
        ['key' => 'uf_500_fanta', 'sku' => '500ml FANTA'],
        ['key' => 'uf_2l_fanta', 'sku' => '2L PET FANTA'],
        ['key' => 'uf_2l_coke', 'sku' => '2L PET COKE'],
        ['key' => 'uf_mm_400', 'sku' => 'MM 400ml PET'],
        ['key' => 'uf_mm_1l', 'sku' => 'MM 1L PET'],
        ['key' => 'uf_w_500', 'sku' => 'WATER 500ml'],
        ['key' => 'uf_w_1500', 'sku' => 'WATER 1500ml'],
        ['key' => 'uf_pred_300', 'sku' => 'PREDATOR 300ml'],
    ];

    return [
        'header' => occd_board_header_defaults(),
        'outlet_data' => [
            'channels' => $outletChannels,
            'tiers' => $outletTiers,
            'values' => occd_outlet_empty_values($outletTiers, $outletChannels),
        ],
        'sales_performance' => [
            'sections' => $salesSections,
            'categories' => $salesCategories,
            'values' => occd_sales_empty_values($salesSections, $salesCategories),
        ],
        'service_model' => [
            'rows' => $serviceRows,
            'values' => occd_metric_empty_values($serviceRows),
        ],
        'execution_excellence' => [
            'rows' => $executionRows,
            'values' => occd_metric_empty_values($executionRows),
        ],
        'unforgivable_packs' => [
            'lines' => $unforgivableSkus,
            'values' => occd_unforgivable_empty_values($unforgivableSkus),
        ],
    ];
}

/** @param array<int, string> $tiers @param array<int, string> $channels */
function occd_outlet_empty_values(array $tiers, array $channels): array
{
    $values = [];
    foreach ($tiers as $tier) {
        $values[$tier] = [];
        foreach ($channels as $ch) {
            $values[$tier][$ch] = '';
        }
    }
    return $values;
}

/** @param array<int, string> $sections @param array<int, string> $categories */
function occd_sales_empty_values(array $sections, array $categories): array
{
    $values = [];
    foreach ($sections as $section) {
        $values[$section] = [];
        foreach ($categories as $cat) {
            if ($cat === 'total') {
                continue;
            }
            $values[$section][$cat] = ['cy' => '', 'target' => '', 'py' => ''];
        }
    }
    return $values;
}

/** @param array<int, string> $rows */
function occd_metric_empty_values(array $rows): array
{
    $values = [];
    foreach ($rows as $row) {
        $values[$row] = ['mtd' => '', 'mtd_target' => '', 'ytd' => '', 'ytd_target' => ''];
    }
    return $values;
}

/** @param array<int, array<string, string>> $lines */
function occd_unforgivable_empty_values(array $lines): array
{
    $values = [];
    foreach ($lines as $line) {
        $values[$line['key']] = [
            'recommended' => '',
            'opening' => '',
            'on_order' => '',
            'comments' => '',
        ];
    }
    return $values;
}

/**
 * Unforgivable board lines → manager warehouse SKUs (opening stock book).
 * Multi-SKU keys sum flavors in that pack family.
 *
 * @return array<string, list<string>>
 */
function occd_unforgivable_sku_map(): array
{
    return [
        'uf_300_coke' => ['300-COKE'],
        'uf_300_fanta' => ['300-FANTA'],
        'uf_330_coke' => ['330-COKE'],
        'uf_330_fanta' => ['330-FANTA'],
        'uf_500_coke' => ['500-COKE'],
        'uf_500_fanta' => ['500-FANTA'],
        'uf_2l_fanta' => ['2L-FANTA'],
        'uf_2l_coke' => ['2L-COKE'],
        'uf_mm_400' => ['400-MM-MANGO', '400-MM-BERRY', '400-MM-APPLE', '400-MM-ORANGE'],
        'uf_mm_1l' => ['1L-MM-MANGO', '1L-MM-BERRY'],
        'uf_w_500' => ['RW-SHRINX', 'RW-500-X24'],
        'uf_w_1500' => ['RW-1500-X12'],
        'uf_pred_300' => ['EN-GOLD', 'EN-MANGO'],
    ];
}

/**
 * Inventory board pack rows → manager warehouse SKUs.
 *
 * @return array<string, list<string>>
 */
function occd_inventory_sku_map(): array
{
    return [
        'csd_pet_1000' => ['1L-COKE'],
        'csd_2l_np' => ['2L-COKE', '2L-FANTA', '2L-SPRITE'],
        'csd_300_rb' => ['300-COKE', '300-FANTA', '300-SPRITE', '300-KREST', '300-STONEY', '300-NOVIDA'],
        'csd_330_np' => ['330-COKE', '330-FANTA', '330-STONEY', '330-NOVIDA'],
        'csd_330_zero' => ['330-COKE-Z', '330-SPRITE-Z', '330-NOVIDA-Z'],
        'csd_500_np' => ['500-COKE', '500-FANTA', '500-SPRITE', '500-KREST', '500-STONEY', '500-NOVIDA'],
        'csd_330_pine' => ['330-FANTA-B'],
        'en_pred_gold' => ['EN-GOLD'],
        'en_pred_mango' => ['EN-MANGO'],
        'en_play' => ['EN-POWERPLAY'],
        'ju_1l' => ['1L-MM-MANGO', '1L-MM-BERRY'],
        'ju_250' => ['280-RF-MANGO', '280-RF-APPLE', '280-RF-ORANGE'],
        'ju_400_mid' => ['400-MM-MANGO', '400-MM-BERRY', '400-MM-APPLE', '400-MM-ORANGE'],
        'ju_400_fruity' => [],
        'ju_500' => ['500-RF-MANGO'],
        'vad_supershake' => [],
        'wa_1500' => ['RW-1500-X12'],
        'wa_2000' => ['RW-5000-X4', 'RW-JUMBO'],
        'wa_500_15' => ['RW-SHRINX'],
        'wa_500_24' => ['RW-500-X24'],
    ];
}

/**
 * Opening qty by warehouse SKU from the manager's 7am stock book for $date.
 *
 * @return array<string, int>
 */
function occd_manager_opening_by_sku(string $date): array
{
    require_once __DIR__ . '/depot_finance.php';
    $snap = depot_snapshot_fetch($date, 'opening');
    if (!$snap || empty($snap['lines']) || !is_array($snap['lines'])) {
        return [];
    }
    $lines = depot_merge_snapshot_onto_catalog($snap['lines']);
    $bySku = [];
    foreach ($lines as $line) {
        $sku = strtoupper(trim((string) ($line['sku'] ?? '')));
        if ($sku === '') {
            continue;
        }
        $bySku[$sku] = (int) ($line['opening'] ?? $line['qty'] ?? 0);
    }
    return $bySku;
}

/**
 * Open Coca-Cola (CCBA) order quantities by warehouse SKU.
 * Includes orders that are placed / in transit, excludes draft / delivered / closed / cancelled.
 *
 * @return array<string, int>
 */
function occd_ccba_on_order_by_sku(): array
{
    try {
        $sql = "SELECT UPPER(TRIM(p.sku)) AS sku,
                       COALESCE(SUM(COALESCE(i.qty_confirmed, i.qty_requested)), 0) AS qty
                FROM ccba_order_items i
                JOIN ccba_orders o ON o.id = i.ccba_order_id
                JOIN products p ON p.id = i.product_id
                WHERE o.status IN (
                    'ready_for_ccba', 'submitted_to_ccba', 'ccba_acknowledged',
                    'ccba_confirmed', 'scheduled', 'dispatched', 'partial_delivery'
                )
                GROUP BY UPPER(TRIM(p.sku))";
        $rows = db()->query($sql)->fetchAll();
    } catch (Throwable) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        $sku = (string) ($row['sku'] ?? '');
        if ($sku === '') {
            continue;
        }
        $out[$sku] = (int) ($row['qty'] ?? 0);
    }
    return $out;
}

/** @param array<string, int> $bySku @param list<string> $skus */
function occd_sum_skus(array $bySku, array $skus): int
{
    $sum = 0;
    foreach ($skus as $sku) {
        $sum += (int) ($bySku[strtoupper($sku)] ?? 0);
    }
    return $sum;
}

/**
 * Fill inventory board opening + on-order from manager opening / CCBA orders.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function occd_apply_inventory_auto_fields(array $payload, string $date): array
{
    if (!isset($payload['values']) || !is_array($payload['values'])) {
        return $payload;
    }
    $openingBySku = occd_manager_opening_by_sku($date);
    $onOrderBySku = occd_ccba_on_order_by_sku();
    $map = occd_inventory_sku_map();
    foreach ($payload['lines'] ?? [] as $line) {
        if (($line['row_type'] ?? '') !== 'sku') {
            continue;
        }
        $key = (string) ($line['key'] ?? '');
        if ($key === '' || !isset($payload['values'][$key]) || !is_array($payload['values'][$key])) {
            continue;
        }
        $skus = $map[$key] ?? [];
        $payload['values'][$key]['opening'] = (string) occd_sum_skus($openingBySku, $skus);
        $payload['values'][$key]['on_order'] = (string) occd_sum_skus($onOrderBySku, $skus);
        $payload['values'][$key]['opening_source'] = 'manager_opening_stock';
        $payload['values'][$key]['on_order_source'] = 'ccba_orders';
    }
    return $payload;
}

/**
 * Fill unforgivable opening + on-order from manager opening / CCBA orders.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function occd_apply_unforgivable_openings(array $payload, string $date): array
{
    if (!isset($payload['unforgivable_packs']) || !is_array($payload['unforgivable_packs'])) {
        return $payload;
    }
    $openingBySku = occd_manager_opening_by_sku($date);
    $onOrderBySku = occd_ccba_on_order_by_sku();
    $map = occd_unforgivable_sku_map();
    $lines = $payload['unforgivable_packs']['lines'] ?? [];
    if (!isset($payload['unforgivable_packs']['values']) || !is_array($payload['unforgivable_packs']['values'])) {
        $payload['unforgivable_packs']['values'] = occd_unforgivable_empty_values(is_array($lines) ? $lines : []);
    }
    foreach (is_array($lines) ? $lines : [] as $line) {
        $key = (string) ($line['key'] ?? '');
        if ($key === '') {
            continue;
        }
        if (!isset($payload['unforgivable_packs']['values'][$key]) || !is_array($payload['unforgivable_packs']['values'][$key])) {
            $payload['unforgivable_packs']['values'][$key] = [
                'recommended' => '',
                'opening' => '0',
                'on_order' => '0',
                'comments' => '',
            ];
        }
        $skus = $map[$key] ?? [];
        $payload['unforgivable_packs']['values'][$key]['opening'] = (string) occd_sum_skus($openingBySku, $skus);
        $payload['unforgivable_packs']['values'][$key]['on_order'] = (string) occd_sum_skus($onOrderBySku, $skus);
        $payload['unforgivable_packs']['values'][$key]['opening_source'] = 'manager_opening_stock';
        $payload['unforgivable_packs']['values'][$key]['on_order_source'] = 'ccba_orders';
    }
    return $payload;
}

function occd_merge_inventory_values(array $template, array $saved): array
{
    $merged = $template;
    if (isset($saved['header']) && is_array($saved['header'])) {
        $merged['header'] = array_merge($merged['header'], $saved['header']);
    }
    if (isset($saved['values']) && is_array($saved['values'])) {
        foreach ($saved['values'] as $key => $vals) {
            if (!is_array($vals) || !isset($merged['values'][$key])) {
                continue;
            }
            $merged['values'][$key] = array_merge($merged['values'][$key], $vals);
        }
    }
    return $merged;
}

function occd_merge_dashboard_values(array $template, array $saved): array
{
    $merged = $template;
    if (isset($saved['header']) && is_array($saved['header'])) {
        $merged['header'] = array_merge($merged['header'], $saved['header']);
    }
    foreach (['outlet_data', 'sales_performance', 'service_model', 'execution_excellence', 'unforgivable_packs'] as $panel) {
        if (!isset($saved[$panel]) || !is_array($saved[$panel])) {
            continue;
        }
        if (isset($saved[$panel]['values']) && is_array($saved[$panel]['values'])) {
            $merged[$panel]['values'] = occd_deep_merge_assoc($merged[$panel]['values'], $saved[$panel]['values']);
        }
    }
    return $merged;
}

/** @param array<string, mixed> $base @param array<string, mixed> $patch */
function occd_deep_merge_assoc(array $base, array $patch): array
{
    foreach ($patch as $key => $val) {
        if (is_array($val) && isset($base[$key]) && is_array($base[$key])) {
            $base[$key] = occd_deep_merge_assoc($base[$key], $val);
        } else {
            $base[$key] = $val;
        }
    }
    return $base;
}

function occd_fetch_board_row(PDO $pdo, string $date, string $type): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, board_date, board_type, status, payload_json, submitted_at, updated_at
         FROM manager_daily_boards WHERE board_date = ? AND board_type = ? LIMIT 1'
    );
    $stmt->execute([$date, $type]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $payload = json_decode((string) $row['payload_json'], true);
    if (!is_array($payload)) {
        $payload = [];
    }
    return [
        'id' => (int) $row['id'],
        'board_date' => $row['board_date'],
        'board_type' => $row['board_type'],
        'status' => $row['status'],
        'submitted_at' => $row['submitted_at'],
        'updated_at' => $row['updated_at'],
        'payload' => $payload,
    ];
}

function occd_prefill_from_stock(array $payload, string $type): array
{
    require_once __DIR__ . '/stock.php';
    $stockRows = db()->query(stock_summary_query())->fetchAll();
    $byProduct = [];
    foreach ($stockRows as $s) {
        $byProduct[(int) $s['product_id']] = $s;
    }

    if ($type === 'inventory_board' && isset($payload['values'])) {
        foreach ($payload['lines'] ?? [] as $line) {
            if (($line['row_type'] ?? '') !== 'sku') {
                continue;
            }
            $key = $line['key'];
            if (!isset($payload['values'][$key])) {
                continue;
            }
            $v = &$payload['values'][$key];
            if (($v['opening'] ?? '') === '' || $v['opening'] === null) {
                $v['opening'] = '';
            }
            if (($v['recommended'] ?? '') === '' || $v['recommended'] === null) {
                $v['recommended'] = '';
            }
            unset($v);
        }
        // Match by SKU substring in product catalog for demo prefill
        foreach ($stockRows as $s) {
            $sku = strtolower((string) $s['sku']);
            foreach ($payload['values'] as $key => &$vals) {
                $lineSku = strtolower($key);
                if (($vals['opening'] ?? '') !== '' && ($vals['opening'] ?? '') !== null) {
                    continue;
                }
                if (str_contains($key, 'coke') && str_contains($sku, 'ck')) {
                    $vals['opening'] = (string) (int) $s['warehouse_qty'];
                    $vals['recommended'] = $vals['recommended'] ?: (string) (int) $s['min_stock'];
                }
            }
            unset($vals);
        }
    }

    if ($type === 'occd_dashboard' && isset($payload['unforgivable_packs']['values'])) {
        foreach ($payload['unforgivable_packs']['lines'] as $line) {
            $key = $line['key'];
            if (!isset($payload['unforgivable_packs']['values'][$key])) {
                continue;
            }
            $v = &$payload['unforgivable_packs']['values'][$key];
            if (($v['opening'] ?? '') !== '') {
                continue;
            }
            $skuLabel = strtolower($line['sku']);
            foreach ($stockRows as $s) {
                $name = strtolower((string) $s['name']);
                if ((str_contains($skuLabel, 'coke') && str_contains($name, 'coke'))
                    || (str_contains($skuLabel, 'fanta') && str_contains($name, 'fanta'))
                    || (str_contains($skuLabel, 'water') && str_contains($name, 'novida'))
                    || (str_contains($skuLabel, 'sprite') && str_contains($name, 'sprite'))) {
                    $v['opening'] = (string) (int) $s['warehouse_qty'];
                    $v['recommended'] = $v['recommended'] ?: (string) (int) $s['min_stock'];
                    break;
                }
            }
            unset($v);
        }
    }

    return $payload;
}

function occd_board_for_date(PDO $pdo, string $date, string $type): array
{
    $saved = occd_fetch_board_row($pdo, $date, $type);
    if ($type === 'inventory_board') {
        $template = occd_inventory_board_template();
        $payload = $saved ? occd_merge_inventory_values($template, $saved['payload']) : occd_prefill_from_stock($template, $type);
        $payload = occd_apply_inventory_auto_fields($payload, $date);
    } else {
        $template = occd_dashboard_template();
        $payload = $saved ? occd_merge_dashboard_values($template, $saved['payload']) : occd_prefill_from_stock($template, $type);
        // Always mirror manager opening + CCBA on-order into unforgivable columns.
        $payload = occd_apply_unforgivable_openings($payload, $date);
    }

    return [
        'board_date' => $date,
        'board_type' => $type,
        'status' => $saved['status'] ?? 'draft',
        'submitted_at' => $saved['submitted_at'] ?? null,
        'updated_at' => $saved['updated_at'] ?? null,
        'payload' => $payload,
    ];
}

/**
 * Build executive-brief lines from a saved inventory board row.
 *
 * @param array<string, mixed>|false|null $row
 * @return list<string>
 */
function occd_inventory_brief_lines($row): array
{
    if (!$row || empty($row['payload_json'])) {
        return ['Inventory board: not started — submit CCBA boards before the executive brief.'];
    }
    $payload = json_decode((string) $row['payload_json'], true);
    if (!is_array($payload)) {
        return ['Inventory board: ' . (string) ($row['status'] ?? 'draft') . ' (no readable payload).'];
    }

    $status = (string) ($row['status'] ?? 'draft');
    $header = $payload['header'] ?? [];
    $lines = $payload['lines'] ?? [];
    $values = $payload['values'] ?? [];

    $byCat = [];
    $grandOpening = 0.0;
    $grandRecommended = 0.0;
    $grandOnOrder = 0.0;
    $skuCount = 0;

    foreach ($lines as $line) {
        if (($line['row_type'] ?? '') !== 'sku') {
            continue;
        }
        $key = (string) ($line['key'] ?? '');
        $cat = (string) ($line['category'] ?? 'OTHER');
        $v = $values[$key] ?? [];
        $opening = (float) ($v['opening'] ?? 0);
        $recommended = (float) ($v['recommended'] ?? 0);
        $onOrder = (float) ($v['on_order'] ?? 0);
        if (!isset($byCat[$cat])) {
            $byCat[$cat] = ['opening' => 0.0, 'recommended' => 0.0, 'on_order' => 0.0, 'skus' => 0];
        }
        $byCat[$cat]['opening'] += $opening;
        $byCat[$cat]['recommended'] += $recommended;
        $byCat[$cat]['on_order'] += $onOrder;
        $byCat[$cat]['skus']++;
        $grandOpening += $opening;
        $grandRecommended += $recommended;
        $grandOnOrder += $onOrder;
        $skuCount++;
    }

    $out = [
        'Status: ' . $status . ($row['submitted_at'] ? ' · submitted ' . $row['submitted_at'] : ''),
        'OCCD: ' . trim((string) ($header['occd_name'] ?? '—')) . ' · Region: ' . trim((string) ($header['region'] ?? '—')),
        'SKU lines filled: ' . $skuCount,
        'Grand opening: ' . number_format($grandOpening, 0)
            . ' · Recommended: ' . number_format($grandRecommended, 0)
            . ' · On order: ' . number_format($grandOnOrder, 0),
    ];
    foreach ($byCat as $cat => $tot) {
        $out[] = $cat . ' — opening ' . number_format($tot['opening'], 0)
            . ' · recommended ' . number_format($tot['recommended'], 0)
            . ' · on order ' . number_format($tot['on_order'], 0)
            . ' (' . $tot['skus'] . ' SKUs)';
    }
    return $out;
}

/**
 * Build executive-brief lines from a saved OCCD dashboard row.
 *
 * @param array<string, mixed>|false|null $row
 * @return list<string>
 */
function occd_dashboard_brief_lines($row): array
{
    if (!$row || empty($row['payload_json'])) {
        return ['OCCD dashboard: not started — submit CCBA boards before the executive brief.'];
    }
    $payload = json_decode((string) $row['payload_json'], true);
    if (!is_array($payload)) {
        return ['OCCD dashboard: ' . (string) ($row['status'] ?? 'draft') . ' (no readable payload).'];
    }

    $status = (string) ($row['status'] ?? 'draft');
    $header = $payload['header'] ?? [];
    $out = [
        'Status: ' . $status . ($row['submitted_at'] ? ' · submitted ' . $row['submitted_at'] : ''),
        'OCCD: ' . trim((string) ($header['occd_name'] ?? '—')) . ' · Region: ' . trim((string) ($header['region'] ?? '—')),
    ];

    $sales = $payload['sales_performance']['values']['current_month']['total'] ?? null;
    if (is_array($sales)) {
        $cy = (float) ($sales['cy'] ?? 0);
        $target = (float) ($sales['target'] ?? 0);
        $py = (float) ($sales['py'] ?? 0);
        $out[] = 'Sales MTD total — CY ' . number_format($cy, 0)
            . ' · Target ' . number_format($target, 0)
            . ' · PY ' . number_format($py, 0);
        if ($target > 0) {
            $out[] = 'vs target: ' . number_format((($cy - $target) / $target) * 100, 1) . '%';
        }
    }

    $ytd = $payload['sales_performance']['values']['ytd']['total'] ?? null;
    if (is_array($ytd)) {
        $out[] = 'Sales YTD total — CY ' . number_format((float) ($ytd['cy'] ?? 0), 0)
            . ' · Target ' . number_format((float) ($ytd['target'] ?? 0), 0)
            . ' · PY ' . number_format((float) ($ytd['py'] ?? 0), 0);
    }

    $outletValues = $payload['outlet_data']['values'] ?? [];
    $outletTotal = 0.0;
    foreach ($outletValues as $tierVals) {
        if (!is_array($tierVals)) {
            continue;
        }
        foreach ($tierVals as $n) {
            $outletTotal += (float) $n;
        }
    }
    if ($outletTotal > 0) {
        $out[] = 'Outlet universe (board): ' . number_format($outletTotal, 0);
    }

    $service = $payload['service_model']['values'] ?? [];
    foreach (['call_adherence' => 'Call adherence', 'strike_rate' => 'Strike rate', 'nps' => 'NPS'] as $key => $label) {
        // nps is under execution_model typically
        if ($key === 'nps') {
            continue;
        }
        $rowVals = $service[$key] ?? null;
        if (is_array($rowVals) && (($rowVals['mtd'] ?? '') !== '' || ($rowVals['ytd'] ?? '') !== '')) {
            $out[] = $label . ' — MTD ' . ($rowVals['mtd'] !== '' ? $rowVals['mtd'] : '—')
                . ' · YTD ' . ($rowVals['ytd'] !== '' ? $rowVals['ytd'] : '—');
        }
    }
    $exec = $payload['execution_model']['values'] ?? [];
    if (isset($exec['nps']) && is_array($exec['nps'])) {
        $out[] = 'NPS — MTD ' . (($exec['nps']['mtd'] ?? '') !== '' ? $exec['nps']['mtd'] : '—')
            . ' · YTD ' . (($exec['nps']['ytd'] ?? '') !== '' ? $exec['nps']['ytd'] : '—');
    }

    $uf = $payload['unforgivable_packs']['values'] ?? [];
    $ufOpen = 0.0;
    $ufRec = 0.0;
    foreach ($uf as $v) {
        if (!is_array($v)) {
            continue;
        }
        $ufOpen += (float) ($v['opening'] ?? 0);
        $ufRec += (float) ($v['recommended'] ?? 0);
    }
    if ($ufOpen > 0 || $ufRec > 0) {
        $out[] = 'Unforgivable packs — opening ' . number_format($ufOpen, 0)
            . ' · recommended ' . number_format($ufRec, 0);
    }

    if (count($out) <= 2) {
        $out[] = 'Board submitted with limited figures — open CCBA boards for full detail.';
    }
    return $out;
}

