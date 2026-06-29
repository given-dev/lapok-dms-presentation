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
    } else {
        $template = occd_dashboard_template();
        $payload = $saved ? occd_merge_dashboard_values($template, $saved['payload']) : occd_prefill_from_stock($template, $type);
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
