<?php
declare(strict_types=1);

/**
 * Sample executive brief + companion CCBA boards PDF.
 * Run: C:\xampp\php\php.exe scripts\sample_executive_brief.php
 */
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/report_packets.php';

try {
    date_default_timezone_set('Africa/Kampala');
} catch (Throwable) {
}

$pdo = db();
$dates = $pdo->query('SELECT balance_date FROM rdc_daily_sheets ORDER BY balance_date DESC LIMIT 5')->fetchAll(PDO::FETCH_COLUMN);
$today = date('Y-m-d');
$date = $dates[0] ?? $today;

$dir = dirname(__DIR__) . '/storage/reports';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

$briefLayout = report_build_manager_layout($date, 'Sample Manager (preview)');
$boardsLayout = report_build_ccba_boards_layout($date, 'Sample Manager (preview)');

$briefPdf = $dir . '/sample-executive-brief-' . str_replace('-', '', $date) . '.pdf';
$boardsPdf = $dir . '/sample-ccba-boards-' . str_replace('-', '', $date) . '.pdf';
simple_pdf_write($briefPdf, (string) $briefLayout['doc_title'], [], $briefLayout);
simple_pdf_write($boardsPdf, (string) $boardsLayout['doc_title'], [], $boardsLayout);

$writeTxt = static function (string $path, array $layout): void {
    $preview = (string) $layout['doc_title'] . "\n" . str_repeat('=', 60) . "\n";
    foreach ($layout['meta'] as $k => $v) {
        $preview .= $k . ': ' . $v . "\n";
    }
    $preview .= "\n";
    foreach ($layout['sections'] as $sec) {
        $preview .= '## ' . ($sec['heading'] ?? $sec['banner']['title'] ?? $sec['panel_title'] ?? 'Section') . "\n";
        foreach (($sec['lines'] ?? []) as $line) {
            $preview .= $line . "\n";
        }
        if (!empty($sec['table']['columns'])) {
            $preview .= '[table: ' . implode(' | ', $sec['table']['columns']) . ' · ' . count($sec['table']['rows'] ?? []) . " rows]\n";
        }
        $preview .= "\n";
    }
    file_put_contents($path, $preview);
};

$writeTxt($dir . '/sample-executive-brief-' . str_replace('-', '', $date) . '.txt', $briefLayout);
$writeTxt($dir . '/sample-ccba-boards-' . str_replace('-', '', $date) . '.txt', $boardsLayout);

echo "DATE={$date}\n";
echo "BRIEF_PDF={$briefPdf}\n";
echo "BOARDS_PDF={$boardsPdf}\n";
echo "BRIEF_URL=http://localhost/project/lapok-dms-presentation/storage/reports/" . basename($briefPdf) . "\n";
echo "BOARDS_URL=http://localhost/project/lapok-dms-presentation/storage/reports/" . basename($boardsPdf) . "\n";
echo "---BRIEF PREVIEW (stock section excerpt)---\n";
foreach ($briefLayout['sections'] as $sec) {
    if (($sec['heading'] ?? '') !== 'Opening & closing stock (full stock book)') {
        continue;
    }
    echo '## ' . $sec['heading'] . "\n";
    foreach (array_slice($sec['lines'], 0, 40) as $line) {
        echo $line . "\n";
    }
    echo "... (" . count($sec['lines']) . " lines total)\n";
}
