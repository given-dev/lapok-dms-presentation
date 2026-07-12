<?php
declare(strict_types=1);

/**
 * Branded Outpost DMS exports — real .xlsx with embedded logo (Excel-safe).
 */

function branded_export_logo_path(): string
{
    if (function_exists('simple_pdf_logo_path')) {
        return simple_pdf_logo_path();
    }
    $base = dirname(__DIR__) . '/assets/img';
    foreach (['outpost-dms-logo.jpg', 'outpost-dms-logo.jpeg', 'outpost-dms-logo.png', 'lapok-dms-logo.jpg'] as $name) {
        $path = $base . '/' . $name;
        if (is_file($path) && filesize($path) > 0) {
            return $path;
        }
    }
    return $base . '/outpost-dms-logo.jpg';
}

function branded_export_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
}

function branded_export_slug(string $title): string
{
    $slug = preg_replace('/[^a-zA-Z0-9]+/', '-', $title) ?? 'export';
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'export';
}

function branded_export_xlsx_col(int $index): string
{
    $index++;
    $col = '';
    while ($index > 0) {
        $index--;
        $col = chr(65 + ($index % 26)) . $col;
        $index = intdiv($index, 26);
    }
    return $col;
}

function branded_export_xlsx_escape(string $value): string
{
    return branded_export_h($value);
}

/**
 * @return array{0: string, 1: bool} [display, is_number]
 */
function branded_export_cell_value(mixed $value): array
{
    if ($value === null || $value === '') {
        return ['—', false];
    }
    if (is_int($value) || is_float($value)) {
        return [(string) $value, true];
    }
    if (is_string($value) && is_numeric($value) && !preg_match('/^0\d+/', $value)) {
        return [$value, true];
    }
    return [(string) $value, false];
}

/**
 * Build and send a real .xlsx workbook with Outpost branding + logo.
 *
 * @param list<string> $headers
 * @param list<list<mixed>> $rows
 * @param array{
 *   subtitle?: string,
 *   meta?: array<string, string>,
 *   filename?: string,
 *   generated_by?: string
 * } $opts
 */
function branded_export_excel(string $reportTitle, array $headers, array $rows, array $opts = []): void
{
    if (!class_exists('ZipArchive')) {
        branded_export_excel_html_fallback($reportTitle, $headers, $rows, $opts);
        return;
    }

    $company = 'OUTPOST DMS';
    $tagline = 'Depot Management System';
    $subtitle = (string) ($opts['subtitle'] ?? 'Official depot export');
    /** @var array<string, string> $meta */
    $meta = $opts['meta'] ?? [];
    $generatedBy = (string) ($opts['generated_by'] ?? '');
    $filename = (string) ($opts['filename'] ?? ('Outpost-DMS-' . branded_export_slug($reportTitle) . '-' . date('Ymd-Hi') . '.xlsx'));
    $filename = preg_replace('/\.xls$/i', '.xlsx', $filename) ?? $filename;
    if (!preg_match('/\.xlsx$/i', $filename)) {
        $filename .= '.xlsx';
    }

    $sheetName = substr(preg_replace('/[\\\\\/\*\?\:\[\]]+/', ' ', $reportTitle) ?? 'Export', 0, 31);
    $colCount = max(count($headers), 1);
    $lastCol = branded_export_xlsx_col($colCount - 1);

    $logoPath = branded_export_logo_path();
    $hasLogo = is_file($logoPath) && filesize($logoPath) > 0;
    $logoBytes = $hasLogo ? (string) file_get_contents($logoPath) : '';
    $logoExt = $hasLogo ? strtolower(pathinfo($logoPath, PATHINFO_EXTENSION)) : 'jpg';
    if ($logoExt === 'jpeg') {
        $logoExt = 'jpg';
    }
    if (!in_array($logoExt, ['jpg', 'png'], true)) {
        $hasLogo = false;
        $logoBytes = '';
        $logoExt = 'jpg';
    }
    $logoMimePart = $logoExt === 'png' ? 'image/png' : 'image/jpeg';
    $logoMediaName = 'image1.' . $logoExt;

    // Layout rows:
    // 1 red accent, 2-3 brand header, 4 title, 5 subtitle, then meta, blank, table
    $r = 1;
    $sheetRows = '';

    // Row 1 — red accent bar
    $sheetRows .= '<row r="' . $r . '" ht="8" customHeight="1">';
    for ($c = 0; $c < $colCount; $c++) {
        $ref = branded_export_xlsx_col($c) . $r;
        $sheetRows .= '<c r="' . $ref . '" s="1"/>';
    }
    $sheetRows .= '</row>';
    $r++;

    // Row 2 — brand name (leave col A for logo when present)
    $brandStart = $hasLogo ? 'B' : 'A';
    $sheetRows .= '<row r="' . $r . '" ht="28" customHeight="1">'
        . ($hasLogo ? '<c r="A' . $r . '" s="2"/>' : '')
        . '<c r="' . $brandStart . $r . '" s="2" t="inlineStr"><is><t>' . branded_export_xlsx_escape($company) . '</t></is></c>';
    for ($c = ($hasLogo ? 2 : 1); $c < $colCount; $c++) {
        $sheetRows .= '<c r="' . branded_export_xlsx_col($c) . $r . '" s="2"/>';
    }
    $sheetRows .= '</row>';
    $brandRow = $r;
    $r++;

    // Row 3 — tagline
    $sheetRows .= '<row r="' . $r . '" ht="18" customHeight="1">'
        . ($hasLogo ? '<c r="A' . $r . '" s="3"/>' : '')
        . '<c r="' . $brandStart . $r . '" s="3" t="inlineStr"><is><t>' . branded_export_xlsx_escape($tagline) . '</t></is></c>';
    for ($c = ($hasLogo ? 2 : 1); $c < $colCount; $c++) {
        $sheetRows .= '<c r="' . branded_export_xlsx_col($c) . $r . '" s="3"/>';
    }
    $sheetRows .= '</row>';
    $r++;

    // Row 4 — report title
    $sheetRows .= '<row r="' . $r . '" ht="22" customHeight="1">'
        . '<c r="A' . $r . '" s="4" t="inlineStr"><is><t>' . branded_export_xlsx_escape($reportTitle) . '</t></is></c>';
    for ($c = 1; $c < $colCount; $c++) {
        $sheetRows .= '<c r="' . branded_export_xlsx_col($c) . $r . '" s="5"/>';
    }
    $sheetRows .= '</row>';
    $r++;

    // Row 5 — subtitle
    $sheetRows .= '<row r="' . $r . '">'
        . '<c r="A' . $r . '" s="6" t="inlineStr"><is><t>' . branded_export_xlsx_escape($subtitle) . '</t></is></c>';
    for ($c = 1; $c < $colCount; $c++) {
        $sheetRows .= '<c r="' . branded_export_xlsx_col($c) . $r . '" s="5"/>';
    }
    $sheetRows .= '</row>';
    $r++;

    foreach ($meta as $label => $value) {
        $sheetRows .= '<row r="' . $r . '">'
            . '<c r="A' . $r . '" s="7" t="inlineStr"><is><t>' . branded_export_xlsx_escape((string) $label) . '</t></is></c>'
            . '<c r="B' . $r . '" s="8" t="inlineStr"><is><t>' . branded_export_xlsx_escape((string) $value) . '</t></is></c>';
        for ($c = 2; $c < $colCount; $c++) {
            $sheetRows .= '<c r="' . branded_export_xlsx_col($c) . $r . '" s="5"/>';
        }
        $sheetRows .= '</row>';
        $r++;
    }

    // Spacer
    $sheetRows .= '<row r="' . $r . '"><c r="A' . $r . '" s="5"/></row>';
    $r++;

    // Header row
    $headerRow = $r;
    $sheetRows .= '<row r="' . $r . '" ht="22" customHeight="1">';
    foreach ($headers as $i => $h) {
        $ref = branded_export_xlsx_col($i) . $r;
        $sheetRows .= '<c r="' . $ref . '" s="9" t="inlineStr"><is><t>' . branded_export_xlsx_escape((string) $h) . '</t></is></c>';
    }
    $sheetRows .= '</row>';
    $r++;

    if (!$rows) {
        $sheetRows .= '<row r="' . $r . '">'
            . '<c r="A' . $r . '" s="10" t="inlineStr"><is><t>No rows for this export.</t></is></c>';
        for ($c = 1; $c < $colCount; $c++) {
            $sheetRows .= '<c r="' . branded_export_xlsx_col($c) . $r . '" s="10"/>';
        }
        $sheetRows .= '</row>';
        $r++;
    } else {
        $i = 0;
        foreach ($rows as $row) {
            $styleEven = 11;
            $styleOdd = 12;
            $numEven = 13;
            $numOdd = 14;
            $base = ($i % 2 === 0) ? $styleEven : $styleOdd;
            $numBase = ($i % 2 === 0) ? $numEven : $numOdd;
            $sheetRows .= '<row r="' . $r . '">';
            for ($c = 0; $c < $colCount; $c++) {
                $cell = $row[$c] ?? '';
                [$text, $isNum] = branded_export_cell_value($cell);
                $ref = branded_export_xlsx_col($c) . $r;
                if ($isNum && is_numeric($text)) {
                    $sheetRows .= '<c r="' . $ref . '" s="' . $numBase . '"><v>' . $text . '</v></c>';
                } else {
                    $sheetRows .= '<c r="' . $ref . '" s="' . $base . '" t="inlineStr"><is><t>' . branded_export_xlsx_escape($text) . '</t></is></c>';
                }
            }
            $sheetRows .= '</row>';
            $r++;
            $i++;
        }
    }

    // Footer
    $r++;
    $footerBits = ['Generated by Outpost DMS', date('d M Y · H:i')];
    if ($generatedBy !== '') {
        $footerBits[] = $generatedBy;
    }
    $footer = implode('  ·  ', $footerBits);
    $sheetRows .= '<row r="' . $r . '">'
        . '<c r="A' . $r . '" s="15" t="inlineStr"><is><t>' . branded_export_xlsx_escape($footer) . '</t></is></c></row>';

    $mergeCells = '';
    if ($colCount > 1) {
        $merges = [];
        if ($hasLogo && $colCount > 2) {
            $merges[] = "B{$brandRow}:{$lastCol}{$brandRow}";
            $merges[] = 'B' . ($brandRow + 1) . ':' . $lastCol . ($brandRow + 1);
        } else {
            $merges[] = "A{$brandRow}:{$lastCol}{$brandRow}";
            $merges[] = 'A' . ($brandRow + 1) . ':' . $lastCol . ($brandRow + 1);
        }
        $merges[] = 'A' . ($brandRow + 2) . ':' . $lastCol . ($brandRow + 2);
        $merges[] = 'A' . ($brandRow + 3) . ':' . $lastCol . ($brandRow + 3);
        $mergeXml = '';
        foreach ($merges as $m) {
            $mergeXml .= '<mergeCell ref="' . $m . '"/>';
        }
        $mergeCells = '<mergeCells count="' . count($merges) . '">' . $mergeXml . '</mergeCells>';
    }

    $drawingXml = '';
    $sheetRelsExtra = '';
    if ($hasLogo) {
        $drawingXml = '<drawing r:id="rId1"/>';
        $sheetRelsExtra = '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/>';
    }

    $colsXml = '<cols>';
    for ($c = 0; $c < $colCount; $c++) {
        $width = $c === 0 ? 22 : ($c === 1 ? 18 : 14);
        $colsXml .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $width . '" customWidth="1"/>';
    }
    $colsXml .= '</cols>';

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . $colsXml
        . '<sheetData>' . $sheetRows . '</sheetData>'
        . $mergeCells
        . $drawingXml
        . '</worksheet>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="7">'
        . '<font><sz val="11"/><color theme="1"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="18"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        . '<font><sz val="10"/><color rgb="FF94A3B8"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="14"/><color rgb="FF0F172A"/><name val="Calibri"/></font>'
        . '<font><sz val="10"/><color rgb="FF64748B"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        . '<font><sz val="9"/><color rgb="FF94A3B8"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="6">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFE53E3E"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF0F172A"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFFFFF"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="3">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border>'
        . '<left style="thin"><color rgb="FFC53030"/></left>'
        . '<right style="thin"><color rgb="FFC53030"/></right>'
        . '<top style="thin"><color rgb="FFC53030"/></top>'
        . '<bottom style="thin"><color rgb="FFC53030"/></bottom>'
        . '</border>'
        . '<border>'
        . '<left style="thin"><color rgb="FFE2E8F0"/></left>'
        . '<right style="thin"><color rgb="FFE2E8F0"/></right>'
        . '<top style="thin"><color rgb="FFE2E8F0"/></top>'
        . '<bottom style="thin"><color rgb="FFE2E8F0"/></bottom>'
        . '</border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="16">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' // 0
        . '<xf numFmtId="0" fontId="0" fillId="2" borderId="0" xfId="0" applyFill="1"/>' // 1 red bar
        . '<xf numFmtId="0" fontId="1" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center"/></xf>' // 2 brand
        . '<xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center"/></xf>' // 3 tagline
        . '<xf numFmtId="0" fontId="3" fillId="4" borderId="0" xfId="0" applyFont="1" applyFill="1"/>' // 4 title
        . '<xf numFmtId="0" fontId="0" fillId="4" borderId="0" xfId="0" applyFill="1"/>' // 5 soft bg
        . '<xf numFmtId="0" fontId="4" fillId="4" borderId="0" xfId="0" applyFont="1" applyFill="1"/>' // 6 subtitle
        . '<xf numFmtId="0" fontId="4" fillId="4" borderId="0" xfId="0" applyFont="1" applyFill="1"/>' // 7 meta label
        . '<xf numFmtId="0" fontId="0" fillId="4" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment horizontal="left"/></xf>' // 8 meta value (bold via font 0 - ok)
        . '<xf numFmtId="0" fontId="5" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // 9 header
        . '<xf numFmtId="0" fontId="4" fillId="5" borderId="2" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>' // 10 empty
        . '<xf numFmtId="0" fontId="0" fillId="5" borderId="2" xfId="0" applyBorder="1"/>' // 11 even
        . '<xf numFmtId="0" fontId="0" fillId="4" borderId="2" xfId="0" applyFill="1" applyBorder="1"/>' // 12 odd
        . '<xf numFmtId="3" fontId="0" fillId="5" borderId="2" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right"/></xf>' // 13 num even
        . '<xf numFmtId="3" fontId="0" fillId="4" borderId="2" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right"/></xf>' // 14 num odd
        . '<xf numFmtId="0" fontId="6" fillId="0" borderId="0" xfId="0" applyFont="1"/>' // 15 footer
        . '</cellXfs>'
        . '</styleSheet>';

    // Bold meta values — use font index; keep simple

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . branded_export_xlsx_escape($sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Default Extension="jpg" ContentType="image/jpeg"/>'
        . '<Default Extension="png" ContentType="image/png"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . ($hasLogo ? '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>' : '')
        . '</Types>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $sheetRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . $sheetRelsExtra
        . '</Relationships>';

    // Logo floats over top-right of brand header (Excel-compatible EMUs).
    // 1 inch = 914400 EMUs; ~0.7" square logo
    $logoEmu = 640000;
    $drawing1 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing"'
        . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
        . '<xdr:absoluteAnchor>'
        . '<xdr:pos x="200000" y="120000"/>'
        . '<xdr:ext cx="' . $logoEmu . '" cy="' . $logoEmu . '"/>'
        . '<xdr:pic>'
        . '<xdr:nvPicPr><xdr:cNvPr id="2" name="OutpostLogo"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>'
        . '<xdr:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
        . '<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $logoEmu . '" cy="' . $logoEmu . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr>'
        . '</xdr:pic>'
        . '<xdr:clientData/>'
        . '</xdr:absoluteAnchor>'
        . '</xdr:wsDr>';

    $drawingRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/' . $logoMediaName . '"/>'
        . '</Relationships>';

    $tmp = tempnam(sys_get_temp_dir(), 'outpost-xlsx-');
    if ($tmp === false) {
        branded_export_excel_html_fallback($reportTitle, $headers, $rows, $opts);
        return;
    }
    @unlink($tmp);
    $tmpFile = $tmp . '.xlsx';

    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        branded_export_excel_html_fallback($reportTitle, $headers, $rows, $opts);
        return;
    }

    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', $sheetRels);

    if ($hasLogo) {
        $zip->addFromString('xl/media/' . $logoMediaName, $logoBytes);
        $zip->addFromString('xl/drawings/drawing1.xml', $drawing1);
        $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', $drawingRels);
    }

    $zip->close();
    $bytes = (string) file_get_contents($tmpFile);
    @unlink($tmpFile);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $filename) . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo $bytes;
    exit;
}

/**
 * HTML fallback without embedded images (Excel blocks data-URI logos).
 *
 * @param list<string> $headers
 * @param list<list<mixed>> $rows
 * @param array<string, mixed> $opts
 */
function branded_export_excel_html_fallback(string $reportTitle, array $headers, array $rows, array $opts = []): void
{
    $company = 'OUTPOST DMS';
    $tagline = 'Depot Management System';
    $subtitle = (string) ($opts['subtitle'] ?? '');
    /** @var array<string, string> $meta */
    $meta = $opts['meta'] ?? [];
    $generatedBy = (string) ($opts['generated_by'] ?? '');
    $filename = (string) ($opts['filename'] ?? ('Outpost-DMS-' . branded_export_slug($reportTitle) . '-' . date('Ymd-Hi') . '.xls'));
    $filename = preg_replace('/\.xlsx$/i', '.xls', $filename) ?? $filename;

    $metaRows = '';
    foreach ($meta as $label => $value) {
        $metaRows .= '<tr><td style="color:#64748B">' . branded_export_h((string) $label) . '</td>'
            . '<td style="font-weight:700">' . branded_export_h((string) $value) . '</td></tr>';
    }

    $headerCells = '';
    foreach ($headers as $h) {
        $headerCells .= '<td style="background:#E53E3E;color:#fff;font-weight:700;padding:8px;border:1px solid #C53030">' . branded_export_h((string) $h) . '</td>';
    }
    $body = '';
    if (!$rows) {
        $body = '<tr><td colspan="' . max(1, count($headers)) . '" style="color:#94A3B8;padding:12px;text-align:center">No rows for this export.</td></tr>';
    } else {
        foreach ($rows as $i => $row) {
            $bg = $i % 2 === 0 ? '#fff' : '#F8FAFC';
            $body .= '<tr style="background:' . $bg . '">';
            foreach ($row as $cell) {
                [$text] = branded_export_cell_value($cell);
                $body .= '<td style="padding:8px;border:1px solid #E2E8F0">' . branded_export_h($text) . '</td>';
            }
            $body .= '</tr>';
        }
    }
    $footer = 'Generated by Outpost DMS · ' . date('d M Y · H:i') . ($generatedBy !== '' ? ' · ' . $generatedBy : '');

    $html = '<html><head><meta charset="utf-8"><title>' . branded_export_h($company . ' — ' . $reportTitle) . '</title></head><body>'
        . '<table style="width:100%;border-collapse:collapse;font-family:Calibri,Arial,sans-serif">'
        . '<tr><td colspan="2" style="height:6px;background:#E53E3E"></td></tr>'
        . '<tr><td style="width:64px;background:#0F172A;padding:12px;color:#fff;font-weight:700;text-align:center;font-size:16pt">OD</td>'
        . '<td style="background:#0F172A;padding:12px"><div style="color:#fff;font-size:18pt;font-weight:700">' . branded_export_h($company) . '</div>'
        . '<div style="color:#94A3B8;font-size:10pt">' . branded_export_h($tagline) . '</div></td></tr>'
        . '<tr><td colspan="2" style="background:#F8FAFC;padding:12px"><div style="font-size:14pt;font-weight:700">' . branded_export_h($reportTitle) . '</div>'
        . '<div style="color:#64748B">' . branded_export_h($subtitle) . '</div><table>' . $metaRows . '</table></td></tr></table>'
        . '<table style="border-collapse:collapse;width:100%;font-family:Calibri,Arial,sans-serif"><tr>' . $headerCells . '</tr>' . $body . '</table>'
        . '<p style="color:#94A3B8;font-size:9pt">' . branded_export_h($footer) . '</p></body></html>';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $filename) . '"');
    echo $html;
    exit;
}

/**
 * @param list<string> $headers
 * @param list<list<mixed>> $rows
 */
function branded_export_csv(string $reportTitle, array $headers, array $rows, string $filename = ''): void
{
    if ($filename === '') {
        $filename = 'Outpost-DMS-' . branded_export_slug($reportTitle) . '-' . date('Ymd') . '.csv';
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $filename) . '"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['OUTPOST DMS — ' . $reportTitle]);
    fputcsv($out, ['Generated', date('Y-m-d H:i')]);
    fputcsv($out, []);
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

/**
 * @param list<string> $headers
 * @param list<list<mixed>> $rows
 * @param array<string, mixed> $opts
 */
function branded_export_send(string $reportTitle, array $headers, array $rows, array $opts = []): void
{
    $format = strtolower((string) ($opts['format'] ?? ($_GET['format'] ?? 'excel')));
    if ($format === 'csv') {
        branded_export_csv($reportTitle, $headers, $rows, (string) ($opts['filename_csv'] ?? ''));
    }
    // Force .xlsx filename for server exports
    if (!empty($opts['filename'])) {
        $opts['filename'] = preg_replace('/\.xls$/i', '.xlsx', (string) $opts['filename']);
    }
    branded_export_excel($reportTitle, $headers, $rows, $opts);
}
