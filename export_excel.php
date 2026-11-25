<?php

declare(strict_types=1);

require __DIR__ . '/config/database.php';

function xml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function imagePaths(?string $value): array
{
    if (!$value) {
        return [];
    }

    $decoded = json_decode($value, true);

    if (is_array($decoded)) {
        return array_values(array_filter($decoded, 'is_string'));
    }

    return [$value];
}

function columnName(int $index): string
{
    $name = '';

    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }

    return $name;
}

function inlineCell(int $column, int $row, string $value, int $style = 1): string
{
    $cell = columnName($column) . $row;

    return '<c r="' . $cell . '" t="inlineStr" s="' . $style . '"><is><t>' . xml($value) . '</t></is></c>';
}

function numericCell(int $column, int $row, int|float $value, int $style): string
{
    $cell = columnName($column) . $row;

    return '<c r="' . $cell . '" s="' . $style . '"><v>' . xml((string)$value) . '</v></c>';
}

function nullableNumericCell(int $column, int $row, mixed $value, int $style): string
{
    if ($value === null || $value === '') {
        return inlineCell($column, $row, '', 1);
    }

    return numericCell($column, $row, (float)$value, $style);
}

function inCellImageCell(int $column, int $row, int $metadataIndex, int $style = 1): string
{
    $cell = columnName($column) . $row;
    $pointer = $metadataIndex + 1;

    return '<c r="' . $cell . '" t="e" s="' . $style . '" vm="' . $pointer . '"><v>#VALUE!</v></c>';
}

/**
 * Build one image for a cell. When a field contains multiple photos, they are
 * combined into a single collage because Excel stores one native image value
 * per cell.
 *
 * @return array{path: string, extension: string, temporary: bool}|null
 */
function imageForCell(array $paths): ?array
{
    $validImages = [];

    foreach ($paths as $path) {
        $absolutePath = __DIR__ . '/' . ltrim($path, '/\\');

        if (!is_file($absolutePath)) {
            continue;
        }

        $imageInfo = @getimagesize($absolutePath);
        $mime = is_array($imageInfo) ? ($imageInfo['mime'] ?? '') : '';
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => null,
        };

        if ($extension !== null) {
            $validImages[] = [
                'path' => $absolutePath,
                'extension' => $extension,
                'mime' => $mime,
            ];
        }
    }

    if ($validImages === []) {
        return null;
    }

    if (count($validImages) === 1 || !extension_loaded('gd')) {
        return [
            'path' => $validImages[0]['path'],
            'extension' => $validImages[0]['extension'],
            'temporary' => false,
        ];
    }

    $canvasWidth = 900;
    $canvasHeight = 600;
    $gap = 8;
    $imageCount = count($validImages);
    $columns = min(3, (int)ceil(sqrt($imageCount * 1.5)));
    $rows = (int)ceil($imageCount / $columns);
    $tileWidth = (int)floor(($canvasWidth - (($columns + 1) * $gap)) / $columns);
    $tileHeight = (int)floor(($canvasHeight - (($rows + 1) * $gap)) / $rows);
    $canvas = imagecreatetruecolor($canvasWidth, $canvasHeight);

    if ($canvas === false) {
        return [
            'path' => $validImages[0]['path'],
            'extension' => $validImages[0]['extension'],
            'temporary' => false,
        ];
    }

    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefill($canvas, 0, 0, $white);

    foreach ($validImages as $index => $image) {
        $source = $image['mime'] === 'image/png'
            ? @imagecreatefrompng($image['path'])
            : @imagecreatefromjpeg($image['path']);

        if ($source === false) {
            continue;
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $scale = max($tileWidth / $sourceWidth, $tileHeight / $sourceHeight);
        $cropWidth = max(1, (int)round($tileWidth / $scale));
        $cropHeight = max(1, (int)round($tileHeight / $scale));
        $sourceX = max(0, (int)floor(($sourceWidth - $cropWidth) / 2));
        $sourceY = max(0, (int)floor(($sourceHeight - $cropHeight) / 2));
        $column = $index % $columns;
        $row = intdiv($index, $columns);
        $destinationX = $gap + ($column * ($tileWidth + $gap));
        $destinationY = $gap + ($row * ($tileHeight + $gap));

        imagecopyresampled(
            $canvas,
            $source,
            $destinationX,
            $destinationY,
            $sourceX,
            $sourceY,
            $tileWidth,
            $tileHeight,
            $cropWidth,
            $cropHeight
        );
        imagedestroy($source);
    }

    $temporaryPath = tempnam(sys_get_temp_dir(), 'airleak_cell_image_');

    if ($temporaryPath === false || !imagejpeg($canvas, $temporaryPath, 88)) {
        imagedestroy($canvas);
        return [
            'path' => $validImages[0]['path'],
            'extension' => $validImages[0]['extension'],
            'temporary' => false,
        ];
    }

    imagedestroy($canvas);

    return [
        'path' => $temporaryPath,
        'extension' => 'jpg',
        'temporary' => true,
    ];
}

function dateCell(int $column, int $row, ?string $value, int $style = 6): string
{
    if (!$value) {
        return inlineCell($column, $row, '', 1);
    }

    try {
        $date = new DateTimeImmutable($value, new DateTimeZone('Asia/Jakarta'));
        $excelEpoch = new DateTimeImmutable('1899-12-30 00:00:00', new DateTimeZone('Asia/Jakarta'));
        $seconds = $date->getTimestamp() - $excelEpoch->getTimestamp();
        $serial = $seconds / 86400;

        return numericCell($column, $row, $serial, $style);
    } catch (Throwable) {
        return inlineCell($column, $row, $value, 1);
    }
}

function formulaCell(int $column, int $row, string $formula, int|float $cachedValue, int $style): string
{
    $cell = columnName($column) . $row;

    return '<c r="' . $cell . '" s="' . $style . '"><f>' . xml($formula) . '</f><v>' . xml((string)$cachedValue) . '</v></c>';
}

function setMergedDashboardCell(
    array &$grid,
    array &$merges,
    int $startColumn,
    int $endColumn,
    int $startRow,
    int $endRow,
    int $style,
    string $topLeftCell
): void {
    for ($row = $startRow; $row <= $endRow; $row++) {
        for ($column = $startColumn; $column <= $endColumn; $column++) {
            $grid[$row][$column] = inlineCell($column, $row, '', $style);
        }
    }

    $grid[$startRow][$startColumn] = $topLeftCell;
    $merges[] = columnName($startColumn) . $startRow . ':' . columnName($endColumn) . $endRow;
}

function zipAdd(ZipArchive $zip, string $path, string $content): void
{
    if (!$zip->addFromString($path, $content)) {
        throw new RuntimeException('Cannot add XLSX part: ' . $path);
    }
}

$audits = db()->query(
    'SELECT audit.id, audit.finding_no, audit.status, audit.date_audit, audit.member, audit.plant, audit.line,
            audit.machine_no, audit.pic_dept, audit.loss_flow, audit.loss_cost, audit.leak_location, audit.co2, audit.machine_picture,
            audit.leak_location_picture, audit.air_leak_image, audit.created_at,
            action.action_taken, action.action_pic, action.action_picture, action.created_at AS action_created_at
     FROM air_leak_audits audit
     LEFT JOIN air_leak_actions action ON action.id = (
         SELECT MAX(latest_action.id)
         FROM air_leak_actions latest_action
         WHERE latest_action.air_leak_audit_id = audit.id
     )
     ORDER BY audit.id DESC'
)->fetchAll();

$totalFindings = count($audits);
$totalOpen = 0;
$totalClosed = 0;
$totalLossFlow = 0.0;
$totalLossCost = 0.0;
$totalCo2 = 0.0;
$totalActions = 0;
$plantCounts = ['MKM-1' => 0, 'MKM-2' => 0];
$lineCounts = [];

foreach ($audits as $audit) {
    $status = strtoupper((string)($audit['status'] ?? 'OPEN'));
    if ($status === 'CLOSED') {
        $totalClosed++;
    } else {
        $totalOpen++;
    }

    $totalLossFlow += (float)($audit['loss_flow'] ?? 0);
    $totalLossCost += (float)($audit['loss_cost'] ?? 0);
    if ($audit['co2'] !== null && $audit['co2'] !== '') {
        $totalCo2 += (float)$audit['co2'];
    }
    if (trim((string)($audit['action_taken'] ?? '')) !== '') {
        $totalActions++;
    }

    $plant = (string)($audit['plant'] ?? '');
    if ($plant !== '') {
        $plantCounts[$plant] = ($plantCounts[$plant] ?? 0) + 1;
    }

    $line = trim((string)($audit['line'] ?? ''));
    if ($line !== '') {
        $lineCounts[$line] = ($lineCounts[$line] ?? 0) + 1;
    }
}

arsort($lineCounts);
$topLines = array_slice($lineCounts, 0, 5, true);
$completionRate = $totalFindings > 0 ? $totalClosed / $totalFindings : 0.0;

$headers = [
    'No',
    'No. Temuan',
    'Date Audit',
    'Member',
    'Plant',
    'Line',
    'Machine No',
    'PIC Dept',
    'Loss Flow (m3/min)',
    'Loss Cost (IDR/Month)',
    'Machine Pict',
    'Leak Location Pict',
    'Air Leak Image',
    'CO2',
    'Leak Location',
    'Due Date',
    'Followup Date',
    'Counter Measure',
    'Air Leak Image After',
    'Status',
];

$imageFields = [
    11 => 'machine_picture',
    12 => 'leak_location_picture',
    13 => 'air_leak_image',
    19 => 'action_picture',
];

$sheetRows = [];
$richValueRels = [];
$richValues = [];
$temporaryImages = [];
$mediaFiles = [];
$mediaIndex = 1;

$sheetRows[] = '<row r="1" ht="24" customHeight="1">' .
    implode('', array_map(
        fn ($header, $i) => inlineCell($i + 1, 1, $header, ($i + 1) >= 17 ? 3 : 2),
        $headers,
        array_keys($headers)
    )) .
    '</row>';

foreach ($audits as $rowIndex => $audit) {
    $excelRow = $rowIndex + 2;
    $fieldImages = [];
    $imageMetadataByColumn = [];

    foreach ($imageFields as $column => $field) {
        $paths = imagePaths($audit[$field] ?? null);
        $fieldImages[$column] = $paths;
    }

    foreach ($fieldImages as $column => $paths) {
        $image = imageForCell($paths);

        if ($image === null) {
            continue;
        }

        $metadataIndex = count($richValues);
        $relationshipId = 'rId' . $mediaIndex;
        $mediaName = 'image' . $mediaIndex . '.' . $image['extension'];

        $imageMetadataByColumn[$column] = $metadataIndex;
        $richValueRels[] = '<rel r:id="' . $relationshipId . '"/>';
        $richValues[] = '<rv s="0"><v>' . $metadataIndex . '</v><v>5</v></rv>';
        $mediaFiles[] = [$mediaName, $image['path']];

        if ($image['temporary']) {
            $temporaryImages[] = $image['path'];
        }

        $mediaIndex++;
    }

    $rowHeight = $imageMetadataByColumn === [] ? 38 : 92;
    $status = strtoupper((string)($audit['status'] ?? 'OPEN')) === 'CLOSED' ? 'CLOSED' : 'OPEN';
    $cells = [
        numericCell(1, $excelRow, $rowIndex + 1, 4),
        inlineCell(2, $excelRow, (string)($audit['finding_no'] ?? '')),
        dateCell(3, $excelRow, (string)$audit['date_audit']),
        inlineCell(4, $excelRow, (string)$audit['member']),
        inlineCell(5, $excelRow, (string)$audit['plant']),
        inlineCell(6, $excelRow, (string)$audit['line']),
        inlineCell(7, $excelRow, (string)$audit['machine_no']),
        inlineCell(8, $excelRow, (string)$audit['pic_dept']),
        numericCell(9, $excelRow, (float)$audit['loss_flow'], 5),
        numericCell(10, $excelRow, (float)$audit['loss_cost'], 4),
        isset($imageMetadataByColumn[11]) ? inCellImageCell(11, $excelRow, $imageMetadataByColumn[11]) : inlineCell(11, $excelRow, '', 1),
        isset($imageMetadataByColumn[12]) ? inCellImageCell(12, $excelRow, $imageMetadataByColumn[12]) : inlineCell(12, $excelRow, '', 1),
        isset($imageMetadataByColumn[13]) ? inCellImageCell(13, $excelRow, $imageMetadataByColumn[13]) : inlineCell(13, $excelRow, '', 1),
        nullableNumericCell(14, $excelRow, $audit['co2'] ?? null, 5),
        inlineCell(15, $excelRow, (string)($audit['leak_location'] ?? '')),
        inlineCell(16, $excelRow, '', 1),
        dateCell(17, $excelRow, $audit['action_created_at'] ?? null),
        inlineCell(18, $excelRow, (string)($audit['action_taken'] ?? '')),
        isset($imageMetadataByColumn[19]) ? inCellImageCell(19, $excelRow, $imageMetadataByColumn[19]) : inlineCell(19, $excelRow, '', 1),
        inlineCell(20, $excelRow, $status, $status === 'CLOSED' ? 8 : 7),
    ];

    $sheetRows[] = '<row r="' . $excelRow . '" ht="' . $rowHeight . '" customHeight="1">' . implode('', $cells) . '</row>';
}

$temporaryFile = tempnam(sys_get_temp_dir(), 'airleak_xlsx_');

if ($temporaryFile === false) {
    throw new RuntimeException('Cannot create temporary export file.');
}

$zip = new ZipArchive();

if ($zip->open($temporaryFile, ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Cannot create XLSX archive.');
}

$columns = '';
$widths = [7, 14, 13, 18, 11, 12, 16, 14, 16, 21, 21, 22, 21, 13, 28, 14, 18, 36, 23, 13];
foreach ($widths as $index => $width) {
    $column = $index + 1;
    $columns .= '<col min="' . $column . '" max="' . $column . '" width="' . $width . '" customWidth="1"/>';
}

$worksheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <sheetViews><sheetView workbookViewId="0" showGridLines="0"><pane xSplit="2" ySplit="1" topLeftCell="C2" activePane="bottomRight" state="frozen"/></sheetView></sheetViews>
    <cols>' . $columns . '</cols>
    <sheetData>' . implode('', $sheetRows) . '</sheetData>
    <autoFilter ref="A1:T1"/>
</worksheet>';

$dashboardGrid = [];
$dashboardMerges = [];
$dataEndRow = max(2, $totalFindings + 1);
$auditSheet = "'Air Leak Audit'";
$exportedAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i');

setMergedDashboardCell($dashboardGrid, $dashboardMerges, 2, 16, 2, 2, 9, inlineCell(2, 2, 'Air Leak Audit Summary Dashboard', 9));
setMergedDashboardCell($dashboardGrid, $dashboardMerges, 2, 16, 3, 3, 10, inlineCell(2, 3, 'Ringkasan audit dan tindak lanjut. Exported ' . $exportedAt, 10));

setMergedDashboardCell($dashboardGrid, $dashboardMerges, 2, 4, 5, 5, 11, inlineCell(2, 5, 'Total Temuan', 11));
setMergedDashboardCell(
    $dashboardGrid,
    $dashboardMerges,
    2,
    4,
    6,
    7,
    12,
    formulaCell(2, 6, "COUNTA({$auditSheet}!B2:B{$dataEndRow})", $totalFindings, 12)
);
setMergedDashboardCell($dashboardGrid, $dashboardMerges, 6, 8, 5, 5, 14, inlineCell(6, 5, 'Open', 14));
setMergedDashboardCell(
    $dashboardGrid,
    $dashboardMerges,
    6,
    8,
    6,
    7,
    15,
    formulaCell(6, 6, "COUNTIF({$auditSheet}!T2:T{$dataEndRow},\"OPEN\")", $totalOpen, 15)
);
setMergedDashboardCell($dashboardGrid, $dashboardMerges, 10, 12, 5, 5, 16, inlineCell(10, 5, 'Closed', 16));
setMergedDashboardCell(
    $dashboardGrid,
    $dashboardMerges,
    10,
    12,
    6,
    7,
    17,
    formulaCell(10, 6, "COUNTIF({$auditSheet}!T2:T{$dataEndRow},\"CLOSED\")", $totalClosed, 17)
);
setMergedDashboardCell($dashboardGrid, $dashboardMerges, 14, 16, 5, 5, 18, inlineCell(14, 5, 'Completion Rate', 18));
setMergedDashboardCell(
    $dashboardGrid,
    $dashboardMerges,
    14,
    16,
    6,
    7,
    19,
    formulaCell(14, 6, 'IF(B6=0,0,J6/B6)', $completionRate, 19)
);

setMergedDashboardCell($dashboardGrid, $dashboardMerges, 2, 4, 9, 9, 11, inlineCell(2, 9, 'Total Loss Flow (m3/min)', 11));
setMergedDashboardCell(
    $dashboardGrid,
    $dashboardMerges,
    2,
    4,
    10,
    11,
    13,
    formulaCell(2, 10, "SUM({$auditSheet}!I2:I{$dataEndRow})", $totalLossFlow, 13)
);
setMergedDashboardCell($dashboardGrid, $dashboardMerges, 6, 8, 9, 9, 11, inlineCell(6, 9, 'Total Loss Cost (IDR/Month)', 11));
setMergedDashboardCell(
    $dashboardGrid,
    $dashboardMerges,
    6,
    8,
    10,
    11,
    12,
    formulaCell(6, 10, "SUM({$auditSheet}!J2:J{$dataEndRow})", $totalLossCost, 12)
);
setMergedDashboardCell($dashboardGrid, $dashboardMerges, 10, 12, 9, 9, 11, inlineCell(10, 9, 'Total CO2', 11));
setMergedDashboardCell(
    $dashboardGrid,
    $dashboardMerges,
    10,
    12,
    10,
    11,
    13,
    formulaCell(10, 10, "SUM({$auditSheet}!N2:N{$dataEndRow})", $totalCo2, 13)
);
setMergedDashboardCell($dashboardGrid, $dashboardMerges, 14, 16, 9, 9, 11, inlineCell(14, 9, 'Temuan Dengan Action', 11));
setMergedDashboardCell(
    $dashboardGrid,
    $dashboardMerges,
    14,
    16,
    10,
    11,
    12,
    formulaCell(14, 10, "COUNTA({$auditSheet}!R2:R{$dataEndRow})", $totalActions, 12)
);

setMergedDashboardCell($dashboardGrid, $dashboardMerges, 2, 8, 14, 14, 20, inlineCell(2, 14, 'Status Summary', 20));
setMergedDashboardCell($dashboardGrid, $dashboardMerges, 2, 4, 15, 15, 21, inlineCell(2, 15, 'Status', 21));
setMergedDashboardCell($dashboardGrid, $dashboardMerges, 5, 6, 15, 15, 21, inlineCell(5, 15, 'Total', 21));
setMergedDashboardCell($dashboardGrid, $dashboardMerges, 7, 8, 15, 15, 21, inlineCell(7, 15, 'Share', 21));
setMergedDashboardCell($dashboardGrid, $dashboardMerges, 2, 4, 16, 16, 14, inlineCell(2, 16, 'OPEN', 14));
setMergedDashboardCell($dashboardGrid, $dashboardMerges, 5, 6, 16, 16, 15, formulaCell(5, 16, "COUNTIF({$auditSheet}!T2:T{$dataEndRow},\"OPEN\")", $totalOpen, 15));
setMergedDashboardCell($dashboardGrid, $dashboardMerges, 7, 8, 16, 16, 22, formulaCell(7, 16, 'IF($B$6=0,0,E16/$B$6)', $totalFindings > 0 ? $totalOpen / $totalFindings : 0, 22));
setMergedDashboardCell($dashboardGrid, $dashboardMerges, 2, 4, 17, 17, 16, inlineCell(2, 17, 'CLOSED', 16));
setMergedDashboardCell($dashboardGrid, $dashboardMerges, 5, 6, 17, 17, 17, formulaCell(5, 17, "COUNTIF({$auditSheet}!T2:T{$dataEndRow},\"CLOSED\")", $totalClosed, 17));
setMergedDashboardCell($dashboardGrid, $dashboardMerges, 7, 8, 17, 17, 22, formulaCell(7, 17, 'IF($B$6=0,0,E17/$B$6)', $completionRate, 22));

setMergedDashboardCell($dashboardGrid, $dashboardMerges, 10, 16, 14, 14, 20, inlineCell(10, 14, 'Plant Summary', 20));
setMergedDashboardCell($dashboardGrid, $dashboardMerges, 10, 12, 15, 15, 21, inlineCell(10, 15, 'Plant', 21));
setMergedDashboardCell($dashboardGrid, $dashboardMerges, 13, 14, 15, 15, 21, inlineCell(13, 15, 'Total', 21));
setMergedDashboardCell($dashboardGrid, $dashboardMerges, 15, 16, 15, 15, 21, inlineCell(15, 15, 'Share', 21));

$plantRow = 16;
foreach (['MKM-1', 'MKM-2'] as $plantName) {
    $plantTotal = (int)($plantCounts[$plantName] ?? 0);
    setMergedDashboardCell($dashboardGrid, $dashboardMerges, 10, 12, $plantRow, $plantRow, 1, inlineCell(10, $plantRow, $plantName, 1));
    setMergedDashboardCell($dashboardGrid, $dashboardMerges, 13, 14, $plantRow, $plantRow, 4, formulaCell(13, $plantRow, "COUNTIF({$auditSheet}!E2:E{$dataEndRow},J{$plantRow})", $plantTotal, 4));
    setMergedDashboardCell($dashboardGrid, $dashboardMerges, 15, 16, $plantRow, $plantRow, 22, formulaCell(15, $plantRow, "IF(\$B\$6=0,0,M{$plantRow}/\$B\$6)", $totalFindings > 0 ? $plantTotal / $totalFindings : 0, 22));
    $plantRow++;
}

setMergedDashboardCell($dashboardGrid, $dashboardMerges, 2, 16, 20, 20, 20, inlineCell(2, 20, 'Top 5 Line by Finding Count', 20));
setMergedDashboardCell($dashboardGrid, $dashboardMerges, 2, 8, 21, 21, 21, inlineCell(2, 21, 'Line', 21));
setMergedDashboardCell($dashboardGrid, $dashboardMerges, 9, 11, 21, 21, 21, inlineCell(9, 21, 'Total', 21));
setMergedDashboardCell($dashboardGrid, $dashboardMerges, 12, 16, 21, 21, 21, inlineCell(12, 21, 'Share', 21));

$lineRow = 22;
foreach ($topLines as $lineName => $lineTotal) {
    setMergedDashboardCell($dashboardGrid, $dashboardMerges, 2, 8, $lineRow, $lineRow, 1, inlineCell(2, $lineRow, (string)$lineName, 1));
    setMergedDashboardCell($dashboardGrid, $dashboardMerges, 9, 11, $lineRow, $lineRow, 4, formulaCell(9, $lineRow, "COUNTIF({$auditSheet}!F2:F{$dataEndRow},B{$lineRow})", (int)$lineTotal, 4));
    setMergedDashboardCell($dashboardGrid, $dashboardMerges, 12, 16, $lineRow, $lineRow, 22, formulaCell(12, $lineRow, "IF(\$B\$6=0,0,I{$lineRow}/\$B\$6)", $totalFindings > 0 ? $lineTotal / $totalFindings : 0, 22));
    $lineRow++;
}

if (!$topLines) {
    setMergedDashboardCell($dashboardGrid, $dashboardMerges, 2, 16, 22, 22, 1, inlineCell(2, 22, 'Belum ada data line.', 1));
}

ksort($dashboardGrid);
$dashboardRows = [];
$dashboardRowHeights = [2 => 30, 3 => 20, 5 => 22, 6 => 28, 7 => 28, 9 => 22, 10 => 28, 11 => 28, 14 => 24, 20 => 24];

foreach ($dashboardGrid as $row => $cells) {
    ksort($cells);
    $height = $dashboardRowHeights[$row] ?? 22;
    $dashboardRows[] = '<row r="' . $row . '" ht="' . $height . '" customHeight="1">' . implode('', $cells) . '</row>';
}

$dashboardMergeXml = '';
foreach ($dashboardMerges as $merge) {
    $dashboardMergeXml .= '<mergeCell ref="' . $merge . '"/>';
}

$dashboardColumns = '<col min="1" max="1" width="2.5" customWidth="1"/>';
for ($column = 2; $column <= 16; $column++) {
    $dashboardColumns .= '<col min="' . $column . '" max="' . $column . '" width="11" customWidth="1"/>';
}

$dashboardWorksheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>
    <sheetViews><sheetView workbookViewId="0" showGridLines="0"/></sheetViews>
    <cols>' . $dashboardColumns . '</cols>
    <sheetData>' . implode('', $dashboardRows) . '</sheetData>
    <mergeCells count="' . count($dashboardMerges) . '">' . $dashboardMergeXml . '</mergeCells>
    <pageMargins left="0.25" right="0.25" top="0.4" bottom="0.4" header="0.2" footer="0.2"/>
    <pageSetup orientation="landscape" fitToWidth="1" fitToHeight="1"/>
</worksheet>';

$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <numFmts count="4">
        <numFmt numFmtId="164" formatCode="#,##0"/>
        <numFmt numFmtId="165" formatCode="0.00"/>
        <numFmt numFmtId="166" formatCode="yyyy-mm-dd"/>
        <numFmt numFmtId="167" formatCode="0.0%"/>
    </numFmts>
    <fonts count="8">
        <font><sz val="10"/><color rgb="FF0F172A"/><name val="Arial"/></font>
        <font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>
        <font><b/><sz val="10"/><color rgb="FF111827"/><name val="Arial"/></font>
        <font><b/><sz val="11"/><color rgb="FF111827"/><name val="Arial"/></font>
        <font><b/><sz val="16"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>
        <font><i/><sz val="9"/><color rgb="FF64748B"/><name val="Arial"/></font>
        <font><b/><sz val="20"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>
        <font><b/><sz val="20"/><color rgb="FF111827"/><name val="Arial"/></font>
    </fonts>
    <fills count="12">
        <fill><patternFill patternType="none"/></fill>
        <fill><patternFill patternType="gray125"/></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FF0086B3"/><bgColor indexed="64"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFFFC000"/><bgColor indexed="64"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFFF0000"/><bgColor indexed="64"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FF92D050"/><bgColor indexed="64"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FF0F172A"/><bgColor indexed="64"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FF0284C7"/><bgColor indexed="64"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFDC2626"/><bgColor indexed="64"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FF16A34A"/><bgColor indexed="64"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFF59E0B"/><bgColor indexed="64"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFE0F2FE"/><bgColor indexed="64"/></patternFill></fill>
    </fills>
    <borders count="2">
        <border><left/><right/><top/><bottom/><diagonal/></border>
        <border><left style="thin"><color rgb="FFCBD5E1"/></left><right style="thin"><color rgb="FFCBD5E1"/></right><top style="thin"><color rgb="FFCBD5E1"/></top><bottom style="thin"><color rgb="FFCBD5E1"/></bottom><diagonal/></border>
    </borders>
    <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
    <cellXfs count="23">
        <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
        <xf numFmtId="49" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>
        <xf numFmtId="49" fontId="1" fillId="2" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
        <xf numFmtId="49" fontId="2" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
        <xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
        <xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
        <xf numFmtId="166" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
        <xf numFmtId="49" fontId="3" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
        <xf numFmtId="49" fontId="3" fillId="5" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
        <xf numFmtId="49" fontId="4" fillId="6" borderId="0" xfId="0" applyNumberFormat="1" applyFill="1" applyFont="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>
        <xf numFmtId="49" fontId="5" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>
        <xf numFmtId="49" fontId="1" fillId="7" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
        <xf numFmtId="164" fontId="6" fillId="7" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
        <xf numFmtId="165" fontId="6" fillId="7" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
        <xf numFmtId="49" fontId="1" fillId="8" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
        <xf numFmtId="164" fontId="6" fillId="8" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
        <xf numFmtId="49" fontId="1" fillId="9" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
        <xf numFmtId="164" fontId="6" fillId="9" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
        <xf numFmtId="49" fontId="2" fillId="10" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
        <xf numFmtId="167" fontId="7" fillId="10" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
        <xf numFmtId="49" fontId="1" fillId="6" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>
        <xf numFmtId="49" fontId="2" fillId="11" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
        <xf numFmtId="167" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
    </cellXfs>
    <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>';

zipAdd($zip, '[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Default Extension="jpg" ContentType="image/jpeg"/>
    <Default Extension="png" ContentType="image/png"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
    <Override PartName="/xl/metadata.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheetMetadata+xml"/>
    <Override PartName="/xl/richData/richValueRel.xml" ContentType="application/vnd.ms-excel.richvaluerel+xml"/>
    <Override PartName="/xl/richData/rdrichvalue.xml" ContentType="application/vnd.ms-excel.rdrichvalue+xml"/>
    <Override PartName="/xl/richData/rdrichvaluestructure.xml" ContentType="application/vnd.ms-excel.rdrichvaluestructure+xml"/>
    <Override PartName="/xl/richData/rdRichValueTypes.xml" ContentType="application/vnd.ms-excel.rdrichvaluetypes+xml"/>
</Types>');

zipAdd($zip, '_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>');

zipAdd($zip, 'xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <bookViews><workbookView activeTab="0" firstSheet="0"/></bookViews>
    <sheets>
        <sheet name="Summary Dashboard" sheetId="2" r:id="rId2"/>
        <sheet name="Air Leak Audit" sheetId="1" r:id="rId1"/>
    </sheets>
    <calcPr calcId="191029" fullCalcOnLoad="1" forceFullCalc="1"/>
</workbook>');

zipAdd($zip, 'xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>
    <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
    <Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sheetMetadata" Target="metadata.xml"/>
    <Relationship Id="rId5" Type="http://schemas.microsoft.com/office/2022/10/relationships/richValueRel" Target="richData/richValueRel.xml"/>
    <Relationship Id="rId6" Type="http://schemas.microsoft.com/office/2017/06/relationships/rdRichValue" Target="richData/rdrichvalue.xml"/>
    <Relationship Id="rId7" Type="http://schemas.microsoft.com/office/2017/06/relationships/rdRichValueStructure" Target="richData/rdrichvaluestructure.xml"/>
    <Relationship Id="rId8" Type="http://schemas.microsoft.com/office/2017/06/relationships/rdRichValueTypes" Target="richData/rdRichValueTypes.xml"/>
</Relationships>');

zipAdd($zip, 'xl/worksheets/sheet1.xml', $worksheet);
zipAdd($zip, 'xl/worksheets/sheet2.xml', $dashboardWorksheet);
zipAdd($zip, 'xl/styles.xml', $styles);

$metadataBindings = '';
foreach (array_keys($richValues) as $metadataIndex) {
    $metadataBindings .= '<bk><extLst><ext uri="{3e2802c4-a4d2-4d8b-9148-e3be6c30e623}"><xlrd:rvb i="' . $metadataIndex . '"/></ext></extLst></bk>';
}

$metadataRecords = '';
foreach (array_keys($richValues) as $metadataIndex) {
    $metadataRecords .= '<bk><rc t="1" v="' . $metadataIndex . '"/></bk>';
}

zipAdd($zip, 'xl/metadata.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<metadata xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:xlrd="http://schemas.microsoft.com/office/spreadsheetml/2017/richdata">
    <metadataTypes count="1"><metadataType name="XLRICHVALUE" minSupportedVersion="120000" copy="1" pasteAll="1" pasteValues="1" merge="1" splitFirst="1" rowColShift="1" clearFormats="1" clearComments="1" assign="1" coerce="1"/></metadataTypes>
    <futureMetadata name="XLRICHVALUE" count="' . count($richValues) . '">' . $metadataBindings . '</futureMetadata>
    <valueMetadata count="' . count($richValues) . '">' . $metadataRecords . '</valueMetadata>
</metadata>');
zipAdd($zip, 'xl/richData/rdRichValueTypes.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<rvTypesInfo xmlns="http://schemas.microsoft.com/office/spreadsheetml/2017/richdata2" xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" mc:Ignorable="x" xmlns:x="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><global><keyFlags><key name="_Self"><flag name="ExcludeFromFile" value="1"/><flag name="ExcludeFromCalcComparison" value="1"/></key><key name="_DisplayString"><flag name="ExcludeFromCalcComparison" value="1"/></key><key name="_Flags"><flag name="ExcludeFromCalcComparison" value="1"/></key><key name="_Format"><flag name="ExcludeFromCalcComparison" value="1"/></key><key name="_SubLabel"><flag name="ExcludeFromCalcComparison" value="1"/></key><key name="_Attribution"><flag name="ExcludeFromCalcComparison" value="1"/></key><key name="_Icon"><flag name="ExcludeFromCalcComparison" value="1"/></key><key name="_Display"><flag name="ExcludeFromCalcComparison" value="1"/></key><key name="_CanonicalPropertyNames"><flag name="ExcludeFromCalcComparison" value="1"/></key><key name="_ClassificationId"><flag name="ExcludeFromCalcComparison" value="1"/></key></keyFlags></global></rvTypesInfo>');
zipAdd($zip, 'xl/richData/rdrichvaluestructure.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<rvStructures xmlns="http://schemas.microsoft.com/office/spreadsheetml/2017/richdata" count="1"><s t="_localImage"><k n="_rvRel:LocalImageIdentifier" t="i"/><k n="CalcOrigin" t="i"/></s></rvStructures>');
zipAdd($zip, 'xl/richData/richValueRel.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<richValueRels xmlns="http://schemas.microsoft.com/office/spreadsheetml/2022/richvaluerel" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . implode('', $richValueRels) . '</richValueRels>');
zipAdd($zip, 'xl/richData/rdrichvalue.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<rvData xmlns="http://schemas.microsoft.com/office/spreadsheetml/2017/richdata" count="' . count($richValues) . '">' . implode('', $richValues) . '</rvData>');
zipAdd($zip, 'xl/richData/_rels/richValueRel.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . implode('', array_map(
    static fn (string $relationship, int $index): string => '<Relationship Id="rId' . ($index + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/' . xml($mediaFiles[$index][0]) . '"/>',
    $richValueRels,
    array_keys($richValueRels)
)) . '</Relationships>');

foreach ($mediaFiles as [$mediaName, $absolutePath]) {
    $zip->addFile($absolutePath, 'xl/media/' . $mediaName);
}

$zip->close();

foreach ($temporaryImages as $temporaryImage) {
    @unlink($temporaryImage);
}

$filename = 'mkm_air_leak_audit_' . date('Ymd_His') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($temporaryFile));
header('Cache-Control: max-age=0');
readfile($temporaryFile);
@unlink($temporaryFile);
