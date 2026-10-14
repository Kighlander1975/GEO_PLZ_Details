<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/GeoCatalog.php';

use Kighlander\GeoPlzDetails\GeoCatalog;

$database = $argv[1] ?? null;
if ($database === null || !is_file($database)) {
    throw new RuntimeException('Expected existing synthetic SQLite fixture.');
}
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "[SKIP] SQLite test: pdo_sqlite unavailable\n");
    exit(0);
}
try {
    new GeoCatalog($database);
} catch (RuntimeException $error) {
    echo "[PASS] Unsigned legacy SQLite catalog denied by signed runtime gate\n";
    exit(0);
}
throw new RuntimeException('Unsigned legacy SQLite data was incorrectly accepted.');
