<?php

declare(strict_types=1);

namespace Kighlander\GeoPlzDetails\InternalPreview;

use DomainException;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Explicit opt-in for a PRIVATE, UNAPPROVED technical dataset.
 * NOT an alternative to GeoCatalog's signed production ReleaseGate.
 * Do not expose this endpoint to a public website or represent its links as verified.
 */
final class Catalog
{
    public const ACKNOWLEDGE = 'I_ACCEPT_UNAPPROVED_PRIVATE_PREVIEW_DATA';
    private readonly PDO $db;

    public function __construct(PDO $database, string $acknowledgment)
    {
        if ($acknowledgment !== self::ACKNOWLEDGE) {
            throw new RuntimeException('D50 BLOCKED: explicit private preview acknowledgment is required.');
        }
        $driver = $database->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver !== 'sqlite' && $driver !== 'mysql') {
            throw new RuntimeException('Only PDO SQLite or MySQL/MariaDB are supported.');
        }
        $metadata = [];
        $query = $database->query('SELECT `key`, `value` FROM preview_metadata');
        if ($query === false) {
            throw new RuntimeException('Missing D50 preview metadata.');
        }
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $metadata[(string) $row['key']] = (string) $row['value'];
        }
        if (($metadata['stage'] ?? '') !== 'D.50'
            || ($metadata['status'] ?? '') !== 'INTERNAL_TECHNICAL_PREVIEW_ONLY'
            || ($metadata['public_release_authorized'] ?? '') !== 'false'
            || ($metadata['formal_postal_acceptance'] ?? '') !== 'false'
            || ($metadata['runtime_approved'] ?? '') !== 'false'
            || ($metadata['not_approved_count'] ?? '') !== '77062'
            || ($metadata['states'] ?? '') !== '16') {
            throw new RuntimeException('D50 BLOCKED: invalid private preview safety contract.');
        }
        $this->db = $database;
    }

    /** Verify the exact private D50/D51 table contract without granting approval. */
    public function check(): array
    {
        $expected = [
            'preview_municipalities' => 10943,
            'preview_places' => 12839,
            'preview_subdivisions' => 75945,
            'preview_postal_candidates' => 77062,
            'preview_decisions' => 80474,
        ];
        $counts = [];
        foreach ($expected as $table => $expectedCount) {
            // Only hard-coded table names, never user-provided identifiers.
            $count = (int)$this->db->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
            if ($count !== $expectedCount) {
                throw new RuntimeException('Private preview: row count mismatch in ' . $table);
            }
            $counts[$table] = $count;
        }
        $states = (int)$this->db->query(
            'SELECT COUNT(DISTINCT SUBSTR(ags, 1, 2)) FROM preview_municipalities'
        )->fetchColumn();
        $forged = (int)$this->db->query(
            "SELECT COUNT(*) FROM preview_postal_candidates WHERE status <> 'NOT_APPROVED'"
        )->fetchColumn();
        $unsafe = (int)$this->db->query(
            "SELECT COUNT(*) FROM preview_decisions WHERE final_postal_acceptance <> 'FALSE' "
            . "OR user_lookup_authorized <> 'FALSE' OR external_data_release_authorized <> 'FALSE'"
        )->fetchColumn();
        if ($states !== 16 || $forged !== 0 || $unsafe !== 0) {
            throw new RuntimeException('Private preview: data coverage or nonapproval invariant violated.');
        }
        return [
            'valid' => true,
            'mode' => 'PRIVATE_INTERNAL_PREVIEW_ONLY',
            'states' => $states,
            'counts' => $counts,
            'formally_approved' => false,
            'public_release_authorized' => false,
        ];
    }

    private static function postal(string $postalCode): string
    {
        if (preg_match('/\A[0-9]{5}\z/D', $postalCode) !== 1) {
            throw new InvalidArgumentException('Postal code must have five digits.');
        }
        return $postalCode;
    }

    private static function ags(string $ags): string
    {
        if (preg_match('/\A[0-9]{8}\z/D', $ags) !== 1) {
            throw new InvalidArgumentException('AGS must have eight digits.');
        }
        return $ags;
    }

    /** @return list<array<string,mixed>> */
    public function municipalitiesByPostalCode(string $postalCode): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.postal_code,m.ags,m.name,p.latitude,p.longitude '
            . 'FROM preview_places p JOIN preview_municipalities m ON m.ags=p.ags '
            . 'WHERE p.postal_code=? ORDER BY m.name,m.ags'
        );
        $stmt->execute([self::postal($postalCode)]);
        return array_map(static function (array $row): array {
            $row['latitude'] = (float) $row['latitude'];
            $row['longitude'] = (float) $row['longitude'];
            return $row + ['place_status' => 'V1_BASELINE', 'preview_only' => true];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string,mixed>> */
    public function subdivisionsByPlace(string $postalCode, string $ags): array
    {
        $postalCode = self::postal($postalCode);
        $ags = self::ags($ags);
        $stmt = $this->db->prepare(
            'SELECT s.subdivision_key,s.parent_subdivision_key,s.display_name AS name,'
            . 's.type,s.place_type,s.administrative_level,s.display_name_status,'
            . 'l.decision_class,l.source_decision,l.status '
            . 'FROM preview_postal_candidates l '
            . 'JOIN preview_subdivisions s ON s.subdivision_key=l.subdivision_key AND s.ags=l.ags '
            . 'WHERE l.ags=? AND l.postal_code=? AND l.status=? '
            . "AND s.display_name_status='SOURCE_NAME_UNAPPROVED' "
            . "AND s.display_name IS NOT NULL AND trim(s.display_name)<>'' "
            . 'ORDER BY s.display_name,s.subdivision_key'
        );
        $stmt->execute([$ags, $postalCode, 'NOT_APPROVED']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['administrative_level'] = $row['administrative_level'] === null
                ? null : (int) $row['administrative_level'];
            $row['preview_only'] = true;
            $row['formally_approved'] = false;
        }
        unset($row);
        return $rows;
    }

    /**
     * Validates the selected subdivision against this precise PLZ+AGS candidate link.
     * WGS84 reference uses V1 PLACE point (not an unapproved subdivision coordinate).
     *
     * @return array<string,mixed>
     */
    public function resolveSelection(string $postalCode, string $ags, ?string $subdivisionKey = null): array
    {
        $postalCode = self::postal($postalCode);
        $ags = self::ags($ags);
        $stmt = $this->db->prepare(
            'SELECT m.name AS municipality_name,p.latitude,p.longitude '
            . 'FROM preview_places p JOIN preview_municipalities m ON m.ags=p.ags '
            . 'WHERE p.postal_code=? AND p.ags=?'
        );
        $stmt->execute([$postalCode, $ags]);
        $place = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($place)) {
            throw new DomainException('Municipality is not mapped to that postal code.');
        }
        $subdivision = null;
        $hierarchy = [];
        if ($subdivisionKey !== null && $subdivisionKey !== '') {
            $stmt = $this->db->prepare(
                'SELECT s.subdivision_key,s.parent_subdivision_key,s.display_name,s.display_name_status,'
                . 'l.decision_class FROM preview_subdivisions s '
                . 'JOIN preview_postal_candidates l ON l.subdivision_key=s.subdivision_key '
                . 'AND l.ags=s.ags '
                . 'WHERE l.ags=? AND l.postal_code=? AND l.subdivision_key=? AND l.status=?'
            );
            $stmt->execute([$ags, $postalCode, $subdivisionKey, 'NOT_APPROVED']);
            $subdivision = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($subdivision) || $subdivision['display_name_status'] !== 'SOURCE_NAME_UNAPPROVED'
                || trim((string) $subdivision['display_name']) === '') {
                throw new DomainException('Subdivision is not selectable for this PLZ and municipality.');
            }
            $visited = [];
            $key = (string) $subdivision['subdivision_key'];
            while ($key !== '') {
                if (isset($visited[$key])) {
                    throw new RuntimeException('Cyclic subdivision hierarchy.');
                }
                $visited[$key] = true;
                $hierarchy[] = $key;
                $query = $this->db->prepare('SELECT ags,parent_subdivision_key FROM preview_subdivisions WHERE subdivision_key=?');
                $query->execute([$key]);
                $parent = $query->fetch(PDO::FETCH_ASSOC);
                if (!is_array($parent) || $parent['ags'] !== $ags) {
                    throw new RuntimeException('Broken subdivision hierarchy.');
                }
                $key = (string) ($parent['parent_subdivision_key'] ?? '');
                if (count($hierarchy) > 100) {
                    throw new RuntimeException('Subdivision hierarchy exceeds safety limit.');
                }
            }
        }
        return [
            'postal_code' => $postalCode,
            'ags' => $ags,
            'municipality_name' => (string) $place['municipality_name'],
            'selected_subdivision_key' => $subdivisionKey ?: null,
            'selected_subdivision_name' => $subdivision['display_name'] ?? null,
            'subdivision_ancestor_keys_leaf_first' => $hierarchy,
            'latitude' => (float) $place['latitude'],
            'longitude' => (float) $place['longitude'],
            'coordinate_level' => 'V1_POSTAL_PLACE_REFERENCE',
            'preview_only' => true,
            'subdivision_postal_approved' => false,
        ];
    }
}
