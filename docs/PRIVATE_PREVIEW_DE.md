# Interner Deutschland-Lookup (D.51) – bewusst vorläufig

Diese Funktion ist ausschließlich für eigene Entwicklungs- und Testinstallationen vorgesehen.
Sie verwendet vorhandene D.50-SQLite- oder D.51-MariaDB-**Preview-Datenbanken**; sie erzeugt
keine Tabellen und verändert keine bestehenden Datenbanken.

## Datenbasis

- 16/16 Länder, 10.943 Gemeinden, 12.839 PLZ/Gemeinde-Paare, 8.161 eindeutige PLZ.
- 75.945 technische Subdivisions, 77.062 PLZ/Subdivision-Kandidaten.
- Alle Kandidaten `NOT_APPROVED` und ausdrücklich **nicht** als gesichert auszugeben.
- 3.407 offene C-Fälle und 5 abgelehnte Fälle bleiben Entscheidungsdaten und keine Vorschläge.
- Auswahl eines Stadtteils ist optional; mehrere plausibel zugeordnete Stadtteile bleiben auswählbar.
- Fehlende tiefere Einträge verhindern die Gemeindeauswahl nicht.
- D.51 verwendet aktuell den V1-PLZ-Ortspunkt als dokumentierten Koordinaten-Fallback.

## Lokale CLI

Nur die Datenbank `geo_d51_YYYYMMDD_HHMMSS_XXXXXXXX` wird in MariaDB akzeptiert;
für SQLite eine bereits vorhandene Datei `geo-d50-INTERNAL-NOT_APPROVED.sqlite`.
Das CLI erfordert `--private-preview` für jeden der vier `preview-*`-Befehle.

Zwingende Umgebungsvariablen für MariaDB (keine Passwörter in Befehlsargumenten):

- `GEO_PLZ_PRIVATE_DATABASE`: exakter Name der vorhandenen D.51-Datenbank
- `GEO_PLZ_PRIVATE_HOST`: Host; optional, Standard `127.0.0.1`
- `GEO_PLZ_PRIVATE_PORT`: Port; optional, Standard `3306`
- `GEO_PLZ_PRIVATE_USER`: MariaDB-Benutzer (möglichst nur SELECT)
- `GEO_PLZ_PRIVATE_PASSWORD`: Passwort als Prozessumgebungsvariable, nicht speichern

Für SQLite stattdessen `GEO_PLZ_PRIVATE_SQLITE_PATH` auf die bestehende D.50-Datei setzen.

Befehle: `preview-check`, `preview-lookup 49086`, `preview-subdivisions 49086 03404000`,
`preview-resolve 49086 03404000 [SUBDIVISION_KEY]`, jeweils mit `--private-preview`.
Alle SQL-Abfragen verwenden parametrisierte WHERE-Bedingungen und ausschließlich feste Tabellen.

### Verwendung aus eigenem PHP-Code

```php
use Kighlander\GeoPlzDetails\InternalPreview\Catalog;
use Kighlander\GeoPlzDetails\InternalPreview\Connection;

$catalog = new Catalog(Connection::fromEnvironment(), Catalog::ACKNOWLEDGE);
$gemeinden = $catalog->municipalitiesByPostalCode('49086');
$stadtteile = $catalog->subdivisionsByPlace('49086', '03404000');
$auswahl = $catalog->resolveSelection('49086', '03404000', null);
```

**Kein Produktionsfreigabe-Bypass:** Der normale `GeoCatalog` verlangt weiterhin signierten
Release-Nachweis und einen vertrauenswürdigen Herausgeber-Schlüssel; `TrustedPublishers::KEYS`
bleibt leer. `composer require` führt **keinen** GEO-Datenbankimport aus.

## Zur regulären Integration

Vor einer öffentlichen Composer-Datenversion sind die fachliche Freigabe, Lizenz-/Quellenkette
und ein signierter Deutschland-Release separat nachzuweisen.
Die lokale D.51-Testdatenbank darf **nicht** automatisch als freigegebenes Datenrelease veröffentlicht werden.
