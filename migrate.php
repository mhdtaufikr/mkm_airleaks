<?php

declare(strict_types=1);

require __DIR__ . '/config/database.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = db(null);
    $files = glob(__DIR__ . '/migrations/*.sql');

    if (!$files) {
        throw new RuntimeException('Migration files not found.');
    }

    sort($files);

    foreach ($files as $file) {
        $migration = file_get_contents($file);

        if ($migration === false) {
            throw new RuntimeException('Migration file cannot be read: ' . basename($file));
        }

        $pdo->exec($migration);
        echo 'Migrated: ' . basename($file) . "\n";
    }

    echo "Migration success.\n";
    echo "Database: " . DB_NAME . "\n";
    echo "Table: air_leak_audits\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "Migration failed: " . $e->getMessage() . "\n";
}
