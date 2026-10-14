<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/ReleaseSafety/ReleaseGate.php';
require_once __DIR__ . '/../src/GeoCatalog.php';

use Kighlander\GeoPlzDetails\ReleaseSafety\ReleaseGate;
use Kighlander\GeoPlzDetails\ReleaseSafety\TrustedPublishers;
use Kighlander\GeoPlzDetails\GeoCatalog;

function d46Pass(string $reason): void { echo "[PASS] $reason\n"; }
function d46Reject(callable $callable, string $reason): void {
    try { $callable(); }
    catch (RuntimeException $error) { d46Pass($reason . ' rejected'); return; }
    throw new RuntimeException('FAIL: unexpectedly accepted: ' . $reason);
}
function d46Ensure(bool $condition, string $reason): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $reason);
    d46Pass($reason);
}
function d46Payload(string $secret, array $changes = []): array {
    $files = ['municipalities.csv.gz','places.csv.gz','subdivisions.csv.gz','place-subdivisions.csv.gz','postal-final-approvals.csv.gz','data-license-lineage.json'];
    $fileSha = [];
    foreach ($files as $i=>$file) { $fileSha[$file] = strtoupper(hash('sha256', $file)); }
    $review = ['status'=>'APPROVED','evidence_sha256'=>str_repeat('D', 64),'reviewed_by'=>'SYNTHETIC TEST ONLY','reviewed_on'=>'2026-10-14'];
    $doc = [
        'policy_version'=>ReleaseGate::POLICY_VERSION,
        'release_status'=>'APPROVED',
        'country'=>'DE',
        'release_id'=>'synthetic-de-test',
        'states'=>array_map(static fn($i)=>str_pad((string)$i, 2, '0', STR_PAD_LEFT), range(1,16)),
        'reviews'=>array_fill_keys(['license','postal','coordinates','names','hierarchy','localities','final_release'],$review),
        'unresolved_blockers'=>[],
        'external_distribution_authorized'=>true,
        'runtime_lookup_authorized'=>true,
        'counts'=>['municipalities'=>1,'places'=>1,'subdivisions'=>1,'place_subdivisions'=>1,'approved_postal_links'=>1],
        'sha256'=>$fileSha,
    ];
    foreach($changes as $key => $value) $doc[$key]=$value;
    $raw = json_encode($doc, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    $signature = sodium_crypto_sign_detached($raw, $secret);
    $sigDoc = json_encode(['algorithm'=>'Ed25519','key_id'=>'synthetic-test','signature_base64'=>base64_encode($signature)], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    $hashes = $fileSha;
    $hashes['release-authorization.json'] = strtoupper(hash('sha256', $raw));
    $hashes['release-signature.json'] = strtoupper(hash('sha256', $sigDoc));
    ksort($hashes);
    $fingerprint = strtoupper(hash('sha256', json_encode($hashes, JSON_THROW_ON_ERROR)));
    $metadata = [
        'format_version'=>'3',
        'release_gate_policy_version'=>ReleaseGate::POLICY_VERSION,
        'runtime_release_status'=>'APPROVED',
        'postal_final_acceptance'=>'true',
        'formal_postal_c_acceptance'=>'true',
        'coordinate_review_approved'=>'true',
        'data_license_approved'=>'true',
        'identity_review_approved'=>'true',
        'country_coverage'=>'DE:16',
        'release_fingerprint'=>$fingerprint,
        'release_authorization_sha256'=>strtoupper(hash('sha256', $raw)),
        'dataset_version'=>'synthetic-de-test',
        'source_sha256'=>json_encode($hashes, JSON_THROW_ON_ERROR),
        'runtime_authorization_base64'=>base64_encode($raw),
        'runtime_signature_base64'=>base64_encode($signature),
        'runtime_signer_key_id'=>'synthetic-test',
        'approved_count_municipalities'=>'1',
        'approved_count_places'=>'1',
        'approved_count_subdivisions'=>'1',
        'approved_count_place_subdivisions'=>'1',
    ];
    return [$metadata,$doc];
}

if (!extension_loaded('sodium') || !extension_loaded('zlib')) {
    throw new RuntimeException('D.46 tests require ext-sodium and ext-zlib.');
}
$keypair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($keypair);
$public = sodium_crypto_sign_publickey($keypair);
$testKeys = ['synthetic-test'=>base64_encode($public)];
[$base, $doc] = d46Payload($secret);
d46Ensure(TrustedPublishers::KEYS === [], 'Production publisher trust store remains EMPTY');
ReleaseGate::assertRuntimeMetadataWithTrustedKeys($base, $testKeys);
d46Pass('Signed synthetic receipt verified against explicit TEST ONLY key');
d46Reject(fn() => ReleaseGate::assertRuntimeMetadataWithTrustedKeys($base, TrustedPublishers::KEYS), 'Signed synthetic publisher in production');
$metaOnly = $base;
unset($metaOnly['runtime_authorization_base64'],$metaOnly['runtime_signature_base64'],$metaOnly['runtime_signer_key_id']);
d46Reject(fn() => ReleaseGate::assertRuntimeMetadataWithTrustedKeys($metaOnly, $testKeys), 'Unsigned APPROVED database status');
foreach ([
    ['runtime_signer_key_id','attacker', 'Changed signer ID'],
    ['runtime_signature_base64',base64_encode(str_repeat("\0", 64)),'Corrupt signature'],
    ['runtime_authorization_base64',base64_encode('garbage'),'Corrupt authorization'],
    ['release_authorization_sha256',str_repeat('A',64),'Forged authorization SHA'],
    ['release_fingerprint',str_repeat('B',64),'Forged release fingerprint'],
    ['approved_count_subdivisions','2','Changed signed table count'],
    ['dataset_version','not-the-release','Changed signed release ID'],
    ['source_sha256','{}','Missing signed source digest'],
    ['postal_final_acceptance','false','Postal metadata not final'],
] as [$key,$value,$label]) {
    $bad = array_replace($base, [$key=>$value]);
    d46Reject(fn()=>ReleaseGate::assertRuntimeMetadataWithTrustedKeys($bad, $testKeys), $label);
}
foreach ([
    ['states'=>array_map(static fn($i)=>str_pad((string)$i, 2, '0', STR_PAD_LEFT), range(1,15)),'Missing 16th state'],
    ['release_status'=>'PENDING','Signed PENDING review'],
    ['unresolved_blockers'=>['OPEN_C'],'Signed open blocker'],
    ['runtime_lookup_authorized'=>false,'Signed runtime gate closed'],
] as $case) {
    $label = array_pop($case);
    [$bad,] = d46Payload($secret, $case);
    d46Reject(fn()=>ReleaseGate::assertRuntimeMetadataWithTrustedKeys($bad, $testKeys), $label);
}
class D46SyntheticPDO extends PDO {
    public function __construct(private array $metadata) {}
    public function getAttribute(int $attribute): mixed { return 'mysql'; }
    public function query(string $sql, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        return new D46SyntheticStatement($this->metadata);
    }
}
class D46SyntheticStatement extends PDOStatement {
    public function __construct(private array $metadata) {}
    public function getIterator(): Iterator {
        foreach ($this->metadata as $key=>$value) yield ['key'=>$key,'value'=>$value];
    }
}
d46Reject(fn()=>new GeoCatalog(new D46SyntheticPDO($metaOnly)), 'GeoCatalog metadata-only fake approval');
d46Reject(fn()=>new GeoCatalog(new D46SyntheticPDO($base)), 'GeoCatalog synthetic signer with no production key');
$installerPhp = (string) file_get_contents(__DIR__ . '/../src/Installer/Installer.php');
d46Ensure(str_contains($installerPhp, "'formal_postal_c_acceptance' => true,"), 'Installer project configuration final-C state aligns with verified source');
$importerPhp = (string) file_get_contents(__DIR__ . '/../src/Installer/ReleaseImporter.php');
d46Ensure(str_contains($importerPhp, 'LONGTEXT NOT NULL'), 'MariaDB metadata stores signed authorization without TEXT truncation');
d46Ensure(str_contains($importerPhp, 'ReleaseGate::assertRuntimeDatabase($db, $prefix);'), 'Reused GEO installation re-checks signed runtime receipt');
d46Ensure(str_contains($importerPhp, "'runtime_authorization_base64' => base64_encode(\$authorizationBytes)"), 'Importer persists signed runtime receipt');

echo "[PASS] D46 synthetic receipt/installer tests complete. NO real approval, no database access.\n";
