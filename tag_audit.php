<?php

declare(strict_types=1);

/**
 * Usage:
 *   php tag_audit.php <tag_file.csv> <name_set_file.csv> [output_directory]
 */

if ($argc < 3) {
    fwrite(
        STDERR,
        "Usage: php tag_audit.php <tag_file.csv> <name_set_file.csv> [output_directory]\n"
    );
    exit(1);
}

$tagFile = $argv[1];
$nameSetFile = $argv[2];
$outputDirectory = $argv[3] ?? getcwd();

const CSV_LENGTH = 0;
const CSV_DELIMITER = ',';
const CSV_ENCLOSURE = '"';
const CSV_ESCAPE = '\\';
const XLSX_STYLE_HIGHLIGHT = 1;

if (!is_file($tagFile) || !is_readable($tagFile)) {
    fwrite(STDERR, "Error: tag file is not readable: {$tagFile}\n");
    exit(1);
}

if (!is_file($nameSetFile) || !is_readable($nameSetFile)) {
    fwrite(STDERR, "Error: name_set file is not readable: {$nameSetFile}\n");
    exit(1);
}

if (!is_dir($outputDirectory) || !is_writable($outputDirectory)) {
    fwrite(STDERR, "Error: output directory is not writable: {$outputDirectory}\n");
    exit(1);
}

/**
 * Read a CSV file into header + rows.
 *
 * @return array{0: array<int, string>, 1: array<int, array<int, string>>}
 */
function readCsv(string $path): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException("Unable to open CSV file: {$path}");
    }

    $header = fgetcsv($handle, CSV_LENGTH, CSV_DELIMITER, CSV_ENCLOSURE, CSV_ESCAPE);
    if ($header === false) {
        fclose($handle);
        throw new RuntimeException("CSV file is empty: {$path}");
    }

    $header = array_map(
        static fn($value): string => trim((string) $value),
        $header
    );

    $rows = [];
    while (($row = fgetcsv($handle, CSV_LENGTH, CSV_DELIMITER, CSV_ENCLOSURE, CSV_ESCAPE)) !== false) {
        if ($row === [null] || $row === false) {
            continue;
        }

        // Keep at least header width. Do not truncate extra columns because
        // tag files can have more tag values than header labels.
        if (count($row) < count($header)) {
            $row = array_pad($row, count($header), '');
        }

        $rows[] = array_map(
            static fn($value): string => trim((string) $value),
            $row
        );
    }

    fclose($handle);
    return [$header, $rows];
}

/**
 * @param array<int, string> $header
 */
function getColumnIndex(array $header, array $candidateNames): ?int
{
    $normalizedHeaderMap = [];
    foreach ($header as $index => $name) {
        $normalizedHeaderMap[normalizeHeaderName($name)] = $index;
    }

    foreach ($candidateNames as $candidate) {
        $normalized = normalizeHeaderName($candidate);
        if (array_key_exists($normalized, $normalizedHeaderMap)) {
            return $normalizedHeaderMap[$normalized];
        }
    }

    return null;
}

function normalizeHeaderName(string $value): string
{
    return preg_replace('/[^a-z0-9]+/', '', strtolower(trim($value))) ?? '';
}

function determineRouter(string $portName): ?string
{
    $normalized = strtoupper($portName);

    $patterns = [
        'ITXR' => 'CT',
        'DRE' => 'CT',
        'TPM' => 'CT',
        'CT' => 'CT',
        'TOC' => 'TOC',
        'GWY' => 'TOC',
    ];

    foreach ($patterns as $match => $router) {
        if (str_contains($normalized, $match)) {
            return $router;
        }
    }

    return null;
}

/**
 * @return array<int, string>
 */
function splitTagsFromCell(string $cell): array
{
    $trimmedCell = trim($cell);
    if ($trimmedCell === '') {
        return [];
    }

    $tokens = preg_split('/[\s,;|\/]+/', $trimmedCell) ?: [];
    $tags = [];
    foreach ($tokens as $token) {
        $tag = trim($token);
        if ($tag !== '') {
            $tags[] = $tag;
        }
    }

    return $tags;
}

/**
 * @param array<int, string> $row
 * @return array<int, string>
 */
function extractTags(array $row, int $startIndex): array
{
    $tags = [];
    for ($i = $startIndex; $i < count($row); $i++) {
        foreach (splitTagsFromCell((string) ($row[$i] ?? '')) as $tag) {
            $tags[] = $tag;
        }
    }

    return $tags;
}

function normalizeTag(string $tag): string
{
    return strtoupper(trim($tag));
}

/**
 * @return array<int, string>
 */
function getOffendingTagsForRouter(string $router): array
{
    if ($router === 'CT') {
        return ['TOC'];
    }

    if ($router === 'TOC') {
        return ['CT', 'QC-SRC', 'CNN-SRC'];
    }

    return [];
}

function xmlEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function excelColumnName(int $index): string
{
    $name = '';
    $current = $index;
    while ($current >= 0) {
        $name = chr(($current % 26) + 65) . $name;
        $current = intdiv($current, 26) - 1;
    }

    return $name;
}

function buildInlineStringCellXml(string $cellReference, string $value, bool $highlighted): string
{
    $styleAttribute = $highlighted ? ' s="' . XLSX_STYLE_HIGHLIGHT . '"' : '';
    $spaceAttribute = ($value !== trim($value)) ? ' xml:space="preserve"' : '';
    return '<c r="' . $cellReference . '"' . $styleAttribute . ' t="inlineStr"><is><t'
        . $spaceAttribute . '>' . xmlEscape($value) . '</t></is></c>';
}

/**
 * @param array<int, string> $header
 * @param array<int, array<int, string>> $rows
 * @param array<int, array<int, int>> $highlightColumnsByRow 0-based data-row => 0-based column indexes
 */
function writeXlsx(
    string $path,
    array $header,
    array $rows,
    array $highlightColumnsByRow
): void {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive extension is required to create .xlsx output.');
    }

    $maxColumns = max(1, count($header));
    foreach ($rows as $row) {
        $maxColumns = max($maxColumns, count($row));
    }

    $allRows = array_merge([$header], $rows);
    $lastRowNumber = max(1, count($allRows));
    $dimension = 'A1:' . excelColumnName($maxColumns - 1) . $lastRowNumber;

    $sheetRowsXml = '';
    foreach ($allRows as $rowIndex => $rowValues) {
        $excelRow = $rowIndex + 1;
        $highlightedColumns = [];
        if ($rowIndex > 0) {
            $highlightedColumns = array_flip($highlightColumnsByRow[$rowIndex - 1] ?? []);
        }

        $cellsXml = '';
        for ($columnIndex = 0; $columnIndex < $maxColumns; $columnIndex++) {
            $value = (string) ($rowValues[$columnIndex] ?? '');
            $isHighlighted = isset($highlightedColumns[$columnIndex]);

            if ($value === '' && !$isHighlighted) {
                continue;
            }

            $cellReference = excelColumnName($columnIndex) . $excelRow;
            $cellsXml .= buildInlineStringCellXml($cellReference, $value, $isHighlighted);
        }

        $sheetRowsXml .= '<row r="' . $excelRow . '">' . $cellsXml . '</row>';
    }

    $worksheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<dimension ref="' . $dimension . '"/>'
        . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>'
        . '<sheetData>' . $sheetRowsXml . '</sheetData>'
        . '</worksheet>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/><family val="2"/></font></fonts>'
        . '<fills count="3">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFFF00"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="0" fillId="2" borderId="0" xfId="0" applyFill="1"/>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Tag Audit" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" '
        . 'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" '
        . 'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" '
        . 'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    $rootRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" '
        . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" '
        . 'Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbookRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" '
        . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
        . 'Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" '
        . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" '
        . 'Target="styles.xml"/>'
        . '</Relationships>';

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("Unable to create output file: {$path}");
    }

    $zip->addFromString('[Content_Types].xml', $contentTypesXml);
    $zip->addFromString('_rels/.rels', $rootRelsXml);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $worksheetXml);
    $zip->close();
}

try {
    [$tagHeader, $tagRows] = readCsv($tagFile);
    [$nameSetHeader, $nameSetRows] = readCsv($nameSetFile);

    $tagNameSetIndex = getColumnIndex($tagHeader, ['NAME (Local)']);
    if ($tagNameSetIndex === null) {
        throw new RuntimeException('Could not find nameset column in tag file (expected "NAME (Local)").');
    }

    // Per requirement, tags are from the 3rd column onward (index 2).
    $tagStartIndex = 2;
    if (count($tagHeader) <= $tagStartIndex) {
        throw new RuntimeException('Tag file must have at least 3 columns.');
    }

    $nameSetNameIndex = getColumnIndex($nameSetHeader, ['NAME (Local)', 'Name (Local)', 'NAME LOCAL']);
    $localNameIndex = getColumnIndex($nameSetHeader, ['Local']);
    $globalNameIndex = getColumnIndex($nameSetHeader, ['Global']);
    $portNameIndex = getColumnIndex($nameSetHeader, ['Port Name']);
    if ($portNameIndex === null) {
        throw new RuntimeException('Could not find "Port Name" column in name_set file.');
    }

    // Build nameset_name -> port_name lookup.
    // Match keys can come from NAME (Local), Local, Global, then Port Name fallback.
    $portByNameSet = [];
    foreach ($nameSetRows as $row) {
        $portName = trim((string) ($row[$portNameIndex] ?? ''));
        if ($portName === '') {
            continue;
        }

        $candidateKeys = [];
        foreach ([$nameSetNameIndex, $localNameIndex, $globalNameIndex] as $index) {
            if ($index === null) {
                continue;
            }
            $value = trim((string) ($row[$index] ?? ''));
            if ($value !== '') {
                $candidateKeys[] = $value;
            }
        }
        $candidateKeys[] = $portName;
        $candidateKeys = array_values(array_unique($candidateKeys));

        foreach ($candidateKeys as $key) {
            $portByNameSet[strtoupper($key)] = $portName;
        }
    }

    $matchedRows = [];
    $tagColumnIndexByNormalized = [];
    $tagDisplayByNormalized = [];

    foreach ($tagRows as $row) {
        $nameSetName = trim((string) ($row[$tagNameSetIndex] ?? ''));
        if ($nameSetName === '') {
            continue;
        }

        $lookupKey = strtoupper($nameSetName);
        if (!array_key_exists($lookupKey, $portByNameSet)) {
            continue;
        }

        $router = determineRouter($portByNameSet[$lookupKey]);
        if ($router === null) {
            continue;
        }

        $tags = extractTags($row, $tagStartIndex);
        if ($tags === []) {
            continue;
        }

        $presentTagsByNormalized = [];
        foreach ($tags as $tag) {
            $normalizedTag = normalizeTag($tag);
            if ($normalizedTag === '') {
                continue;
            }

            $presentTagsByNormalized[$normalizedTag] = true;

            if (!array_key_exists($normalizedTag, $tagColumnIndexByNormalized)) {
                $tagColumnIndexByNormalized[$normalizedTag] = count($tagColumnIndexByNormalized);
                $tagDisplayByNormalized[$normalizedTag] = $normalizedTag;
            }
        }

        if ($presentTagsByNormalized === []) {
            continue;
        }

        $offendingTagSet = getOffendingTagsForRouter($router);
        $offendingTagsByNormalized = [];
        foreach ($offendingTagSet as $offendingTag) {
            if (isset($presentTagsByNormalized[$offendingTag])) {
                $offendingTagsByNormalized[$offendingTag] = true;
            }
        }

        if ($offendingTagsByNormalized === []) {
            continue;
        }

        $matchedRows[] = [
            'baseColumns' => array_slice($row, 0, $tagStartIndex),
            'presentTags' => array_keys($presentTagsByNormalized),
            'offendingTags' => array_keys($offendingTagsByNormalized),
        ];
    }

    $tagColumnCount = max(1, count($tagColumnIndexByNormalized));
    $outputHeader = array_slice($tagHeader, 0, $tagStartIndex);
    if ($tagColumnIndexByNormalized === []) {
        $outputHeader[] = 'Tag';
    } else {
        foreach ($tagColumnIndexByNormalized as $normalizedTag => $_index) {
            $outputHeader[] = $tagDisplayByNormalized[$normalizedTag];
        }
    }

    $outputRows = [];
    $highlightColumnsByRow = [];
    foreach ($matchedRows as $matchedRow) {
        $outRow = array_merge($matchedRow['baseColumns'], array_fill(0, $tagColumnCount, ''));
        $requiredSize = $tagStartIndex + $tagColumnCount;
        if (count($outRow) < $requiredSize) {
            $outRow = array_pad($outRow, $requiredSize, '');
        }

        foreach ($matchedRow['presentTags'] as $normalizedTag) {
            if (!array_key_exists($normalizedTag, $tagColumnIndexByNormalized)) {
                continue;
            }

            $columnOffset = $tagColumnIndexByNormalized[$normalizedTag];
            $outRow[$tagStartIndex + $columnOffset] = $tagDisplayByNormalized[$normalizedTag];
        }

        $outputRows[] = $outRow;

        $highlightColumns = [];
        foreach ($matchedRow['offendingTags'] as $offendingTag) {
            if (!array_key_exists($offendingTag, $tagColumnIndexByNormalized)) {
                continue;
            }

            $highlightColumns[] = $tagStartIndex + $tagColumnIndexByNormalized[$offendingTag];
        }
        $highlightColumnsByRow[] = $highlightColumns;
    }

    $outputFile = rtrim($outputDirectory, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . date('Y-m-d-H-i')
        . '-tag-audit.xlsx';

    writeXlsx($outputFile, $outputHeader, $outputRows, $highlightColumnsByRow);

    fwrite(STDOUT, "Output written: {$outputFile}\n");
    fwrite(STDOUT, 'Rows written: ' . count($outputRows) . "\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Error: ' . $exception->getMessage() . "\n");
    exit(1);
}

