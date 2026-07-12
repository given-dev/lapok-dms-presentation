<?php
declare(strict_types=1);

require_once __DIR__ . '/simple_pdf.php';
require_once __DIR__ . '/occd_boards.php';

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
        'demo-eod-001.pdf' => [
            'Field End-of-Day Report',
            [
                'doc_title' => 'Field End-of-Day Report',
                'meta' => [
                    'Report date' => '2026-05-07',
                    'Agent' => 'David Ssemuju (Cadet)',
                    'Vehicle' => 'TUK-001',
                    'Route' => 'Owino / Katwe',
                    'Recipient' => 'Accountant (RDC)',
                ],
                'sections' => [
                    ['heading' => 'Cash handed', 'lines' => ['Cash reported: UGX 480,000']],
                    ['heading' => 'Notes', 'lines' => ['2 edit requests pending manager approval.']],
                ],
            ],
        ],
        'demo-acc-001.pdf' => [
            'RDC daily pack for manager - 2026-05-07',
            [
                'doc_title' => 'RDC daily pack for manager - 2026-05-07',
                'meta' => [
                    'Report date' => '2026-05-07',
                    'Prepared by' => 'Grace Apio (Accountant / RDC)',
                    'Recipient' => 'Manager',
                    'Purpose' => 'Review balancing, cash, cadets, stock, deliveries',
                ],
                'sections' => [
                    ['heading' => 'Manager attention', 'lines' => [
                        'Sample pack - regenerate from live data for today\'s figures.',
                        'Cash variance and cadet flags appear here when present.',
                    ]],
                    ['heading' => 'Cash and receivables', 'lines' => [
                        'Cash confirmations pending: 1 trip',
                        'Receivables total: UGX 280,000',
                        'Variance vs reported cash: UGX 0',
                    ]],
                    ['heading' => 'Next step for manager', 'lines' => [
                        'Open RDC review, confirm deliveries, then Approve.',
                    ]],
                ],
            ],
        ],
        'demo-mgr-001.pdf' => [
            'Executive operations brief - 2026-05-07',
            [
                'doc_title' => 'Executive operations brief - 2026-05-07',
                'meta' => [
                    'Report date' => '2026-05-07',
                    'Prepared by' => 'Sarah Nakato (Manager)',
                    'Recipient' => 'Executive / Board',
                ],
                'sections' => [
                    ['heading' => 'Operations snapshot', 'lines' => [
                        'Sales today: 186 cartons - UGX 3.72M',
                        'Fleet: 3/4 vehicles on route',
                    ]],
                    ['heading' => 'Low stock', 'lines' => ['- Coke 500ml', '- Sprite 1L']],
                ],
            ],
        ],
    ];
    foreach ($demos as $file => [$title, $layout]) {
        $path = $dir . '/' . $file;
        // Always keep demos on the branded multi-page writer.
        if (!is_file($path) || filesize($path) < 8000) {
            simple_pdf_write($path, $title, [], $layout);
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

/**
 * Organized accountant pack for the manager — what RDC closes today and what needs attention.
 *
 * @return array{doc_title: string, meta: array<string, string>, sections: list<array{heading: string, lines: list<string>}>}
 */
function report_build_accountant_layout(string $date, ?string $preparedBy = null): array
{
    require_once __DIR__ . '/rdc_balancing.php';
    require_once __DIR__ . '/cadet_reports.php';
    require_once __DIR__ . '/depot_finance.php';

    $pdo = db();
    $sheetStmt = $pdo->prepare('SELECT * FROM rdc_daily_sheets WHERE balance_date = ? LIMIT 1');
    $sheetStmt->execute([$date]);
    $sheetRow = $sheetStmt->fetch() ?: null;
    $sheet = $sheetRow ? rdc_sheet_to_response($sheetRow) : null;

    $cash = $pdo->query(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(cash_reported),0) AS reported,
                COALESCE(SUM(cash_collected),0) AS collected
         FROM delivery_trips WHERE DATE(returned_at) = " . $pdo->quote($date)
    )->fetch();
    $pendingCash = (int) $pdo->query(
        "SELECT COUNT(*) FROM delivery_trips
         WHERE status = 'returned' AND cash_collected IS NULL AND DATE(returned_at) = " . $pdo->quote($date)
    )->fetchColumn();
    $tripsOut = (int) $pdo->query(
        "SELECT COUNT(*) FROM delivery_trips
         WHERE status IN ('dispatched','on_route') AND DATE(dispatched_at) = " . $pdo->quote($date)
    )->fetchColumn();

    // Per-trip cash confirmation detail (core RDC control)
    $cashTripStmt = $pdo->prepare(
        "SELECT dt.id, dt.status, dt.cash_reported, dt.cash_collected, dt.returned_at, dt.route_area,
                v.registration,
                COALESCE(cadet.full_name, driver.full_name, 'Crew') AS crew_name
         FROM delivery_trips dt
         JOIN vehicles v ON v.id = dt.vehicle_id
         LEFT JOIN users cadet ON cadet.id = dt.cadet_id
         LEFT JOIN users driver ON driver.id = dt.driver_id
         WHERE DATE(COALESCE(dt.returned_at, dt.dispatched_at)) = ?
           AND dt.status IN ('returned','completed')
         ORDER BY dt.returned_at ASC, dt.id ASC"
    );
    $cashTripStmt->execute([$date]);
    $cashTrips = $cashTripStmt->fetchAll();
    $cashConfirmLines = [];
    $confirmedTrips = 0;
    $pendingTripLines = [];
    $confirmedVarianceAbs = 0.0;
    foreach ($cashTrips as $t) {
        $reported = (float) ($t['cash_reported'] ?? 0);
        $collectedRaw = $t['cash_collected'];
        $reg = (string) ($t['registration'] ?? 'Vehicle');
        $crew = (string) ($t['crew_name'] ?? 'Crew');
        if ($collectedRaw === null) {
            $pendingTripLines[] = sprintf(
                'PENDING - %s / %s - reported %s - not yet confirmed by RDC',
                $reg,
                $crew,
                simple_pdf_ugx($reported)
            );
            continue;
        }
        $collected = (float) $collectedRaw;
        $var = $collected - $reported;
        $confirmedTrips++;
        $confirmedVarianceAbs += abs($var);
        $cashConfirmLines[] = sprintf(
            'CONFIRMED - %s / %s - reported %s | collected %s | variance %s',
            $reg,
            $crew,
            simple_pdf_ugx($reported),
            simple_pdf_ugx($collected),
            simple_pdf_ugx($var)
        );
    }
    $cashSection = [
        'Control: RDC confirms physical cash against each returned trip before close.',
        'Trips returned today: ' . count($cashTrips),
        'Cash confirmations done: ' . $confirmedTrips,
        'Cash confirmations PENDING: ' . $pendingCash,
        'Total cash reported: ' . simple_pdf_ugx((float) ($cash['reported'] ?? 0)),
        'Total cash collected (confirmed): ' . simple_pdf_ugx((float) ($cash['collected'] ?? 0)),
        'Net collection gap (reported - collected): ' . simple_pdf_ugx(
            (float) ($cash['reported'] ?? 0) - (float) ($cash['collected'] ?? 0)
        ),
    ];
    if ($confirmedVarianceAbs >= 1) {
        $cashSection[] = 'Sum of absolute trip variances after confirm: ' . simple_pdf_ugx($confirmedVarianceAbs);
    }
    $cashSection[] = '';
    if ($pendingTripLines) {
        $cashSection[] = 'Still needing RDC confirmation:';
        foreach ($pendingTripLines as $line) {
            $cashSection[] = $line;
        }
        $cashSection[] = '';
    }
    if ($cashConfirmLines) {
        $cashSection[] = 'Confirmed trips:';
        foreach ($cashConfirmLines as $line) {
            $cashSection[] = $line;
        }
    } elseif (!$pendingTripLines) {
        $cashSection[] = 'No returned trips today for cash confirmation.';
    }

    $recv = $pdo->query(
        'SELECT COALESCE(SUM(credit_balance),0) AS total,
                COUNT(*) AS with_balance
         FROM customers WHERE is_active = 1 AND credit_balance > 0'
    )->fetch();

    $cadetReports = rdc_cadet_reports_for_date($date);
    $cadetLines = [];
    $attention = [];
    $salesSum = 0.0;
    $cashSum = 0.0;
    $fuelSum = 0.0;
    $otherSum = 0.0;
    foreach ($cadetReports as $r) {
        $salesSum += (float) ($r['sales_total'] ?? 0);
        $cashSum += (float) ($r['cash_handed'] ?? 0);
        $fuelSum += (float) ($r['fuel_expense'] ?? 0);
        $otherSum += (float) ($r['other_expense'] ?? 0);
        $flags = $r['flags'] ?? [];
        $flagText = $flags ? cadet_flag_labels($flags) : 'OK';
        $cadetLines[] = sprintf(
            '%s / %s',
            (string) ($r['registration'] ?? 'Vehicle'),
            (string) ($r['cadet_name'] ?? 'Cadet')
        );
        $cadetLines[] = sprintf(
            '  Sales %s | Cash handed %s | Fuel %s | Other %s',
            simple_pdf_ugx((float) ($r['sales_total'] ?? 0)),
            simple_pdf_ugx((float) ($r['cash_handed'] ?? 0)),
            simple_pdf_ugx((float) ($r['fuel_expense'] ?? 0)),
            simple_pdf_ugx((float) ($r['other_expense'] ?? 0))
        );
        $productBits = cadet_sales_summary($r['sales_lines'] ?? []);
        if ($productBits !== '' && $productBits !== 'No product sales') {
            $cadetLines[] = '  Products: ' . $productBits;
        }
        $cadetLines[] = '  Status: ' . $flagText;
        if (!empty($r['corrected_by_name'])) {
            $cadetLines[] = '  RDC correction by ' . (string) $r['corrected_by_name']
                . (!empty($r['corrected_at']) ? ' at ' . (string) $r['corrected_at'] : '');
        }
        $note = trim((string) ($r['note'] ?? ''));
        if ($note !== '') {
            $cadetLines[] = '  Note: ' . $note;
        }
        if ($flags) {
            $attention[] = sprintf(
                '%s (%s): %s',
                (string) ($r['registration'] ?? 'Vehicle'),
                (string) ($r['cadet_name'] ?? 'Cadet'),
                cadet_flag_labels($flags)
            );
        }
        $cadetLines[] = '';
    }
    if (!$cadetLines) {
        $cadetLines[] = 'No cadet reports received for this date.';
        $attention[] = 'No cadet EOD reports on file for this date.';
    }

    $balancing = [];
    $expensesLines = [];
    $cashActualLines = [];
    $topSales = [];
    if ($sheet) {
        $status = str_replace('_', ' ', (string) ($sheet['status'] ?? 'draft'));
        $balancing = [
            'Sheet status: ' . $status,
            'Submitted at: ' . ((string) ($sheet['submitted_at'] ?? '') ?: 'Not submitted yet'),
            'Sales total: ' . simple_pdf_ugx((float) ($sheet['sales_total'] ?? 0)),
            'Recoveries: ' . simple_pdf_ugx((float) ($sheet['recovery_total'] ?? 0)),
            'Expenses total: ' . simple_pdf_ugx((float) ($sheet['expenses_total'] ?? 0)),
            'Grand total (sales + recoveries): ' . simple_pdf_ugx((float) ($sheet['grand_total'] ?? 0)),
            'Expected cash: ' . simple_pdf_ugx((float) ($sheet['expected_amount'] ?? 0)),
            'Actual cash counted: ' . simple_pdf_ugx((float) ($sheet['actual_total'] ?? 0)),
            'Cash variance (expected - actual): ' . simple_pdf_ugx((float) ($sheet['variance'] ?? 0)),
        ];
        $var = (float) ($sheet['variance'] ?? 0);
        if (abs($var) >= 1) {
            $attention[] = 'RDC cash variance of ' . simple_pdf_ugx($var) . ' needs manager review.';
        }

        // Expense breakdown from sheet
        foreach ($sheet['expenses'] ?? [] as $exp) {
            $label = (string) ($exp['label'] ?? $exp['key'] ?? 'Expense');
            $amt = 0.0;
            if (isset($exp['amounts']) && is_array($exp['amounts'])) {
                foreach ($exp['amounts'] as $v) {
                    $amt += (float) $v;
                }
            } elseif (isset($exp['amount'])) {
                $amt = (float) $exp['amount'];
            }
            if ($amt > 0) {
                $expensesLines[] = $label . ': ' . simple_pdf_ugx($amt);
            }
        }
        if (!$expensesLines) {
            $expensesLines[] = 'No expense lines recorded on the sheet.';
        }

        // Cash actual by channel / column
        $cashActual = $sheet['cash_actual'] ?? [];
        if (is_array($cashActual) && $cashActual) {
            foreach ($cashActual as $ck => $cv) {
                if (is_array($cv)) {
                    continue;
                }
                $amt = (float) $cv;
                if ($amt == 0.0) {
                    continue;
                }
                $cashActualLines[] = str_replace('_', ' ', (string) $ck) . ': ' . simple_pdf_ugx($amt);
            }
        }
        if (!$cashActualLines) {
            $cashActualLines[] = 'No cash actual breakdown captured.';
        }

        // Top sold product lines for manager skim
        $ranked = [];
        foreach ($sheet['sales'] ?? [] as $line) {
            $label = (string) ($line['label'] ?? $line['rdc_label'] ?? 'Product');
            $qty = 0;
            if (isset($line['qty']) && is_array($line['qty'])) {
                foreach ($line['qty'] as $q) {
                    $qty += (int) $q;
                }
            } else {
                $qty = (int) ($line['qty_sold'] ?? 0);
            }
            $unit = (float) ($line['price'] ?? $line['unit_price'] ?? 0);
            $amount = isset($line['amount']) ? (float) $line['amount'] : ($qty * $unit);
            if ($qty <= 0 && $amount <= 0) {
                continue;
            }
            $ranked[] = ['label' => $label, 'qty' => $qty, 'amount' => $amount];
        }
        usort($ranked, static fn($a, $b) => $b['amount'] <=> $a['amount']);
        foreach (array_slice($ranked, 0, 12) as $row) {
            $topSales[] = sprintf(
                '%s - qty %d - %s',
                $row['label'],
                $row['qty'],
                simple_pdf_ugx((float) $row['amount'])
            );
        }
        if (!$topSales) {
            $topSales[] = 'No product sales lines with quantities on the RDC sheet.';
        }

        $notes = trim((string) ($sheet['notes'] ?? ''));
        if ($notes !== '') {
            // Strip internal sync tags for manager-facing note
            $notes = preg_replace('/\[CADET_VEHICLE_SYNC\][^\n]*/', '', $notes) ?? $notes;
            $notes = trim(preg_replace('/\s+/', ' ', $notes) ?? $notes);
            if ($notes !== '') {
                $balancing[] = 'RDC notes: ' . $notes;
            }
        }
    } else {
        $balancing[] = 'No RDC balancing sheet found for this date.';
        $attention[] = 'RDC sheet missing - ask accountant to finish daily balancing.';
        $expensesLines[] = 'N/A - no sheet.';
        $cashActualLines[] = 'N/A - no sheet.';
        $topSales[] = 'N/A - no sheet.';
    }

    // Closing stock / delivery confirmations
    $closing = depot_snapshot_fetch($date, 'closing');
    $opening = depot_snapshot_fetch($date, 'opening');
    $controls = [];
    $controls[] = 'Opening stock (7am): ' . ($opening ? 'Saved' : 'Not saved');
    $controls[] = 'Closing stock (7pm): ' . ($closing ? 'Saved' : 'Not saved');
    if (!$closing) {
        $attention[] = '7pm closing stock not saved yet (manager enters this on Stock management).';
    }

    $pendingDeliveries = 0;
    $deliveryLines = [];
    try {
        $delStmt = $pdo->prepare(
            "SELECT waybill, truck_plate, confirm_status, confirmed_at
             FROM supplier_deliveries WHERE delivery_date = ? ORDER BY id"
        );
        $delStmt->execute([$date]);
        $dels = $delStmt->fetchAll();
        if (!$dels) {
            $deliveryLines[] = 'No Coca-Cola deliveries recorded today.';
        } else {
            foreach ($dels as $d) {
                $st = (string) ($d['confirm_status'] ?? 'pending_confirm');
                if ($st === 'pending_confirm') {
                    $pendingDeliveries++;
                }
                $deliveryLines[] = sprintf(
                    'Waybill %s (%s) - %s',
                    (string) ($d['waybill'] ?: 'n/a'),
                    (string) ($d['truck_plate'] ?: 'no plate'),
                    str_replace('_', ' ', $st)
                );
            }
        }
        if ($pendingDeliveries > 0) {
            $attention[] = $pendingDeliveries . ' supplier delivery(ies) still awaiting manager confirmation.';
        }
    } catch (Throwable) {
        $deliveryLines[] = 'Delivery confirmation not available (run migration 013).';
    }

    $fieldSummary = [
        'Cadet reports received: ' . count($cadetReports),
        'Cadet sales (from reports): ' . simple_pdf_ugx($salesSum),
        'Cash handed (from reports): ' . simple_pdf_ugx($cashSum),
        'Fuel expenses (from reports): ' . simple_pdf_ugx($fuelSum),
        'Other expenses (from reports): ' . simple_pdf_ugx($otherSum),
        'Trips returned today: ' . (int) ($cash['cnt'] ?? 0),
        'Trips still out: ' . $tripsOut,
        'Customers with credit balance: ' . (int) ($recv['with_balance'] ?? 0),
        'Outstanding receivables: ' . simple_pdf_ugx((float) ($recv['total'] ?? 0)),
    ];
    if ($pendingCash > 0) {
        array_unshift(
            $attention,
            'CASH CONFIRMATION: ' . $pendingCash . ' returned trip(s) still awaiting RDC cash confirmation - manager should not approve until cleared.'
        );
    } else {
        array_unshift(
            $attention,
            'Cash confirmation: all returned trips for today have been confirmed by RDC (or none returned yet).'
        );
    }
    if ($tripsOut > 0) {
        $attention[] = $tripsOut . ' trip(s) still out on route at pack time.';
    }
    if (count($attention) <= 1) {
        $attention[] = 'No other critical flags - sheet is ready for manager review/approval.';
    }

    $meta = [
        'Report date' => $date,
        'Prepared by' => $preparedBy ?: 'Accountant (RDC)',
        'Document' => 'RDC daily pack for manager',
        'Recipient' => 'Manager',
        'Purpose' => 'Cash confirmation, balancing, cadets, stock, deliveries',
    ];

    return [
        'doc_title' => 'RDC daily pack for manager - ' . $date,
        'meta' => $meta,
        'sections' => [
            [
                'heading' => 'Manager attention',
                'lines' => $attention,
            ],
            [
                'heading' => 'Cash confirmation (critical)',
                'lines' => $cashSection,
            ],
            [
                'heading' => 'RDC balancing summary',
                'lines' => $balancing,
            ],
            [
                'heading' => 'Field position',
                'lines' => $fieldSummary,
            ],
            [
                'heading' => 'Cadet reports (detail)',
                'lines' => $cadetLines,
            ],
            [
                'heading' => 'Top product sales (RDC sheet)',
                'lines' => $topSales,
            ],
            [
                'heading' => 'Expenses breakdown',
                'lines' => $expensesLines,
            ],
            [
                'heading' => 'Cash counted on RDC sheet (actual)',
                'lines' => $cashActualLines,
            ],
            [
                'heading' => 'Depot controls and deliveries',
                'lines' => array_merge($controls, [''], $deliveryLines),
            ],
            [
                'heading' => 'Next step for manager',
                'lines' => [
                    '1. Check Cash confirmation - all returned trips should be CONFIRMED by RDC first.',
                    '2. Confirm any pending Coca-Cola deliveries on Stock management.',
                    '3. Open RDC review - edit the sheet if figures need correction.',
                    '4. Approve the sheet when cash confirmation, variance, and cadet flags are acceptable.',
                    '5. Then prepare / send the executive brief before 8:00 PM.',
                ],
            ],
        ],
    ];
}

/**
 * @return array{doc_title: string, meta: array<string, string>, sections: list<array{heading: string, lines: list<string>}>}
 */
function report_build_manager_layout(string $date, ?string $preparedBy = null): array
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

    $inv = $pdo->prepare("SELECT status, submitted_at, payload_json FROM manager_daily_boards WHERE board_date = ? AND board_type = 'inventory_board' LIMIT 1");
    $inv->execute([$date]);
    $invRow = $inv->fetch();
    $occd = $pdo->prepare("SELECT status, submitted_at, payload_json FROM manager_daily_boards WHERE board_date = ? AND board_type = 'occd_dashboard' LIMIT 1");
    $occd->execute([$date]);
    $occdRow = $occd->fetch();

    $sheet = $pdo->prepare('SELECT status, grand_total, variance FROM rdc_daily_sheets WHERE balance_date = ? LIMIT 1');
    $sheet->execute([$date]);
    $sheetRow = $sheet->fetch() ?: null;

    $lowLines = [];
    foreach ($low as $row) {
        $lowLines[] = '- ' . $row['name'] . ' (' . $row['warehouse_qty'] . ' crates)';
    }
    if (!$lowLines) {
        $lowLines[] = '- None flagged';
    }

    $rdcLines = $sheetRow
        ? [
            'Sheet status: ' . str_replace('_', ' ', (string) $sheetRow['status']),
            'Grand total: ' . simple_pdf_ugx((float) $sheetRow['grand_total']),
            'Cash variance: ' . simple_pdf_ugx((float) $sheetRow['variance']),
        ]
        : ['No RDC sheet submitted for this date.'];

    $invLines = occd_inventory_brief_lines($invRow ?: null);
    $occdLines = occd_dashboard_brief_lines($occdRow ?: null);

    return [
        'doc_title' => 'Executive operations brief - ' . $date,
        'meta' => [
            'Report date' => $date,
            'Prepared by' => $preparedBy ?: 'Manager',
            'Document' => 'Executive operations brief',
            'Recipient' => 'Executive / Board',
        ],
        'sections' => [
            [
                'heading' => 'Operations snapshot',
                'lines' => [
                    'Orders today: ' . (int) ($sales['orders'] ?? 0),
                    'Revenue today: ' . simple_pdf_ugx((float) ($sales['revenue'] ?? 0)),
                    'Pending sales to confirm: ' . $pendingOrders,
                    'Edit requests pending: ' . $pendingEdits,
                    'Fleet on route: ' . $onRoute . '/' . $vehicles,
                ],
            ],
            [
                'heading' => 'RDC finance status',
                'lines' => $rdcLines,
            ],
            [
                'heading' => 'CCBA inventory board',
                'lines' => $invLines,
            ],
            [
                'heading' => 'CCBA OCCD dashboard',
                'lines' => $occdLines,
            ],
            [
                'heading' => 'Low stock',
                'lines' => $lowLines,
            ],
        ],
    ];
}

/**
 * @return array{doc_title: string, meta: array<string, string>, sections: list<array{heading: string, lines: list<string>}>}
 */
function report_build_field_eod_layout(array $trip, string $userRole, float $cashReported, ?string $notes, string $date): array
{
    return [
        'doc_title' => 'Field end-of-day report',
        'meta' => [
            'Report date' => $date,
            'Agent' => (string) ($trip['full_name'] ?? 'Cadet') . ' (' . $userRole . ')',
            'Vehicle' => (string) ($trip['registration'] ?? '—'),
            'Route' => (string) ($trip['route_area'] ?: '—'),
            'Recipient' => 'Accountant (RDC)',
        ],
        'sections' => [
            [
                'heading' => 'Cash handed',
                'lines' => [
                    'Cash reported: ' . simple_pdf_ugx($cashReported),
                ],
            ],
            [
                'heading' => 'Notes',
                'lines' => [
                    $notes !== null && trim($notes) !== '' ? trim($notes) : 'No notes provided.',
                ],
            ],
        ],
    ];
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

    $userName = (string) (db()->query('SELECT full_name FROM users WHERE id = ' . (int) $userId)->fetchColumn() ?: ucfirst($role));

    if ($role === 'accountant') {
        $reportType = 'accountant_pack';
        $layout = report_build_accountant_layout($date, $userName . ' (Accountant / RDC)');
        $pdfTitle = $title ?: (string) $layout['doc_title'];
        $prefix = 'acc-pack';
        $summaryDefault = 'RDC daily pack: cash confirmation, balancing, cadets, stock, deliveries.';
    } else {
        $reportType = 'manager_brief';
        $layout = report_build_manager_layout($date, $userName . ' (Manager)');
        $pdfTitle = $title ?: (string) $layout['doc_title'];
        $prefix = 'mgr-brief';
        $summaryDefault = 'Executive operations brief — includes CCBA inventory + OCCD boards when submitted.';
    }
    $layout['doc_title'] = $pdfTitle;
    if ($notes) {
        $layout['sections'][] = [
            'heading' => 'Cover note',
            'lines' => [$notes],
        ];
    }

    $file = $prefix . '-' . date('Ymd-His') . '.pdf';
    $abs = $dir . '/' . $file;
    simple_pdf_write($abs, $pdfTitle, [], $layout);
    $size = (int) filesize($abs);
    $relative = 'storage/reports/' . $file;
    $summary = $notes ?: $summaryDefault;

    // Replace any earlier same-day pack so the manager always opens the latest PDF
    // (avoids stale Lapok-era files with missing logo / &&&& artifacts).
    $existing = db()->prepare(
        'SELECT id, file_path FROM report_packets
         WHERE report_type = ? AND report_date = ? AND from_role = ? AND to_role = ?
         ORDER BY id DESC LIMIT 1'
    );
    $existing->execute([$reportType, $date, $role, $toRole]);
    $prev = $existing->fetch() ?: null;
    if ($prev) {
        $prevId = (int) $prev['id'];
        db()->prepare(
            'UPDATE report_packets
             SET title = ?, summary = ?, file_path = ?, file_name = ?, file_size = ?,
                 from_user_id = ?, notes = ?, status = ?, created_at = NOW()
             WHERE id = ?'
        )->execute([
            $pdfTitle,
            $summary,
            $relative,
            basename($file),
            $size,
            $userId,
            $notes,
            'sent',
            $prevId,
        ]);
        $oldRel = (string) ($prev['file_path'] ?? '');
        if ($oldRel !== '' && $oldRel !== $relative) {
            $oldAbs = dirname(__DIR__) . '/' . ltrim($oldRel, '/');
            if (is_file($oldAbs)) {
                @unlink($oldAbs);
            }
        }
        $row = report_fetch_packet($prevId);
        $formatted = $row ? report_format_packet($row, $role, $userId) : ['id' => $prevId, 'to_role' => $toRole];
        if ($toRole === 'executive') {
            require_once __DIR__ . '/notifications.php';
            $raw = $row ?: [
                'id' => $prevId,
                'to_role' => $toRole,
                'title' => $pdfTitle,
                'report_date' => $date,
                'from_user_id' => $userId,
            ];
            $raw['to_role'] = $toRole;
            // Re-alert on same-day replace so the bell lights again.
            try {
                db()->prepare(
                    'DELETE FROM user_notifications WHERE body LIKE ?'
                )->execute(['%#exec_pack_' . $prevId . '#%']);
            } catch (Throwable) {
            }
            notify_executives_of_pack($raw, $userId);
        }
        return $formatted;
    }

    $inserted = report_insert_packet(
        $reportType,
        $pdfTitle,
        $summary,
        $date,
        $relative,
        basename($file),
        $size,
        $userId,
        $role,
        $toRole,
        null,
        null,
        $notes
    );
    if ($toRole === 'executive') {
        require_once __DIR__ . '/notifications.php';
        $raw = [
            'id' => (int) ($inserted['id'] ?? 0),
            'to_role' => $toRole,
            'title' => $pdfTitle,
            'packet_ref' => $inserted['packet_ref'] ?? null,
            'report_date' => $date,
            'from_user_id' => $userId,
        ];
        notify_executives_of_pack($raw, $userId);
    }
    return $inserted;
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
        'EOD - %s - %s - %s',
        $trip['full_name'],
        $trip['registration'],
        $trip['route_area'] ?: 'Route'
    );
    $layout = report_build_field_eod_layout($trip, $userRole, $cashReported, $notes, $date);

    $dir = report_storage_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $file = 'eod-' . $tripId . '-' . date('Ymd-His') . '.pdf';
    $abs = $dir . '/' . $file;
    simple_pdf_write($abs, 'Field End-of-Day Report', [], $layout);

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
