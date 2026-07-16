<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/rdc_balancing.php';

$user = require_login();
if (!role_can($user['role'], 'rdc_balancing') && !role_can($user['role'], 'rdc_view')) {
    json_error('Insufficient permissions', 403);
}

$date = trim($_GET['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    json_error('Invalid date  -  use YYYY-MM-DD');
}

try {
    $stmt = db()->prepare('SELECT * FROM rdc_daily_sheets WHERE balance_date = ? LIMIT 1');
    $stmt->execute([$date]);
    $row = $stmt->fetch();
} catch (Throwable $e) {
    json_error('RDC tables not ready  -  run migrations 008 and 009.', 500);
}

if (!$row) {
    try {
        $template = rdc_new_sheet_template($date);
        if (role_can($user['role'], 'rdc_balancing')) {
            rdc_sync_cadet_reports_into_sheet(db(), $date, true);
            $stmt->execute([$date]);
            $row = $stmt->fetch();
            if ($row) {
                $template = rdc_sheet_to_response($row);
            }
        }
    } catch (Throwable $e) {
        json_error('Could not build RDC sheet: ' . $e->getMessage(), 500);
    }
    $canEdit = role_can($user['role'], 'rdc_balancing');
    $reports = rdc_cadet_reports_for_date($date);
    json_ok([
        'sheet' => $row ? rdc_sheet_to_response($row) : $template,
        'is_new' => !$row,
        'read_only' => !$canEdit,
        'editor_mode' => $canEdit ? 'accountant' : 'viewer',
        'cadet_consolidation' => [
            'reports_today' => count($reports),
            'vehicles_synced' => count($reports),
            'reports' => $reports,
        ],
    ]);
    exit;
}

if (role_can($user['role'], 'rdc_balancing')) {
    $lockedStatuses = ['submitted', 'under_review', 'approved', 'rejected'];
    if (!in_array($row['status'], $lockedStatuses, true)) {
        rdc_sync_cadet_reports_into_sheet(db(), $date, true);
        $stmt->execute([$date]);
        $row = $stmt->fetch();
    }
}

$role = (string) ($user['role'] ?? '');
$status = (string) ($row['status'] ?? 'draft');
$lockedStatuses = ['submitted', 'under_review', 'approved', 'rejected'];
$locked = in_array($status, $lockedStatuses, true);

// Accountant (balancing) edits drafts/reopened; admin may edit even when locked.
// Manager (review) may correct a received sheet while submitted / under_review.
$canAccountantEdit = role_can($role, 'rdc_balancing') && (!$locked || $role === 'admin');
$canManagerEdit = role_can($role, 'rdc_review')
    && in_array($status, ['submitted', 'under_review'], true)
    && in_array($role, ['manager', 'admin'], true);
$readOnly = !($canAccountantEdit || $canManagerEdit);

$cadetReports = rdc_cadet_reports_for_date($date);

json_ok([
    'sheet' => rdc_sheet_to_response($row),
    'is_new' => false,
    'read_only' => $readOnly,
    'editor_mode' => $canManagerEdit && !$canAccountantEdit ? 'manager' : ($canAccountantEdit ? 'accountant' : 'viewer'),
    'cadet_consolidation' => [
        'reports_today' => count($cadetReports),
        'vehicles_synced' => count($cadetReports),
        'reports' => $cadetReports,
    ],
]);
