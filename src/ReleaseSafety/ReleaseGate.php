<?php

declare(strict_types=1);

namespace Kighlander\GeoPlzDetails\ReleaseSafety;

use PDO;
use RuntimeException;

require_once __DIR__ . '/TrustedPublishers.php';

/**
 * D.45 candidate: Fail-closed release and runtime authorization policy.
 * This is NOT an authorization of the D.40/D.42/D.44 technical data.
 * Human evidence review and legal approval are separate prerequisites.
 */
final class ReleaseGate
{
    public const POLICY_VERSION = 'D46-1';
    private const STATES = ['01','02','03','04','05','06','07','08','09','10','11','12','13','14','15','16'];
    private const APPROVAL_TOPICS = ['license', 'postal', 'coordinates', 'names', 'hierarchy', 'localities', 'final_release'];
    private const RELEASE_FILES = [
        'municipalities.csv.gz', 'places.csv.gz', 'subdivisions.csv.gz',
        'place-subdivisions.csv.gz', 'postal-final-approvals.csv.gz',
        'data-license-lineage.json',
    ];

    /** @return array<string,mixed> */
    public static function verifyRelease(string $directory): array
    {
        return self::verifyReleaseWithTrustedKeys($directory, TrustedPublishers::KEYS);
    }

    /**
     * The default installer supplies only pinned publisher keys.
     * A separate explicit-key entrypoint permits synthetic offline tests;
     * it is NEVER invoked from ReleaseImporter or GeoCatalog.
     * @param array<string,string> $trustedKeys
     * @return array<string,mixed>
     */
    public static function verifyReleaseWithTrustedKeys(string $directory, array $trustedKeys): array
    {
        $root = rtrim($directory, '/\\');
        $authorization = $root . '/release-authorization.json';
        if (!is_file($authorization)) {
            throw new RuntimeException('D45 BLOCKED: no explicit release-authorization.json (legacy and technical previews are not authorized).');
        }
        $raw = file_get_contents($authorization);
        if ($raw === false || strlen($raw) > 1024 * 1024) {
            throw new RuntimeException('D45 BLOCKED: authorization not readable or oversized.');
        }
        self::verifySignature($root, $raw, $trustedKeys);
        $doc = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($doc) || ($doc['policy_version'] ?? null) !== self::POLICY_VERSION
            || ($doc['release_status'] ?? null) !== 'APPROVED'
            || ($doc['country'] ?? null) !== 'DE') {
            throw new RuntimeException('D45 BLOCKED: release authorization policy, country or status invalid.');
        }
        $states = $doc['states'] ?? null;
        if (!is_array($states) || $states !== self::STATES) {
            throw new RuntimeException('D45 BLOCKED: complete ordered 16-state coverage must be attested.');
        }
        $id = $doc['release_id'] ?? null;
        if (!is_string($id) || preg_match('/\A[a-z0-9][a-z0-9_.-]{4,80}\z/D', $id) !== 1
            || $id !== basename($root)) {
            throw new RuntimeException('D45 BLOCKED: release ID must match release directory name.');
        }
        foreach (self::APPROVAL_TOPICS as $topic) {
            $review = $doc['reviews'][$topic] ?? null;
            if (!is_array($review) || ($review['status'] ?? null) !== 'APPROVED'
                || !self::sha($review['evidence_sha256'] ?? null)
                || !self::human($review['reviewed_by'] ?? null)
                || !self::date($review['reviewed_on'] ?? null)) {
                throw new RuntimeException('D45 BLOCKED: missing approved review: ' . $topic);
            }
        }
        $unresolved = $doc['unresolved_blockers'] ?? null;
        if (!is_array($unresolved) || $unresolved !== [] || ($doc['external_distribution_authorized'] ?? false) !== true
            || ($doc['runtime_lookup_authorized'] ?? false) !== true) {
            throw new RuntimeException('D45 BLOCKED: open blockers or external/runtime approval absent.');
        }
        $counts = $doc['counts'] ?? null;
        if (!is_array($counts)) {
            throw new RuntimeException('D45 BLOCKED: release counts absent.');
        }
        foreach (['municipalities','places','subdivisions','place_subdivisions'] as $dataset) {
            if (!isset($counts[$dataset]) || !is_int($counts[$dataset]) || $counts[$dataset] <= 0) {
                throw new RuntimeException('D45 BLOCKED: missing positive approved count for ' . $dataset);
            }
        }
        if (($counts['approved_postal_links'] ?? null) !== $counts['place_subdivisions']) {
            throw new RuntimeException('D45 BLOCKED: approval count does not match runtime postal links.');
        }
        $sha = $doc['sha256'] ?? null;
        if (!is_array($sha) || count($sha) !== count(self::RELEASE_FILES)) {
            throw new RuntimeException('D45 BLOCKED: declared source hashes incomplete.');
        }
        foreach (self::RELEASE_FILES as $name) {
            if (!self::sha($sha[$name] ?? null)) {
                throw new RuntimeException('D45 BLOCKED: invalid source hash for ' . $name);
            }
            $path = $root . '/' . $name;
            if (!is_file($path) || !hash_equals(strtoupper($sha[$name]), strtoupper((string) hash_file('sha256', $path)))) {
                throw new RuntimeException('D45 BLOCKED: source bytes differ: ' . $name);
            }
        }
        $lineage = json_decode((string) file_get_contents($root . '/data-license-lineage.json'), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($lineage) || ($lineage['distribution_review_status'] ?? null) !== 'APPROVED'
            || !is_array($lineage['sources'] ?? null) || count($lineage['sources']) === 0) {
            throw new RuntimeException('D45 BLOCKED: source license lineage missing or not approved.');
        }
        foreach ($lineage['sources'] as $entry) {
            if (!is_array($entry) || !self::human($entry['name'] ?? null)
                || !self::human($entry['license'] ?? null)
                || !self::human($entry['attribution'] ?? null)
                || !self::human($entry['source_reference'] ?? null)) {
                throw new RuntimeException('D45 BLOCKED: incomplete source license/attribution record.');
            }
        }
        self::verifyPostalApprovals($root . '/postal-final-approvals.csv.gz',
            $root . '/place-subdivisions.csv.gz', $counts['approved_postal_links']);
        return $doc;
    }

    /** @param array<string,string> $trustedKeys */
    private static function verifySignature(string $root, string $manifestBytes, array $trustedKeys): void
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new RuntimeException('D45 BLOCKED: PHP ext-sodium required for release authenticity.');
        }
        $path = $root . '/release-signature.json';
        if (!is_file($path)) {
            throw new RuntimeException('D45 BLOCKED: publisher signature missing.');
        }
        $sig = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        $keyId = is_array($sig) ? ($sig['key_id'] ?? null) : null;
        if (!is_string($keyId) || ($sig['algorithm'] ?? null) !== 'Ed25519'
            || !isset($trustedKeys[$keyId]) || !is_string($trustedKeys[$keyId])) {
            throw new RuntimeException('D45 BLOCKED: signer is not in the trusted publisher keys.');
        }
        $publicKey = base64_decode($trustedKeys[$keyId], true);
        $signature = base64_decode((string) ($sig['signature_base64'] ?? ''), true);
        if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($signature, $manifestBytes, $publicKey)) {
            throw new RuntimeException('D45 BLOCKED: invalid publisher signature.');
        }
    }

    /**
     * Every runtime link MUST have a distinct final approval with evidence,
     * and no approval may be orphaned. This rejects automatic promotion of D44 cohorts.
     */
    private static function verifyPostalApprovals(string $approvedFile, string $runtimeFile, int $expected): void
    {
        $approved = [];
        foreach (self::csv($approvedFile) as $record) {
            $key = self::key($record);
            if (($record['decision'] ?? null) !== 'APPROVED_FINAL'
                || !self::sha($record['evidence_sha256'] ?? null)
                || !self::human($record['reviewed_by'] ?? null)
                || !self::date($record['reviewed_on'] ?? null)
                || isset($approved[$key])) {
                throw new RuntimeException('D45 BLOCKED: duplicate or unapproved postal proof.');
            }
            $approved[$key] = true;
        }
        if (count($approved) !== $expected) {
            throw new RuntimeException('D45 BLOCKED: final postal approval count mismatch.');
        }
        $runtime = 0;
        foreach (self::csv($runtimeFile) as $record) {
            $key = self::key($record);
            if (!isset($approved[$key])) {
                throw new RuntimeException('D45 BLOCKED: runtime postal link has no matching final approval: ' . $key);
            }
            unset($approved[$key]);
            $runtime++;
        }
        if ($approved !== [] || $runtime !== $expected) {
            throw new RuntimeException('D45 BLOCKED: extra approvals or duplicate runtime links.');
        }
    }

    /** @return \Generator<int,array<string,string>> */
    private static function csv(string $path): \Generator
    {
        $stream = fopen('compress.zlib://' . $path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('D45 BLOCKED: compressed CSV cannot be read.');
        }
        try {
            $header = fgetcsv($stream, 0, ',', '"', '');
            if (!is_array($header)) {
                throw new RuntimeException('D45 BLOCKED: empty CSV.');
            }
            $header[0] = ltrim((string) $header[0], "\xEF\xBB\xBF");
            foreach (['ags','postal_code','subdivision_key'] as $column) {
                if (!in_array($column, $header, true)) {
                    throw new RuntimeException('D45 BLOCKED: missing key column: ' . $column);
                }
            }
            while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                if (count($header) !== count($row)) {
                    throw new RuntimeException('D45 BLOCKED: wrong CSV column count.');
                }
                yield array_combine($header, $row);
            }
        } finally {
            fclose($stream);
        }
    }

    /** @param array<string,mixed> $row */
    private static function key(array $row): string
    {
        $ags = (string) ($row['ags'] ?? '');
        $postal = (string) ($row['postal_code'] ?? '');
        $subdivision = (string) ($row['subdivision_key'] ?? '');
        if (preg_match('/\A[0-9]{8}\z/D', $ags) !== 1
            || preg_match('/\A[0-9]{5}\z/D', $postal) !== 1
            || preg_match('/\A[A-Za-z0-9:._-]{1,128}\z/D', $subdivision) !== 1) {
            throw new RuntimeException('D45 BLOCKED: malformed postal identity.');
        }
        return $ags . "\0" . $postal . "\0" . $subdivision;
    }

    private static function sha(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-fA-F0-9]{64}\z/D', $value) === 1;
    }

    private static function human(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '' && strlen($value) <= 512;
    }

    private static function date(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/\A(20[0-9]{2})-([0-9]{2})-([0-9]{2})\z/D', $value, $parts) !== 1) {
            return false;
        }
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    /** @param array<string,string> $metadata */
    private static function assertRuntimeMetadataFlags(array $metadata): void
    {
        $required = [
            'format_version' => '3',
            'release_gate_policy_version' => self::POLICY_VERSION,
            'runtime_release_status' => 'APPROVED',
            'postal_final_acceptance' => 'true',
            'formal_postal_c_acceptance' => 'true',
            'coordinate_review_approved' => 'true',
            'data_license_approved' => 'true',
            'identity_review_approved' => 'true',
            'country_coverage' => 'DE:16',
        ];
        foreach ($required as $key => $value) {
            if (($metadata[$key] ?? null) !== $value) {
                throw new RuntimeException('GEO runtime BLOCKED: missing or nonapproved metadata: ' . $key);
            }
        }
        if (!self::sha($metadata['release_fingerprint'] ?? null)
            || !self::sha($metadata['release_authorization_sha256'] ?? null)) {
            throw new RuntimeException('GEO runtime BLOCKED: release and authorization fingerprints missing.');
        }
    }

    /**
     * D.46: An unsigned status flag stored in a database can NEVER be sufficient
     * to unlock a lookup. The installer stores a copy of the signed authorization
     * receipt; GeoCatalog independently verifies it against pinned publisher keys.
     * The optional key parameter exists only for self-contained synthetic tests;
     * the production DB entrypoint always uses TrustedPublishers::KEYS.
     *
     * This authenticates the RELEASE, not every subsequent database row edit.
     * @param array<string,mixed> $metadata
     * @param array<string,string> $trustedKeys
     */
    public static function assertRuntimeMetadataWithTrustedKeys(array $metadata, array $trustedKeys): void
    {
        self::assertRuntimeMetadataFlags($metadata);
        $authEncoded = $metadata['runtime_authorization_base64'] ?? null;
        $sigEncoded = $metadata['runtime_signature_base64'] ?? null;
        $keyId = $metadata['runtime_signer_key_id'] ?? null;
        if (!is_string($authEncoded) || strlen($authEncoded) > 2 * 1024 * 1024
            || !is_string($sigEncoded) || !is_string($keyId)
            || !isset($trustedKeys[$keyId]) || !is_string($trustedKeys[$keyId])) {
            throw new RuntimeException('GEO runtime BLOCKED: signed publisher receipt absent or signer untrusted.');
        }
        $auth = base64_decode($authEncoded, true);
        $signature = base64_decode($sigEncoded, true);
        $public = base64_decode($trustedKeys[$keyId], true);
        if (!function_exists('sodium_crypto_sign_verify_detached')
            || $auth === false || strlen($auth) > 1024 * 1024 || strlen($auth) === 0
            || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || $public === false || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !sodium_crypto_sign_verify_detached($signature, $auth, $public)) {
            throw new RuntimeException('GEO runtime BLOCKED: publisher receipt signature invalid.');
        }
        if (!hash_equals(strtoupper((string) $metadata['release_authorization_sha256']),
                strtoupper(hash('sha256', $auth)))) {
            throw new RuntimeException('GEO runtime BLOCKED: receipt SHA256 does not match imported release.');
        }
        try {
            $document = json_decode($auth, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($document) || ($document['policy_version'] ?? null) !== self::POLICY_VERSION
                || ($document['release_status'] ?? null) !== 'APPROVED'
                || ($document['country'] ?? null) !== 'DE'
                || ($document['states'] ?? null) !== self::STATES
                || ($document['release_id'] ?? null) !== ($metadata['dataset_version'] ?? null)
                || ($document['unresolved_blockers'] ?? null) !== []
                || ($document['external_distribution_authorized'] ?? false) !== true
                || ($document['runtime_lookup_authorized'] ?? false) !== true) {
                throw new RuntimeException('GEO runtime BLOCKED: signed authorization does not approve runtime usage.');
            }
            foreach (self::APPROVAL_TOPICS as $topic) {
                $review = $document['reviews'][$topic] ?? null;
                if (!is_array($review) || ($review['status'] ?? null) !== 'APPROVED'
                    || !self::sha($review['evidence_sha256'] ?? null)
                    || !self::human($review['reviewed_by'] ?? null)
                    || !self::date($review['reviewed_on'] ?? null)) {
                    throw new RuntimeException('GEO runtime BLOCKED: signed review not final: ' . $topic);
                }
            }
            $counts = $document['counts'] ?? null;
            if (!is_array($counts) || ($counts['place_subdivisions'] ?? null) !== ($counts['approved_postal_links'] ?? null)) {
                throw new RuntimeException('GEO runtime BLOCKED: signed postal counts disagree.');
            }
            foreach (['municipalities','places','subdivisions','place_subdivisions'] as $set) {
                $expected = $counts[$set] ?? null;
                if (!is_int($expected) || $expected <= 0
                    || (string) $expected !== ($metadata['approved_count_' . str_replace('-', '_', $set)] ?? null)) {
                    throw new RuntimeException('GEO runtime BLOCKED: signed table count differs: ' . $set);
                }
            }
            $hashes = json_decode((string) ($metadata['source_sha256'] ?? ''), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($hashes) || !self::sha($hashes['release-authorization.json'] ?? null)
                || !hash_equals(strtoupper(hash('sha256', $auth)), strtoupper($hashes['release-authorization.json']))) {
                throw new RuntimeException('GEO runtime BLOCKED: source fingerprint input missing.');
            }
            $files = [
                'municipalities.csv.gz','places.csv.gz','subdivisions.csv.gz',
                'place-subdivisions.csv.gz','postal-final-approvals.csv.gz','data-license-lineage.json',
            ];
            foreach ($files as $file) {
                $expectedHash = $document['sha256'][$file] ?? null;
                $installedHash = $hashes[$file] ?? null;
                if (!self::sha($expectedHash) || !self::sha($installedHash)
                    || !hash_equals(strtoupper($expectedHash), strtoupper($installedHash))) {
                    throw new RuntimeException('GEO runtime BLOCKED: signed source digest mismatch: ' . $file);
                }
            }
            ksort($hashes);
            $fingerprint = strtoupper(hash('sha256', (string) json_encode($hashes, JSON_THROW_ON_ERROR)));
            if (!hash_equals(strtoupper((string) $metadata['release_fingerprint']), $fingerprint)) {
                throw new RuntimeException('GEO runtime BLOCKED: stored release fingerprint invalid.');
            }
        } catch (\JsonException $error) {
            throw new RuntimeException('GEO runtime BLOCKED: malformed signed receipt metadata.', 0, $error);
        }
    }

    public static function assertRuntimeDatabase(PDO $db, string $prefix): void
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,30}\z/D', $prefix) !== 1 && $prefix !== '') {
            throw new RuntimeException('GEO runtime BLOCKED: unsafe table prefix.');
        }
        try {
            $rows = $db->query('SELECT `key`,`value` FROM ' . $prefix . 'metadata');
            if ($rows === false) {
                throw new RuntimeException('GEO metadata unreadable.');
            }
            $metadata = [];
            foreach ($rows as $row) {
                $key = (string) $row['key'];
                if (array_key_exists($key, $metadata)) {
                    throw new RuntimeException('Duplicate GEO metadata key.');
                }
                $metadata[$key] = (string) $row['value'];
            }
            self::assertRuntimeMetadataWithTrustedKeys($metadata, TrustedPublishers::KEYS);
        } catch (\Throwable $error) {
            throw new RuntimeException('GEO runtime BLOCKED: release metadata not authorized.', 0, $error);
        }
    }
}
