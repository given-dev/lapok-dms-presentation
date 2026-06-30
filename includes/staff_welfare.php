<?php
declare(strict_types=1);

function welfare_roles_view(): array
{
    return ['accountant', 'manager', 'executive', 'admin'];
}

function welfare_roles_write(): array
{
    return ['accountant', 'manager', 'admin'];
}

function welfare_can_view(string $role): bool
{
    return in_array($role, welfare_roles_view(), true);
}

function welfare_can_write(string $role): bool
{
    return in_array($role, welfare_roles_write(), true);
}

/** @return list<array<string, mixed>> */
function welfare_list_entries(?string $status = null, int $limit = 100): array
{
    $limit = max(1, min(200, $limit));
    $sql = 'SELECT w.*, cu.full_name AS created_by_name, uu.full_name AS updated_by_name
            FROM staff_welfare_entries w
            LEFT JOIN users cu ON cu.id = w.created_by
            LEFT JOIN users uu ON uu.id = w.updated_by';
    $params = [];
    if ($status && in_array($status, ['open', 'resolved'], true)) {
        $sql .= ' WHERE w.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY w.entry_date DESC, w.id DESC LIMIT ' . $limit;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = welfare_row_to_response($row);
    }
    return $rows;
}

/** @param array<string, mixed> $row */
function welfare_row_to_response(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'date' => $row['entry_date'],
        'staff' => $row['staff_name'],
        'type' => $row['entry_type'],
        'amount' => (float) $row['amount_ugx'],
        'status' => $row['status'],
        'notes' => $row['notes'] ?? '',
        'created_by' => (int) $row['created_by'],
        'created_by_name' => $row['created_by_name'] ?? null,
        'updated_by' => isset($row['updated_by']) ? (int) $row['updated_by'] : null,
        'updated_by_name' => $row['updated_by_name'] ?? null,
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

/** @param array<string, mixed> $body */
function welfare_save_entry(array $body, int $userId): array
{
    $id = (int) ($body['id'] ?? 0);
    $date = trim((string) ($body['date'] ?? date('Y-m-d')));
    $staff = trim((string) ($body['staff'] ?? ''));
    $type = trim((string) ($body['type'] ?? 'request'));
    $amount = max(0, (float) ($body['amount'] ?? 0));
    $status = trim((string) ($body['status'] ?? 'open'));
    $notes = trim((string) ($body['notes'] ?? '')) ?: null;

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_error('Invalid date');
    }
    if ($staff === '') {
        json_error('Staff name is required');
    }
    if (!in_array($type, ['request', 'advance', 'medical', 'other'], true)) {
        json_error('Invalid welfare type');
    }
    if (!in_array($status, ['open', 'resolved'], true)) {
        json_error('Invalid status');
    }

    $pdo = db();
    if ($id > 0) {
        $existing = $pdo->prepare('SELECT * FROM staff_welfare_entries WHERE id = ? LIMIT 1');
        $existing->execute([$id]);
        $row = $existing->fetch();
        if (!$row) {
            json_error('Welfare entry not found', 404);
        }
        $pdo->prepare(
            'UPDATE staff_welfare_entries
             SET entry_date = ?, staff_name = ?, entry_type = ?, amount_ugx = ?, status = ?, notes = ?, updated_by = ?, updated_at = NOW()
             WHERE id = ?'
        )->execute([$date, $staff, $type, $amount, $status, $notes, $userId, $id]);
        audit_log($userId, 'staff_welfare_entries', $id, 'update', $row, [
            'date' => $date, 'staff' => $staff, 'status' => $status,
        ]);
    } else {
        $pdo->prepare(
            'INSERT INTO staff_welfare_entries
             (entry_date, staff_name, entry_type, amount_ugx, status, notes, created_by, updated_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute([$date, $staff, $type, $amount, $status, $notes, $userId, $userId]);
        $id = (int) $pdo->lastInsertId();
        audit_log($userId, 'staff_welfare_entries', $id, 'create', null, [
            'date' => $date, 'staff' => $staff, 'type' => $type,
        ]);
    }

    $stmt = $pdo->prepare(
        'SELECT w.*, cu.full_name AS created_by_name, uu.full_name AS updated_by_name
         FROM staff_welfare_entries w
         LEFT JOIN users cu ON cu.id = w.created_by
         LEFT JOIN users uu ON uu.id = w.updated_by
         WHERE w.id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    $saved = $stmt->fetch();
    if (!$saved) {
        json_error('Could not load saved entry', 500);
    }
    return welfare_row_to_response($saved);
}

function welfare_delete_entry(int $id, int $userId): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM staff_welfare_entries WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        json_error('Welfare entry not found', 404);
    }
    $pdo->prepare('DELETE FROM staff_welfare_entries WHERE id = ?')->execute([$id]);
    audit_log($userId, 'staff_welfare_entries', $id, 'delete', $row, null);
}

function welfare_summary(): array
{
    $pdo = db();
    $open = (int) $pdo->query("SELECT COUNT(*) FROM staff_welfare_entries WHERE status = 'open'")->fetchColumn();
    $resolved = (int) $pdo->query("SELECT COUNT(*) FROM staff_welfare_entries WHERE status = 'resolved'")->fetchColumn();
    $openAmount = (float) $pdo->query("SELECT COALESCE(SUM(amount_ugx), 0) FROM staff_welfare_entries WHERE status = 'open'")->fetchColumn();
    return [
        'open_count' => $open,
        'resolved_count' => $resolved,
        'open_amount' => $openAmount,
    ];
}
