<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/GeoCatalog.php';
foreach (['ConnectionDiscovery', 'ProjectInstallation', 'ReleaseImporter', 'Installer'] as $class) {
    require_once __DIR__ . '/../src/Installer/' . $class . '.php';
}

use Kighlander\GeoPlzDetails\GeoCatalog;
use Kighlander\GeoPlzDetails\Installer\Installer;

function ensure(bool $truth, string $message): void
{
    if (!$truth) {
        throw new RuntimeException('Installer integration failure: ' . $message);
    }
}

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "[SKIP] Installer SQLite integration: pdo_sqlite unavailable\n");
    exit(0);
}
$release = $argv[1] ?? throw new RuntimeException('Expected synthetic release path.');
$project = $argv[2] ?? throw new RuntimeException('Expected fresh test project path.');
if (!mkdir($project, 0700, true) && !is_dir($project)) {
    throw new RuntimeException('Cannot create synthetic test project.');
}
$plan = Installer::plan($project, $release, false);
ensure($plan['mode'] === 'sqlite_fallback', 'No database -> SQLite fallback');
$first = Installer::execute($plan);
ensure($first['status'] === 'CREATED', 'SQLite installation created');
$catalog = GeoCatalog::fromProject($project);
ensure(count($catalog->municipalitiesByPostalCode('49086')) === 2, 'Two municipalities per PLZ');
ensure(count($catalog->subdivisionsByPlace('49086', '03404000')) === 3, 'Three subdivisions');
$ost = $catalog->resolveLocation('49086', '03404000', 'osm:r:2');
ensure($ost['display_name'] === 'Luestringen-Mitte', 'Inherited display name');
ensure($ost['coordinate_level'] === 'subdivision', 'Own coordinate preferred');
$west = $catalog->resolveLocation('49086', '03404000', 'osm:r:3');
ensure($west['coordinate_level'] === 'ancestor_subdivision', 'Parent coordinate inherited');
ensure($catalog->check()['valid'] === true, 'GEO catalog integrity');
$again = Installer::execute($plan);
ensure($again['status'] === 'REUSED', 'Same installation safely reused');

$appProject = dirname($project) . '/existing-app';
mkdir($appProject . '/database', 0700, true);
$appDb = $appProject . '/database/database.sqlite';
$db = new PDO('sqlite:' . $appDb);
$db->exec('CREATE TABLE application_users (id INTEGER PRIMARY KEY, name TEXT)');
$db->exec("INSERT INTO application_users VALUES (1, 'preserve me')");
$db = null;
file_put_contents($appProject . '/.env', "DB_CONNECTION=sqlite\nDB_DATABASE=" . $appDb . "\n");
$appPlan = Installer::plan($appProject, $release, false);
ensure($appPlan['mode'] === 'existing_database', 'Existing app DB discovered');
$result = Installer::execute($appPlan);
ensure($result['status'] === 'CREATED', 'Schema installed in configured app DB');
$appDbHandle = new PDO('sqlite:' . $appDb);
ensure($appDbHandle->query('SELECT name FROM application_users WHERE id=1')->fetchColumn() === 'preserve me', 'Application data untouched');
ensure($appDbHandle->query('SELECT COUNT(*) FROM geo_plz_places')->fetchColumn() === 2, 'Namespaced data imported');
ensure(GeoCatalog::fromProject($appProject)->check()['valid'] === true, 'App DB catalog integrity');
$again = Installer::execute($appPlan);
ensure($again['status'] === 'REUSED', 'Configured app DB safely reused');
echo "[PASS] SQLite fallback, existing app DB, no-table-overwrite, exact/ancestor coordinates, reuse\n";
