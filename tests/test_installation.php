<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Installer/ReleaseImporter.php';
require_once __DIR__ . '/../src/Installer/ConnectionDiscovery.php';
require_once __DIR__ . '/../src/Installer/ProjectInstallation.php';
require_once __DIR__ . '/../src/Installer/Installer.php';

use Kighlander\GeoPlzDetails\Installer\Installer;

$release = $argv[1] ?? null;
$project = $argv[2] ?? null;
if ($release === null || !is_dir($release) || $project === null) {
    throw new RuntimeException('Expected synthetic legacy release and project paths.');
}
if (!is_dir($project) && !mkdir($project, 0700, true) && !is_dir($project)) {
    throw new RuntimeException('Cannot create isolated synthetic project.');
}
try {
    Installer::plan($project, $release, false);
} catch (RuntimeException $error) {
    echo "[PASS] Unsigned legacy installation plan denied before any DB access\n";
    exit(0);
}
throw new RuntimeException('Unsigned legacy install was incorrectly accepted.');
