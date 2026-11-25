<?php

declare(strict_types=1);

require __DIR__ . '/config/database.php';

const MAX_UPLOAD_BYTES = 20 * 1024 * 1024;
const MAX_STORED_IMAGE_SIDE = 1400;
const STORED_IMAGE_QUALITY = 82;
const DELETE_PASSWORD = 'adimas';

$errors = [];
$auditSaved = isset($_GET['saved']) && $_GET['saved'] === '1';
$actionSaved = isset($_GET['action_saved']) && $_GET['action_saved'] === '1';
$auditDeleted = isset($_GET['deleted']) && $_GET['deleted'] === '1';
$auditUpdated = isset($_GET['updated']) && $_GET['updated'] === '1';
$savedFindingNo = strtoupper(trim((string)($_GET['finding_no'] ?? '')));
$savedFindingNo = preg_match('/^[A-Z0-9]{4}$/', $savedFindingNo) ? $savedFindingNo : '';
$old = [
    'date_audit' => date('Y-m-d'),
    'member' => '',
    'plant' => 'MKM-1',
    'line' => '',
    'machine_no' => '',
    'pic_dept' => '',
    'loss_flow' => '',
    'loss_cost' => '',
    'leak_location' => '',
    'co2' => '',
];
$oldAction = [
    'finding_no' => strtoupper(trim((string)($_GET['answer'] ?? ''))),
    'action_taken' => '',
    'action_pic' => '',
];

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function normalizeLossCost(string $value): string
{
    if ($value === '' || !is_numeric($value)) {
        return $value;
    }

    $cost = (float)$value;
    if ($cost >= 1 && $cost < 1000) {
        $cost *= 1000;
    }

    return number_format($cost, 2, '.', '');
}

function editAuditPayload(array $audit): string
{
    $fields = [
        'id', 'finding_no', 'date_audit', 'member', 'plant', 'line', 'machine_no',
        'pic_dept', 'loss_flow', 'loss_cost', 'leak_location', 'co2',
    ];
    $payload = [];

    foreach ($fields as $field) {
        $payload[$field] = (string)($audit[$field] ?? '');
    }

    return (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function uploadFolder(): array
{
    $folder = 'uploads/airleak/' . date('Y/m');
    $absoluteFolder = __DIR__ . '/' . $folder;

    if (!is_dir($absoluteFolder) && !mkdir($absoluteFolder, 0775, true) && !is_dir($absoluteFolder)) {
        throw new RuntimeException('Folder upload tidak bisa dibuat.');
    }

    return [$folder, $absoluteFolder];
}

function resizeAndSaveImage(string $source, string $mime, string $target): bool
{
    $image = match ($mime) {
        'image/jpeg' => imagecreatefromjpeg($source),
        'image/png' => imagecreatefrompng($source),
        'image/webp' => imagecreatefromwebp($source),
        default => false,
    };

    if (!$image) {
        return false;
    }

    $width = imagesx($image);
    $height = imagesy($image);
    $ratio = min(MAX_STORED_IMAGE_SIDE / $width, MAX_STORED_IMAGE_SIDE / $height, 1);
    $newWidth = (int)round($width * $ratio);
    $newHeight = (int)round($height * $ratio);
    $canvas = imagecreatetruecolor($newWidth, $newHeight);
    $white = imagecolorallocate($canvas, 255, 255, 255);

    imagefill($canvas, 0, 0, $white);
    imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    $saved = imagejpeg($canvas, $target, STORED_IMAGE_QUALITY);

    imagedestroy($image);
    imagedestroy($canvas);

    return $saved;
}

function saveDataUrlImage(string $field, string $dataUrl, array &$errors): ?string
{
    if ($dataUrl === '') {
        return null;
    }

    if (!preg_match('/^data:image\/(png|jpeg|webp);base64,/', $dataUrl, $matches)) {
        $errors[$field] = 'Hasil mark foto tidak valid.';
        return null;
    }

    $encoded = substr($dataUrl, strpos($dataUrl, ',') + 1);
    $binary = base64_decode($encoded, true);

    if ($binary === false || strlen($binary) > MAX_UPLOAD_BYTES) {
        $errors[$field] = 'Hasil mark foto terlalu besar atau rusak.';
        return null;
    }

    try {
        [$folder, $absoluteFolder] = uploadFolder();
    } catch (Throwable $e) {
        $errors[$field] = $e->getMessage();
        return null;
    }

    $filename = $field . '_marked_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.jpg';
    $temporary = tempnam(sys_get_temp_dir(), 'airleak_mark_');

    if ($temporary === false || file_put_contents($temporary, $binary) === false) {
        $errors[$field] = 'Hasil mark foto tidak bisa disimpan.';
        return null;
    }

    $mime = 'image/' . $matches[1];
    $target = $absoluteFolder . '/' . $filename;

    if (!resizeAndSaveImage($temporary, $mime, $target)) {
        @unlink($temporary);
        $errors[$field] = 'Hasil mark foto tidak bisa dikompres.';
        return null;
    }

    @unlink($temporary);

    return $folder . '/' . $filename;
}

function saveDataUrlImages(string $field, array &$errors): ?string
{
    $json = (string)($_POST[$field . '_marked'] ?? '');

    if ($json === '') {
        return null;
    }

    $images = json_decode($json, true);

    if (!is_array($images)) {
        $errors[$field] = 'Data foto tidak valid.';
        return null;
    }

    $paths = [];

    foreach ($images as $index => $dataUrl) {
        if (!is_string($dataUrl) || $dataUrl === '') {
            continue;
        }

        $path = saveDataUrlImage($field . '_' . ($index + 1), $dataUrl, $errors);

        if (isset($errors[$field])) {
            return null;
        }

        if ($path !== null) {
            $paths[] = $path;
        }
    }

    if (!$paths) {
        return null;
    }

    return json_encode($paths, JSON_UNESCAPED_SLASHES);
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

function deleteStoredImages(array $storedValues): void
{
    $uploadsRoot = realpath(__DIR__ . '/uploads');

    if ($uploadsRoot === false) {
        return;
    }

    $uploadsPrefix = $uploadsRoot . DIRECTORY_SEPARATOR;

    foreach ($storedValues as $storedValue) {
        foreach (imagePaths(is_string($storedValue) ? $storedValue : null) as $path) {
            $absolutePath = realpath(__DIR__ . '/' . ltrim($path, '/\\'));

            if (
                $absolutePath !== false
                && str_starts_with($absolutePath, $uploadsPrefix)
                && is_file($absolutePath)
            ) {
                @unlink($absolutePath);
            }
        }
    }
}

function generateFindingNo(): string
{
    // Character yang mudah tertukar (0/O dan 1/I) sengaja tidak dipakai.
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lastIndex = strlen($alphabet) - 1;
    $findingNo = '';

    for ($index = 0; $index < 4; $index++) {
        $findingNo .= $alphabet[random_int(0, $lastIndex)];
    }

    return $findingNo;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = (string)($_POST['form_type'] ?? 'audit');

    if ($formType === 'delete') {
        $auditId = filter_var($_POST['audit_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $deletePassword = (string)($_POST['delete_password'] ?? '');

        if ($auditId === false) {
            $errors['delete'] = 'Data temuan yang akan dihapus tidak valid.';
        } elseif (!hash_equals(DELETE_PASSWORD, $deletePassword)) {
            $errors['delete'] = 'Password hapus salah.';
        } else {
            try {
                $pdo = db();
                $pdo->beginTransaction();

                $auditStatement = $pdo->prepare(
                    'SELECT machine_picture, leak_location_picture, air_leak_image
                     FROM air_leak_audits
                     WHERE id = :id
                     FOR UPDATE'
                );
                $auditStatement->execute(['id' => $auditId]);
                $auditImages = $auditStatement->fetch();

                if (!$auditImages) {
                    throw new RuntimeException('Data temuan tidak ditemukan.');
                }

                $actionStatement = $pdo->prepare(
                    'SELECT action_picture FROM air_leak_actions WHERE air_leak_audit_id = :id'
                );
                $actionStatement->execute(['id' => $auditId]);
                $storedImages = array_values($auditImages);
                foreach ($actionStatement->fetchAll(PDO::FETCH_COLUMN) as $actionPicture) {
                    $storedImages[] = $actionPicture;
                }

                $deleteStatement = $pdo->prepare('DELETE FROM air_leak_audits WHERE id = :id');
                $deleteStatement->execute(['id' => $auditId]);
                $pdo->commit();

                deleteStoredImages($storedImages);

                header('Location: index.php?deleted=1', true, 303);
                exit;
            } catch (Throwable $e) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors['delete'] = 'Data temuan gagal dihapus. Detail: ' . $e->getMessage();
            }
        }
    } elseif ($formType === 'edit') {
        $auditId = filter_var($_POST['audit_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $editPassword = (string)($_POST['edit_password'] ?? '');
        $editValues = [];
        foreach (array_keys($old) as $field) {
            $editValues[$field] = trim((string)($_POST[$field] ?? ''));
        }

        $requiredFields = [
            'date_audit' => 'Date Audit wajib diisi.',
            'member' => 'Member wajib diisi.',
            'plant' => 'Plant wajib dipilih.',
            'line' => 'Line wajib diisi.',
            'machine_no' => 'Machine No wajib diisi.',
            'pic_dept' => 'PIC Dept wajib diisi.',
            'loss_flow' => 'Loss Flow wajib diisi.',
            'loss_cost' => 'Loss Cost wajib diisi.',
            'leak_location' => 'Leak Location wajib diisi.',
            'co2' => 'CO2 wajib diisi.',
        ];

        if ($auditId === false) {
            $errors['edit'] = 'Data temuan yang akan diedit tidak valid.';
        } elseif (!hash_equals(DELETE_PASSWORD, $editPassword)) {
            $errors['edit'] = 'Password edit salah.';
        }
        foreach ($requiredFields as $field => $message) {
            if ($editValues[$field] === '') {
                $errors['edit'] = $message;
                break;
            }
        }
        if (!in_array($editValues['plant'], ['MKM-1', 'MKM-2'], true)) {
            $errors['edit'] = 'Plant harus MKM-1 atau MKM-2.';
        } elseif (!is_numeric($editValues['loss_flow']) || (float)$editValues['loss_flow'] < 0) {
            $errors['edit'] = 'Loss Flow harus angka 0 atau lebih.';
        } elseif (!is_numeric($editValues['loss_cost']) || (float)$editValues['loss_cost'] < 0) {
            $errors['edit'] = 'Loss Cost harus angka 0 atau lebih.';
        } elseif (!is_numeric($editValues['co2']) || (float)$editValues['co2'] < 0) {
            $errors['edit'] = 'CO2 harus angka 0 atau lebih.';
        }

        if (!$errors) {
            try {
                $editValues['loss_cost'] = normalizeLossCost($editValues['loss_cost']);
                $pdo = db();
                $update = $pdo->prepare(
                    'UPDATE air_leak_audits
                     SET date_audit = :date_audit, member = :member, plant = :plant, line = :line,
                         machine_no = :machine_no, pic_dept = :pic_dept, loss_flow = :loss_flow,
                         loss_cost = :loss_cost, leak_location = :leak_location, co2 = :co2
                     WHERE id = :id'
                );
                $update->execute([
                    'date_audit' => $editValues['date_audit'],
                    'member' => $editValues['member'],
                    'plant' => $editValues['plant'],
                    'line' => $editValues['line'],
                    'machine_no' => $editValues['machine_no'],
                    'pic_dept' => $editValues['pic_dept'],
                    'loss_flow' => $editValues['loss_flow'],
                    'loss_cost' => $editValues['loss_cost'],
                    'leak_location' => $editValues['leak_location'],
                    'co2' => $editValues['co2'],
                    'id' => $auditId,
                ]);

                if ($update->rowCount() === 0) {
                    $exists = $pdo->prepare('SELECT COUNT(*) FROM air_leak_audits WHERE id = :id');
                    $exists->execute(['id' => $auditId]);
                    if (!(int)$exists->fetchColumn()) {
                        throw new RuntimeException('Data temuan tidak ditemukan.');
                    }
                }

                header('Location: index.php?updated=1', true, 303);
                exit;
            } catch (Throwable $e) {
                $errors['edit'] = 'Data temuan gagal diedit. Detail: ' . $e->getMessage();
            }
        }
    } elseif ($formType === 'action') {
        foreach ($oldAction as $key => $default) {
            $oldAction[$key] = trim((string)($_POST[$key] ?? $default));
        }
        $oldAction['finding_no'] = strtoupper($oldAction['finding_no']);

        if (!preg_match('/^[A-Z0-9]{4}$/', $oldAction['finding_no'])) {
            $errors['action_finding_no'] = 'No. Temuan harus terdiri dari 4 karakter.';
        }
        if ($oldAction['action_taken'] === '') {
            $errors['action_taken'] = 'Action/perbaikan wajib diisi.';
        }
        if ($oldAction['action_pic'] === '') {
            $errors['action_pic'] = 'Nama PIC wajib diisi.';
        }

        $actionPicture = null;
        if (!$errors) {
            $actionPicture = saveDataUrlImages('action_picture', $errors);
            if ($actionPicture === null && !isset($errors['action_picture'])) {
                $errors['action_picture'] = 'Foto hasil perbaikan wajib diisi.';
            }
        }

        if (!$errors) {
            try {
                $pdo = db();
                $findAudit = $pdo->prepare('SELECT id FROM air_leak_audits WHERE finding_no = :finding_no LIMIT 1');
                $findAudit->execute(['finding_no' => $oldAction['finding_no']]);
                $auditId = $findAudit->fetchColumn();

                if (!$auditId) {
                    $errors['action_finding_no'] = 'No. Temuan tidak ditemukan.';
                } else {
                    $pdo->beginTransaction();
                    $insertAction = $pdo->prepare(
                        'INSERT INTO air_leak_actions (air_leak_audit_id, action_taken, action_pic, action_picture)
                         VALUES (:air_leak_audit_id, :action_taken, :action_pic, :action_picture)'
                    );
                    $insertAction->execute([
                        'air_leak_audit_id' => $auditId,
                        'action_taken' => $oldAction['action_taken'],
                        'action_pic' => $oldAction['action_pic'],
                        'action_picture' => $actionPicture,
                    ]);
                    $closeAudit = $pdo->prepare(
                        "UPDATE air_leak_audits SET status = 'CLOSED', closed_at = NOW() WHERE id = :id"
                    );
                    $closeAudit->execute(['id' => $auditId]);
                    $pdo->commit();

                    header('Location: index.php?action_saved=1&finding_no=' . rawurlencode($oldAction['finding_no']) . '#answer-finding', true, 303);
                    exit;
                }
            } catch (Throwable $e) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors['database'] = 'Jawaban temuan gagal disimpan. Pastikan migration sudah dijalankan. Detail: ' . $e->getMessage();
            }
        }
    } else {
        foreach ($old as $key => $default) {
            $old[$key] = trim((string)($_POST[$key] ?? $default));
        }

        $requiredFields = [
            'date_audit' => 'Date Audit wajib diisi.',
            'member' => 'Member wajib diisi.',
            'plant' => 'Plant wajib dipilih.',
            'line' => 'Line wajib diisi.',
            'machine_no' => 'Machine No wajib diisi.',
            'pic_dept' => 'PIC Dept wajib diisi.',
            'loss_flow' => 'Loss Flow wajib diisi.',
            'loss_cost' => 'Loss Cost wajib diisi.',
            'leak_location' => 'Leak Location wajib diisi.',
            'co2' => 'CO₂ wajib diisi.',
        ];

        foreach ($requiredFields as $field => $message) {
            if ($old[$field] === '') {
                $errors[$field] = $message;
            }
        }

        if (!in_array($old['plant'], ['MKM-1', 'MKM-2'], true)) {
            $errors['plant'] = 'Plant harus MKM-1 atau MKM-2.';
        }
        if ($old['loss_flow'] !== '' && (!is_numeric($old['loss_flow']) || (float)$old['loss_flow'] < 0)) {
            $errors['loss_flow'] = 'Loss Flow harus angka 0 atau lebih.';
        }
        if ($old['loss_cost'] !== '' && (!is_numeric($old['loss_cost']) || (float)$old['loss_cost'] < 0)) {
            $errors['loss_cost'] = 'Loss Cost harus angka 0 atau lebih.';
        }
        if ($old['co2'] !== '' && (!is_numeric($old['co2']) || (float)$old['co2'] < 0)) {
            $errors['co2'] = 'CO₂ harus angka 0 atau lebih.';
        }

        if (!isset($errors['loss_cost'])) {
            $old['loss_cost'] = normalizeLossCost($old['loss_cost']);
        }

        $machinePicture = saveDataUrlImages('machine_picture', $errors);
        $leakLocationPicture = saveDataUrlImages('leak_location_picture', $errors);
        $airLeakImage = saveDataUrlImages('air_leak_image', $errors);

        if (!$errors) {
            try {
                $pdo = db();
                $stmt = $pdo->prepare(
                    'INSERT INTO air_leak_audits
                    (finding_no, date_audit, member, plant, line, machine_no, pic_dept, loss_flow, loss_cost, leak_location, co2, machine_picture, leak_location_picture, air_leak_image)
                    VALUES
                    (:finding_no, :date_audit, :member, :plant, :line, :machine_no, :pic_dept, :loss_flow, :loss_cost, :leak_location, :co2, :machine_picture, :leak_location_picture, :air_leak_image)'
                );

                $findingNo = '';
                for ($attempt = 0; $attempt < 25; $attempt++) {
                    $findingNo = generateFindingNo();
                    try {
                        $stmt->execute([
                            'finding_no' => $findingNo,
                            'date_audit' => $old['date_audit'],
                            'member' => $old['member'],
                            'plant' => $old['plant'],
                            'line' => $old['line'],
                            'machine_no' => $old['machine_no'],
                            'pic_dept' => $old['pic_dept'],
                            'loss_flow' => $old['loss_flow'],
                            'loss_cost' => $old['loss_cost'],
                            'leak_location' => $old['leak_location'],
                            'co2' => $old['co2'],
                            'machine_picture' => $machinePicture,
                            'leak_location_picture' => $leakLocationPicture,
                            'air_leak_image' => $airLeakImage,
                        ]);
                        break;
                    } catch (PDOException $e) {
                        $driverCode = (int)($e->errorInfo[1] ?? 0);
                        if ($driverCode !== 1062 || $attempt === 24) {
                            throw $e;
                        }
                    }
                }

                header('Location: index.php?saved=1&finding_no=' . rawurlencode($findingNo), true, 303);
                exit;
            } catch (Throwable $e) {
                $errors['database'] = 'Database belum siap atau koneksi gagal. Jalankan migrate.php dulu. Detail: ' . $e->getMessage();
            }
        }
    }
}

$fields = [
    'machine_picture' => ['Machine Pict', 'Foto mesin'],
    'leak_location_picture' => ['Leak Location', 'Foto titik lokasi leak'],
    'air_leak_image' => ['Air Leak Image', 'Foto kebocoran udara'],
];

try {
    $perPage = 10;
    $currentPage = max(1, (int)($_GET['page'] ?? 1));
    $pdo = db();
    $totalAudits = (int)$pdo->query('SELECT COUNT(*) FROM air_leak_audits')->fetchColumn();
    $totalPages = max(1, (int)ceil($totalAudits / $perPage));
    $currentPage = min($currentPage, $totalPages);
    $offset = ($currentPage - 1) * $perPage;
    $stmt = $pdo->prepare(
        'SELECT audit.id, audit.finding_no, audit.date_audit, audit.member, audit.plant, audit.line,
                audit.machine_no, audit.pic_dept, audit.loss_flow, audit.loss_cost, audit.leak_location, audit.co2, audit.machine_picture,
                audit.leak_location_picture, audit.air_leak_image, audit.status, audit.closed_at, audit.created_at,
                action.action_taken, action.action_pic, action.action_picture, action.created_at AS action_created_at
        FROM air_leak_audits audit
        LEFT JOIN air_leak_actions action ON action.id = (
            SELECT MAX(latest_action.id)
            FROM air_leak_actions latest_action
            WHERE latest_action.air_leak_audit_id = audit.id
        )
        ORDER BY audit.id DESC
        LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $audits = $stmt->fetchAll();
} catch (Throwable $e) {
    $audits = [];
    $perPage = 10;
    $currentPage = 1;
    $totalAudits = 0;
    $totalPages = 1;
    $offset = 0;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MKM Air Leak Audit</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            200: '#bfdbfe',
                            400: '#60a5fa',
                            500: '#38bdf8',
                            600: '#0284c7',
                            700: '#0369a1'
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-800 antialiased">
    <main class="mx-auto flex min-h-screen w-full max-w-7xl flex-col px-3 py-4 sm:px-6 sm:py-5 lg:px-8">
        <section class="mb-5 rounded-lg border border-sky-100 bg-white p-4 shadow-xl shadow-sky-100/70 sm:p-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div class="flex items-center gap-4">
                    <img src="MKM.png" alt="MKM" class="h-14 w-14 rounded-lg border border-slate-100 bg-white object-contain p-2 shadow-sm sm:h-16 sm:w-16">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-primary-600">MKM Maintenance</p>
                        <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl">Air Leak Audit</h1>
                        <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500">Form audit mobile-friendly untuk catat mesin, PIC, dan foto temuan leak langsung dari kamera.</p>
                    </div>
                </div>
                <div class="rounded-md border border-primary-200 bg-primary-50 px-3 py-2 text-sm text-primary-700">
                    Database: <span class="font-semibold">mkm_airleak</span>
                </div>
            </div>
        </section>

        <?php if ($auditSaved && $savedFindingNo !== ''): ?>
            <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-5 text-center text-emerald-800 shadow-sm">
                <p class="text-sm font-semibold">Data audit berhasil disimpan. Tulis nomor ini pada tag:</p>
                <p class="my-2 font-mono text-4xl font-black tracking-[0.25em] text-emerald-700 sm:text-5xl"><?= h($savedFindingNo) ?></p>
                <a href="?answer=<?= rawurlencode($savedFindingNo) ?>#answer-finding" class="inline-flex rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Jawab Temuan Ini</a>
            </div>
        <?php endif; ?>

        <?php if ($actionSaved && $savedFindingNo !== ''): ?>
            <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700">
                Jawaban untuk temuan <span class="font-mono text-base"><?= h($savedFindingNo) ?></span> berhasil disimpan. Status temuan sudah Closed.
            </div>
        <?php endif; ?>

        <?php if ($auditDeleted): ?>
            <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700">
                Data temuan dan seluruh foto terkait berhasil dihapus.
            </div>
        <?php endif; ?>

        <?php if ($auditUpdated): ?>
            <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700">
                Data temuan berhasil diperbarui.
            </div>
        <?php endif; ?>

        <?php if (isset($errors['delete'])): ?>
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-700">
                <?= h($errors['delete']) ?>
            </div>
        <?php endif; ?>

        <?php if (isset($errors['edit'])): ?>
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-700">
                <?= h($errors['edit']) ?> Silakan buka Edit dan coba lagi.
            </div>
        <?php endif; ?>

        <?php if (isset($errors['database'])): ?>
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                <?= h($errors['database']) ?>
            </div>
        <?php endif; ?>

        <form id="audit-form" method="post" enctype="multipart/form-data" class="rounded-lg border border-slate-200 bg-white p-4 shadow-xl shadow-slate-200/70 sm:p-6">
            <input type="hidden" name="form_type" value="audit">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Date Audit</span>
                    <input type="date" name="date_audit" value="<?= h($old['date_audit']) ?>" class="mt-2 w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-base text-slate-950 outline-none transition focus:border-primary-400 focus:bg-white focus:ring-2 focus:ring-primary-500/20">
                    <?php if (isset($errors['date_audit'])): ?><span class="mt-1 block text-sm text-red-600"><?= h($errors['date_audit']) ?></span><?php endif; ?>
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Member</span>
                    <input type="text" name="member" value="<?= h($old['member']) ?>" placeholder="Nama member" class="mt-2 w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-base text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-primary-400 focus:bg-white focus:ring-2 focus:ring-primary-500/20">
                    <?php if (isset($errors['member'])): ?><span class="mt-1 block text-sm text-red-600"><?= h($errors['member']) ?></span><?php endif; ?>
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Plant</span>
                    <select name="plant" class="mt-2 w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-base text-slate-950 outline-none transition focus:border-primary-400 focus:bg-white focus:ring-2 focus:ring-primary-500/20">
                        <option value="MKM-1" <?= $old['plant'] === 'MKM-1' ? 'selected' : '' ?>>MKM-1</option>
                        <option value="MKM-2" <?= $old['plant'] === 'MKM-2' ? 'selected' : '' ?>>MKM-2</option>
                    </select>
                    <?php if (isset($errors['plant'])): ?><span class="mt-1 block text-sm text-red-600"><?= h($errors['plant']) ?></span><?php endif; ?>
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Line</span>
                    <input type="text" name="line" value="<?= h($old['line']) ?>" placeholder="Contoh: Line A" class="mt-2 w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-base text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-primary-400 focus:bg-white focus:ring-2 focus:ring-primary-500/20">
                    <?php if (isset($errors['line'])): ?><span class="mt-1 block text-sm text-red-600"><?= h($errors['line']) ?></span><?php endif; ?>
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Machine No</span>
                    <input type="text" name="machine_no" value="<?= h($old['machine_no']) ?>" placeholder="Nomor mesin" class="mt-2 w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-base text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-primary-400 focus:bg-white focus:ring-2 focus:ring-primary-500/20">
                    <?php if (isset($errors['machine_no'])): ?><span class="mt-1 block text-sm text-red-600"><?= h($errors['machine_no']) ?></span><?php endif; ?>
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">PIC Dept</span>
                    <input type="text" name="pic_dept" value="<?= h($old['pic_dept']) ?>" placeholder="Departemen PIC" class="mt-2 w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-base text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-primary-400 focus:bg-white focus:ring-2 focus:ring-primary-500/20">
                    <?php if (isset($errors['pic_dept'])): ?><span class="mt-1 block text-sm text-red-600"><?= h($errors['pic_dept']) ?></span><?php endif; ?>
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Loss Flow (m3/min) <span class="text-red-500">*</span></span>
                    <input type="number" name="loss_flow" value="<?= h($old['loss_flow']) ?>" min="0" step="0.01" inputmode="decimal" placeholder="Enter your answer" class="mt-2 w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-base text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-primary-400 focus:bg-white focus:ring-2 focus:ring-primary-500/20">
                    <?php if (isset($errors['loss_flow'])): ?><span class="mt-1 block text-sm text-red-600"><?= h($errors['loss_flow']) ?></span><?php endif; ?>
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Loss Cost (IDR) <span class="text-red-500">*</span></span>
                    <input type="number" name="loss_cost" value="<?= h($old['loss_cost']) ?>" min="0" step="1" inputmode="numeric" placeholder="Contoh: 20 menjadi Rp20.000" data-loss-cost class="mt-2 w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-base text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-primary-400 focus:bg-white focus:ring-2 focus:ring-primary-500/20">
                    <span class="mt-1 block text-xs text-slate-500">Input di bawah 1.000 otomatis dianggap ribuan. Contoh: 20 = Rp20.000.</span>
                    <?php if (isset($errors['loss_cost'])): ?><span class="mt-1 block text-sm text-red-600"><?= h($errors['loss_cost']) ?></span><?php endif; ?>
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Leak Location <span class="text-red-500">*</span></span>
                    <input type="text" name="leak_location" value="<?= h($old['leak_location']) ?>" placeholder="Contoh: Joint hose bagian belakang" class="mt-2 w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-base text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-primary-400 focus:bg-white focus:ring-2 focus:ring-primary-500/20">
                    <?php if (isset($errors['leak_location'])): ?><span class="mt-1 block text-sm text-red-600"><?= h($errors['leak_location']) ?></span><?php endif; ?>
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">CO₂ <span class="text-red-500">*</span></span>
                    <input type="number" name="co2" value="<?= h($old['co2']) ?>" min="0" step="0.01" inputmode="decimal" placeholder="Enter your answer" class="mt-2 w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-base text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-primary-400 focus:bg-white focus:ring-2 focus:ring-primary-500/20">
                    <?php if (isset($errors['co2'])): ?><span class="mt-1 block text-sm text-red-600"><?= h($errors['co2']) ?></span><?php endif; ?>
                </label>
            </div>

            <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <?php foreach ($fields as $name => [$label, $hint]): ?>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 shadow-sm" data-photo-field="<?= h($name) ?>">
                        <div class="mb-3">
                            <p class="text-sm font-semibold text-slate-950"><?= h($label) ?></p>
                            <p class="text-xs text-slate-500"><?= h($hint) ?>. Bisa lebih dari satu foto, maksimal 20 MB/foto.</p>
                        </div>
                        <div data-gallery="<?= h($name) ?>" class="mb-3 grid grid-cols-2 gap-2"></div>
                        <label for="<?= h($name) ?>" class="flex min-h-32 cursor-pointer flex-col items-center justify-center rounded-md border border-dashed border-primary-300 bg-white p-3 text-center transition hover:border-primary-500 hover:bg-primary-50">
                            <span class="grid h-12 w-12 place-items-center rounded-full bg-primary-100 text-2xl text-primary-700">+</span>
                            <span class="mt-2 text-sm font-semibold text-slate-600">Tambah foto</span>
                            <span data-count="<?= h($name) ?>" class="mt-1 text-xs text-slate-400">0 foto</span>
                        </label>
                        <input id="<?= h($name) ?>" type="file" name="<?= h($name) ?>[]" accept="image/*" capture="environment" multiple class="sr-only" data-file-input="<?= h($name) ?>">
                        <input type="hidden" name="<?= h($name) ?>_marked" data-marked="<?= h($name) ?>">
                        <?php if (isset($errors[$name])): ?><span class="mt-2 block text-sm text-red-600"><?= h($errors[$name]) ?></span><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <button type="reset" class="rounded-md border border-slate-200 px-5 py-3 text-sm font-semibold text-slate-600 transition hover:bg-slate-50">Reset</button>
                <button id="submit-button" type="submit" class="rounded-md bg-primary-600 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-sky-200 transition hover:bg-primary-700 disabled:cursor-not-allowed disabled:bg-slate-400 disabled:shadow-none focus:outline-none focus:ring-2 focus:ring-primary-400 focus:ring-offset-2 focus:ring-offset-white">Simpan Audit</button>
            </div>
        </form>

        <section id="answer-finding" class="mt-6 rounded-lg border border-amber-200 bg-white p-4 shadow-xl shadow-amber-100/70 sm:p-6">
            <div class="mb-5">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-amber-600">Follow Up</p>
                <h2 class="mt-2 text-xl font-bold text-slate-950">Jawab Temuan</h2>
                <p class="mt-1 text-sm text-slate-500">Masukkan nomor pada tag, action yang dilakukan, foto hasil perbaikan, dan nama PIC.</p>
            </div>

            <form id="action-form" method="post" enctype="multipart/form-data">
                <input type="hidden" name="form_type" value="action">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">No. Temuan <span class="text-red-500">*</span></span>
                        <input type="text" name="finding_no" value="<?= h($oldAction['finding_no']) ?>" maxlength="4" minlength="4" autocomplete="off" autocapitalize="characters" placeholder="Contoh: A7K2" class="mt-2 w-full rounded-md border border-amber-200 bg-amber-50 px-3 py-3 font-mono text-xl font-bold uppercase tracking-[0.2em] text-slate-950 outline-none transition placeholder:text-base placeholder:font-normal placeholder:tracking-normal placeholder:text-slate-400 focus:border-amber-400 focus:bg-white focus:ring-2 focus:ring-amber-500/20">
                        <?php if (isset($errors['action_finding_no'])): ?><span class="mt-1 block text-sm text-red-600"><?= h($errors['action_finding_no']) ?></span><?php endif; ?>
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">Nama PIC <span class="text-red-500">*</span></span>
                        <input type="text" name="action_pic" value="<?= h($oldAction['action_pic']) ?>" placeholder="Nama orang yang melakukan action" class="mt-2 w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-base text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-amber-400 focus:bg-white focus:ring-2 focus:ring-amber-500/20">
                        <?php if (isset($errors['action_pic'])): ?><span class="mt-1 block text-sm text-red-600"><?= h($errors['action_pic']) ?></span><?php endif; ?>
                    </label>

                    <label class="block md:col-span-2">
                        <span class="text-sm font-medium text-slate-700">Action / Perbaikan <span class="text-red-500">*</span></span>
                        <textarea name="action_taken" rows="4" placeholder="Jelaskan perbaikan yang sudah dilakukan" class="mt-2 w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-base text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-amber-400 focus:bg-white focus:ring-2 focus:ring-amber-500/20"><?= h($oldAction['action_taken']) ?></textarea>
                        <?php if (isset($errors['action_taken'])): ?><span class="mt-1 block text-sm text-red-600"><?= h($errors['action_taken']) ?></span><?php endif; ?>
                    </label>
                </div>

                <div class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-3 shadow-sm" data-photo-field="action_picture">
                    <div class="mb-3">
                        <p class="text-sm font-semibold text-slate-950">Foto Hasil Perbaikan</p>
                        <p class="text-xs text-slate-500">Bisa lebih dari satu foto dan dapat diberi mark. Maksimal 20 MB/foto.</p>
                    </div>
                    <div data-gallery="action_picture" class="mb-3 grid grid-cols-2 gap-2 sm:grid-cols-3"></div>
                    <label for="action_picture" class="flex min-h-28 cursor-pointer flex-col items-center justify-center rounded-md border border-dashed border-amber-300 bg-white p-3 text-center transition hover:border-amber-500 hover:bg-amber-50">
                        <span class="grid h-12 w-12 place-items-center rounded-full bg-amber-100 text-2xl text-amber-700">+</span>
                        <span class="mt-2 text-sm font-semibold text-slate-600">Tambah foto perbaikan</span>
                        <span data-count="action_picture" class="mt-1 text-xs text-slate-400">0 foto</span>
                    </label>
                    <input id="action_picture" type="file" name="action_picture[]" accept="image/*" capture="environment" multiple class="sr-only" data-file-input="action_picture">
                    <input type="hidden" name="action_picture_marked" data-marked="action_picture">
                    <?php if (isset($errors['action_picture'])): ?><span class="mt-2 block text-sm text-red-600"><?= h($errors['action_picture']) ?></span><?php endif; ?>
                </div>

                <div class="mt-5 flex justify-end">
                    <button id="action-submit-button" type="submit" class="w-full rounded-md bg-amber-500 px-5 py-3 text-sm font-bold text-slate-950 shadow-lg shadow-amber-100 transition hover:bg-amber-400 disabled:cursor-not-allowed disabled:bg-slate-300 sm:w-auto">Simpan Jawaban &amp; Tutup Temuan</button>
                </div>
            </form>
        </section>

        <section class="mt-6 rounded-lg border border-slate-200 bg-white p-4 shadow-xl shadow-slate-200/70 sm:p-6">
            <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-lg font-bold text-slate-950">Data Audit</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Menampilkan <?= $totalAudits ? h((string)($offset + 1)) : '0' ?>-<?= h((string)min($offset + $perPage, $totalAudits)) ?> dari <?= h((string)$totalAudits) ?> data.
                    </p>
                </div>
                <a href="export_excel.php" class="inline-flex items-center justify-center rounded-md bg-emerald-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-emerald-100 transition hover:bg-emerald-700">Export XLSX + Gambar</a>
            </div>

            <?php if (!$audits): ?>
                <div class="rounded-md border border-dashed border-slate-200 bg-slate-50 p-6 text-center text-sm text-slate-500">
                    Belum ada data audit.
                </div>
            <?php else: ?>
                <div class="grid gap-4 lg:hidden">
                    <?php foreach ($audits as $audit): ?>
                        <?php $isClosed = ($audit['status'] ?? 'OPEN') === 'CLOSED'; ?>
                        <article class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                            <div class="flex items-start justify-between gap-3 border-b border-slate-100 bg-slate-50 p-4">
                                <div>
                                    <p class="font-mono text-xl font-black tracking-wider text-primary-700"><?= h((string)($audit['finding_no'] ?? '-')) ?></p>
                                    <p class="mt-1 text-xs text-slate-500"><?= h((string)$audit['date_audit']) ?> · <?= h((string)$audit['plant']) ?></p>
                                </div>
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold <?= $isClosed ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?>">
                                    <?= $isClosed ? 'Closed' : 'Open' ?>
                                </span>
                            </div>
                            <div class="grid grid-cols-2 gap-x-4 gap-y-3 p-4 text-sm">
                                <div><p class="text-xs text-slate-400">Member</p><p class="font-medium text-slate-700"><?= h((string)$audit['member']) ?></p></div>
                                <div><p class="text-xs text-slate-400">PIC Dept</p><p class="font-medium text-slate-700"><?= h((string)$audit['pic_dept']) ?></p></div>
                                <div><p class="text-xs text-slate-400">Line</p><p class="font-medium text-slate-700"><?= h((string)$audit['line']) ?></p></div>
                                <div><p class="text-xs text-slate-400">Machine</p><p class="font-medium text-slate-700"><?= h((string)$audit['machine_no']) ?></p></div>
                                <div><p class="text-xs text-slate-400">Loss Flow</p><p class="font-semibold text-slate-800"><?= h(number_format((float)$audit['loss_flow'], 2)) ?></p></div>
                                <div><p class="text-xs text-slate-400">Loss Cost</p><p class="font-semibold text-slate-800">Rp <?= h(number_format((float)$audit['loss_cost'], 0, ',', '.')) ?></p></div>
                                <div class="col-span-2"><p class="text-xs text-slate-400">Leak Location</p><p class="font-medium text-slate-700"><?= h((string)($audit['leak_location'] ?? '')) ?></p></div>
                                <div><p class="text-xs text-slate-400">CO2</p><p class="font-medium text-slate-700"><?= h(number_format((float)($audit['co2'] ?? 0), 2)) ?></p></div>
                                <div><p class="text-xs text-slate-400">Action Terakhir</p><p class="font-medium text-slate-700"><?= !empty($audit['action_taken']) ? h((string)$audit['action_taken']) : 'Belum dijawab' ?></p></div>
                            </div>
                            <details class="border-t border-slate-100 px-4 py-3">
                                <summary class="cursor-pointer text-sm font-bold text-primary-700">Lihat foto &amp; detail follow up</summary>
                                <div class="mt-3 grid grid-cols-3 gap-2">
                                    <?php foreach (['machine_picture', 'leak_location_picture', 'air_leak_image'] as $imageField): ?>
                                        <div>
                                            <p class="mb-1 text-[11px] text-slate-400"><?= h($fields[$imageField][0]) ?></p>
                                            <?php $mobilePaths = imagePaths($audit[$imageField] ?? null); ?>
                                            <?php if ($mobilePaths): ?>
                                                <a href="<?= h($mobilePaths[0]) ?>" target="_blank"><img src="<?= h($mobilePaths[0]) ?>" alt="" class="h-20 w-full rounded-md border border-slate-200 object-cover"></a>
                                            <?php else: ?>
                                                <div class="grid h-20 place-items-center rounded-md border border-dashed border-slate-200 text-xs text-slate-400">No image</div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="mt-3 rounded-md bg-slate-50 p-3 text-sm">
                                    <p class="text-xs text-slate-400">PIC Action</p>
                                    <p class="font-medium text-slate-700"><?= !empty($audit['action_pic']) ? h((string)$audit['action_pic']) : '-' ?></p>
                                    <?php $mobileActionPaths = imagePaths($audit['action_picture'] ?? null); ?>
                                    <?php if ($mobileActionPaths): ?>
                                        <a href="<?= h($mobileActionPaths[0]) ?>" target="_blank" class="mt-2 inline-flex text-xs font-bold text-primary-700">Buka foto perbaikan</a>
                                    <?php endif; ?>
                                </div>
                            </details>
                            <div class="flex flex-col gap-2 border-t border-slate-100 p-4 min-[420px]:flex-row">
                                <?php if (!empty($audit['finding_no'])): ?>
                                    <a href="?answer=<?= rawurlencode((string)$audit['finding_no']) ?>#answer-finding" class="inline-flex flex-1 items-center justify-center rounded-md border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm font-bold text-amber-700">Jawab</a>
                                <?php endif; ?>
                                <button type="button" data-edit-audit='<?= h(editAuditPayload($audit)) ?>' class="inline-flex flex-1 items-center justify-center rounded-md border border-sky-200 bg-sky-50 px-3 py-2.5 text-sm font-bold text-sky-700">Edit</button>
                                <button type="button" data-delete-audit data-audit-id="<?= h((string)$audit['id']) ?>" data-finding-no="<?= h((string)($audit['finding_no'] ?? 'Data lama')) ?>" class="inline-flex flex-1 items-center justify-center rounded-md border border-red-200 bg-red-50 px-3 py-2.5 text-sm font-bold text-red-700">Hapus</button>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="hidden overflow-x-auto lg:block">
                    <table class="min-w-[1960px] w-full border-collapse text-left text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                <th class="px-3 py-3">No. Temuan</th>
                                <th class="px-3 py-3">Status</th>
                                <th class="px-3 py-3">Date</th>
                                <th class="px-3 py-3">Member</th>
                                <th class="px-3 py-3">Plant</th>
                                <th class="px-3 py-3">Line</th>
                                <th class="px-3 py-3">Machine</th>
                                <th class="px-3 py-3">PIC</th>
                                <th class="px-3 py-3">Loss Flow</th>
                                <th class="px-3 py-3">Loss Cost</th>
                                <th class="px-3 py-3">Leak Location</th>
                                <th class="px-3 py-3">CO₂</th>
                                <th class="px-3 py-3">Machine Pict</th>
                                <th class="px-3 py-3">Leak Location</th>
                                <th class="px-3 py-3">Air Leak</th>
                                <th class="px-3 py-3">Action Terakhir</th>
                                <th class="px-3 py-3">PIC Action</th>
                                <th class="px-3 py-3">Foto Perbaikan</th>
                                <th class="px-3 py-3 text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($audits as $audit): ?>
                                <tr class="align-top hover:bg-sky-50/50">
                                    <td class="px-3 py-3">
                                        <?php if (!empty($audit['finding_no'])): ?>
                                            <div class="font-mono text-base font-black tracking-wider text-primary-700"><?= h((string)$audit['finding_no']) ?></div>
                                            <a href="?answer=<?= rawurlencode((string)$audit['finding_no']) ?>#answer-finding" class="mt-1 inline-block text-xs font-semibold text-amber-700 hover:underline">Jawab</a>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-400">Data lama</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-3">
                                        <?php $isClosed = ($audit['status'] ?? 'OPEN') === 'CLOSED'; ?>
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold <?= $isClosed ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?>">
                                            <?= $isClosed ? 'Closed' : 'Open' ?>
                                        </span>
                                    </td>
                                    <td class="px-3 py-3 font-medium text-slate-700"><?= h((string)$audit['date_audit']) ?></td>
                                    <td class="px-3 py-3 text-slate-700"><?= h((string)$audit['member']) ?></td>
                                    <td class="px-3 py-3 text-slate-700"><?= h((string)$audit['plant']) ?></td>
                                    <td class="px-3 py-3 text-slate-700"><?= h((string)$audit['line']) ?></td>
                                    <td class="px-3 py-3 text-slate-700"><?= h((string)$audit['machine_no']) ?></td>
                                    <td class="px-3 py-3 text-slate-700"><?= h((string)$audit['pic_dept']) ?></td>
                                    <td class="px-3 py-3 text-slate-700"><?= h(number_format((float)$audit['loss_flow'], 2)) ?></td>
                                    <td class="px-3 py-3 text-slate-700">Rp <?= h(number_format((float)$audit['loss_cost'], 0, ',', '.')) ?></td>
                                    <td class="max-w-xs whitespace-normal px-3 py-3 text-slate-700"><?= h((string)($audit['leak_location'] ?? '')) ?></td>
                                    <td class="px-3 py-3 text-slate-700"><?= h(number_format((float)($audit['co2'] ?? 0), 2)) ?></td>
                                    <?php foreach (['machine_picture', 'leak_location_picture', 'air_leak_image'] as $imageField): ?>
                                        <td class="px-3 py-3">
                                            <?php $paths = imagePaths($audit[$imageField] ?? null); ?>
                                            <?php if ($paths): ?>
                                                <div class="grid grid-cols-2 gap-2">
                                                    <?php foreach ($paths as $index => $path): ?>
                                                        <a href="<?= h($path) ?>" target="_blank" class="block">
                                                            <img src="<?= h($path) ?>" alt="" class="h-16 w-20 rounded-md border border-slate-200 object-cover">
                                                        </a>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-xs text-slate-400">No image</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="max-w-xs px-3 py-3 text-slate-700">
                                        <?php if (!empty($audit['action_taken'])): ?>
                                            <p class="whitespace-pre-wrap"><?= h((string)$audit['action_taken']) ?></p>
                                            <p class="mt-1 text-xs text-slate-400"><?= h((string)$audit['action_created_at']) ?></p>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-400">Belum dijawab</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-3 text-slate-700"><?= !empty($audit['action_pic']) ? h((string)$audit['action_pic']) : '<span class="text-xs text-slate-400">-</span>' ?></td>
                                    <td class="px-3 py-3">
                                        <?php $actionPaths = imagePaths($audit['action_picture'] ?? null); ?>
                                        <?php if ($actionPaths): ?>
                                            <div class="grid grid-cols-2 gap-2">
                                                <?php foreach ($actionPaths as $path): ?>
                                                    <a href="<?= h($path) ?>" target="_blank" class="block">
                                                        <img src="<?= h($path) ?>" alt="Foto hasil perbaikan" class="h-16 w-20 rounded-md border border-slate-200 object-cover">
                                                    </a>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-400">No image</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-3 text-center">
                                        <button
                                            type="button"
                                            data-edit-audit='<?= h(editAuditPayload($audit)) ?>'
                                            class="mb-2 rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs font-bold text-sky-700 transition hover:border-sky-300 hover:bg-sky-100"
                                        >Edit</button>
                                        <button
                                            type="button"
                                            data-delete-audit
                                            data-audit-id="<?= h((string)$audit['id']) ?>"
                                            data-finding-no="<?= h((string)($audit['finding_no'] ?? 'Data lama')) ?>"
                                            class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs font-bold text-red-700 transition hover:border-red-300 hover:bg-red-100"
                                        >Hapus</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="mt-4 flex flex-col gap-3 border-t border-slate-100 pt-4 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-sm text-slate-500">Page <?= h((string)$currentPage) ?> of <?= h((string)$totalPages) ?></p>
                        <div class="flex flex-wrap gap-2">
                            <a href="?page=<?= max(1, $currentPage - 1) ?>" class="<?= $currentPage <= 1 ? 'pointer-events-none opacity-40' : '' ?> rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Prev</a>
                            <?php
                                $startPage = max(1, $currentPage - 2);
                                $endPage = min($totalPages, $currentPage + 2);
                            ?>
                            <?php if ($startPage > 1): ?>
                                <a href="?page=1" class="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">1</a>
                                <?php if ($startPage > 2): ?><span class="px-2 py-2 text-sm text-slate-400">...</span><?php endif; ?>
                            <?php endif; ?>
                            <?php for ($page = $startPage; $page <= $endPage; $page++): ?>
                                <a href="?page=<?= $page ?>" class="<?= $page === $currentPage ? 'border-primary-600 bg-primary-600 text-white' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50' ?> rounded-md border px-3 py-2 text-sm font-semibold"><?= $page ?></a>
                            <?php endfor; ?>
                            <?php if ($endPage < $totalPages): ?>
                                <?php if ($endPage < $totalPages - 1): ?><span class="px-2 py-2 text-sm text-slate-400">...</span><?php endif; ?>
                                <a href="?page=<?= $totalPages ?>" class="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50"><?= $totalPages ?></a>
                            <?php endif; ?>
                            <a href="?page=<?= min($totalPages, $currentPage + 1) ?>" class="<?= $currentPage >= $totalPages ? 'pointer-events-none opacity-40' : '' ?> rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Next</a>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>

    <div id="edit-modal" class="fixed inset-0 z-[60] hidden items-center justify-center bg-slate-950/70 p-2 backdrop-blur-sm sm:p-4">
        <form method="post" id="edit-form" class="flex max-h-[calc(100dvh-1rem)] w-full max-w-4xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl sm:max-h-[calc(100dvh-2rem)]">
            <input type="hidden" name="form_type" value="edit">
            <input type="hidden" name="audit_id" id="edit-audit-id">
            <div class="flex items-start justify-between gap-4 border-b border-slate-200 p-4 sm:p-5">
                <div>
                    <p class="text-lg font-bold text-slate-950">Edit Temuan <span id="edit-finding-no" class="font-mono text-primary-700"></span></p>
                    <p class="mt-1 text-sm leading-5 text-slate-500">Foto, status, dan riwayat action tidak berubah.</p>
                </div>
                <button type="button" id="edit-modal-close" class="shrink-0 rounded-md border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Tutup</button>
            </div>
            <div class="overflow-y-auto p-4 sm:p-5">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <label class="block"><span class="text-sm font-semibold text-slate-700">Date Audit</span><input id="edit-date-audit" type="date" name="date_audit" required class="mt-2 w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-base outline-none focus:border-primary-400 focus:bg-white focus:ring-2 focus:ring-primary-500/20"></label>
                    <label class="block"><span class="text-sm font-semibold text-slate-700">Member</span><input id="edit-member" type="text" name="member" required class="mt-2 w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-base outline-none focus:border-primary-400 focus:bg-white focus:ring-2 focus:ring-primary-500/20"></label>
                    <label class="block"><span class="text-sm font-semibold text-slate-700">Plant</span><select id="edit-plant" name="plant" required class="mt-2 w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-base outline-none focus:border-primary-400 focus:bg-white focus:ring-2 focus:ring-primary-500/20"><option value="MKM-1">MKM-1</option><option value="MKM-2">MKM-2</option></select></label>
                    <label class="block"><span class="text-sm font-semibold text-slate-700">Line</span><input id="edit-line" type="text" name="line" required class="mt-2 w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-base outline-none focus:border-primary-400 focus:bg-white focus:ring-2 focus:ring-primary-500/20"></label>
                    <label class="block"><span class="text-sm font-semibold text-slate-700">Machine No</span><input id="edit-machine-no" type="text" name="machine_no" required class="mt-2 w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-base outline-none focus:border-primary-400 focus:bg-white focus:ring-2 focus:ring-primary-500/20"></label>
                    <label class="block"><span class="text-sm font-semibold text-slate-700">PIC Dept</span><input id="edit-pic-dept" type="text" name="pic_dept" required class="mt-2 w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-base outline-none focus:border-primary-400 focus:bg-white focus:ring-2 focus:ring-primary-500/20"></label>
                    <label class="block"><span class="text-sm font-semibold text-slate-700">Loss Flow (m3/min)</span><input id="edit-loss-flow" type="number" name="loss_flow" min="0" step="0.01" inputmode="decimal" required class="mt-2 w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-base outline-none focus:border-primary-400 focus:bg-white focus:ring-2 focus:ring-primary-500/20"></label>
                    <label class="block"><span class="text-sm font-semibold text-slate-700">Loss Cost (IDR)</span><input id="edit-loss-cost" type="number" name="loss_cost" min="0" step="1" inputmode="numeric" required data-loss-cost class="mt-2 w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-base outline-none focus:border-primary-400 focus:bg-white focus:ring-2 focus:ring-primary-500/20"><span class="mt-1 block text-xs text-slate-500">20 otomatis menjadi Rp20.000.</span></label>
                    <label class="block"><span class="text-sm font-semibold text-slate-700">CO2</span><input id="edit-co2" type="number" name="co2" min="0" step="0.01" inputmode="decimal" required class="mt-2 w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-base outline-none focus:border-primary-400 focus:bg-white focus:ring-2 focus:ring-primary-500/20"></label>
                    <label class="block sm:col-span-2 lg:col-span-3"><span class="text-sm font-semibold text-slate-700">Leak Location</span><input id="edit-leak-location" type="text" name="leak_location" required class="mt-2 w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-base outline-none focus:border-primary-400 focus:bg-white focus:ring-2 focus:ring-primary-500/20"></label>
                </div>
                <label class="mt-5 block rounded-lg border border-amber-200 bg-amber-50 p-3 sm:p-4">
                    <span class="text-sm font-bold text-amber-900">Password Edit</span>
                    <span class="mt-1 block text-xs text-amber-700">Gunakan password yang sama dengan Hapus.</span>
                    <input id="edit-password" type="password" name="edit_password" required autocomplete="off" placeholder="Masukkan password" class="mt-2 w-full rounded-md border border-amber-200 bg-white px-3 py-3 text-base text-slate-950 outline-none focus:border-amber-400 focus:ring-2 focus:ring-amber-500/20">
                </label>
            </div>
            <div class="flex flex-col-reverse gap-2 border-t border-slate-200 p-4 sm:flex-row sm:justify-end sm:p-5">
                <button type="button" id="edit-modal-cancel" class="rounded-md border border-slate-200 px-4 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-50">Batal</button>
                <button type="submit" id="edit-submit-button" class="rounded-md bg-primary-600 px-5 py-3 text-sm font-bold text-white shadow-lg shadow-sky-100 hover:bg-primary-700 disabled:cursor-not-allowed disabled:bg-slate-300">Simpan Perubahan</button>
            </div>
        </form>
    </div>

    <div id="delete-modal" class="fixed inset-0 z-[60] hidden items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm">
        <form method="post" id="delete-form" class="w-full max-w-md rounded-lg bg-white p-5 shadow-2xl sm:p-6">
            <input type="hidden" name="form_type" value="delete">
            <input type="hidden" name="audit_id" id="delete-audit-id">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-lg font-bold text-slate-950">Hapus Temuan</p>
                    <p class="mt-1 text-sm leading-6 text-slate-500">Temuan <span id="delete-finding-no" class="font-mono font-bold text-slate-800"></span>, jawaban, dan seluruh fotonya akan dihapus permanen.</p>
                </div>
                <button type="button" id="delete-modal-close" class="rounded-md border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Tutup</button>
            </div>
            <label class="mt-5 block">
                <span class="text-sm font-semibold text-slate-700">Password Hapus</span>
                <input id="delete-password" type="password" name="delete_password" required autocomplete="off" placeholder="Masukkan password" class="mt-2 w-full rounded-md border border-red-200 bg-red-50 px-3 py-3 text-base text-slate-950 outline-none focus:border-red-400 focus:bg-white focus:ring-2 focus:ring-red-500/20">
            </label>
            <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" id="delete-modal-cancel" class="rounded-md border border-slate-200 px-4 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-50">Batal</button>
                <button type="submit" id="delete-submit-button" class="rounded-md bg-red-600 px-4 py-3 text-sm font-bold text-white shadow-lg shadow-red-100 hover:bg-red-700 disabled:cursor-not-allowed disabled:bg-slate-300">Hapus Permanen</button>
            </div>
        </form>
    </div>

    <div id="mark-modal" class="fixed inset-0 z-50 hidden bg-slate-950/75 p-3 backdrop-blur-sm sm:p-5">
        <div class="mx-auto flex h-full max-w-5xl flex-col rounded-lg bg-white shadow-2xl">
            <div class="flex items-center justify-between gap-3 border-b border-slate-200 p-3 sm:p-4">
                <div>
                    <p id="modal-photo-label" class="text-sm font-bold text-slate-950">Mark Photo</p>
                    <p class="text-xs text-slate-500">Tambahkan pen, garis, circle, atau panah lalu simpan.</p>
                </div>
                <button type="button" id="modal-close" class="rounded-md border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Tutup</button>
            </div>
            <div class="flex min-h-0 flex-1 flex-col gap-3 p-3 sm:p-4">
                <div class="grid grid-cols-4 gap-2 sm:flex sm:flex-wrap">
                    <button type="button" data-modal-tool="pen" class="modal-tool rounded-md border border-primary-200 bg-primary-100 px-3 py-2 text-sm font-semibold text-primary-700">Pen</button>
                    <button type="button" data-modal-tool="line" class="modal-tool rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-600">Line</button>
                    <button type="button" data-modal-tool="circle" class="modal-tool rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-600">Circle</button>
                    <button type="button" data-modal-tool="arrow" class="modal-tool rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-600">Arrow</button>
                    <button type="button" data-modal-color="#ef4444" class="modal-color h-10 rounded-md border-2 border-slate-900 bg-red-500 sm:w-12" aria-label="Red marker"></button>
                    <button type="button" data-modal-color="#0284c7" class="modal-color h-10 rounded-md border border-slate-200 bg-sky-600 sm:w-12" aria-label="Blue marker"></button>
                    <button type="button" data-modal-color="#f59e0b" class="modal-color h-10 rounded-md border border-slate-200 bg-amber-500 sm:w-12" aria-label="Amber marker"></button>
                    <button type="button" id="modal-undo" class="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Undo</button>
                </div>
                <div class="min-h-0 flex-1 overflow-auto rounded-md border border-slate-200 bg-slate-100">
                    <canvas id="mark-canvas" class="mx-auto block max-w-full touch-none"></canvas>
                </div>
            </div>
            <div class="flex flex-col-reverse gap-2 border-t border-slate-200 p-3 sm:flex-row sm:justify-end sm:p-4">
                <button type="button" id="modal-discard" class="rounded-md border border-slate-200 px-4 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-50">Buang Foto</button>
                <button type="button" id="modal-save" class="rounded-md bg-primary-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-sky-100 hover:bg-primary-700">Simpan Mark</button>
            </div>
        </div>
    </div>

    <script>
        const photoFields = {};
        const modal = {
            element: document.getElementById('mark-modal'),
            canvas: document.getElementById('mark-canvas'),
            label: document.getElementById('modal-photo-label'),
            close: document.getElementById('modal-close'),
            discard: document.getElementById('modal-discard'),
            save: document.getElementById('modal-save'),
            undo: document.getElementById('modal-undo'),
            tool: 'pen',
            color: '#ef4444',
            width: 6,
            drawing: false,
            start: null,
            history: [],
            currentField: null,
            currentIndex: null,
            queue: []
        };
        modal.ctx = modal.canvas.getContext('2d', { willReadFrequently: true });

        function dataUrlFromCanvas() {
            return modal.canvas.toDataURL('image/jpeg', 0.82);
        }

        function setActiveButton(buttons, activeButton) {
            buttons.forEach((button) => {
                button.classList.remove('border-primary-200', 'bg-primary-100', 'text-primary-700', 'border-slate-900', 'border-2');
                button.classList.add('border-slate-200', 'bg-white', 'text-slate-600', 'border');
            });

            activeButton.classList.remove('border-slate-200', 'bg-white', 'text-slate-600', 'border');
            activeButton.classList.add('border-primary-200', 'bg-primary-100', 'text-primary-700');
        }

        function drawArrow(ctx, fromX, fromY, toX, toY, color, width) {
            const headLength = 22;
            const angle = Math.atan2(toY - fromY, toX - fromX);
            ctx.strokeStyle = color;
            ctx.fillStyle = color;
            ctx.lineWidth = width;
            ctx.lineCap = 'round';
            ctx.beginPath();
            ctx.moveTo(fromX, fromY);
            ctx.lineTo(toX, toY);
            ctx.stroke();
            ctx.beginPath();
            ctx.moveTo(toX, toY);
            ctx.lineTo(toX - headLength * Math.cos(angle - Math.PI / 6), toY - headLength * Math.sin(angle - Math.PI / 6));
            ctx.lineTo(toX - headLength * Math.cos(angle + Math.PI / 6), toY - headLength * Math.sin(angle + Math.PI / 6));
            ctx.closePath();
            ctx.fill();
        }

        function canvasPoint(canvas, event) {
            const rect = canvas.getBoundingClientRect();
            return {
                x: (event.clientX - rect.left) * (canvas.width / rect.width),
                y: (event.clientY - rect.top) * (canvas.height / rect.height)
            };
        }

        function redrawModal(previewShape = null) {
            modal.ctx.clearRect(0, 0, modal.canvas.width, modal.canvas.height);
            modal.history.forEach((imageData) => modal.ctx.putImageData(imageData, 0, 0));

            if (!previewShape) return;

            modal.ctx.strokeStyle = modal.color;
            modal.ctx.lineWidth = modal.width;
            modal.ctx.lineCap = 'round';
            modal.ctx.lineJoin = 'round';

            if (modal.tool === 'line') {
                modal.ctx.beginPath();
                modal.ctx.moveTo(previewShape.start.x, previewShape.start.y);
                modal.ctx.lineTo(previewShape.end.x, previewShape.end.y);
                modal.ctx.stroke();
            }

            if (modal.tool === 'circle') {
                const radiusX = Math.abs(previewShape.end.x - previewShape.start.x);
                const radiusY = Math.abs(previewShape.end.y - previewShape.start.y);
                modal.ctx.beginPath();
                modal.ctx.ellipse(previewShape.start.x, previewShape.start.y, radiusX, radiusY, 0, 0, Math.PI * 2);
                modal.ctx.stroke();
            }

            if (modal.tool === 'arrow') {
                drawArrow(modal.ctx, previewShape.start.x, previewShape.start.y, previewShape.end.x, previewShape.end.y, modal.color, modal.width);
            }
        }

        function pushModalSnapshot() {
            modal.history.push(modal.ctx.getImageData(0, 0, modal.canvas.width, modal.canvas.height));
            if (modal.history.length > 20) modal.history.shift();
        }

        function syncField(fieldName) {
            const field = photoFields[fieldName];
            field.hidden.value = JSON.stringify(field.items.map((item) => item.dataUrl));
            field.count.textContent = `${field.items.length} foto`;
        }

        function renderGallery(fieldName) {
            const field = photoFields[fieldName];
            field.gallery.innerHTML = '';

            field.items.forEach((item, index) => {
                const wrapper = document.createElement('div');
                wrapper.className = 'relative overflow-hidden rounded-md border border-slate-200 bg-white';
                wrapper.innerHTML = `
                    <button type="button" class="block w-full" data-edit-photo="${fieldName}" data-index="${index}">
                        <img src="${item.dataUrl}" alt="" class="h-24 w-full object-cover">
                    </button>
                    <button type="button" class="absolute right-1 top-1 rounded bg-white/90 px-2 py-1 text-xs font-bold text-red-600 shadow" data-remove-photo="${fieldName}" data-index="${index}">X</button>
                    <div class="px-2 py-1 text-xs text-slate-500">Foto ${index + 1}</div>
                `;
                field.gallery.appendChild(wrapper);
            });

            syncField(fieldName);
        }

        function openModal(fieldName, index, dataUrl) {
            const image = new Image();
            image.onload = () => {
                const maxSide = 1400;
                const ratio = Math.min(maxSide / image.width, maxSide / image.height, 1);
                modal.canvas.width = Math.round(image.width * ratio);
                modal.canvas.height = Math.round(image.height * ratio);
                modal.ctx.clearRect(0, 0, modal.canvas.width, modal.canvas.height);
                modal.ctx.drawImage(image, 0, 0, modal.canvas.width, modal.canvas.height);
                modal.history = [modal.ctx.getImageData(0, 0, modal.canvas.width, modal.canvas.height)];
                modal.currentField = fieldName;
                modal.currentIndex = index;
                modal.label.textContent = `${photoFields[fieldName].label} - Foto ${index + 1}`;
                modal.element.classList.remove('hidden');
                document.body.classList.add('overflow-hidden');
            };
            image.src = dataUrl;
        }

        function openNextQueuedPhoto() {
            const next = modal.queue.shift();
            if (!next) return;

            const field = photoFields[next.fieldName];
            const index = field.items.length;
            field.items.push({ dataUrl: next.dataUrl });
            renderGallery(next.fieldName);
            openModal(next.fieldName, index, next.dataUrl);
        }

        function closeModal() {
            modal.element.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
            modal.currentField = null;
            modal.currentIndex = null;
            modal.ctx.clearRect(0, 0, modal.canvas.width, modal.canvas.height);
            modal.canvas.width = 0;
            modal.canvas.height = 0;
            openNextQueuedPhoto();
        }

        function saveModalPhoto() {
            const field = photoFields[modal.currentField];
            if (!field || modal.currentIndex === null) return;

            field.items[modal.currentIndex].dataUrl = dataUrlFromCanvas();
            renderGallery(modal.currentField);
            closeModal();
        }

        function removeModalPhoto() {
            const field = photoFields[modal.currentField];
            if (field && modal.currentIndex !== null) {
                field.items.splice(modal.currentIndex, 1);
                renderGallery(modal.currentField);
            }
            closeModal();
        }

        document.querySelectorAll('[data-file-input]').forEach((input) => {
            const fieldName = input.dataset.fileInput;
            const card = document.querySelector(`[data-photo-field="${fieldName}"]`);
            photoFields[fieldName] = {
                input,
                form: input.closest('form'),
                label: card.querySelector('p').textContent.trim(),
                gallery: document.querySelector(`[data-gallery="${fieldName}"]`),
                hidden: document.querySelector(`[data-marked="${fieldName}"]`),
                count: document.querySelector(`[data-count="${fieldName}"]`),
                items: []
            };

            input.addEventListener('change', () => {
                const files = Array.from(input.files || []);
                if (!files.length) return;

                files.forEach((file) => {
                    if (!file.type.startsWith('image/')) return;
                    const reader = new FileReader();
                    reader.onload = () => {
                        modal.queue.push({ fieldName, dataUrl: reader.result });
                        if (modal.element.classList.contains('hidden')) {
                            openNextQueuedPhoto();
                        }
                    };
                    reader.readAsDataURL(file);
                });
                input.value = '';
            });
        });

        document.addEventListener('click', (event) => {
            const editButton = event.target.closest('[data-edit-photo]');
            if (editButton) {
                const fieldName = editButton.dataset.editPhoto;
                const index = Number(editButton.dataset.index);
                openModal(fieldName, index, photoFields[fieldName].items[index].dataUrl);
            }

            const removeButton = event.target.closest('[data-remove-photo]');
            if (removeButton) {
                const fieldName = removeButton.dataset.removePhoto;
                const index = Number(removeButton.dataset.index);
                photoFields[fieldName].items.splice(index, 1);
                renderGallery(fieldName);
            }
        });

        document.querySelectorAll('.modal-tool').forEach((button) => {
            button.addEventListener('click', () => {
                modal.tool = button.dataset.modalTool;
                setActiveButton(document.querySelectorAll('.modal-tool'), button);
            });
        });

        document.querySelectorAll('.modal-color').forEach((button) => {
            button.addEventListener('click', () => {
                modal.color = button.dataset.modalColor;
                document.querySelectorAll('.modal-color').forEach((item) => {
                    item.classList.remove('border-slate-900', 'border-2');
                    item.classList.add('border', 'border-slate-200');
                });
                button.classList.remove('border', 'border-slate-200');
                button.classList.add('border-2', 'border-slate-900');
            });
        });

        modal.canvas.addEventListener('pointerdown', (event) => {
            if (!modal.canvas.width) return;
            event.preventDefault();
            modal.drawing = true;
            modal.start = canvasPoint(modal.canvas, event);

            if (modal.tool === 'pen') {
                modal.ctx.strokeStyle = modal.color;
                modal.ctx.lineWidth = modal.width;
                modal.ctx.lineCap = 'round';
                modal.ctx.lineJoin = 'round';
                modal.ctx.beginPath();
                modal.ctx.moveTo(modal.start.x, modal.start.y);
            }
        });

        modal.canvas.addEventListener('pointermove', (event) => {
            if (!modal.drawing) return;
            event.preventDefault();
            const point = canvasPoint(modal.canvas, event);

            if (modal.tool === 'pen') {
                modal.ctx.lineTo(point.x, point.y);
                modal.ctx.stroke();
                return;
            }

            redrawModal({ start: modal.start, end: point });
        });

        function endModalDraw(event) {
            if (!modal.drawing) return;
            event.preventDefault();
            modal.drawing = false;
            pushModalSnapshot();
        }

        modal.canvas.addEventListener('pointerup', endModalDraw);
        modal.canvas.addEventListener('pointerleave', endModalDraw);
        modal.undo.addEventListener('click', () => {
            if (modal.history.length <= 1) return;
            modal.history.pop();
            modal.ctx.putImageData(modal.history[modal.history.length - 1], 0, 0);
        });
        modal.save.addEventListener('click', saveModalPhoto);
        modal.discard.addEventListener('click', removeModalPhoto);
        modal.close.addEventListener('click', saveModalPhoto);

        document.getElementById('audit-form').addEventListener('reset', () => {
            setTimeout(() => {
                Object.keys(photoFields).forEach((fieldName) => {
                    if (photoFields[fieldName].form?.id !== 'audit-form') return;
                    photoFields[fieldName].items = [];
                    renderGallery(fieldName);
                });
                modal.queue = [];
                if (!modal.element.classList.contains('hidden')) {
                    modal.element.classList.add('hidden');
                    document.body.classList.remove('overflow-hidden');
                }
            }, 0);
        });

        function normalizeLossCostInput(input) {
            const value = Number(input.value);
            if (Number.isFinite(value) && value >= 1 && value < 1000) {
                input.value = String(Math.round(value * 1000));
            }
        }

        document.querySelectorAll('[data-loss-cost]').forEach((input) => {
            input.addEventListener('blur', () => normalizeLossCostInput(input));
        });

        document.getElementById('audit-form').addEventListener('submit', () => {
            normalizeLossCostInput(document.querySelector('#audit-form [data-loss-cost]'));
            const submitButton = document.getElementById('submit-button');
            submitButton.disabled = true;
            submitButton.textContent = 'Menyimpan...';
        });

        document.querySelector('input[name="finding_no"]')?.addEventListener('input', (event) => {
            event.target.value = event.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 4);
        });

        document.getElementById('action-form').addEventListener('submit', () => {
            const submitButton = document.getElementById('action-submit-button');
            submitButton.disabled = true;
            submitButton.textContent = 'Menyimpan...';
        });

        const editModal = document.getElementById('edit-modal');
        const editForm = document.getElementById('edit-form');
        const editPassword = document.getElementById('edit-password');
        const editFieldIds = {
            date_audit: 'edit-date-audit',
            member: 'edit-member',
            plant: 'edit-plant',
            line: 'edit-line',
            machine_no: 'edit-machine-no',
            pic_dept: 'edit-pic-dept',
            loss_flow: 'edit-loss-flow',
            loss_cost: 'edit-loss-cost',
            leak_location: 'edit-leak-location',
            co2: 'edit-co2'
        };

        function closeEditModal() {
            editModal.classList.add('hidden');
            editModal.classList.remove('flex');
            editForm.reset();
            document.getElementById('edit-audit-id').value = '';
            document.body.classList.remove('overflow-hidden');
        }

        document.querySelectorAll('[data-edit-audit]').forEach((button) => {
            button.addEventListener('click', () => {
                let audit;
                try {
                    audit = JSON.parse(button.dataset.editAudit);
                } catch (error) {
                    window.alert('Data edit tidak dapat dibuka. Silakan refresh halaman.');
                    return;
                }

                document.getElementById('edit-audit-id').value = audit.id;
                document.getElementById('edit-finding-no').textContent = audit.finding_no || '-';
                Object.entries(editFieldIds).forEach(([field, id]) => {
                    document.getElementById(id).value = audit[field] ?? '';
                });
                editPassword.value = '';
                editModal.classList.remove('hidden');
                editModal.classList.add('flex');
                document.body.classList.add('overflow-hidden');
                window.setTimeout(() => document.getElementById('edit-member').focus(), 50);
            });
        });

        document.getElementById('edit-modal-close').addEventListener('click', closeEditModal);
        document.getElementById('edit-modal-cancel').addEventListener('click', closeEditModal);
        editModal.addEventListener('click', (event) => {
            if (event.target === editModal) closeEditModal();
        });
        editForm.addEventListener('submit', () => {
            normalizeLossCostInput(document.getElementById('edit-loss-cost'));
            const submitButton = document.getElementById('edit-submit-button');
            submitButton.disabled = true;
            submitButton.textContent = 'Menyimpan...';
        });

        const deleteModal = document.getElementById('delete-modal');
        const deleteForm = document.getElementById('delete-form');
        const deleteAuditId = document.getElementById('delete-audit-id');
        const deleteFindingNo = document.getElementById('delete-finding-no');
        const deletePassword = document.getElementById('delete-password');

        function closeDeleteModal() {
            deleteModal.classList.add('hidden');
            deleteModal.classList.remove('flex');
            deleteForm.reset();
            deleteAuditId.value = '';
            document.body.classList.remove('overflow-hidden');
        }

        document.querySelectorAll('[data-delete-audit]').forEach((button) => {
            button.addEventListener('click', () => {
                deleteAuditId.value = button.dataset.auditId;
                deleteFindingNo.textContent = button.dataset.findingNo || '-';
                deleteModal.classList.remove('hidden');
                deleteModal.classList.add('flex');
                document.body.classList.add('overflow-hidden');
                window.setTimeout(() => deletePassword.focus(), 50);
            });
        });

        document.getElementById('delete-modal-close').addEventListener('click', closeDeleteModal);
        document.getElementById('delete-modal-cancel').addEventListener('click', closeDeleteModal);
        deleteModal.addEventListener('click', (event) => {
            if (event.target === deleteModal) closeDeleteModal();
        });
        deleteForm.addEventListener('submit', () => {
            const submitButton = document.getElementById('delete-submit-button');
            submitButton.disabled = true;
            submitButton.textContent = 'Menghapus...';
        });

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            if (!editModal.classList.contains('hidden')) closeEditModal();
            if (!deleteModal.classList.contains('hidden')) closeDeleteModal();
        });
    </script>
</body>
</html>
