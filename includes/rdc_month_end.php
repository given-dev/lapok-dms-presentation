<?php
declare(strict_types=1);

/** @return array<string, mixed> */
function rdc_month_end_default_state(): array
{
    return [
        'automation' => [
            ['key' => 'bankFeeds', 'label' => 'Bank feeds synced daily', 'enabled' => true],
            ['key' => 'invoiceCapture', 'label' => 'Invoice capture and coding workflow (manual in this release)', 'enabled' => true],
            ['key' => 'recurringJv', 'label' => 'Recurring journals posted automatically', 'enabled' => false],
            ['key' => 'reconciliationRules', 'label' => 'Reconciliation rules active', 'enabled' => true],
        ],
        'checklist' => [
            ['task' => 'Bank reconciliation complete', 'owner' => 'Accountant', 'due' => 'D+1 10:00', 'status' => 'pending'],
            ['task' => 'Sales and cash tie-out', 'owner' => 'Accountant', 'due' => 'D+1 11:00', 'status' => 'pending'],
            ['task' => 'Expense review and coding', 'owner' => 'Manager', 'due' => 'D+1 13:00', 'status' => 'pending'],
            ['task' => 'Close pack sent to leadership', 'owner' => 'Accountant', 'due' => 'D+1 16:00', 'status' => 'pending'],
        ],
        'controls' => [
            ['action' => 'Cash confirmation', 'maker' => 'Cadet', 'checker' => 'Accountant', 'status' => 'active'],
            ['action' => 'Credit adjustment', 'maker' => 'Accountant', 'checker' => 'Manager', 'status' => 'active'],
        ],
        'documents' => [
            ['name' => 'Route cash handover note', 'source' => 'Cadet', 'status' => 'received'],
            ['name' => 'Fuel support receipt', 'source' => 'Driver', 'status' => 'missing'],
        ],
        'templates' => [
            'pnl' => 'Revenue vs fuel/operating costs, with major variances and action owners.',
        ],
        'approvalMatrix' => 'green',
        'processReviewDate' => '',
        'bottlenecks' => '',
        'sopUpdates' => '',
        'monthlySummary' => '',
    ];
}

function rdc_month_end_roles_view(): array
{
    return ['accountant', 'manager', 'executive', 'admin'];
}

function rdc_month_end_roles_edit(): array
{
    return ['accountant', 'admin'];
}

function rdc_month_end_can_view(string $role): bool
{
    return in_array($role, rdc_month_end_roles_view(), true);
}

function rdc_month_end_can_edit(string $role): bool
{
    return in_array($role, rdc_month_end_roles_edit(), true);
}

/** @param array<string, mixed> $state */
function rdc_month_end_normalize_state(array $state): array
{
    $default = rdc_month_end_default_state();
    $merged = array_merge($default, $state);
    if (isset($state['templates']) && is_array($state['templates'])) {
        $merged['templates'] = [
            'pnl' => (string) ($state['templates']['pnl'] ?? $default['templates']['pnl']),
        ];
    }
    foreach (['automation', 'checklist', 'controls', 'documents'] as $key) {
        if (!isset($merged[$key]) || !is_array($merged[$key])) {
            $merged[$key] = $default[$key];
        }
    }
    return $merged;
}

function rdc_month_end_fetch(string $month): ?array
{
    $stmt = db()->prepare(
        'SELECT m.*, u.full_name AS updated_by_name
         FROM rdc_month_end m
         LEFT JOIN users u ON u.id = m.updated_by
         WHERE m.period_month = ?
         LIMIT 1'
    );
    $stmt->execute([$month]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $state = json_decode((string) ($row['state_json'] ?? '{}'), true);
    return [
        'period_month' => $row['period_month'],
        'state' => rdc_month_end_normalize_state(is_array($state) ? $state : []),
        'updated_by' => isset($row['updated_by']) ? (int) $row['updated_by'] : null,
        'updated_by_name' => $row['updated_by_name'] ?? null,
        'updated_at' => $row['updated_at'],
    ];
}

/** @param array<string, mixed> $state */
function rdc_month_end_save(string $month, array $state, int $userId): array
{
    $clean = rdc_month_end_normalize_state($state);
    $pdo = db();
    $pdo->prepare(
        'INSERT INTO rdc_month_end (period_month, state_json, updated_by, updated_at)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
           state_json = VALUES(state_json),
           updated_by = VALUES(updated_by),
           updated_at = NOW()'
    )->execute([
        $month,
        json_encode($clean, JSON_UNESCAPED_UNICODE),
        $userId,
    ]);

    audit_log($userId, 'rdc_month_end', null, 'save', null, ['period_month' => $month]);

    $saved = rdc_month_end_fetch($month);
    return $saved ?? [
        'period_month' => $month,
        'state' => $clean,
        'updated_by' => $userId,
        'updated_by_name' => null,
        'updated_at' => date('Y-m-d H:i:s'),
    ];
}
