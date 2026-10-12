<?php

declare(strict_types=1);

namespace Kighlander\GeoPlzDetails\Installer;

use PDO;
use RuntimeException;

/** Offline importer. Does not call the network or read live OSM sources. */
final class ReleaseImporter
{
    private const DATASETS = [
        'municipalities' => ['country_code', 'ags', 'ars', 'name', 'type_code', 'type_label',
            'admin_postal_code', 'centroid_latitude', 'centroid_longitude', 'centroid_source_date'],
        'places' => ['ags', 'postal_code', 'latitude', 'longitude', 'relation_source',
            'coordinate_source', 'coordinate_method', 'coordinate_accuracy'],
        'subdivisions' => ['ags', 'type', 'name'],
        'place-subdivisions' => ['ags', 'postal_code', 'subdivision_type', 'subdivision_name'],
    ];
    private const TABLES = ['metadata', 'municipalities', 'places', 'subdivisions', 'place_subdivisions'];

    /** @return array<string,mixed> */
    public static function source(string $releaseDirectory): array
    {
        $releaseDirectory = rtrim($releaseDirectory, '/\\');
        $hashes = [];
        foreach (self::DATASETS as $name => $_) {
            $file = $releaseDirectory . '/' . $name . '.csv.gz';
            if (!is_file($file)) {
                throw new RuntimeException('Missing GEO release file: ' . $file);
            }
            $hashes[$name . '.csv.gz'] = strtoupper((string) hash_file('sha256', $file));
        }
        if (is_file($releaseDirectory . '/manifest.json')) {
            $hashes['manifest.json'] = strtoupper((string) hash_file('sha256', $releaseDirectory . '/manifest.json'));
        }
        ksort($hashes);
        return [
            'version' => basename($releaseDirectory),
            'hashes' => $hashes,
            'fingerprint' => strtoupper(hash('sha256', (string) json_encode($hashes, JSON_THROW_ON_ERROR))),
        ];
    }

    /** @return \Generator<int,array<string,string|null>> */
    private static function rows(string $release, string $dataset): \Generator
    {
        $file = rtrim($release, '/\\') . '/' . $dataset . '.csv.gz';
        $stream = fopen('compress.zlib://' . $file, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Cannot read compressed release: ' . $file);
        }
        try {
            $header = fgetcsv($stream, 0, ',', '"', '');
            if (!is_array($header)) {
                throw new RuntimeException('Empty release: ' . $file);
            }
            if (isset($header[0])) {
                $header[0] = ltrim($header[0], "\xEF\xBB\xBF");
            }
            foreach (self::DATASETS[$dataset] as $required) {
                if (!in_array($required, $header, true)) {
                    throw new RuntimeException('Required column ' . $required . ' missing: ' . $file);
                }
            }
            $line = 1;
            while (($values = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                $line++;
                if ($values === [null]) {
                    continue;
                }
                if (count($values) !== count($header)) {
                    throw new RuntimeException('Wrong CSV field count: ' . $file . ':' . $line);
                }
                $row = array_combine($header, $values);
                if (!is_array($row)) {
                    throw new RuntimeException('Invalid CSV row: ' . $file . ':' . $line);
                }
                yield $row;
            }
        } finally {
            fclose($stream);
        }
    }

    /** @param array<string,string|null> $row */
    private static function value(array $row, string $field): ?string
    {
        $value = trim((string) ($row[$field] ?? ''));
        return $value !== '' ? $value : null;
    }

    private static function ags(?string $value): string
    {
        if ($value === null || preg_match('/\A[0-9]{8}\z/D', $value) !== 1) {
            throw new RuntimeException('Invalid AGS in GEO release.');
        }
        return $value;
    }

    private static function postal(?string $value, bool $optional = false): ?string
    {
        if ($optional && $value === null) {
            return null;
        }
        if ($value === null || preg_match('/\A[0-9]{5}\z/D', $value) !== 1) {
            throw new RuntimeException('Invalid postal code in GEO release.');
        }
        return $value;
    }

    /** @param array<string,string|null> $row @return array{0:?float,1:?float} */
    private static function coordinate(array $row, string $latitudeColumn, string $longitudeColumn, bool $required = false): array
    {
        $lat = self::value($row, $latitudeColumn);
        $lon = self::value($row, $longitudeColumn);
        if (($lat === null) !== ($lon === null) || ($required && $lat === null)) {
            throw new RuntimeException('Missing or incomplete WGS84 coordinate pair.');
        }
        if ($lat === null) {
            return [null, null];
        }
        if (!is_numeric($lat) || !is_numeric($lon)) {
            throw new RuntimeException('Non-numeric WGS84 coordinates.');
        }
        $latitude = (float) $lat;
        $longitude = (float) $lon;
        if (!is_finite($latitude) || !is_finite($longitude)
            || abs($latitude) > 90 || abs($longitude) > 180) {
            throw new RuntimeException('Out-of-range WGS84 coordinates.');
        }
        return [$latitude, $longitude];
    }

    public static function validatePrefix(string $prefix): string
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,30}\z/D', $prefix) !== 1) {
            throw new RuntimeException('Unsafe database table prefix.');
        }
        return $prefix;
    }

    private static function table(string $prefix, string $name): string
    {
        return $prefix . $name;
    }

    private static function tableExists(PDO $db, string $table): bool
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
        } else {
            $stmt = $db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        }
        $stmt->execute([$table]);
        return $stmt->fetchColumn() !== false;
    }

    /** @param array<string,mixed> $source @return array<string,mixed>|null */
    public static function alreadyInstalled(PDO $db, string $prefix, array $source): ?array
    {
        $present = [];
        foreach (self::TABLES as $name) {
            $present[] = self::tableExists($db, self::table($prefix, $name));
        }
        if (!in_array(true, $present, true)) {
            return null;
        }
        if (in_array(false, $present, true)) {
            throw new RuntimeException('Partial GEO table set exists; refusing to overwrite existing application tables.');
        }
        $metadata = [];
        foreach ($db->query('SELECT `key`,`value` FROM ' . $prefix . 'metadata') as $row) {
            $metadata[$row['key']] = $row['value'];
        }
        if (($metadata['format_version'] ?? '') !== '2'
            || ($metadata['release_fingerprint'] ?? '') !== $source['fingerprint']) {
            throw new RuntimeException('Existing GEO tables have an unknown or different dataset. No automatic overwrite.');
        }
        return ['status' => 'REUSED', 'dataset_version' => $source['version'], 'table_prefix' => $prefix];
    }

    /** @return list<string> */
    private static function createSql(string $driver, string $prefix): array
    {
        $m = $prefix . 'metadata';
        $u = $prefix . 'municipalities';
        $p = $prefix . 'places';
        $s = $prefix . 'subdivisions';
        $x = $prefix . 'place_subdivisions';
        if ($driver === 'sqlite') {
            return [
                "CREATE TABLE $m (key TEXT PRIMARY KEY, value TEXT NOT NULL)",
                "CREATE TABLE $u (ags TEXT PRIMARY KEY, country_code TEXT NOT NULL, ars TEXT NOT NULL UNIQUE, name TEXT NOT NULL, display_name TEXT, type_code TEXT NOT NULL, type_label TEXT NOT NULL, admin_postal_code TEXT, centroid_latitude REAL, centroid_longitude REAL, centroid_source_date TEXT)",
                "CREATE TABLE $p (id INTEGER PRIMARY KEY AUTOINCREMENT, ags TEXT NOT NULL REFERENCES $u(ags), postal_code TEXT NOT NULL, latitude REAL NOT NULL, longitude REAL NOT NULL, relation_source TEXT NOT NULL, coordinate_source TEXT NOT NULL, coordinate_method TEXT NOT NULL, coordinate_accuracy INTEGER, UNIQUE(ags,postal_code))",
                "CREATE INDEX {$prefix}idx_postal ON $p(postal_code,ags)",
                "CREATE TABLE $s (subdivision_key TEXT PRIMARY KEY, ags TEXT NOT NULL REFERENCES $u(ags), type TEXT NOT NULL, name TEXT NOT NULL, parent_subdivision_key TEXT REFERENCES $s(subdivision_key), display_name TEXT, latitude REAL, longitude REAL, coordinate_source TEXT, coordinate_reference TEXT, coordinate_method TEXT, coordinate_quality TEXT, hierarchy_method TEXT, administrative_level INTEGER, place_type TEXT, source TEXT, source_reference TEXT)",
                "CREATE INDEX {$prefix}idx_subdivisions ON $s(ags,name)",
                "CREATE TABLE $x (place_id INTEGER NOT NULL REFERENCES $p(id), subdivision_key TEXT NOT NULL REFERENCES $s(subdivision_key), PRIMARY KEY(place_id,subdivision_key))",
            ];
        }
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            return [
                "CREATE TABLE $m (`key` VARCHAR(191) PRIMARY KEY, `value` TEXT NOT NULL)$tail",
                "CREATE TABLE $u (ags CHAR(8) PRIMARY KEY, country_code CHAR(2) NOT NULL, ars CHAR(12) NOT NULL UNIQUE, name VARCHAR(255) NOT NULL, display_name VARCHAR(255) NULL, type_code CHAR(2) NOT NULL, type_label VARCHAR(64) NOT NULL, admin_postal_code CHAR(5) NULL, centroid_latitude DOUBLE NULL, centroid_longitude DOUBLE NULL, centroid_source_date DATE NULL)$tail",
                "CREATE TABLE $p (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, ags CHAR(8) NOT NULL, postal_code CHAR(5) NOT NULL, latitude DOUBLE NOT NULL, longitude DOUBLE NOT NULL, relation_source VARCHAR(32) NOT NULL, coordinate_source VARCHAR(32) NOT NULL, coordinate_method VARCHAR(32) NOT NULL, coordinate_accuracy INT NULL, UNIQUE KEY uq_ags_postal (ags,postal_code), INDEX idx_postal (postal_code,ags), FOREIGN KEY (ags) REFERENCES $u(ags))$tail",
                "CREATE TABLE $s (subdivision_key VARCHAR(128) PRIMARY KEY, ags CHAR(8) NOT NULL, type VARCHAR(32) NOT NULL, name VARCHAR(255) NOT NULL, parent_subdivision_key VARCHAR(128) NULL, display_name VARCHAR(255) NULL, latitude DOUBLE NULL, longitude DOUBLE NULL, coordinate_source VARCHAR(32) NULL, coordinate_reference VARCHAR(96) NULL, coordinate_method VARCHAR(32) NULL, coordinate_quality VARCHAR(32) NULL, hierarchy_method VARCHAR(32) NULL, administrative_level INT NULL, place_type VARCHAR(32) NULL, source VARCHAR(32) NULL, source_reference VARCHAR(96) NULL, INDEX idx_subdivisions (ags,name), FOREIGN KEY (ags) REFERENCES $u(ags), FOREIGN KEY (parent_subdivision_key) REFERENCES $s(subdivision_key))$tail",
                "CREATE TABLE $x (place_id BIGINT UNSIGNED NOT NULL, subdivision_key VARCHAR(128) NOT NULL, PRIMARY KEY (place_id,subdivision_key), FOREIGN KEY (place_id) REFERENCES $p(id), FOREIGN KEY (subdivision_key) REFERENCES $s(subdivision_key))$tail",
            ];
        }
        throw new RuntimeException('Unsupported database driver for schema.');
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    public static function install(PDO $db, string $prefix, string $release, array $source): array
    {
        self::validatePrefix($prefix);
        $reused = self::alreadyInstalled($db, $prefix, $source);
        if ($reused !== null) {
            return $reused;
        }
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $created = [];
        try {
            foreach (self::createSql($driver, $prefix) as $sql) {
                $db->exec($sql);
                if (preg_match('/\ACREATE TABLE\s+([a-z0-9_]+)/i', $sql, $match) === 1) {
                    $created[] = $match[1];
                }
            }
            $db->beginTransaction();
            $metadata = [
                'format_version' => '2',
                'dataset_version' => $source['version'],
                'release_fingerprint' => $source['fingerprint'],
                'source_sha256' => json_encode($source['hashes'], JSON_THROW_ON_ERROR),
                'coordinate_system' => 'EPSG:4326',
                'subdivision_coordinate_fallback' => 'selected > ancestor > postal_place > municipality',
                'formal_postal_c_acceptance' => 'false',
            ];
            $meta = $db->prepare('INSERT INTO ' . $prefix . 'metadata (`key`,`value`) VALUES (?,?)');
            foreach ($metadata as $key => $value) {
                $meta->execute([$key, $value]);
            }
            $counts = self::populate($db, $prefix, $release);
            $db->commit();
            return [
                'status' => 'CREATED', 'dataset_version' => $source['version'],
                'table_prefix' => $prefix, 'counts' => $counts,
            ];
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            // Only tables created in THIS run may be deleted on failure.
            // Existing application tables were rejected before schema creation.
            if ($driver === 'mysql' || $driver === 'mariadb') {
                $db->exec('SET FOREIGN_KEY_CHECKS=0');
            }
            try {
                foreach (array_reverse($created) as $table) {
                    $db->exec('DROP TABLE ' . $table);
                }
            } finally {
                if ($driver === 'mysql' || $driver === 'mariadb') {
                    $db->exec('SET FOREIGN_KEY_CHECKS=1');
                }
            }
            throw $exception;
        }
    }

    /** @return array<string,int> */
    private static function populate(PDO $db, string $prefix, string $release): array
    {
        $municipalities = [];
        $insert = $db->prepare('INSERT INTO ' . $prefix . 'municipalities (ags,country_code,ars,name,display_name,type_code,type_label,admin_postal_code,centroid_latitude,centroid_longitude,centroid_source_date) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        foreach (self::rows($release, 'municipalities') as $row) {
            $ags = self::ags(self::value($row, 'ags'));
            [$lat, $lon] = self::coordinate($row, 'centroid_latitude', 'centroid_longitude');
            $municipalities[$ags] = true;
            $insert->execute([$ags, self::value($row, 'country_code') ?? 'DE',
                self::value($row, 'ars'), self::value($row, 'name'),
                self::value($row, 'display_name'), self::value($row, 'type_code'),
                self::value($row, 'type_label'), self::postal(self::value($row, 'admin_postal_code'), true),
                $lat, $lon, self::value($row, 'centroid_source_date')]);
        }
        if ($municipalities === []) {
            throw new RuntimeException('Empty municipalities dataset.');
        }

        $places = [];
        $insert = $db->prepare('INSERT INTO ' . $prefix . 'places (ags,postal_code,latitude,longitude,relation_source,coordinate_source,coordinate_method,coordinate_accuracy) VALUES (?,?,?,?,?,?,?,?)');
        foreach (self::rows($release, 'places') as $row) {
            $ags = self::ags(self::value($row, 'ags'));
            $plz = self::postal(self::value($row, 'postal_code'));
            if (!isset($municipalities[$ags])) {
                throw new RuntimeException('Place refers to missing municipality: ' . $ags);
            }
            [$lat, $lon] = self::coordinate($row, 'latitude', 'longitude', true);
            $insert->execute([$ags, $plz, $lat, $lon,
                self::value($row, 'relation_source'), self::value($row, 'coordinate_source'),
                self::value($row, 'coordinate_method'), self::value($row, 'coordinate_accuracy')]);
            $places[$ags . ':' . $plz] = (int) $db->lastInsertId();
        }
        if ($places === []) {
            throw new RuntimeException('Empty places dataset.');
        }

        $keys = [];
        $parents = [];
        $insert = $db->prepare('INSERT INTO ' . $prefix . 'subdivisions (subdivision_key,ags,type,name,display_name,latitude,longitude,coordinate_source,coordinate_reference,coordinate_method,coordinate_quality,hierarchy_method,administrative_level,place_type,source,source_reference) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach (self::rows($release, 'subdivisions') as $row) {
            $ags = self::ags(self::value($row, 'ags'));
            $type = self::value($row, 'type');
            $name = self::value($row, 'name');
            if ($type === null || $name === null || !isset($municipalities[$ags])) {
                throw new RuntimeException('Invalid subdivision identity or municipality.');
            }
            $key = self::value($row, 'subdivision_key')
                ?? 'legacy:' . $ags . ':' . substr(hash('sha256', $type . "\0" . $name), 0, 24);
            [$lat, $lon] = self::coordinate($row, 'latitude', 'longitude');
            $identity = json_encode([$ags,$type,$name], JSON_THROW_ON_ERROR);
            if (array_key_exists($identity, $keys)) {
                $keys[$identity] = null;
            } else {
                $keys[$identity] = $key;
            }
            $parent = self::value($row, 'parent_subdivision_key');
            if ($parent !== null) {
                $parents[$key] = [$ags, $parent];
            }
            $insert->execute([$key, $ags, $type, $name, self::value($row, 'display_name'),
                $lat, $lon, self::value($row, 'coordinate_source'),
                self::value($row, 'coordinate_reference'), self::value($row, 'coordinate_method'),
                self::value($row, 'coordinate_quality'), self::value($row, 'hierarchy_method'),
                self::value($row, 'administrative_level'), self::value($row, 'place_type'),
                self::value($row, 'source'), self::value($row, 'source_reference')]);
        }
        $query = $db->prepare('SELECT ags FROM ' . $prefix . 'subdivisions WHERE subdivision_key=?');
        $update = $db->prepare('UPDATE ' . $prefix . 'subdivisions SET parent_subdivision_key=? WHERE subdivision_key=?');
        foreach ($parents as $key => [$ags, $parent]) {
            if ($key === $parent) {
                throw new RuntimeException('Self-referential subdivision: ' . $key);
            }
            $query->execute([$parent]);
            if ($query->fetchColumn() !== $ags) {
                throw new RuntimeException('Invalid parent subdivision: ' . $key);
            }
            $update->execute([$parent, $key]);
        }

        $insert = $db->prepare('INSERT INTO ' . $prefix . 'place_subdivisions (place_id,subdivision_key) VALUES (?,?)');
        foreach (self::rows($release, 'place-subdivisions') as $row) {
            $ags = self::ags(self::value($row, 'ags'));
            $plz = self::postal(self::value($row, 'postal_code'));
            $id = $places[$ags . ':' . $plz] ?? null;
            if ($id === null) {
                throw new RuntimeException('Missing place relation for subdivision: ' . $ags . '/' . $plz);
            }
            $key = self::value($row, 'subdivision_key');
            if ($key === null) {
                $identity = json_encode([$ags,self::value($row, 'subdivision_type'),self::value($row, 'subdivision_name')], JSON_THROW_ON_ERROR);
                $key = $keys[$identity] ?? null;
            }
            if ($key === null) {
                throw new RuntimeException('Unknown or ambiguous linked subdivision for ' . $plz);
            }
            $query->execute([$key]);
            if ($query->fetchColumn() !== $ags) {
                throw new RuntimeException('Subdivision belongs to wrong municipality.');
            }
            $insert->execute([$id, $key]);
        }
        // Detect indirect cycles even when foreign keys are locally satisfied.
        foreach ($db->query('SELECT subdivision_key,parent_subdivision_key FROM ' . $prefix . 'subdivisions') as $row) {
            $seen = [(string) $row['subdivision_key'] => true];
            $parent = $row['parent_subdivision_key'];
            while ($parent !== null) {
                if (isset($seen[(string) $parent])) {
                    throw new RuntimeException('Subdivision parent cycle detected.');
                }
                $seen[(string) $parent] = true;
                $query->execute([$parent]);
                if ($query->fetchColumn() === false) {
                    throw new RuntimeException('Unresolved subdivision parent.');
                }
                $getParent = $db->prepare('SELECT parent_subdivision_key FROM ' . $prefix . 'subdivisions WHERE subdivision_key=?');
                $getParent->execute([$parent]);
                $parent = $getParent->fetchColumn();
                if ($parent === false) {
                    $parent = null;
                }
            }
        }
        return [
            'municipalities' => count($municipalities),
            'places' => count($places),
            'subdivisions' => (int) $db->query('SELECT COUNT(*) FROM ' . $prefix . 'subdivisions')->fetchColumn(),
            'place_subdivisions' => (int) $db->query('SELECT COUNT(*) FROM ' . $prefix . 'place_subdivisions')->fetchColumn(),
        ];
    }
}
