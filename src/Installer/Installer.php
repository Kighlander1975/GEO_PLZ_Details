<?php

declare(strict_types=1);

namespace Kighlander\GeoPlzDetails\Installer;

use PDO;
use RuntimeException;

/** Explicit, non-destructive installer for an application's configured DB or isolated SQLite. */
final class Installer
{
    /** @return array<string,mixed> */
    public static function plan(string $project, string $release, bool $forceSqlite): array
    {
        if (!is_dir($project)) {
            throw new RuntimeException('Project directory not found: ' . $project);
        }
        $root = (string) realpath($project);
        $source = ReleaseImporter::source($release);
        $configured = $forceSqlite ? null : ConnectionDiscovery::detect($root);
        $mode = $configured === null ? 'sqlite_fallback' : 'existing_database';
        $driver = $configured['driver'] ?? 'sqlite';
        $location = $configured === null
            ? ProjectInstallation::dataDirectory($root) . '/geo-plz-details.sqlite'
            : ($driver === 'sqlite'
                ? (string) $configured['database']
                : (string) $configured['database'] . ' @ ' . $configured['host']);
        // Credentials are never included in the returned plan.
        return [
            'project' => $root,
            'mode' => $mode,
            'driver' => $driver,
            'target' => $location,
            'table_prefix' => 'geo_plz_',
            'dataset_version' => $source['version'],
            'release_fingerprint' => $source['fingerprint'],
            'release_path' => (string) realpath($release),
            'configured' => $configured,
            'source' => $source,
        ];
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    public static function execute(array $plan): array
    {
        $root = $plan['project'];
        $folder = ProjectInstallation::dataDirectory($root);
        $configPath = ProjectInstallation::configPath($root);
        $existingConfig = is_file($configPath) ? ProjectInstallation::load($root) : null;
        if ($existingConfig !== null) {
            if (($existingConfig['mode'] ?? null) !== $plan['mode']
                || ($existingConfig['driver'] ?? null) !== $plan['driver']
                || ($existingConfig['release_fingerprint'] ?? null) !== $plan['source']['fingerprint']
                || ($existingConfig['table_prefix'] ?? null) !== 'geo_plz_'
                || ($existingConfig['connection_identity'] ?? null) !== ConnectionDiscovery::connectionIdentity(
                    $plan['mode'] === 'sqlite_fallback'
                        ? ['driver' => 'sqlite', 'database' => $folder . '/geo-plz-details.sqlite']
                        : $plan['configured']
                )) {
                throw new RuntimeException('Different GEO installation already configured; no automatic replacement.');
            }
        }
        if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
            throw new RuntimeException('Cannot create GEO private data directory.');
        }
        // Prevent accidentally committing fallback DBs in a generic project.
        $gitIgnore = $folder . '/.gitignore';
        if (!file_exists($gitIgnore)) {
            file_put_contents($gitIgnore, "*\n!.gitignore\n", LOCK_EX);
        }
        $newSqlite = false;
        if ($plan['mode'] === 'sqlite_fallback') {
            if (!extension_loaded('pdo_sqlite')) {
                throw new RuntimeException('SQLite fallback requires PHP extension pdo_sqlite.');
            }
            $file = $folder . '/geo-plz-details.sqlite';
            if (!file_exists($file)) {
                $handle = fopen($file, 'x');
                if ($handle === false) {
                    throw new RuntimeException('Cannot exclusively create SQLite fallback.');
                }
                fclose($handle);
                @chmod($file, 0600);
                $newSqlite = true;
            }
            $connection = ['driver' => 'sqlite', 'database' => $file];
        } else {
            $connection = $plan['configured'];
        }
        try {
            $db = ConnectionDiscovery::connect($connection);
            $result = ReleaseImporter::install(
                $db,
                'geo_plz_',
                (string) $plan['release_path'],
                $plan['source'],
            );
            if ($existingConfig === null) {
                ProjectInstallation::saveNew($root, [
                    'format_version' => 1,
                    'mode' => $plan['mode'],
                    'driver' => $plan['driver'],
                    'table_prefix' => 'geo_plz_',
                    'dataset_version' => $plan['source']['version'],
                    'release_fingerprint' => $plan['source']['fingerprint'],
                    'connection_identity' => ConnectionDiscovery::connectionIdentity($connection),
                    'formal_postal_c_acceptance' => false,
                ]);
            }
            return $result + [
                'mode' => $plan['mode'],
                'driver' => $plan['driver'],
                'target' => $plan['target'],
                'configuration' => $configPath,
            ];
        } catch (\Throwable $error) {
            if ($newSqlite && file_exists($folder . '/geo-plz-details.sqlite')) {
                // The new SQLite file was created by this exact run only.
                @unlink($folder . '/geo-plz-details.sqlite');
            }
            throw $error;
        }
    }
}
