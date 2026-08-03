<?php
declare(strict_types=1);

// Shared datetime range helpers (also defined in bootstrap.php for API endpoints);
// guarded so this file is self-contained when loaded without bootstrap (CLI/cron).
if (!function_exists('day_bounds')) {
    function day_bounds(string $date): array
    {
        return [$date . ' 00:00:00', date('Y-m-d 00:00:00', strtotime($date . ' +1 day'))];
    }
}
if (!function_exists('period_bounds')) {
    function period_bounds(string $from, string $to): array
    {
        return [$from . ' 00:00:00', date('Y-m-d 00:00:00', strtotime($to . ' +1 day'))];
    }
}

/**
 * Cash out ledger — cadets issue credit to customers (amount out) and
 * record recoveries over time until the balance is settled.
 */

/** Roles that may view the full cashout ledger (manager + RDC/accountant + exec/admin). */
function cashout_view_all_roles(): array
{
    return ['admin', 'manager', 'accountant', 'executive'];
}

function cashout_can_view_all(string $role): bool
{
    return in_array($role, cashout_view_all_roles(), true);
}

/** @return list<array<string, mixed>> */
function cashout_list(PDO $pdo, ?int $cadetId = null, ?string $status = null, string $search = ''): array
{
    $sql = "SELECT cc.id, cc.customer_id, cc.cadet_id, cc.trip_id, cc.amount_out, cc.balance,
                   cc.status, cc.notes, cc.created_at,
                   c.name AS customer_name, c.nin, c.phone, c.location,
                   u.full_name AS cadet_name,
                   COALESCE(SUM(p.amount), 0) AS paid_total
            FROM customer_cashouts cc
            JOIN customers c ON c.id = cc.customer_id
            LEFT JOIN users u ON u.id = cc.cadet_id
            LEFT JOIN cashout_payments p ON p.cashout_id = cc.id
            WHERE 1 = 1";
    $params = [];

    if ($cadetId !== null) {
        $sql .= ' AND cc.cadet_id = ?';
        $params[] = $cadetId;
    }
    if ($status !== null && in_array($status, ['open', 'settled'], true)) {
        $sql .= ' AND cc.status = ?';
        $params[] = $status;
    }
    if ($search !== '') {
        $sql .= ' AND (c.name LIKE ? OR c.nin LIKE ? OR c.phone LIKE ?)';
        $q = '%' . $search . '%';
        $params = array_merge($params, [$q, $q, $q]);
    }

    $sql .= ' GROUP BY cc.id ORDER BY (cc.status = \'open\') DESC, cc.created_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[] = cashout_row_to_array($row);
    }
    return $out;
}

/** @return array<string, mixed> */
function cashout_row_to_array(array $row): array
{
    $amountOut = (float) $row['amount_out'];
    $balance = (float) $row['balance'];
    $paid = (float) ($row['paid_total'] ?? $amountOut - $balance);
    return [
        'id' => (int) $row['id'],
        'customer_id' => (int) $row['customer_id'],
        'customer_name' => (string) ($row['customer_name'] ?? ''),
        'nin' => (string) ($row['nin'] ?? ''),
        'phone' => (string) ($row['phone'] ?? ''),
        'location' => (string) ($row['location'] ?? ''),
        'cadet_id' => (int) $row['cadet_id'],
        'cadet_name' => (string) ($row['cadet_name'] ?? ''),
        'trip_id' => isset($row['trip_id']) && $row['trip_id'] !== null ? (int) $row['trip_id'] : null,
        'amount_out' => $amountOut,
        'paid_total' => round($paid, 2),
        'balance' => round($balance, 2),
        'status' => (string) $row['status'],
        'notes' => (string) ($row['notes'] ?? ''),
        'created_at' => (string) ($row['created_at'] ?? ''),
    ];
}

/**
 * @return array{cash_out: array<int, float>, recoveries: array<int, float>}
 *     Per cadet amounts issued / collected on a given date, keyed by cadet id.
 */
function cashout_daily_totals(PDO $pdo, string $date): array
{
    $issued = [];
    $collected = [];

    [$dayFrom, $dayUntil] = day_bounds($date);
    $stmt = $pdo->prepare(
        'SELECT cadet_id, COALESCE(SUM(amount_out), 0) AS total
         FROM customer_cashouts
         WHERE created_at >= ? AND created_at < ?
         GROUP BY cadet_id'
    );
    $stmt->execute([$dayFrom, $dayUntil]);
    foreach ($stmt->fetchAll() as $row) {
        $issued[(int) $row['cadet_id']] = (float) $row['total'];
    }

    $stmt = $pdo->prepare(
        'SELECT cc.cadet_id, COALESCE(SUM(p.amount), 0) AS total
         FROM cashout_payments p
         JOIN customer_cashouts cc ON cc.id = p.cashout_id
         WHERE p.paid_on = ?
         GROUP BY cc.cadet_id'
    );
    $stmt->execute([$date]);
    foreach ($stmt->fetchAll() as $row) {
        $collected[(int) $row['cadet_id']] = (float) $row['total'];
    }

    return ['cash_out' => $issued, 'recoveries' => $collected];
}

function cashout_find(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT cc.*, c.name AS customer_name, c.nin, c.phone
         FROM customer_cashouts cc
         JOIN customers c ON c.id = cc.customer_id
         WHERE cc.id = ? LIMIT 1"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Prefill RDC sheet cash_out / recoveries rows from the cashout ledger for a date.
 * Only fills cadet_* columns, and only where the existing amount is still zero so
 * RDC manual adjustments are never overwritten.
 *
 * @param list<array<string, mixed>> $recoveriesRows
 * @param list<array<string, mixed>> $cashOutRows
 * @return array{recoveries: array, cash_out: array}
 */
function cashout_prefill_sheet_totals(array $recoveriesRows, array $cashOutRows, string $date): array
{
    $pdo = db();
    $totals = cashout_daily_totals($pdo, $date);

    $apply = static function (array &$row, array $ledger) {
        foreach ($row['amounts'] ?? [] as $key => $value) {
            if (!str_starts_with((string) $key, 'cadet_')) {
                continue;
            }
            $cadetId = (int) substr((string) $key, 6);
            $amount = (float) ($ledger[$cadetId] ?? 0);
            if ($amount > 0 && (float) $value === 0.0) {
                $row['amounts'][$key] = $amount;
            }
        }
    };

    foreach ($recoveriesRows as &$row) {
        $apply($row, $totals['recoveries']);
    }
    unset($row);
    foreach ($cashOutRows as &$row) {
        $apply($row, $totals['cash_out']);
    }
    unset($row);

    return ['recoveries' => $recoveriesRows, 'cash_out' => $cashOutRows];
}

/** @return array<string, mixed> */
function cashout_create(PDO $pdo, int $customerId, int $cadetId, float $amountOut, ?int $tripId, ?string $notes): array
{
    if ($amountOut <= 0) {
        throw new RuntimeException('Amount out must be greater than zero');
    }

    $cStmt = $pdo->prepare('SELECT id FROM customers WHERE id = ? AND is_active = 1');
    $cStmt->execute([$customerId]);
    if (!$cStmt->fetch()) {
        throw new RuntimeException('Customer not found');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO customer_cashouts (customer_id, cadet_id, trip_id, amount_out, balance, status, notes)
         VALUES (?, ?, ?, ?, ?, \'open\', ?)'
    );
    $stmt->execute([$customerId, $cadetId, $tripId, $amountOut, $amountOut, $notes !== '' ? $notes : null]);
    $id = (int) $pdo->lastInsertId();

    return cashout_find($pdo, $id) ?: [];
}

/**
 * Record a recovery (repayment) and settle the cashout when balance reaches zero.
 *
 * @return array<string, mixed>
 */
function cashout_record_recovery(PDO $pdo, int $cashoutId, int $notedBy, float $amount, string $paidOn): array
{
    if ($amount <= 0) {
        throw new RuntimeException('Recovery amount must be greater than zero');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidOn)) {
        throw new RuntimeException('Invalid recovery date');
    }

    $cashout = cashout_find($pdo, $cashoutId);
    if (!$cashout) {
        throw new RuntimeException('Cash out not found', 404);
    }
    if ((float) $cashout['balance'] <= 0) {
        throw new RuntimeException('This cash out is already settled');
    }

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare(
            'INSERT INTO cashout_payments (cashout_id, amount, paid_on, noted_by) VALUES (?, ?, ?, ?)'
        );
        $ins->execute([$cashoutId, $amount, $paidOn, $notedBy]);
        $paymentId = (int) $pdo->lastInsertId();

        $balance = round(max(0, (float) $cashout['balance'] - $amount), 2);
        $status = $balance <= 0 ? 'settled' : 'open';
        $upd = $pdo->prepare('UPDATE customer_cashouts SET balance = ?, status = ? WHERE id = ?');
        $upd->execute([$balance, $status, $cashoutId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [
        'cashout' => cashout_find($pdo, $cashoutId) ?: [],
        'payment_id' => $paymentId,
        'settled' => $status === 'settled',
        'balance' => $balance,
    ];
}
