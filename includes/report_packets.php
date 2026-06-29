<?php
declare(strict_types=1);

require_once __DIR__ . '/simple_pdf.php';

const REPORT_LEADERSHIP_ROLES = ['accountant', 'manager', 'executive', 'admin'];

const REPORT_TYPE_LABELS = [
    'field_eod' => 'Field EOD',
    'accountant_pack' => 'Finance consolidation',
    'manager_brief' => 'Executive brief',
    'uploaded' => 'Uploaded PDF',
];

function report_storage_dir(): string
{
    return dirname(__DIR__) . '/storage/reports';
}

function report_generate_ref(string $prefix): string
{
    return strtoupper($prefix) . '-' . date('Ymd') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
}

function report_next_recipient(string $role): ?string
{
    return match ($role) {
        'accountant' => 'manager',
        'manager' => 'executive',
        default => null,
    };
}

function report_can_access_role(string $userRole, string $packetRole, bool $isSender, int $userId, int $fromUserId): bool
{
    if ($userRole === 'admin') {
        return true;
    }
    if ($isSender && $userId === $fromUserId) {
        return true;
    }
    return $userRole === $packetRole;
}

/** @return array<string, mixed>|null */
function report_fetch_packet(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT rp.*, fu.full_name AS from_name, fu.email AS from_email,
                au.full_name AS acknowledged_by_name
         FROM report_packets rp
         JOIN users fu ON fu.id = rp.from_user_id
         LEFT JOIN users au ON au.id = rp.acknowledged_by
         WHERE rp.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function report_format_packet(array $row, string $viewerRole, int $viewerId): array
{
    $isInbox = report_can_access_role($viewerRole, $row['to_role'], false, $viewerId, (int) $row['from_user_id']);
    return [
        'id' => (int) $row['id'],
        'packet_ref' => $row['packet_ref'],
        'report_type' => $row['report_type'],
        'report_type_label' => REPORT_TYPE_LABELS[$row['report_type']] ?? $row['report_type'],
        'title' => $row['title'],
        'summary' => $row['summary'],
        'report_date' => $row['report_date'],
        'file_name' => $row['file_name'],
        'file_size' => (int) $row['file_size'],
        'from_user_id' => (int) $row['from_user_id'],
        'from_name' => $row['from_name'],
        'from_role' => $row['from_role'],
        'to_role' => $row['to_role'],
        'status' => $row['status'],
        'parent_packet_id' => $row['parent_packet_id'] !== null ? (int) $row['parent_packet_id'] : null,
        'sent_at' => $row['sent_at'],
        'read_at' => $row['read_at'],
        'acknowledged_at' => $row['acknowledged_at'],
        'acknowledged_by_name' => $row['acknowledged_by_name'] ?? null,
        'notes' => $row['notes'],
        'direction' => $isInbox ? 'inbox' : 'outbox',
    ];
}

/** @return array<int, array<string, mixed>> */
function report_inbox(string $role, int $limit = 50): array
{
    if ($role === 'admin') {
        $stmt = db()->query(
            'SELECT rp.*, fu.full_name AS from_name, fu.email AS from_email, au.full_name AS acknowledged_by_name
             FROM report_packets rp
             JOIN users fu ON fu.id = rp.from_user_id
             LEFT JOIN users au ON au.id = rp.acknowledged_by
             ORDER BY rp.sent_at DESC
             LIMIT ' . (int) $limit
        );
        return array_map(fn($r) => report_format_packet($r, $role, 0), $stmt->fetchAll());
    }

    $stmt = db()->prepare(
        'SELECT rp.*, fu.full_name AS from_name, fu.email AS from_email, au.full_name AS acknowledged_by_name
         FROM report_packets rp
         JOIN users fu ON fu.id = rp.from_user_id
         LEFT JOIN users au ON au.id = rp.acknowledged_by
         WHERE rp.to_role = ?
         ORDER BY rp.sent_at DESC
         LIMIT ' . (int) $limit
    );
    $stmt->execute([$role]);
    return array_map(fn($r) => report_format_packet($r, $role, 0), $stmt->fetchAll());
}

/** @return array<int, array<string, mixed>> */
function report_outbox(int $userId, int $limit = 50): array
{
    $stmt = db()->prepare(
        'SELECT rp.*, fu.full_name AS from_name, fu.email AS from_email, au.full_name AS acknowledged_by_name
         FROM report_packets rp
         JOIN users fu ON fu.id = rp.from_user_id
         LEFT JOIN users au ON au.id = rp.acknowledged_by
         WHERE rp.from_user_id = ?
         ORDER BY rp.sent_at DESC
         LIMIT ' . (int) $limit
    );
    $stmt->execute([$userId]);
    $role = current_user()['role'] ?? '';
    return array_map(fn($r) => report_format_packet($r, $role, $userId), $stmt->fetchAll());
}

function report_ensure_demo_files(): void
{
    $dir = report_storage_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $demos = [
        'demo-eod-001.pdf' => ['Field EOD Report', [
            'Agent: David Ssemuju (Cadet)',
            'Vehicle: TUK-001 · Route: Owino / Katwe',
            'Date: 07 May 2026',
            '',
            'Cash reported: UGX 480,000',
            'Stock returned: 4 cartons',
            'Notes: 2 edit requests pending manager approval.',
        ]],
        'demo-acc-001.pdf' => ['Accountant Daily Consolidation', [
            'Prepared by: Grace Apio (Accountant)',
            'Date: 07 May 2026',
            '',
            'Cash confirmations pending: 1 trip',
            'Receivables total: UGX 280,000',
            'Variance vs reported cash: UGX 0',
            'Forwarded to: Manager (Sarah Nakato)',
        ]],
        'demo-mgr-001.pdf' => ['Manager Executive Brief', [
            'Prepared by: Sarah Nakato (Manager)',
            'Date: 07 May 2026 · Region: Northern',
            '',
            'Sales today: 186 cartons · UGX 3.72M',
            'Low stock: Coke 500ml, Sprite 1L',
            'Fleet: 3/4 vehicles on route',
            'Submitted to: Executive / Board',
        ]],
    ];
    foreach ($demos as $file => [$title, $lines]) {
        $path = $dir . '/' . $file;
        if (!is_file($path)) {
            simple_pdf_write($path, $title, $lines);
        }
    }
}

function report_save_uploaded_file(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('PDF upload failed');
    }
    $name = (string) ($file['name'] ?? '');
    $tmp = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        throw new RuntimeException('Only PDF files are accepted');
    }
    if ($size > 10 * 1024 * 1024) {
        throw new RuntimeException('PDF must be under 10 MB');
    }

    $dir = report_storage_dir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create storage directory');
    }

    $stored = 'upload-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.pdf';
    $dest = $dir . '/' . $stored;
    if (!move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('Could not save uploaded PDF');
    }

    return [
        'file_path' => 'storage/reports/' . $stored,
        'file_name' => $name,
        'file_size' => $size,
    ];
}

function report_insert_packet(
    string $reportType,
    string $title,
    ?string $summary,
    string $reportDate,
    string $filePath,
    string $fileName,
    int $fileSize,
    int $fromUserId,
    string $fromRole,
    string $toRole,
    ?int $parentId = null,
    ?int $tripId = null,
    ?string $notes = null
): array {
    $ref = report_generate_ref(
        match ($reportType) {
            'field_eod' => 'RPT-EOD',
            'accountant_pack' => 'RPT-ACC',
            'manager_brief' => 'RPT-MGR',
            default => 'RPT-UPL',
        }
    );

    $stmt = db()->prepare(
        'INSERT INTO report_packets (
            packet_ref, report_type, title, summary, report_date,
            file_path, file_name, file_size,
            from_user_id, from_role, to_role, status,
            parent_packet_id, trip_id, notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $ref, $reportType, $title, $summary, $reportDate,
        $filePath, $fileName, $fileSize,
        $fromUserId, $fromRole, $toRole, 'sent',
        $parentId, $tripId, $notes,
    ]);

    $id = (int) db()->lastInsertId();
    $row = report_fetch_packet($id);
    return $row ? report_format_packet($row, $fromRole, $fromUserId) : ['id' => $id];
}

/** @return array<int, string> */
function report_build_accountant_lines(string $date): array
{
    $pdo = db();
    $cash = $pdo->query(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(cash_reported),0) AS reported
         FROM delivery_trips WHERE DATE(returned_at) = " . $pdo->quote($date)
    )->fetch();
    $recv = $pdo->query(
        'SELECT COALESCE(SUM(credit_balance),0) AS total FROM customers WHERE is_active = 1'
    )->fetch();
    return [
        'Report date: ' . $date,
        '',
        'Trips returned today: ' . (int) ($cash['cnt'] ?? 0),
        'Cash reported by field: UGX ' . number_format((float) ($cash['reported'] ?? 0)),
        'Outstanding receivables: UGX ' . number_format((float) ($recv['total'] ?? 0)),
        '',
        'Includes: cash handover status, receivables, trip variances.',
        'Recipient: Manager',
    ];
}

/** @return array<int, string> */
function report_build_manager_lines(string $date): array
{
    $pdo = db();
    $sales = $pdo->query(
        "SELECT COUNT(*) AS orders, COALESCE(SUM(amount_total),0) AS revenue
         FROM orders WHERE DATE(created_at) = " . $pdo->quote($date)
    )->fetch();
    $low = $pdo->query(
        'SELECT name, warehouse_qty FROM (
            SELECT p.name, COALESCE(SUM(b.qty_warehouse),0) AS warehouse_qty, p.min_stock
            FROM products p LEFT JOIN batches b ON b.product_id = p.id
            WHERE p.is_active = 1 GROUP BY p.id
         ) x WHERE warehouse_qty < min_stock LIMIT 5'
    )->fetchAll();
    $pendingEdits = (int) $pdo->query("SELECT COUNT(*) FROM edit_requests WHERE status = 'pending'")->fetchColumn();
    $pendingOrders = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
    $onRoute = (int) $pdo->query("SELECT COUNT(*) FROM vehicles WHERE status = 'on_route'")->fetchColumn();
    $vehicles = (int) $pdo->query("SELECT COUNT(*) FROM vehicles WHERE is_active = 1")->fetchColumn();

    $inv = $pdo->prepare("SELECT status FROM manager_daily_boards WHERE board_date = ? AND board_type = 'inventory_board' LIMIT 1");
    $inv->execute([$date]);
    $invRow = $inv->fetch();
    $occd = $pdo->prepare("SELECT status FROM manager_daily_boards WHERE board_date = ? AND board_type = 'occd_dashboard' LIMIT 1");
    $occd->execute([$date]);
    $occdRow = $occd->fetch();

    $lines = [
        'Report date: ' . $date,
        '',
        'Orders today: ' . (int) ($sales['orders'] ?? 0),
        'Revenue today: UGX ' . number_format((float) ($sales['revenue'] ?? 0)),
        'Pending sales to confirm: ' . $pendingOrders,
        'Edit requests pending: ' . $pendingEdits,
        'Fleet on route: ' . $onRoute . '/' . $vehicles,
        '',
        'CCBA boards today:',
        '  · Inventory board: ' . ($invRow['status'] ?? 'not started'),
        '  · OCCD dashboard: ' . ($occdRow['status'] ?? 'not started'),
        '',
        'Low stock items:',
    ];
    foreach ($low as $row) {
        $lines[] = '  · ' . $row['name'] . ' (' . $row['warehouse_qty'] . ' crates)';
    }
    if (!$low) {
        $lines[] = '  · None flagged';
    }
    $lines[] = '';
    $lines[] = 'Recipient: Executive / Board';
    return $lines;
}

function report_generate_pack(string $role, int $userId, string $date, ?string $title = null, ?string $notes = null): array
{
    $toRole = report_next_recipient($role);
    if (!$toRole) {
        throw new RuntimeException('Your role cannot send reports upward');
    }

    $dir = report_storage_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    if ($role === 'accountant') {
        $reportType = 'accountant_pack';
        $pdfTitle = $title ?: 'Daily finance consolidation — ' . $date;
        $lines = report_build_accountant_lines($date);
        $prefix = 'acc-pack';
    } else {
        $reportType = 'manager_brief';
        $pdfTitle = $title ?: 'Executive operations brief — ' . $date;
        $lines = report_build_manager_lines($date);
        $prefix = 'mgr-brief';
    }

    $file = $prefix . '-' . date('Ymd-His') . '.pdf';
    $abs = $dir . '/' . $file;
    simple_pdf_write($abs, $pdfTitle, $lines);
    $size = (int) filesize($abs);

    return report_insert_packet(
        $reportType,
        $pdfTitle,
        $notes ?: 'Generated from Lapok DMS data.',
        $date,
        'storage/reports/' . $file,
        basename($file),
        $size,
        $userId,
        $role,
        $toRole,
        null,
        null,
        $notes
    );
}

function report_create_field_eod(int $tripId, int $userId, string $userRole, float $cashReported, ?string $notes): ?array
{
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT dt.*, v.registration, u.full_name
         FROM delivery_trips dt
         JOIN vehicles v ON v.id = dt.vehicle_id
         JOIN users u ON u.id = ?
         WHERE dt.id = ?'
    );
    $stmt->execute([$userId, $tripId]);
    $trip = $stmt->fetch();
    if (!$trip) {
        return null;
    }

    $date = date('Y-m-d');
    $title = sprintf(
        'EOD — %s · %s · %s',
        $trip['full_name'],
        $trip['registration'],
        $trip['route_area'] ?: 'Route'
    );
    $lines = [
        'Agent: ' . $trip['full_name'] . ' (' . $userRole . ')',
        'Vehicle: ' . $trip['registration'],
        'Route: ' . ($trip['route_area'] ?: '—'),
        'Date: ' . $date,
        '',
        'Cash reported: UGX ' . number_format($cashReported),
        'Notes: ' . ($notes ?: '—'),
        '',
        'Recipient: Accountant',
    ];

    $dir = report_storage_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $file = 'eod-' . $tripId . '-' . date('Ymd-His') . '.pdf';
    $abs = $dir . '/' . $file;
    simple_pdf_write($abs, 'Field End-of-Day Report', $lines);

    return report_insert_packet(
        'field_eod',
        $title,
        'Auto-generated when field agent submitted EOD.',
        $date,
        'storage/reports/' . $file,
        basename($file),
        (int) filesize($abs),
        $userId,
        $userRole,
        'accountant',
        null,
        $tripId,
        $notes
    );
}

function report_mark_read(int $packetId, string $viewerRole): void
{
    $row = report_fetch_packet($packetId);
    if (!$row || ($viewerRole !== 'admin' && $viewerRole !== $row['to_role'])) {
        throw new RuntimeException('Report not found');
    }
    if ($row['status'] === 'sent') {
        db()->prepare('UPDATE report_packets SET status = ?, read_at = NOW() WHERE id = ?')
            ->execute(['read', $packetId]);
    }
}

function report_acknowledge(int $packetId, int $userId, string $viewerRole): void
{
    $row = report_fetch_packet($packetId);
    if (!$row || !in_array($viewerRole, ['executive', 'admin'], true)) {
        throw new RuntimeException('Only executives can acknowledge board reports');
    }
    if ($row['to_role'] !== 'executive' && $viewerRole !== 'admin') {
        throw new RuntimeException('Not an executive report');
    }
    db()->prepare(
        'UPDATE report_packets SET status = ?, acknowledged_at = NOW(), acknowledged_by = ?, read_at = COALESCE(read_at, NOW()) WHERE id = ?'
    )->execute(['acknowledged', $userId, $packetId]);
}

function report_forward_upload(
    int $userId,
    string $fromRole,
    string $toRole,
    string $title,
    ?string $summary,
    string $reportDate,
    array $uploadFile,
    ?int $parentPacketId = null,
    ?string $notes = null
): array {
    if (report_next_recipient($fromRole) !== $toRole && $fromRole !== 'admin') {
        throw new RuntimeException('Invalid recipient for your role');
    }

    $saved = report_save_uploaded_file($uploadFile);
    return report_insert_packet(
        'uploaded',
        $title,
        $summary,
        $reportDate,
        $saved['file_path'],
        $saved['file_name'],
        $saved['file_size'],
        $userId,
        $fromRole,
        $toRole,
        $parentPacketId,
        null,
        $notes
    );
}
