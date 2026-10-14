<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/InternalPreview/Connection.php';
require_once __DIR__ . '/../src/InternalPreview/Catalog.php';
require_once __DIR__ . '/../src/ReleaseSafety/ReleaseGate.php';

use Kighlander\GeoPlzDetails\InternalPreview\Catalog;
use Kighlander\GeoPlzDetails\InternalPreview\Connection;
use Kighlander\GeoPlzDetails\ReleaseSafety\TrustedPublishers;

function ensureD52(bool $good, string $name): void {
    if (!$good) { throw new RuntimeException('D52 test failed: ' . $name); }
    echo '[PASS] ' . $name . "\n";
}
function rejectD52(callable $action, string $name): void {
    try { $action(); } catch (RuntimeException $exception) {
        echo '[PASS] ' . $name . " rejected\n"; return;
    }
    throw new RuntimeException('D52 test failed: unexpected acceptance: ' . $name);
}
putenv('GEO_PLZ_PRIVATE_SQLITE_PATH');
putenv('GEO_PLZ_PRIVATE_DATABASE');
ensureD52(TrustedPublishers::KEYS === [], 'Production publisher trust store EMPTY');
rejectD52(fn() => Connection::fromEnvironment(), 'No D51 database configured');
putenv('GEO_PLZ_PRIVATE_DATABASE=wrong');
rejectD52(fn() => Connection::fromEnvironment(), 'Non-D51 database rejected');
putenv('GEO_PLZ_PRIVATE_DATABASE');
rejectD52(fn() => new Catalog(new class extends PDO { public function __construct() {} }, 'WRONG'),
    'Unacknowledged preview rejected');
ensureD52(Catalog::ACKNOWLEDGE === 'I_ACCEPT_UNAPPROVED_PRIVATE_PREVIEW_DATA', 'Explicit opt-in constant');
$cli = (string)file_get_contents(__DIR__ . '/../bin/geo-plz-details.php');
ensureD52(str_contains($cli, "'preview-resolve'") && str_contains($cli, "'preview-subdivisions'")
    && str_contains($cli, "'preview-check'"), 'Private CLI commands present');
ensureD52(str_contains($cli, "if (isset(\$options['private-preview']))"),
    'Normal CLI cannot consume private preview option');
// Isolated fake PDO validates the new read-only catalogue count contract.
class D52SyntheticStatement extends PDOStatement {
    public function __construct(private array $rows = [], private mixed $scalar = null) {}
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function fetchColumn(int $column = 0): mixed { return $this->scalar; }
}
class D52SyntheticPDO extends PDO {
    public function __construct(private bool $wrongCount = false, private bool $badGate = false) {}
    public function getAttribute(int $attribute): mixed { return 'mysql'; }
    public function query(string $sql, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        if (str_contains($sql, 'FROM preview_metadata')) {
            $meta = [
                'stage'=>'D.50', 'status'=>'INTERNAL_TECHNICAL_PREVIEW_ONLY',
                'public_release_authorized'=>'false', 'formal_postal_acceptance'=>'false',
                'runtime_approved'=>'false', 'not_approved_count'=>'77062', 'states'=>'16',
            ];
            if ($this->badGate) { $meta['runtime_approved'] = 'true'; }
            $rows=[];
            foreach($meta as $k=>$v) { $rows[]=['key'=>$k,'value'=>$v]; }
            return new D52SyntheticStatement($rows);
        }
        if (preg_match('/SELECT COUNT\(\*\) FROM `([a-z_]+)`/', $sql, $match)) {
            $counts=[
                'preview_municipalities'=>10943, 'preview_places'=>12839,
                'preview_subdivisions'=>75945, 'preview_postal_candidates'=>77062,
                'preview_decisions'=>80474,
            ];
            $value=$counts[$match[1]] ?? throw new RuntimeException('Unknown table in synthetic fixture');
            if ($this->wrongCount && $match[1]==='preview_postal_candidates') { $value--; }
            return new D52SyntheticStatement([], $value);
        }
        if (str_contains($sql,'DISTINCT SUBSTR')) { return new D52SyntheticStatement([],16); }
        if (str_contains($sql,'WHERE status <>')) { return new D52SyntheticStatement([],0); }
        if (str_contains($sql,'WHERE final_postal_acceptance <>')) { return new D52SyntheticStatement([],0); }
        throw new RuntimeException('Unexpected SQL in synthetic fixture');
    }
}
$testCatalog = new Catalog(new D52SyntheticPDO(), Catalog::ACKNOWLEDGE);
ensureD52($testCatalog->check()['valid'] === true, 'Synthetic 16-state private preview validation');
rejectD52(fn()=> (new Catalog(new D52SyntheticPDO(true),Catalog::ACKNOWLEDGE))->check(),
    'Row-count mismatch denied');
rejectD52(fn()=>new Catalog(new D52SyntheticPDO(false,true),Catalog::ACKNOWLEDGE),
    'Forged runtime approval denied');
echo "[PASS] D52 offline CLI and opt-in tests complete\n";
