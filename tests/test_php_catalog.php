<?php

declare(strict_types=1);

require __DIR__ . '/../src/GeoCatalog.php';

use Kighlander\GeoPlzDetails\GeoCatalog;

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('PHP catalog test failed: ' . $message);
    }
}

$db = $argv[1] ?? null;
if ($db === null) {
    throw new RuntimeException('Expected temporary test database path.');
}
$geo = new GeoCatalog($db);
$municipalities = $geo->municipalitiesByPostalCode('49086');
verify(count($municipalities) === 2, 'Multiple municipalities per postcode');
verify(count($geo->subdivisionsByPlace('49086', '03404000')) === 3, 'Subdivision list');
verify(count($geo->subdivisionsByPlace('49086', '03405000')) === 0, 'Subdivision AGS isolation');

$city = $geo->resolveLocation('49086', '03404000');
verify($city['coordinate_level'] === 'place', 'No selection uses place point');
verify(abs($city['latitude'] - 52.31) < 0.0000001, 'No selection place coordinate');

$exact = $geo->resolveLocation('49086', '03404000', 'osm:r:2');
verify($exact['selected_subdivision_name'] === 'Luestringen-Ost', 'Exact selection retained');
verify($exact['coordinate_level'] === 'subdivision', 'Own coordinates preferred');
verify(abs($exact['latitude'] - 52.325) < 0.0000001, 'Subdivision latitude');
verify($exact['display_name'] === 'Luestringen-Mitte', 'Parent display inherited');

$fallback = $geo->resolveLocation('49086', '03404000', 'osm:r:3');
verify($fallback['selected_subdivision_name'] === 'Luestringen-WEST', 'Fallback selection retained');
verify($fallback['coordinate_level'] === 'ancestor_subdivision', 'Nearest ancestor coordinate');
verify(abs($fallback['latitude'] - 52.321) < 0.0000001, 'Ancestor latitude');
verify($fallback['display_name'] === 'Luestringen-Mitte', 'Ancestor display name');

$exceptionThrown = false;
try {
    $geo->resolveLocation('49086', '03405000', 'osm:r:2');
} catch (DomainException) {
    $exceptionThrown = true;
}
verify($exceptionThrown, 'Cannot select subdivisions across municipality');

verify(GeoCatalog::withinRadius(52.0, 8.0, 52.0, 8.0, 0), 'Zero-distance radius');
verify(GeoCatalog::distanceKm(52.0, 8.0, 53.0, 8.0) > 110, 'Distance calculation');
verify($geo->check()['valid'] === true, 'PHP catalog validation');

echo "[PASS] PHP catalog lookups, inherited display, exact/ancestor/place coordinates, distance, integrity\n";
