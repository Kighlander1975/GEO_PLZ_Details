<?php

declare(strict_types=1);

namespace Kighlander\GeoPlzDetails;

use DomainException;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Kighlander\GeoPlzDetails\ReleaseSafety\ReleaseGate;

require_once __DIR__ . '/ReleaseSafety/ReleaseGate.php';

/**
 * Read-only, framework-independent German municipality/postcode/subdivision API.
 * Accepts a SQLite file path or an already connected PDO instance.
 */
final class GeoCatalog
{
    private readonly PDO $db;
    private readonly string $tablePrefix;

    public function __construct(PDO|string $database, string $tablePrefix = '')
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,30}\z/D', $tablePrefix) !== 1 && $tablePrefix !== '') {
            throw new InvalidArgumentException('Unsafe table prefix.');
        }
        $this->tablePrefix = $tablePrefix;
        $ownsConnection = is_string($database);
        if (is_string($database)) {
            if (!is_file($database)) {
                throw new RuntimeException('GEO database not found: ' . $database);
            }
            if (!extension_loaded('pdo_sqlite')) {
                throw new RuntimeException('PHP extension pdo_sqlite must be enabled.');
            }
            $database = new PDO('sqlite:' . $database);
        }

        if ($ownsConnection) {
            $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        }
        if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            if ($ownsConnection) {
                $database->exec('PRAGMA foreign_keys = ON');
                $database->exec('PRAGMA query_only = ON');
            }
        }
        $this->db = $database;
        // Fail-closed before any public lookup is possible, including fromProject().
        ReleaseGate::assertRuntimeDatabase($this->db, $this->tablePrefix);
    }

    /** @return list<array<string,mixed>> */
    public function municipalitiesByPostalCode(string $postalCode): array
    {
        self::assertPostalCode($postalCode);
        $statement = $this->db->prepare($this->sql(<<<'SQL'
            SELECT p.postal_code, m.ags, m.name,
                   COALESCE(m.display_name, m.name) AS display_name,
                   p.latitude, p.longitude,
                   p.coordinate_source, p.coordinate_method,
                   p.coordinate_accuracy
              FROM places p
              JOIN municipalities m ON m.ags = p.ags
             WHERE p.postal_code = ?
             ORDER BY m.name, m.ags
        SQL));
        $statement->execute([$postalCode]);
        return array_map(static function (array $record): array {
            $record['latitude'] = (float) $record['latitude'];
            $record['longitude'] = (float) $record['longitude'];
            $record['coordinate_accuracy'] = $record['coordinate_accuracy'] === null
                ? null : (int) $record['coordinate_accuracy'];
            return $record;
        }, $statement->fetchAll());
    }

    /**
     * Optional subdivisions genuinely linked to this exact PLZ and municipality.
     * The hierarchy is returned as parent_subdivision_key if available.
     *
     * @return list<array<string,mixed>>
     */
    public function subdivisionsByPlace(string $postalCode, string $ags): array
    {
        self::assertPostalCode($postalCode);
        self::assertAgs($ags);
        $statement = $this->db->prepare($this->sql(<<<'SQL'
            SELECT s.subdivision_key, s.parent_subdivision_key, s.type,
                   s.name, s.display_name, s.administrative_level,
                   s.place_type, s.latitude, s.longitude,
                   s.coordinate_source, s.coordinate_method,
                   s.coordinate_quality
              FROM subdivisions s
              JOIN place_subdivisions ps
                ON ps.subdivision_key = s.subdivision_key
              JOIN places p ON p.id = ps.place_id
             WHERE p.postal_code = ? AND p.ags = ? AND s.ags = ?
             ORDER BY s.name, s.subdivision_key
        SQL));
        $statement->execute([$postalCode, $ags, $ags]);
        return array_map(static function (array $record): array {
            $record['latitude'] = $record['latitude'] === null ? null : (float) $record['latitude'];
            $record['longitude'] = $record['longitude'] === null ? null : (float) $record['longitude'];
            $record['administrative_level'] = $record['administrative_level'] === null
                ? null : (int) $record['administrative_level'];
            return $record;
        }, $statement->fetchAll());
    }

    /**
     * Resolve the *required* WGS84 reference point for an optional selection.
     * Prefer subdivision point, then ancestors, then PLZ place, then municipality.
     * Never invent a coordinate and never accept an unlinked subdivision.
     *
     * @return array<string,mixed>
     */
    public function resolveLocation(
        string $postalCode,
        string $ags,
        ?string $subdivisionKey = null,
    ): array {
        self::assertPostalCode($postalCode);
        self::assertAgs($ags);

        $statement = $this->db->prepare($this->sql(<<<'SQL'
            SELECT p.id AS place_id, p.latitude AS place_latitude,
                   p.longitude AS place_longitude,
                   p.coordinate_source AS place_coordinate_source,
                   p.coordinate_method AS place_coordinate_method,
                   p.coordinate_accuracy AS place_coordinate_accuracy,
                   m.name AS municipality_name,
                   m.display_name AS municipality_display_name,
                   m.centroid_latitude, m.centroid_longitude
              FROM places p JOIN municipalities m ON m.ags = p.ags
             WHERE p.ags = ? AND p.postal_code = ?
        SQL));
        $statement->execute([$ags, $postalCode]);
        $place = $statement->fetch();
        if ($place === false) {
            throw new DomainException('No municipality/postcode relation: ' . $ags . '/' . $postalCode);
        }

        $selected = null;
        $displayName = (string) ($place['municipality_display_name'] ?: $place['municipality_name']);
        $chosenPoint = null;

        if ($subdivisionKey !== null && $subdivisionKey !== '') {
            $statement = $this->db->prepare($this->sql(<<<'SQL'
                SELECT s.* FROM subdivisions s
                  JOIN place_subdivisions ps
                    ON ps.subdivision_key = s.subdivision_key
                 WHERE ps.place_id = ? AND s.subdivision_key = ? AND s.ags = ?
            SQL));
            $statement->execute([(int) $place['place_id'], $subdivisionKey, $ags]);
            $selected = $statement->fetch();
            if ($selected === false) {
                throw new DomainException('Subdivision is not selectable for this PLZ and municipality.');
            }
            $visited = [];
            $current = $selected;
            $level = 'subdivision';
            $configuredDisplay = null;

            while ($current !== null) {
                $key = (string) $current['subdivision_key'];
                if (isset($visited[$key])) {
                    throw new RuntimeException('Subdivision hierarchy cycle: ' . $key);
                }
                $visited[$key] = true;
                if ($configuredDisplay === null && !empty($current['display_name'])) {
                    $configuredDisplay = (string) $current['display_name'];
                }
                if ($chosenPoint === null && self::validPair($current['latitude'], $current['longitude'])) {
                    $chosenPoint = [
                        'latitude' => (float) $current['latitude'],
                        'longitude' => (float) $current['longitude'],
                        'coordinate_level' => $level,
                        'coordinate_reference' => $key,
                        'coordinate_source' => $current['coordinate_source'],
                        'coordinate_method' => $current['coordinate_method'],
                        'coordinate_quality' => $current['coordinate_quality'],
                    ];
                }
                $parentKey = $current['parent_subdivision_key'];
                if ($parentKey === null || $parentKey === '') {
                    break;
                }
                $parentStmt = $this->db->prepare($this->sql('SELECT * FROM subdivisions WHERE subdivision_key = ?'));
                $parentStmt->execute([(string) $parentKey]);
                $parent = $parentStmt->fetch();
                if ($parent === false || $parent['ags'] !== $ags) {
                    throw new RuntimeException('Invalid cross-municipality parent subdivision: ' . $key);
                }
                $current = $parent;
                $level = 'ancestor_subdivision';
            }
            $displayName = $configuredDisplay ?: (string) $selected['name'];
        }

        if ($chosenPoint === null && self::validPair($place['place_latitude'], $place['place_longitude'])) {
            $chosenPoint = [
                'latitude' => (float) $place['place_latitude'],
                'longitude' => (float) $place['place_longitude'],
                'coordinate_level' => 'place',
                'coordinate_reference' => $ags . ':' . $postalCode,
                'coordinate_source' => $place['place_coordinate_source'],
                'coordinate_method' => $place['place_coordinate_method'],
                'coordinate_quality' => $place['place_coordinate_accuracy'],
            ];
        }
        if ($chosenPoint === null && self::validPair($place['centroid_latitude'], $place['centroid_longitude'])) {
            $chosenPoint = [
                'latitude' => (float) $place['centroid_latitude'],
                'longitude' => (float) $place['centroid_longitude'],
                'coordinate_level' => 'municipality',
                'coordinate_reference' => $ags,
                'coordinate_source' => 'municipality_centroid',
                'coordinate_method' => 'fallback',
                'coordinate_quality' => null,
            ];
        }
        if ($chosenPoint === null) {
            throw new DomainException('No reliable reference point for ' . $ags . '/' . $postalCode);
        }

        return [
            'postal_code' => $postalCode,
            'ags' => $ags,
            'municipality_name' => $place['municipality_name'],
            'selected_subdivision_key' => $selected === null ? null : $selected['subdivision_key'],
            'selected_subdivision_name' => $selected === null ? null : $selected['name'],
            'display_name' => $displayName,
            ...$chosenPoint,
            'coordinate_system' => 'EPSG:4326',
            'dataset_version' => $this->metadata('dataset_version'),
        ];
    }

    public static function distanceKm(float $latitude1, float $longitude1,
                                      float $latitude2, float $longitude2): float
    {
        self::assertCoordinates($latitude1, $longitude1);
        self::assertCoordinates($latitude2, $longitude2);
        $p1 = deg2rad($latitude1);
        $p2 = deg2rad($latitude2);
        $dp = $p2 - $p1;
        $dl = deg2rad($longitude2 - $longitude1);
        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
        return 6371.0088 * 2 * asin(min(1.0, sqrt(max(0.0, $a))));
    }

    public static function withinRadius(float $latitude1, float $longitude1,
                                        float $latitude2, float $longitude2,
                                        float $radiusKm): bool
    {
        if (!is_finite($radiusKm) || $radiusKm < 0) {
            throw new InvalidArgumentException('Radius must be a non-negative finite number.');
        }
        return self::distanceKm($latitude1, $longitude1, $latitude2, $longitude2) <= $radiusKm;
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $metadata = [];
        foreach ($this->db->query($this->sql('SELECT `key`,`value` FROM metadata ORDER BY `key`')) as $row) {
            $metadata[$row['key']] = $row['value'];
        }
        $counts = [];
        foreach (['municipalities', 'places', 'subdivisions', 'place_subdivisions'] as $table) {
            $counts[$table] = (int) $this->db->query('SELECT COUNT(*) FROM ' . $this->table($table))->fetchColumn();
        }
        return ['metadata' => $metadata, 'counts' => $counts];
    }

    /** @return array<string,mixed> */
    public function check(): array
    {
        $issues = [];
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $integrity = (string) $this->db->query('PRAGMA integrity_check')->fetchColumn();
            if ($integrity !== 'ok') {
                $issues[] = 'SQLite integrity_check: ' . $integrity;
            }
            $foreignIssues = 0;
            foreach (['places', 'subdivisions', 'place_subdivisions'] as $entityTable) {
                $table = $this->table($entityTable);
                $foreignIssues += count($this->db->query("PRAGMA foreign_key_check('" . $table . "')")->fetchAll());
            }
            if ($foreignIssues !== 0) {
                $issues[] = 'GEO foreign_key_check failed: ' . $foreignIssues;
            }
        }
        $coordinates = (int) $this->db->query($this->sql(<<<'SQL'
            SELECT COUNT(*) FROM places WHERE
                latitude NOT BETWEEN -90 AND 90 OR longitude NOT BETWEEN -180 AND 180
        SQL))->fetchColumn();
        if ($coordinates !== 0) {
            $issues[] = 'Invalid place coordinate pairs: ' . $coordinates;
        }
        $cross = (int) $this->db->query($this->sql(<<<'SQL'
            SELECT COUNT(*) FROM place_subdivisions ps
              JOIN places p ON p.id = ps.place_id
              JOIN subdivisions s ON s.subdivision_key = ps.subdivision_key
             WHERE p.ags <> s.ags
        SQL))->fetchColumn();
        if ($cross !== 0) {
            $issues[] = 'Cross-municipality subdivision links: ' . $cross;
        }
        $parentIssues = (int) $this->db->query($this->sql(<<<'SQL'
            SELECT COUNT(*) FROM subdivisions s
              JOIN subdivisions parent ON s.parent_subdivision_key = parent.subdivision_key
             WHERE s.ags <> parent.ags
        SQL))->fetchColumn();
        if ($parentIssues !== 0) {
            $issues[] = 'Cross-municipality parent links: ' . $parentIssues;
        }
        return ['valid' => $issues === [], 'issues' => $issues, ...$this->status()];
    }

    /** Convert only trusted, static SQL table identifiers to this catalog's namespace. */
    private function sql(string $sql): string
    {
        if ($this->tablePrefix === '') {
            return $sql;
        }
        return (string) preg_replace_callback(
            '/\b(place_subdivisions|municipalities|subdivisions|places|metadata)\b/',
            fn (array $match): string => $this->tablePrefix . $match[1],
            $sql,
        );
    }

    private function table(string $name): string
    {
        return $this->tablePrefix . $name;
    }

    /** Open an installed package from application-owned configuration. */
    public static function fromProject(string $projectDirectory): self
    {
        $install = Installer\ProjectInstallation::load($projectDirectory);
        $database = Installer\ConnectionDiscovery::connectFromInstallation($projectDirectory, $install);
        $catalog = new self($database, (string) ($install['table_prefix'] ?? ''));
        if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $database->exec('PRAGMA query_only = ON');
        }
        return $catalog;
    }

    private function metadata(string $key): ?string
    {
        $query = $this->db->prepare($this->sql('SELECT `value` FROM metadata WHERE `key` = ?'));
        $query->execute([$key]);
        $value = $query->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    private static function validPair(mixed $latitude, mixed $longitude): bool
    {
        if ($latitude === null || $longitude === null || !is_numeric($latitude) || !is_numeric($longitude)) {
            return false;
        }
        try {
            self::assertCoordinates((float) $latitude, (float) $longitude);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private static function assertCoordinates(float $latitude, float $longitude): void
    {
        if (!is_finite($latitude) || !is_finite($longitude) || abs($latitude) > 90 || abs($longitude) > 180) {
            throw new InvalidArgumentException('Invalid WGS84 latitude/longitude.');
        }
    }

    private static function assertPostalCode(string $postalCode): void
    {
        if (preg_match('/\A[0-9]{5}\z/D', $postalCode) !== 1) {
            throw new InvalidArgumentException('PLZ must consist of exactly five digits.');
        }
    }

    private static function assertAgs(string $ags): void
    {
        if (preg_match('/\A[0-9]{8}\z/D', $ags) !== 1) {
            throw new InvalidArgumentException('AGS must consist of exactly eight digits.');
        }
    }
}
