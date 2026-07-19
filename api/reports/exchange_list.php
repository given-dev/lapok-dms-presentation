<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/report_packets.php';

$user = require_permission('reports');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    json_error('Method not allowed', 405);
}

try {
    $role = $user['role'];
    $userId = (int) $user['id'];
    $limit = 50;
    $reportDate = trim((string) ($_GET['report_date'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
        json_error('Invalid report_date');
    }

    if ($role === 'admin') {
        $stmt = db()->query(
            'SELECT rp.*, fu.full_name AS from_name, fu.email AS from_email, au.full_name AS acknowledged_by_name
             FROM report_packets rp
             JOIN users fu ON fu.id = rp.from_user_id
             LEFT JOIN users au ON au.id = rp.acknowledged_by
             ORDER BY rp.sent_at DESC
             LIMIT ' . (int) $limit
        );
        $inbox = array_map(fn($r) => report_format_packet($r, 'admin', 0), $stmt->fetchAll());
    } else {
        $inbox = report_inbox($role);
    }

    $inbox = array_map(
        static function (array $packet): array {
            $sentAt = strtotime((string) ($packet['sent_at'] ?? ''));
            $ageHours = $sentAt > 0 ? (int) floor((time() - $sentAt) / 3600) : 0;
            $isOverdue = in_array($packet['status'], ['sent', 'read'], true) && $ageHours >= 24;
            $isCritical = $isOverdue || in_array(($packet['report_type'] ?? ''), ['manager_brief', 'accountant_pack'], true);
            $packet['age_hours'] = $ageHours;
            $packet['is_overdue'] = $isOverdue;
            $packet['is_critical'] = $isCritical;
            return $packet;
        },
        $inbox
    );

    json_ok([
        'role' => $role,
        'next_recipient' => $role === 'admin' ? 'executive' : report_next_recipient($role),
        'inbox' => $inbox,
        'outbox' => report_outbox($userId),
        'chain' => [
            ['role' => 'field', 'label' => 'Field agents', 'sends_to' => 'accountant'],
            ['role' => 'accountant', 'label' => 'Accountant', 'sends_to' => 'manager'],
            ['role' => 'manager', 'label' => 'Manager', 'sends_to' => 'executive'],
            ['role' => 'executive', 'label' => 'Executive / Board', 'sends_to' => null],
        ],
        'manager_readiness' => in_array($role, ['manager', 'admin'], true)
            ? report_manager_readiness($reportDate)
            : null,
        'accountant_readiness' => in_array($role, ['accountant', 'admin'], true)
            ? report_accountant_readiness($reportDate)
            : null,
        'chain_status' => report_chain_status($reportDate),
    ]);
} catch (Throwable $e) {
    json_error('Could not load report exchange  -  run migration 005_report_exchange.sql.', 500);
}
