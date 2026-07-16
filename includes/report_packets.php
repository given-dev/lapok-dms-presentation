<?php
declare(strict_types=1);

require_once __DIR__ . '/simple_pdf.php';
require_once __DIR__ . '/occd_boards.php';

const REPORT_LEADERSHIP_ROLES = ['accountant', 'manager', 'executive', 'admin'];

const REPORT_TYPE_LABELS = [
    'field_eod' => 'Field EOD',
    'accountant_pack' => 'Finance consolidation',
    'manager_brief' => 'Executive brief',
    'ccba_boards' => 'CCBA boards pack',
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
    // Keep demo PDF metadata in sync with the server's local (Africa/Kampala) date.
    $today = date('Y-m-d');
    $demos = [
        'demo-eod-001.pdf' => [
            'Field End-of-Day Report',
            [
                'doc_title' => 'Field End-of-Day Report',
                'meta' => [
                    'Report date' => $today,
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
            'RDC daily pack for manager - ' . $today,
            [
                'doc_title' => 'RDC daily pack for manager - ' . $today,
                'meta' => [
                    'Report date' => $today,
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
            'Executive operations brief - ' . $today,
            [
                'doc_title' => 'Executive operations brief - ' . $today,
                'meta' => [
                    'Report date' => $today,
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
            'ccba_boards' => 'RPT-CCBA',
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
 * Rank RDC sheet product sales by amount (then qty).
 *
 * @return list<array{label: string, qty: int, amount: float}>
 */
function report_rank_rdc_product_sales(?array $sheet): array
{
    if (!$sheet) {
        return [];
    }
    $ranked = [];
    foreach ($sheet['sales'] ?? [] as $line) {
        $label = trim((string) ($line['label'] ?? $line['rdc_label'] ?? 'Product'));
        if ($label === '') {
            $label = 'Product';
        }
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
    usort($ranked, static function ($a, $b) {
        $cmp = $b['amount'] <=> $a['amount'];
        return $cmp !== 0 ? $cmp : ($b['qty'] <=> $a['qty']);
    });
    return $ranked;
}

/**
 * Opening / closing depot stock — styled stock-book section for executives.
 *
 * @return array{heading: string, lines: list<string>, table?: array<string, mixed>}
 */
function report_stock_snapshot_section(?array $opening, ?array $closing): array
{
    $lines = [];
    $lines[] = 'Opening stock (7am): ' . ($opening
        ? 'Saved' . (!empty($opening['submitted_at']) ? ' - ' . $opening['submitted_at'] : '')
            . (!empty($opening['submitted_by_name']) ? ' by ' . $opening['submitted_by_name'] : '')
        : 'NOT SAVED');
    $lines[] = 'Closing stock (7pm): ' . ($closing
        ? 'Saved' . (!empty($closing['submitted_at']) ? ' - ' . $closing['submitted_at'] : '')
            . (!empty($closing['submitted_by_name']) ? ' by ' . $closing['submitted_by_name'] : '')
        : 'NOT SAVED');

    $openLines = is_array($opening['lines'] ?? null) ? $opening['lines'] : [];
    $closeLines = is_array($closing['lines'] ?? null) ? $closing['lines'] : [];

    $byKey = [];
    $addRow = static function (array $row, string $source) use (&$byKey): void {
        $pid = (int) ($row['product_id'] ?? 0);
        $sku = strtoupper(trim((string) ($row['sku'] ?? '')));
        $key = $pid > 0 ? 'id:' . $pid : ($sku !== '' ? 'sku:' . $sku : 'n:' . md5((string) ($row['product_name'] ?? '')));
        if (!isset($byKey[$key])) {
            $byKey[$key] = [
                'product_name' => trim((string) ($row['product_name'] ?? $row['name'] ?? 'Product')),
                'sku' => (string) ($row['sku'] ?? ''),
                'brand' => trim((string) ($row['brand'] ?? $row['category'] ?? 'OTHER')) ?: 'OTHER',
                'opening' => 0,
                'purchase' => 0,
                'sales' => 0,
                'closing' => 0,
            ];
        }
        $name = trim((string) ($row['product_name'] ?? $row['name'] ?? ''));
        if ($name !== '') {
            $byKey[$key]['product_name'] = $name;
        }
        if ($source === 'opening') {
            $openingQty = (int) ($row['opening'] ?? 0);
            $qty = (int) ($row['qty'] ?? 0);
            $use = $openingQty > 0 ? $openingQty : $qty;
            $byKey[$key]['opening'] = max($byKey[$key]['opening'], max(0, $use));
        } else {
            $byKey[$key]['opening'] = max($byKey[$key]['opening'], max(0, (int) ($row['opening'] ?? 0)));
            $byKey[$key]['purchase'] = max($byKey[$key]['purchase'], max(0, (int) ($row['purchase'] ?? 0)));
            $byKey[$key]['sales'] = max($byKey[$key]['sales'], max(0, (int) ($row['sales'] ?? 0)));
            $closeQty = (int) ($row['closing'] ?? $row['qty'] ?? 0);
            $byKey[$key]['closing'] = max($byKey[$key]['closing'], max(0, $closeQty));
        }
    };

    foreach ($openLines as $row) {
        if (is_array($row)) {
            $addRow($row, 'opening');
        }
    }
    foreach ($closeLines as $row) {
        if (is_array($row)) {
            $addRow($row, 'closing');
        }
    }

    if (!$byKey) {
        $lines[] = 'No stock lines on file for this date.';
        return [
            'heading' => 'Opening & closing stock (full stock book)',
            'lines' => $lines,
        ];
    }

    $openTotal = $purchTotal = $salesTotal = $closeTotal = 0;
    foreach ($byKey as $row) {
        $openTotal += $row['opening'];
        $purchTotal += $row['purchase'];
        $salesTotal += $row['sales'];
        $closeTotal += $row['closing'];
    }
    $lines[] = 'Day totals — Opening ' . number_format($openTotal)
        . ' | Purchase ' . number_format($purchTotal)
        . ' | Sales ' . number_format($salesTotal)
        . ' | Closing ' . number_format($closeTotal)
        . '  (' . count($byKey) . ' SKUs)';

    if (!empty($opening['notes'])) {
        $lines[] = 'Opening notes: ' . trim((string) $opening['notes']);
    }
    if (!empty($closing['notes'])) {
        $lines[] = 'Closing notes: ' . trim((string) $closing['notes']);
    }

    $sorted = array_values($byKey);
    usort($sorted, static function ($a, $b) {
        $c = strcasecmp($a['brand'], $b['brand']);
        return $c !== 0 ? $c : strcasecmp($a['product_name'], $b['product_name']);
    });

    $tableRows = [];
    $lastBrand = null;
    $brandBucket = ['opening' => 0, 'purchase' => 0, 'sales' => 0, 'closing' => 0];
    $flushBrand = static function () use (&$tableRows, &$lastBrand, &$brandBucket): void {
        if ($lastBrand === null) {
            return;
        }
        $tableRows[] = [
            'type' => 'total',
            'cells' => [
                $lastBrand . ' TOTAL',
                number_format($brandBucket['opening']),
                number_format($brandBucket['purchase']),
                number_format($brandBucket['sales']),
                number_format($brandBucket['closing']),
            ],
        ];
        $brandBucket = ['opening' => 0, 'purchase' => 0, 'sales' => 0, 'closing' => 0];
    };

    foreach ($sorted as $row) {
        if ($row['brand'] !== $lastBrand) {
            $flushBrand();
            $lastBrand = $row['brand'];
            $tableRows[] = ['type' => 'category', 'cells' => [$lastBrand, '', '', '', '']];
        }
        $label = $row['product_name'];
        if ($row['sku'] !== '') {
            $label .= ' [' . $row['sku'] . ']';
        }
        $tableRows[] = [
            'type' => 'data',
            'cells' => [
                $label,
                number_format($row['opening']),
                number_format($row['purchase']),
                number_format($row['sales']),
                number_format($row['closing']),
            ],
        ];
        $brandBucket['opening'] += $row['opening'];
        $brandBucket['purchase'] += $row['purchase'];
        $brandBucket['sales'] += $row['sales'];
        $brandBucket['closing'] += $row['closing'];
    }
    $flushBrand();
    $tableRows[] = [
        'type' => 'grand',
        'cells' => [
            'GRAND TOTAL',
            number_format($openTotal),
            number_format($purchTotal),
            number_format($salesTotal),
            number_format($closeTotal),
        ],
    ];

    return [
        'heading' => 'Opening & closing stock (full stock book)',
        'lines' => $lines,
        'table' => [
            'columns' => ['Product / SKU', 'Opening', 'Purchase', 'Sales', 'Closing'],
            'align' => ['left', 'right', 'right', 'right', 'right'],
            'widths' => [220, 73, 73, 73, 73],
            'rows' => $tableRows,
        ],
    ];
}

/** @deprecated use report_stock_snapshot_section */
function report_stock_snapshot_brief_lines(?array $opening, ?array $closing): array
{
    $sec = report_stock_snapshot_section($opening, $closing);
    return $sec['lines'] ?? [];
}
/**
 * Format a numeric cell for board PDF tables.
 */
function report_board_num($v): string
{
    if ($v === null || $v === '') {
        return '-';
    }
    if (is_numeric($v)) {
        return number_format((float) $v, 0);
    }
    return simple_pdf_plain((string) $v);
}

/**
 * Structured Inventory board sections (banner + table) for styled PDF.
 *
 * @param array<string, mixed>|false|null $row
 * @return list<array<string, mixed>>
 */
function report_ccba_inventory_sections($row, string $date): array
{
    if (!$row || empty($row['payload_json'])) {
        return [[
            'heading' => 'Inventory board',
            'lines' => ['Inventory board: not started for this date.'],
        ]];
    }
    $payload = json_decode((string) $row['payload_json'], true);
    if (!is_array($payload)) {
        return [[
            'heading' => 'Inventory board',
            'lines' => ['Inventory board: unreadable payload.'],
        ]];
    }

    $header = $payload['header'] ?? [];
    $lines = $payload['lines'] ?? [];
    $values = $payload['values'] ?? [];
    $rows = [];
    $lastCat = '';

    // Precompute section / grand totals from SKU values (same as UI)
    $byCat = [];
    $grand = ['recommended' => 0.0, 'opening' => 0.0, 'on_order' => 0.0];
    foreach ($lines as $line) {
        if (($line['row_type'] ?? '') !== 'sku') {
            continue;
        }
        $key = (string) ($line['key'] ?? '');
        $cat = (string) ($line['category'] ?? 'OTHER');
        $v = is_array($values[$key] ?? null) ? $values[$key] : [];
        $rec = (float) ($v['recommended'] ?? 0);
        $op = (float) ($v['opening'] ?? 0);
        $oo = (float) ($v['on_order'] ?? 0);
        if (!isset($byCat[$cat])) {
            $byCat[$cat] = ['recommended' => 0.0, 'opening' => 0.0, 'on_order' => 0.0];
        }
        $byCat[$cat]['recommended'] += $rec;
        $byCat[$cat]['opening'] += $op;
        $byCat[$cat]['on_order'] += $oo;
        $grand['recommended'] += $rec;
        $grand['opening'] += $op;
        $grand['on_order'] += $oo;
    }

    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $rowType = (string) ($line['row_type'] ?? 'sku');
        $key = (string) ($line['key'] ?? '');
        $v = is_array($values[$key] ?? null) ? $values[$key] : [];

        if ($rowType === 'sku') {
            $cat = (string) ($line['category'] ?? '');
            if ($cat !== '' && $cat !== $lastCat) {
                $lastCat = $cat;
                $rows[] = ['type' => 'category', 'cells' => [$cat, '', '', '', '']];
            }
            $rows[] = [
                'type' => 'data',
                'cells' => [
                    (string) ($line['sku'] ?? $key),
                    report_board_num($v['recommended'] ?? 0),
                    report_board_num($v['opening'] ?? 0),
                    report_board_num($v['on_order'] ?? 0),
                    (string) ($v['comments'] ?? ''),
                ],
            ];
            continue;
        }

        if ($rowType === 'grand_total') {
            $rows[] = [
                'type' => 'grand',
                'cells' => [
                    'GRAND TOTAL',
                    report_board_num($grand['recommended']),
                    report_board_num($grand['opening']),
                    report_board_num($grand['on_order']),
                    '',
                ],
            ];
            continue;
        }

        $cat = (string) ($line['category'] ?? '');
        $tot = $byCat[$cat] ?? ['recommended' => 0, 'opening' => 0, 'on_order' => 0];
        $rows[] = [
            'type' => 'total',
            'cells' => [
                strtoupper($cat !== '' ? $cat : 'SECTION') . ' TOTAL',
                report_board_num($tot['recommended']),
                report_board_num($tot['opening']),
                report_board_num($tot['on_order']),
                '',
            ],
        ];
    }

    $status = (string) ($row['status'] ?? 'draft');
    return [[
        'banner' => [
            'title' => 'INVENTORY BOARD',
            'meta' => [
                'OCCD NAME' => trim((string) ($header['occd_name'] ?? '-')),
                'REGION' => trim((string) ($header['region'] ?? '-')),
                'DATE' => $date,
                'STATUS' => strtoupper($status),
            ],
        ],
        'lines' => [
            'Opening = manager 7am stock  |  Qty on order = open CCBA / Coca-Cola orders (automatic).',
        ],
        'table' => [
            'columns' => ['SKU / pack', 'Recommended', 'Opening', 'On order', 'Comments'],
            'align' => ['left', 'right', 'right', 'right', 'left'],
            'widths' => [170, 78, 72, 72, 120],
            'rows' => $rows,
        ],
    ]];
}

/**
 * Structured OCCD dashboard sections (banner + panel tables) for styled PDF.
 *
 * @param array<string, mixed>|false|null $row
 * @return list<array<string, mixed>>
 */
function report_ccba_occd_sections($row, string $date): array
{
    if (!$row || empty($row['payload_json'])) {
        return [[
            'heading' => 'OCCD dashboard',
            'lines' => ['OCCD dashboard: not started for this date.'],
        ]];
    }
    $payload = json_decode((string) $row['payload_json'], true);
    if (!is_array($payload)) {
        return [[
            'heading' => 'OCCD dashboard',
            'lines' => ['OCCD dashboard: unreadable payload.'],
        ]];
    }

    $header = $payload['header'] ?? [];
    $status = (string) ($row['status'] ?? 'draft');
    $sections = [];

    $sections[] = [
        'banner' => [
            'title' => 'OCCD DASHBOARD',
            'meta' => [
                'OCCD NAME' => trim((string) ($header['occd_name'] ?? '-')),
                'REGION' => trim((string) ($header['region'] ?? '-')),
                'DATE' => $date,
                'STATUS' => strtoupper($status),
            ],
        ],
        'lines' => [],
    ];

    // Outlet data
    $outlet = $payload['outlet_data'] ?? [];
    $channels = $outlet['channels'] ?? [];
    $tiers = $outlet['tiers'] ?? [];
    $vals = $outlet['values'] ?? [];
    $tierLabels = ['gold' => 'Gold', 'silver' => 'Silver', 'bronze' => 'Bronze', 'tin' => 'Tin'];
    $chLabels = [
        'coke' => 'Coke', 'kad' => 'KAD', 'stockists' => 'Stockists', 'superettes' => 'Superettes',
        'pfns' => 'PFNs', 'horeca' => 'HORECA', 'bars_pubs' => 'Bars/Pubs', 'education' => 'Education',
    ];
    if ($channels && $tiers) {
        $cols = ['Tier'];
        $align = ['left'];
        $widths = [52.0];
        foreach ($channels as $ch) {
            $cols[] = $chLabels[$ch] ?? $ch;
            $align[] = 'right';
            $widths[] = 42.0;
        }
        $cols[] = 'Total';
        $cols[] = '%';
        $align[] = 'right';
        $align[] = 'right';
        $widths[] = 48.0;
        $widths[] = 40.0;

        $colTotals = array_fill_keys($channels, 0.0);
        $grand = 0.0;
        $rowTotals = [];
        foreach ($tiers as $tier) {
            $rowTotal = 0.0;
            foreach ($channels as $ch) {
                $n = (float) ($vals[$tier][$ch] ?? 0);
                $rowTotal += $n;
                $colTotals[$ch] += $n;
            }
            $rowTotals[$tier] = $rowTotal;
            $grand += $rowTotal;
        }
        $outRows = [];
        foreach ($tiers as $tier) {
            $cells = [$tierLabels[$tier] ?? $tier];
            foreach ($channels as $ch) {
                $cells[] = report_board_num($vals[$tier][$ch] ?? 0);
            }
            $cells[] = report_board_num($rowTotals[$tier]);
            $cells[] = $grand > 0 ? number_format(($rowTotals[$tier] / $grand) * 100, 1) . '%' : '-';
            $outRows[] = ['type' => 'data', 'cells' => $cells];
        }
        $totCells = ['TOTAL'];
        foreach ($channels as $ch) {
            $totCells[] = report_board_num($colTotals[$ch]);
        }
        $totCells[] = report_board_num($grand);
        $totCells[] = '100%';
        $outRows[] = ['type' => 'total', 'cells' => $totCells];

        $sections[] = [
            'panel_title' => 'Outlet data',
            'table' => [
                'columns' => $cols,
                'align' => $align,
                'widths' => $widths,
                'rows' => $outRows,
            ],
        ];
    }

    // Sales performance
    $sales = $payload['sales_performance'] ?? [];
    $cats = $sales['categories'] ?? ['csd', 'water', 'juice', 'energy'];
    $salesLabels = ['csd' => 'CSD', 'water' => 'Water', 'juice' => 'Juice', 'energy' => 'Energy', 'total' => 'TOTAL'];
    foreach (['current_month' => 'Sales performance — Current month (unit cases)', 'ytd' => 'Sales performance — YTD (unit cases)'] as $secKey => $secLabel) {
        $sectionVals = $sales['values'][$secKey] ?? [];
        $totals = ['cy' => 0.0, 'target' => 0.0, 'py' => 0.0];
        $sRows = [];
        foreach ($cats as $cat) {
            if ($cat === 'total') {
                continue;
            }
            $v = $sectionVals[$cat] ?? [];
            $cy = (float) ($v['cy'] ?? 0);
            $tg = (float) ($v['target'] ?? 0);
            $py = (float) ($v['py'] ?? 0);
            $totals['cy'] += $cy;
            $totals['target'] += $tg;
            $totals['py'] += $py;
            $sRows[] = [
                'type' => 'data',
                'cells' => [
                    $salesLabels[$cat] ?? $cat,
                    report_board_num($cy),
                    report_board_num($tg),
                    report_board_num($py),
                    report_board_num($cy - $tg),
                    report_board_num($cy - $py),
                ],
            ];
        }
        $sRows[] = [
            'type' => 'total',
            'cells' => [
                'TOTAL',
                report_board_num($totals['cy']),
                report_board_num($totals['target']),
                report_board_num($totals['py']),
                report_board_num($totals['cy'] - $totals['target']),
                report_board_num($totals['cy'] - $totals['py']),
            ],
        ];
        $sections[] = [
            'panel_title' => $secLabel,
            'table' => [
                'columns' => ['Category', 'CY', 'Target', 'PY', 'VAR vs Target', 'VAR vs PY'],
                'align' => ['left', 'right', 'right', 'right', 'right', 'right'],
                'widths' => [100, 70, 70, 70, 100, 100],
                'rows' => $sRows,
            ],
        ];
    }

    // Metric panels
    foreach (
        [
            'service_model' => 'Service model',
            'execution_excellence' => 'Execution excellence',
            'execution_model' => 'Execution model',
        ] as $panelKey => $title
    ) {
        $panel = $payload[$panelKey] ?? null;
        if (!is_array($panel)) {
            continue;
        }
        $mRows = $panel['rows'] ?? array_keys($panel['values'] ?? []);
        $pvals = $panel['values'] ?? [];
        if (!$mRows) {
            continue;
        }
        $tableRows = [];
        foreach ($mRows as $r) {
            $v = $pvals[$r] ?? [];
            $tableRows[] = [
                'type' => 'data',
                'cells' => [
                    str_replace('_', ' ', (string) $r),
                    report_board_num($v['mtd'] ?? ''),
                    report_board_num($v['mtd_target'] ?? ''),
                    report_board_num($v['ytd'] ?? ''),
                    report_board_num($v['ytd_target'] ?? ''),
                ],
            ];
        }
        $sections[] = [
            'panel_title' => $title,
            'table' => [
                'columns' => ['Metric', 'MTD', 'Target', 'YTD', 'Target'],
                'align' => ['left', 'right', 'right', 'right', 'right'],
                'widths' => [180, 80, 80, 80, 80],
                'rows' => $tableRows,
            ],
        ];
    }

    // Unforgivable packs
    $uf = $payload['unforgivable_packs'] ?? [];
    $ufLines = $uf['lines'] ?? [];
    $ufVals = $uf['values'] ?? [];
    $ufRows = [];
    foreach ($ufLines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $key = (string) ($line['key'] ?? '');
        $v = is_array($ufVals[$key] ?? null) ? $ufVals[$key] : [];
        $ufRows[] = [
            'type' => 'data',
            'cells' => [
                (string) ($line['sku'] ?? $key),
                report_board_num($v['recommended'] ?? 0),
                report_board_num($v['opening'] ?? 0),
                report_board_num($v['on_order'] ?? 0),
                (string) ($v['comments'] ?? ''),
            ],
        ];
    }
    $sections[] = [
        'panel_title' => 'Unforgivable packs inventory (physical cases)',
        'lines' => ['Opening = manager 7am stock  |  Qty on order = open CCBA orders (automatic).'],
        'table' => [
            'columns' => ['SKU', 'Recommended', 'Opening', 'On order', 'Comments'],
            'align' => ['left', 'right', 'right', 'right', 'left'],
            'widths' => [170, 78, 72, 72, 120],
            'rows' => $ufRows ?: [['type' => 'data', 'cells' => ['(none)', '-', '-', '-', '']]],
        ],
    ];

    return $sections;
}

/**
 * Separate PDF: CCBA Inventory + OCCD boards styled like the on-screen boards.
 *
 * @return array{doc_title: string, meta: array<string, string>, sections: list<array<string, mixed>>}
 */
function report_build_ccba_boards_layout(string $date, ?string $preparedBy = null): array
{
    $pdo = db();
    $inv = $pdo->prepare("SELECT status, submitted_at, payload_json FROM manager_daily_boards WHERE board_date = ? AND board_type = 'inventory_board' LIMIT 1");
    $inv->execute([$date]);
    $invRow = $inv->fetch();
    $occd = $pdo->prepare("SELECT status, submitted_at, payload_json FROM manager_daily_boards WHERE board_date = ? AND board_type = 'occd_dashboard' LIMIT 1");
    $occd->execute([$date]);
    $occdRow = $occd->fetch();

    $sections = array_merge(
        report_ccba_inventory_sections($invRow ?: null, $date),
        report_ccba_occd_sections($occdRow ?: null, $date)
    );

    return [
        'doc_title' => 'CCBA daily boards - ' . $date,
        'meta' => [
            'Report date' => $date,
            'Prepared by' => $preparedBy ?: 'Manager',
            'Document' => 'CCBA boards pack (Inventory + OCCD)',
            'Recipient' => 'Executive / Board',
            'Purpose' => 'Boards styled as in Outpost — companion to the executive brief',
        ],
        'sections' => $sections,
    ];
}

/**
 * Format top / slow product flags for executives.
 *
 * @param list<array{label: string, qty: int, amount: float}> $ranked
 * @return array{top: list<string>, slow: list<string>, zero: list<string>}
 */
function report_product_sales_flag_lines(array $ranked, int $topN = 8, int $slowN = 8): array
{
    $top = [];
    $slow = [];
    $zero = [];

    if (!$ranked) {
        return [
            'top' => ['No product sales lines with quantities on the RDC sheet.'],
            'slow' => ['No slow-movers to flag.'],
            'zero' => [],
        ];
    }

    $withSales = array_values(array_filter($ranked, static fn($r) => (int) $r['qty'] > 0 || (float) $r['amount'] > 0));
    foreach (array_slice($withSales, 0, $topN) as $i => $row) {
        $top[] = sprintf(
            '%d. ★ %s — qty %s — %s',
            $i + 1,
            $row['label'],
            number_format((int) $row['qty']),
            simple_pdf_ugx((float) $row['amount'])
        );
    }
    if (!$top) {
        $top[] = 'No top sellers for this date.';
    }

    $asc = $withSales;
    usort($asc, static function ($a, $b) {
        $cmp = $a['qty'] <=> $b['qty'];
        return $cmp !== 0 ? $cmp : ($a['amount'] <=> $b['amount']);
    });
    // Prefer products that sold something but little — skip zeros here
    $slowPool = array_values(array_filter($asc, static fn($r) => (int) $r['qty'] > 0));
    foreach (array_slice($slowPool, 0, $slowN) as $i => $row) {
        $slow[] = sprintf(
            '%d. ▼ %s — qty %s — %s',
            $i + 1,
            $row['label'],
            number_format((int) $row['qty']),
            simple_pdf_ugx((float) $row['amount'])
        );
    }
    if (!$slow) {
        $slow[] = 'No slow-movers with sales > 0.';
    }

    return ['top' => $top, 'slow' => $slow, 'zero' => $zero];
}

/**
 * @return array{doc_title: string, meta: array<string, string>, sections: list<array{heading: string, lines: list<string>}>}
 */
function report_build_manager_layout(string $date, ?string $preparedBy = null): array
{
    require_once __DIR__ . '/rdc_balancing.php';
    require_once __DIR__ . '/depot_finance.php';
    require_once __DIR__ . '/cadet_reports.php';

    $pdo = db();
    $sales = $pdo->query(
        "SELECT COUNT(*) AS orders, COALESCE(SUM(amount_total),0) AS revenue
         FROM orders WHERE DATE(created_at) = " . $pdo->quote($date)
         . " AND status NOT IN ('cancelled','draft')"
    )->fetch();
    $low = $pdo->query(
        'SELECT name, warehouse_qty, min_stock FROM (
            SELECT p.name, COALESCE(SUM(b.qty_warehouse),0) AS warehouse_qty, p.min_stock
            FROM products p LEFT JOIN batches b ON b.product_id = p.id
            WHERE p.is_active = 1 GROUP BY p.id
         ) x WHERE warehouse_qty < min_stock ORDER BY warehouse_qty ASC LIMIT 8'
    )->fetchAll();
    $pendingEdits = (int) $pdo->query("SELECT COUNT(*) FROM edit_requests WHERE status = 'pending'")->fetchColumn();
    $pendingOrders = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
    $onRoute = (int) $pdo->query("SELECT COUNT(*) FROM vehicles WHERE status = 'on_route'")->fetchColumn();
    $vehicles = (int) $pdo->query("SELECT COUNT(*) FROM vehicles WHERE is_active = 1")->fetchColumn();
    $tripsReturned = (int) $pdo->query(
        "SELECT COUNT(*) FROM delivery_trips WHERE status = 'returned' AND DATE(returned_at) = " . $pdo->quote($date)
    )->fetchColumn();
    $cashPending = (int) $pdo->query(
        "SELECT COUNT(*) FROM delivery_trips
         WHERE status = 'returned' AND cash_collected IS NULL AND DATE(returned_at) = " . $pdo->quote($date)
    )->fetchColumn();
    $recv = $pdo->query(
        'SELECT COUNT(*) AS with_balance, COALESCE(SUM(credit_balance),0) AS total
         FROM customers WHERE is_active = 1 AND credit_balance > 0'
    )->fetch() ?: ['with_balance' => 0, 'total' => 0];

    $inv = $pdo->prepare("SELECT status, submitted_at, payload_json FROM manager_daily_boards WHERE board_date = ? AND board_type = 'inventory_board' LIMIT 1");
    $inv->execute([$date]);
    $invRow = $inv->fetch();
    $occd = $pdo->prepare("SELECT status, submitted_at, payload_json FROM manager_daily_boards WHERE board_date = ? AND board_type = 'occd_dashboard' LIMIT 1");
    $occd->execute([$date]);
    $occdRow = $occd->fetch();

    $sheetStmt = $pdo->prepare('SELECT * FROM rdc_daily_sheets WHERE balance_date = ? LIMIT 1');
    $sheetStmt->execute([$date]);
    $sheetRow = $sheetStmt->fetch() ?: null;
    $sheet = $sheetRow ? rdc_sheet_to_response($sheetRow) : null;

    $opening = depot_snapshot_fetch($date, 'opening');
    $closing = depot_snapshot_fetch($date, 'closing');
    $stockSection = report_stock_snapshot_section($opening, $closing);

    $ranked = report_rank_rdc_product_sales($sheet);
    $productFlags = report_product_sales_flag_lines($ranked, 8, 8);

    // Also flag slow stock-book movers from closing snapshot sales column
    $stockSlow = [];
    $stockHot = [];
    if ($closing && is_array($closing['lines'] ?? null)) {
        $stockRank = [];
        foreach ($closing['lines'] as $row) {
            $qty = (int) ($row['sales'] ?? 0);
            $name = trim((string) ($row['product_name'] ?? $row['sku'] ?? ''));
            if ($name === '') {
                continue;
            }
            $stockRank[] = ['name' => $name, 'sales' => $qty, 'closing' => (int) ($row['closing'] ?? $row['qty'] ?? 0)];
        }
        usort($stockRank, static fn($a, $b) => $b['sales'] <=> $a['sales']);
        foreach (array_slice($stockRank, 0, 5) as $i => $row) {
            if ((int) $row['sales'] <= 0) {
                break;
            }
            $stockHot[] = sprintf('%d. ★ %s — sold %s (closing %s)', $i + 1, $row['name'], number_format((int) $row['sales']), number_format((int) $row['closing']));
        }
        $asc = $stockRank;
        usort($asc, static fn($a, $b) => $a['sales'] <=> $b['sales']);
        $slowPool = array_values(array_filter($asc, static fn($r) => (int) $r['sales'] > 0));
        foreach (array_slice($slowPool, 0, 5) as $i => $row) {
            $stockSlow[] = sprintf('%d. ▼ %s — sold %s (closing %s)', $i + 1, $row['name'], number_format((int) $row['sales']), number_format((int) $row['closing']));
        }
        // Zero sales with stock still on hand
        $zeroWithStock = array_values(array_filter($stockRank, static fn($r) => (int) $r['sales'] === 0 && (int) $r['closing'] > 0));
        usort($zeroWithStock, static fn($a, $b) => $b['closing'] <=> $a['closing']);
        if ($zeroWithStock) {
            $stockSlow[] = 'No sales today (still in stock — review):';
            foreach (array_slice($zeroWithStock, 0, 6) as $row) {
                $stockSlow[] = '  · ' . $row['name'] . ' — closing ' . number_format((int) $row['closing']);
            }
        }
    }

    $attention = [];
    if (!$opening) {
        $attention[] = 'FLAG: Opening stock (7am) was not saved.';
    }
    if (!$closing) {
        $attention[] = 'FLAG: Closing stock (7pm) was not saved.';
    }
    if (!$sheet) {
        $attention[] = 'FLAG: RDC balancing sheet missing for this date.';
    } else {
        $var = (float) ($sheet['variance'] ?? 0);
        if (abs($var) >= 1) {
            $attention[] = 'FLAG: RDC cash variance ' . simple_pdf_ugx($var) . '.';
        }
        $st = (string) ($sheet['status'] ?? 'draft');
        if (!in_array($st, ['submitted', 'approved', 'manager_approved', 'reviewed'], true)) {
            $attention[] = 'FLAG: RDC sheet status is "' . str_replace('_', ' ', $st) . '" (not fully closed).';
        }
    }
    if ($cashPending > 0) {
        $attention[] = 'FLAG: ' . $cashPending . ' returned trip(s) still awaiting cash confirmation.';
    }
    if (!$invRow || ($invRow['status'] ?? '') !== 'submitted') {
        $attention[] = 'NOTE: CCBA inventory board not submitted (figures may be draft/missing).';
    }
    if (!$occdRow || ($occdRow['status'] ?? '') !== 'submitted') {
        $attention[] = 'NOTE: OCCD dashboard not submitted (figures may be draft/missing).';
    }
    if ($ranked) {
        $attention[] = 'Most selling today: ' . $ranked[0]['label']
            . ' (qty ' . number_format((int) $ranked[0]['qty']) . ', ' . simple_pdf_ugx((float) $ranked[0]['amount']) . ').';
        $least = end($ranked);
        if (is_array($least) && $least['label'] !== $ranked[0]['label']) {
            $attention[] = 'Least selling (among products with sales): ' . $least['label']
                . ' (qty ' . number_format((int) $least['qty']) . ', ' . simple_pdf_ugx((float) $least['amount']) . ').';
        }
    }
    if (!$attention) {
        $attention[] = 'No critical flags — day controls look complete.';
    }

    $rdcLines = [];
    if ($sheet) {
        $rdcLines = [
            'Sheet status: ' . str_replace('_', ' ', (string) ($sheet['status'] ?? 'draft')),
            'Submitted at: ' . ((string) ($sheet['submitted_at'] ?? '') ?: 'Not submitted'),
            'Sales total: ' . simple_pdf_ugx((float) ($sheet['sales_total'] ?? 0)),
            'Recoveries: ' . simple_pdf_ugx((float) ($sheet['recovery_total'] ?? 0)),
            'Expenses total: ' . simple_pdf_ugx((float) ($sheet['expenses_total'] ?? 0)),
            'Grand total (sales + recoveries): ' . simple_pdf_ugx((float) ($sheet['grand_total'] ?? 0)),
            'Expected cash: ' . simple_pdf_ugx((float) ($sheet['expected_amount'] ?? 0)),
            'Actual cash counted: ' . simple_pdf_ugx((float) ($sheet['actual_total'] ?? 0)),
            'Cash variance: ' . simple_pdf_ugx((float) ($sheet['variance'] ?? 0)),
        ];
        // Top expense lines
        $expShown = 0;
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
            if ($amt <= 0) {
                continue;
            }
            if ($expShown === 0) {
                $rdcLines[] = 'Expense highlights:';
            }
            $rdcLines[] = '  · ' . $label . ': ' . simple_pdf_ugx($amt);
            if (++$expShown >= 6) {
                break;
            }
        }
    } else {
        $rdcLines[] = 'No RDC sheet submitted for this date.';
    }

    $lowLines = [];
    foreach ($low as $row) {
        $lowLines[] = '- ' . $row['name'] . ' — ' . $row['warehouse_qty'] . ' on hand (min ' . $row['min_stock'] . ')';
    }
    if (!$lowLines) {
        $lowLines[] = '- None flagged under minimum stock.';
    }

    $cadetReports = function_exists('rdc_cadet_reports_for_date')
        ? rdc_cadet_reports_for_date($date)
        : [];
    $fieldLines = [
        'Orders today: ' . (int) ($sales['orders'] ?? 0),
        'System revenue (orders): ' . simple_pdf_ugx((float) ($sales['revenue'] ?? 0)),
        'RDC booked grand total: ' . ($sheet ? simple_pdf_ugx((float) ($sheet['grand_total'] ?? 0)) : 'n/a'),
        'Pending sales to confirm: ' . $pendingOrders,
        'Edit requests pending: ' . $pendingEdits,
        'Fleet on route: ' . $onRoute . '/' . $vehicles,
        'Trips returned today: ' . $tripsReturned,
        'Cash handovers pending: ' . $cashPending,
        'Cadet EOD reports: ' . count($cadetReports),
        'Customers with credit: ' . (int) ($recv['with_balance'] ?? 0),
        'Outstanding receivables: ' . simple_pdf_ugx((float) ($recv['total'] ?? 0)),
    ];

    $topLines = $productFlags['top'];
    if ($stockHot) {
        $topLines[] = '';
        $topLines[] = 'From stock book (closing snapshot sales):';
        foreach ($stockHot as $line) {
            $topLines[] = $line;
        }
    }

    $slowLines = $productFlags['slow'];
    if ($stockSlow) {
        $slowLines[] = '';
        $slowLines[] = 'From stock book:';
        foreach ($stockSlow as $line) {
            $slowLines[] = $line;
        }
    }

    $invLines = [
        'Full Inventory + OCCD boards are in the companion PDF: "CCBA boards pack".',
        'Inventory board status: ' . (string) (($invRow['status'] ?? null) ?: 'not started'),
        'OCCD dashboard status: ' . (string) (($occdRow['status'] ?? null) ?: 'not started'),
    ];

    return [
        'doc_title' => 'Executive operations brief - ' . $date,
        'meta' => [
            'Report date' => $date,
            'Prepared by' => $preparedBy ?: 'Manager',
            'Document' => 'Executive operations brief',
            'Recipient' => 'Executive / Board',
            'Purpose' => 'Day summary: finance, full stock book, top & slow sellers (CCBA boards = separate PDF)',
        ],
        'sections' => [
            [
                'heading' => 'Executive attention',
                'lines' => $attention,
            ],
            [
                'heading' => 'Day at a glance',
                'lines' => $fieldLines,
            ],
            [
                'heading' => 'Finance summary (RDC)',
                'lines' => $rdcLines,
            ],
            $stockSection,
            [
                'heading' => 'Most selling products',
                'lines' => $topLines,
            ],
            [
                'heading' => 'Least selling / slow movers',
                'lines' => $slowLines,
            ],
            [
                'heading' => 'CCBA boards (see companion PDF)',
                'lines' => $invLines,
            ],
            [
                'heading' => 'Stock risk (below minimum)',
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

/**
 * Write PDF + upsert same-day packet for from_role → to_role + report_type.
 *
 * @param array{doc_title?: string, meta?: array<string, string>, sections?: list<array{heading: string, lines: list<string>}>} $layout
 * @return array<string, mixed>
 */
function report_upsert_layout_packet(
    string $reportType,
    array $layout,
    string $date,
    int $userId,
    string $fromRole,
    string $toRole,
    string $filePrefix,
    string $summary,
    ?string $notes = null,
    bool $notifyExecutive = false
): array {
    $dir = report_storage_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $pdfTitle = (string) ($layout['doc_title'] ?? 'Report');
    $file = $filePrefix . '-' . date('Ymd-His') . '.pdf';
    $abs = $dir . '/' . $file;
    simple_pdf_write($abs, $pdfTitle, [], $layout);
    $size = (int) filesize($abs);
    $relative = 'storage/reports/' . $file;

    $existing = db()->prepare(
        'SELECT id, file_path FROM report_packets
         WHERE report_type = ? AND report_date = ? AND from_role = ? AND to_role = ?
         ORDER BY id DESC LIMIT 1'
    );
    $existing->execute([$reportType, $date, $fromRole, $toRole]);
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
        $formatted = $row ? report_format_packet($row, $fromRole, $userId) : ['id' => $prevId, 'to_role' => $toRole];
        if ($notifyExecutive && $toRole === 'executive') {
            require_once __DIR__ . '/notifications.php';
            $raw = $row ?: [
                'id' => $prevId,
                'to_role' => $toRole,
                'title' => $pdfTitle,
                'report_date' => $date,
                'from_user_id' => $userId,
            ];
            $raw['to_role'] = $toRole;
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
        $fromRole,
        $toRole,
        null,
        null,
        $notes
    );
    if ($notifyExecutive && $toRole === 'executive') {
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

function report_generate_pack(string $role, int $userId, string $date, ?string $title = null, ?string $notes = null): array
{
    $toRole = report_next_recipient($role);
    if (!$toRole) {
        throw new RuntimeException('Your role cannot send reports upward');
    }

    $userName = (string) (db()->query('SELECT full_name FROM users WHERE id = ' . (int) $userId)->fetchColumn() ?: ucfirst($role));

    if ($role === 'accountant') {
        $layout = report_build_accountant_layout($date, $userName . ' (Accountant / RDC)');
        if ($title) {
            $layout['doc_title'] = $title;
        }
        if ($notes) {
            $layout['sections'][] = [
                'heading' => 'Cover note',
                'lines' => [$notes],
            ];
        }
        return report_upsert_layout_packet(
            'accountant_pack',
            $layout,
            $date,
            $userId,
            $role,
            $toRole,
            'acc-pack',
            $notes ?: 'RDC daily pack: cash confirmation, balancing, cadets, stock, deliveries.',
            $notes,
            false
        );
    }

    // Manager → executive: operations brief + separate CCBA boards PDF
    $layout = report_build_manager_layout($date, $userName . ' (Manager)');
    if ($title) {
        $layout['doc_title'] = $title;
    }
    if ($notes) {
        $layout['sections'][] = [
            'heading' => 'Cover note',
            'lines' => [$notes],
        ];
    }
    $brief = report_upsert_layout_packet(
        'manager_brief',
        $layout,
        $date,
        $userId,
        $role,
        $toRole,
        'mgr-brief',
        $notes ?: 'Executive brief + companion CCBA boards PDF (full stock book, sellers, finance).',
        $notes,
        true
    );

    $boardsLayout = report_build_ccba_boards_layout($date, $userName . ' (Manager)');
    $boardsType = 'ccba_boards';
    try {
        $boards = report_upsert_layout_packet(
            $boardsType,
            $boardsLayout,
            $date,
            $userId,
            $role,
            $toRole,
            'ccba-boards',
            'Full Inventory + OCCD boards as entered in Outpost (companion to executive brief).',
            null,
            false
        );
    } catch (Throwable $e) {
        // Migration 015 not applied yet — still deliver boards as uploaded companion
        $boards = report_upsert_layout_packet(
            'uploaded',
            $boardsLayout,
            $date,
            $userId,
            $role,
            $toRole,
            'ccba-boards',
            'Full Inventory + OCCD boards (companion). Apply migration 015 for typed ccba_boards packets.',
            null,
            false
        );
    }

    $brief['companion_ccba_boards'] = $boards;
    return $brief;
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
