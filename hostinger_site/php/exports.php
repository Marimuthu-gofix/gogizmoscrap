<?php
/**
 * CSV and Excel exports for the PHP dashboard. The .xlsx is written by hand
 * with ZipArchive (an .xlsx is a zip of XML files), so no library is needed.
 * Same columns and sheets as the exports in dashboard.py.
 */
declare(strict_types=1);

const BB_EXPORT_HEADER = [
    'Brand', 'Model', 'Variant', 'Cashify Price', 'DOFY Price', 'Best Offer', 'Difference', 'Difference %',
    'Cashify Checked', 'DOFY Checked', 'Cashify URL', 'DOFY URL',
];

/** One comparison row as export cells; $date turns an ISO time into a cell. */
function bb_export_line(array $row, callable $date): array
{
    $cashify = $row['cashify'];
    $dofy = $row['dofy'];
    return [
        $row['brand'], $row['model'], $row['variant'], $row['cashify_value'], $row['dofy_value'], $row['best'] ?? '',
        $row['gap'], $row['gap_pct'],
        $cashify ? $date($cashify['date']) : '', $dofy ? $date($dofy['date']) : '',
        $cashify ? $cashify['url'] : '', $dofy ? $dofy['url'] : '',
    ];
}

function bb_rows_to_csv(array $rows): string
{
    $out = fopen('php://temp', 'r+');
    fputcsv($out, BB_EXPORT_HEADER, ',', '"', '\\', "\r\n");
    foreach ($rows as $row) {
        fputcsv($out, bb_export_line($row, fn ($value) => (string) $value), ',', '"', '\\', "\r\n");
    }
    rewind($out);
    return (string) stream_get_contents($out);
}

/** ISO timestamp -> Excel serial number carrying the India wall-clock time. */
function bb_excel_date($value): ?float
{
    $date = bb_parse_iso($value);
    if (!$date) {
        return null;
    }
    $local = $date->setTimezone(new DateTimeZone(BB_DISPLAY_TIMEZONE));
    $asUtc = gmmktime((int) $local->format('G'), (int) $local->format('i'), (int) $local->format('s'), (int) $local->format('n'), (int) $local->format('j'), (int) $local->format('Y'));
    return $asUtc / 86400 + 25569;
}

function bb_xml(string $text): string
{
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? $text;
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function bb_column_letter(int $index): string
{
    $letters = '';
    for ($n = $index; $n > 0; $n = intdiv($n - 1, 26)) {
        $letters = chr(65 + ($n - 1) % 26) . $letters;
    }
    return $letters;
}

/**
 * One worksheet's XML. $cells are strings, numbers (a float in a date column is an Excel date) or null.
 * Styles: 0 plain, 1 money, 2 date, 3 header.
 */
function bb_sheet_xml(array $header, array $rows, array $widths, array $moneyColumns, array $dateColumns): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<cols>';
    foreach ($widths as $i => $width) {
        $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $width . '" customWidth="1"/>';
    }
    $xml .= '</cols><sheetData><row r="1">';
    foreach ($header as $i => $title) {
        $xml .= '<c r="' . bb_column_letter($i + 1) . '1" s="3" t="inlineStr"><is><t>' . bb_xml((string) $title) . '</t></is></c>';
    }
    $xml .= '</row>';
    $money = array_flip($moneyColumns);
    $dates = array_flip($dateColumns);
    foreach ($rows as $r => $cells) {
        $line = $r + 2;
        $xml .= '<row r="' . $line . '">';
        foreach ($cells as $i => $value) {
            $column = $i + 1;
            $ref = bb_column_letter($column) . $line;
            if ($value === null || $value === '') {
                continue;
            }
            if (isset($dates[$column]) && is_float($value)) {
                $xml .= '<c r="' . $ref . '" s="2"><v>' . $value . '</v></c>';
            } elseif (is_int($value) || is_float($value)) {
                $xml .= '<c r="' . $ref . '" s="' . (isset($money[$column]) ? 1 : 0) . '"><v>' . $value . '</v></c>';
            } else {
                $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . bb_xml((string) $value) . '</t></is></c>';
            }
        }
        $xml .= '</row>';
    }
    $xml .= '</sheetData>';
    if ($rows) {
        $xml .= '<autoFilter ref="A1:' . bb_column_letter(count($header)) . (count($rows) + 1) . '"/>';
    }
    return $xml . '</worksheet>';
}

/** Build the workbook (two sheets) and return the .xlsx bytes, or null when ZipArchive is unavailable. */
function bb_rows_to_excel(array $rows): ?string
{
    if (!class_exists('ZipArchive')) {
        return null;
    }
    $date = fn ($value) => bb_excel_date($value) ?? (string) $value;
    $compare = [];
    // One line per platform price, for filtering in Excel.
    $prices = [];
    foreach ($rows as $row) {
        $compare[] = bb_export_line($row, $date);
        foreach ([$row['cashify'], $row['dofy']] as $source) {
            if ($source) {
                $prices[] = [
                    $source['platform'], $row['brand'], $source['model'], $source['variant'], $source['price_value'],
                    $source['currency'] ?? 'INR', $date($source['date']), $source['url'],
                ];
            }
        }
    }

    $sheet1 = bb_sheet_xml(BB_EXPORT_HEADER, $compare, [12, 34, 16, 14, 14, 12, 12, 13, 20, 20, 60, 60], [4, 5, 7], [9, 10]);
    $sheet2 = bb_sheet_xml(
        ['Platform', 'Brand', 'Model', 'Variant', 'Price', 'Currency', 'Last Checked', 'Source URL'],
        $prices, [10, 12, 34, 16, 12, 10, 20, 60], [5], [7],
    );
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0"/><numFmt numFmtId="165" formatCode="dd-mmm-yyyy hh:mm"/></numFmts>'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF1F3A8A"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="4">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment vertical="center"/></xf>'
        . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Cashify vs DOFY" sheetId="1" r:id="rId1"/><sheet name="Prices" sheetId="2" r:id="rId2"/></sheets></workbook>';
    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';
    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    $file = tempnam(sys_get_temp_dir(), 'bbx');
    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::OVERWRITE) !== true) {
        return null;
    }
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/styles.xml', $styles);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet1);
    $zip->addFromString('xl/worksheets/sheet2.xml', $sheet2);
    $zip->close();
    $bytes = (string) file_get_contents($file);
    @unlink($file);
    return $bytes;
}
