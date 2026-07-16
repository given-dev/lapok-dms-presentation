<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

// Security: delete this file after use, or restrict by IP.
// Only accessible from localhost.
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Access denied');
}

$pdo = db();

// Force UTF-8 connection so REPLACE works on the right bytes.
$pdo->exec("SET NAMES 'utf8mb4'");

// The em dash — stored as UTF-8 (E2 80 94) reads correctly, but
// MySQL may have stored it as the Windows-1252 mojibake ÔÇö
// (bytes C3 94 C3 87 C3 B6) if the seed ran on a latin1 connection.
// We fix both variants.

$tables = [
    'report_packets'   => ['title', 'summary'],
    'rdc_daily_sheets' => ['notes'],
    'delivery_trips'   => ['notes'],
    'orders'           => ['efris_ref'],
];

// Variants of the garbled em-dash we want to remove
$badStrings = [
    "\xc3\x94\xc3\x87\xc3\xb6",   // ÔÇö  — the actual corruption bytes
    "\xe2\x80\x94",                 // —    — raw UTF-8 em dash (safe to replace too)
    "\xc3\xa2\xc2\x80\xc2\x94",   // â€"  — another common mojibake form
];
$safeReplacement = ' - ';

// Middle dot variants
$dotBads = [
    "\xc3\x82\xc2\xb7",   // Â·
    "\xc2\xb7",            // ·
];
$dotSafe = ' - ';

$totalFixed = 0;

echo "<pre style='font-family:monospace;font-size:14px;padding:1rem'>";
echo "Lapok DMS — Encoding Repair Tool\n";
echo str_repeat("=", 50) . "\n\n";

foreach ($tables as $table => $columns) {
    foreach ($columns as $col) {
        // Check if table/column exists
        try {
            $check = $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        } catch (Throwable $e) {
            echo "SKIP: {$table}.{$col} (table not found)\n";
            continue;
        }

        foreach ($badStrings as $bad) {
            $stmt = $pdo->prepare(
                "UPDATE `{$table}` SET `{$col}` = REPLACE(`{$col}`, ?, ?) WHERE `{$col}` LIKE CONCAT('%', ?, '%')"
            );
            $stmt->execute([$bad, $safeReplacement, $bad]);
            $n = $stmt->rowCount();
            if ($n > 0) {
                echo "FIXED {$n} row(s): {$table}.{$col}\n";
                $totalFixed += $n;
            }
        }

        foreach ($dotBads as $bad) {
            $stmt = $pdo->prepare(
                "UPDATE `{$table}` SET `{$col}` = REPLACE(`{$col}`, ?, ?) WHERE `{$col}` LIKE CONCAT('%', ?, '%')"
            );
            $stmt->execute([$bad, $dotSafe, $bad]);
            $n = $stmt->rowCount();
            if ($n > 0) {
                echo "FIXED {$n} row(s): {$table}.{$col} (middle-dot)\n";
                $totalFixed += $n;
            }
        }
    }
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "Total rows fixed: {$totalFixed}\n";
echo "\nDONE. You can now delete this file:\n";
echo __FILE__ . "\n";
echo "</pre>";

// Auto-delete this script after running for safety
@unlink(__FILE__);
