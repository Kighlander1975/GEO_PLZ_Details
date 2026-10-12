<?php

declare(strict_types=1);

require __DIR__ . '/../src/Installer/ConnectionDiscovery.php';
require __DIR__ . '/../src/Installer/ProjectInstallation.php';
require __DIR__ . '/../src/Installer/ReleaseImporter.php';
require __DIR__ . '/../src/Installer/Installer.php';

use Kighlander\GeoPlzDetails\Installer\ConnectionDiscovery;
use Kighlander\GeoPlzDetails\Installer\ProjectInstallation;
use Kighlander\GeoPlzDetails\Installer\ReleaseImporter;

function expect(bool $ok, string $why): void
{
    if (!$ok) {
        throw new RuntimeException('Installer detection test: ' . $why);
    }
}

$root = sys_get_temp_dir() . '/geo-plz-details-detect-' . bin2hex(random_bytes(8));
mkdir($root, 0700, true);
try {
    expect(ConnectionDiscovery::detect($root) === null, 'No database configured');

    file_put_contents($root . '/.env', "DB_CONNECTION=mysql\nDB_HOST=localhost\nDB_DATABASE=my_app\nDB_USERNAME=test\nDB_PASSWORD=\"has # space\"\n");
    $mysql = ConnectionDiscovery::detect($root);
    expect(($mysql['driver'] ?? '') === 'mysql', 'MySQL chosen from .env');
    expect(($mysql['database'] ?? '') === 'my_app', 'Database chosen');
    expect(($mysql['password'] ?? '') === 'has # space', 'Password parsing');

    file_put_contents($root . '/.env', "DB_URL=mysql://u:p%40ss@db.example.test:3336/exampledb\n");
    $url = ConnectionDiscovery::detect($root);
    expect(($url['database'] ?? '') === 'exampledb', 'DB_URL db');
    expect(($url['password'] ?? '') === 'p@ss', 'DB_URL credentials decoded');
    expect(($url['host'] ?? '') === 'db.example.test', 'DB_URL host');

    mkdir($root . '/database');
    file_put_contents($root . '/database/database.sqlite', 'fake sqlite test placeholder');
    file_put_contents($root . '/.env', "DB_CONNECTION=sqlite\n");
    $sqlite = ConnectionDiscovery::detect($root);
    expect(($sqlite['driver'] ?? '') === 'sqlite', 'Existing Laravel default SQLite detected');
    expect(($sqlite['database'] ?? '') === $root . '/database/database.sqlite', 'Existing SQLite path');

    mkdir($root . '/bootstrap/cache', 0700, true);
    $cached = '<?php return ' . var_export([
        'database' => [
            'default' => 'mysql',
            'connections' => ['mysql' => [
                'driver' => 'mariadb', 'host' => '127.0.0.1', 'port' => 3306,
                'database' => 'from_cache', 'username' => 'test', 'password' => 'secret',
            ]],
        ],
    ], true) . ';';
    file_put_contents($root . '/bootstrap/cache/config.php', $cached);
    $cache = ConnectionDiscovery::detect($root);
    expect(($cache['database'] ?? '') === 'from_cache', 'Laravel cached database prioritized');
    expect(($cache['driver'] ?? '') === 'mariadb', 'MariaDB cached driver');

    putenv('GEO_PLZ_DSN=mysql:host=127.0.0.1;port=3307;dbname=generic_app');
    putenv('GEO_PLZ_USERNAME=test');
    putenv('GEO_PLZ_PASSWORD=test-secret');
    $dsn = ConnectionDiscovery::detect($root);
    expect(($dsn['database'] ?? '') === 'generic_app', 'Explicit generic DSN overrides Laravel');
    expect(($dsn['port'] ?? 0) === 3307, 'Generic DSN port');
    putenv('GEO_PLZ_DSN');
    putenv('GEO_PLZ_USERNAME');
    putenv('GEO_PLZ_PASSWORD');

    expect(str_ends_with(ProjectInstallation::dataDirectory($root), '/.geo-plz-details'), 'Generic private directory');
    mkdir($root . '/storage/app/private', 0700, true);
    expect(str_ends_with(ProjectInstallation::dataDirectory($root), '/storage/app/private/geo-plz-details'), 'Laravel private directory');

    foreach (['geo_plz_', 'g_01'] as $good) {
        expect(ReleaseImporter::validatePrefix($good) === $good, 'Valid SQL prefix');
    }
    foreach (['geo-plz-details_', 'geo_plz_;DROP TABLE users', ''] as $bad) {
        try {
            ReleaseImporter::validatePrefix($bad);
            throw new RuntimeException('Unsafe SQL prefix was accepted.');
        } catch (RuntimeException $expected) {
            if ($expected->getMessage() === 'Unsafe SQL prefix was accepted.') {
                throw $expected;
            }
        }
    }
    echo "[PASS] .env, DB_URL, cached Laravel config, PDO DSN, SQLite detection, private paths, safe SQL prefix\n";
} finally {
    putenv('GEO_PLZ_DSN');
    putenv('GEO_PLZ_USERNAME');
    putenv('GEO_PLZ_PASSWORD');
    // Delete only this test's private, newly-created temporary files.
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}
