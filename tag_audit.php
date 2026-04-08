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

    $header = fgetcsv($handle);
    if ($header === false) {
        fclose($handle);
        throw new RuntimeException("CSV file is empty: {$path}");
    }

    $header = array_map(
        static fn($value): string => trim((string) $value),
        $header
    );

    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        if ($row === [null] || $row === false) {
            continue;
        }

        // Keep row width aligned with header width for output consistency.
        if (count($row) < count($header)) {
            $row = array_pad($row, count($header), '');
        } elseif (count($row) > count($header)) {
            $row = array_slice($row, 0, count($header));
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

/**
 * @param array<int, string> $row
 * @param int $startIndex
 */
function rowContainsTag(array $row, int $startIndex, string $targetTag): bool
{
    $target = strtoupper(trim($targetTag));

    for ($i = $startIndex; $i < count($row); $i++) {
        $cell = strtoupper(trim((string) $row[$i]));
        if ($cell === '') {
            continue;
        }

        if ($cell === $target) {
            return true;
        }

        // Handle cases like "CT|TOC", "CT,TOC", "CT TOC", etc.
        $tokens = preg_split('/[\s,;|\/]+/', $cell) ?: [];
        foreach ($tokens as $token) {
            if ($token === $target) {
                return true;
            }
        }
    }

    return false;
}

function determineRouter(string $portName): ?string
{
    $normalized = strtoupper($portName);

    // Order matters only if a port name contains multiple patterns.
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

[$tagHeader, $tagRows] = readCsv($tagFile);
[$nameSetHeader, $nameSetRows] = readCsv($nameSetFile);

$tagNameSetIndex = getColumnIndex($tagHeader, ['NAME (Local)', 'nameset_name', 'Name Set Name']);
if ($tagNameSetIndex === null) {
    fwrite(STDERR, "Error: could not find nameset column in tag file (expected \"NAME (Local)\").\n");
    exit(1);
}

// Per requirement, tags are from the 3rd column onward (index 2).
$tagStartIndex = 2;
if (count($tagHeader) <= $tagStartIndex) {
    fwrite(STDERR, "Error: tag file must have at least 3 columns.\n");
    exit(1);
}

$nameSetNameIndex = getColumnIndex(
    $nameSetHeader,
    ['NAME (Local)', 'nameset_name', 'Name Set Name', 'NameSet Name', 'Name Set']
);
$portNameIndex = getColumnIndex($nameSetHeader, ['Port Name']);

if ($nameSetNameIndex === null) {
    fwrite(
        STDERR,
        "Error: could not find nameset column in name_set file (e.g. \"nameset_name\").\n"
    );
    exit(1);
}

if ($portNameIndex === null) {
    fwrite(STDERR, "Error: could not find \"Port Name\" column in name_set file.\n");
    exit(1);
}

// Build nameset_name -> port_name lookup from name_set file.
$portByNameSet = [];
foreach ($nameSetRows as $row) {
    $nameSetName = trim((string) ($row[$nameSetNameIndex] ?? ''));
    $portName = trim((string) ($row[$portNameIndex] ?? ''));
    if ($nameSetName === '' || $portName === '') {
        continue;
    }

    $portByNameSet[$nameSetName] = $portName;
}

$matchedRows = [];
foreach ($tagRows as $row) {
    $nameSetName = trim((string) ($row[$tagNameSetIndex] ?? ''));
    if ($nameSetName === '') {
        continue;
    }

    if (!array_key_exists($nameSetName, $portByNameSet)) {
        continue;
    }

    $router = determineRouter($portByNameSet[$nameSetName]);
    if ($router === null) {
        continue;
    }

    $hasCtTag = rowContainsTag($row, $tagStartIndex, 'CT');
    $hasTocTag = rowContainsTag($row, $tagStartIndex, 'TOC');

    if ($router === 'CT' && $hasTocTag) {
        $matchedRows[] = $row;
        continue;
    }

    if ($router === 'TOC' && $hasCtTag) {
        $matchedRows[] = $row;
    }
}

$outputFile = rtrim($outputDirectory, DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR
    . date('Y-m-d-H:i')
    . '-tag-audit.csv';

$outputHandle = fopen($outputFile, 'wb');
if ($outputHandle === false) {
    fwrite(STDERR, "Error: unable to create output file: {$outputFile}\n");
    exit(1);
}

fputcsv($outputHandle, $tagHeader);
foreach ($matchedRows as $row) {
    fputcsv($outputHandle, $row);
}
fclose($outputHandle);

fwrite(STDOUT, "Output written: {$outputFile}\n");
fwrite(STDOUT, 'Rows written: ' . count($matchedRows) . "\n");

