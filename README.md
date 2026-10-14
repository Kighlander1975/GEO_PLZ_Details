# GEO_PLZ_Details

Wiederverwendbares, **framework-unabhängiges PHP-/Composer-Paket** zur Auswahl deutscher Postleitzahlen, Gemeinden und optionaler Stadtteile/Unterbereiche – inklusive belastbarer WGS84-Referenzkoordinaten für Umkreissuchen.

- **Entwickler:** Kighlander
- **Composer:** `kighlander/geo-plz-details`
- **PHP:** 8.2 oder neuer; ext-sodium für den signierten Produktionsvertrag

**Status:** 0.3.0-Preview (noch kein öffentlich freigegebenes Datenrelease)

> [!IMPORTANT]
> Das Paket enthält bewusst **keine bundesweit befüllte Datenbank**. Die rechtliche Zulässigkeit der Weitergabe der zugrunde liegenden GEO-Daten ist noch zu prüfen. Ein vorhandenes, passendes und rechtmäßig verwendbares GEO-CSV-Release muss vor der Datenbankinstallation bereitgestellt werden. Die alte postalische C-Evidenz wird nicht ungeprüft veröffentlicht.


## Deutschlandweit intern testen (D.51; noch kein Datenrelease)

Die separate D.51-Testdatenbank `geo_d51_*` umfasst **alle 16 Bundesländer**, 10.943 Gemeinden,
12.839 PLZ-Gemeinde-Verknüpfungen, 75.945 technische Subdivisions und
77.062 nicht final freigegebene postalische Kandidaten. Die Datenbank liegt **außerhalb dieses
Git-/Composer-Repositories**. Das Git-Repository enthält keine ungeprüften Datendumps.

Der neue Kommandozeilen-Zugang ist **nur für ausdrücklich aktivierte private Tests** gedacht.
Er nutzt `Kighlander\GeoPlzDetails\InternalPreview\Catalog` und `Connection`, **nicht** den
signiert freigabepflichtigen `GeoCatalog`. Aufrufbeispiele (PowerShell):

```powershell
$env:GEO_PLZ_PRIVATE_DATABASE = 'geo_d51_20261014_113815_4434e06e' # eigene DB einsetzen
$env:GEO_PLZ_PRIVATE_HOST = '127.0.0.1'
$env:GEO_PLZ_PRIVATE_PORT = '3306'
$env:GEO_PLZ_PRIVATE_USER = 'readonly_geo' # Konto mit SELECT-Recht
# Passwort nur verdeckt per sicherer Eingabe/Prozessumgebung setzen; nicht in Skripten speichern.
php bin/geo-plz-details.php preview-check --private-preview
php bin/geo-plz-details.php preview-lookup 49086 --private-preview
php bin/geo-plz-details.php preview-subdivisions 49086 03404000 --private-preview
php bin/geo-plz-details.php preview-resolve 49086 03404000 de:03404000:osm:n:3750979925 --private-preview
```

Mehrdeutigkeiten liefern mehrere auswählbare Stadtteile zurück; tiefere Auswahl ist freiwillig.
Fehlende Kandidaten verhindern **nicht** die PLZ-/Gemeindeauswahl. Der Koordinaten-Fallback
ist derzeit bewusst der V1-PLZ-Ortspunkt, gekennzeichnet durch `coordinate_level`.

**Wichtig:** `preview-*` ausschließlich in einer privaten Entwicklungs-/Testumgebung ausführen,
niemals ungeprüft an öffentliche Webendpunkte hängen. Die 77.062 Beziehungen bleiben
`NOT_APPROVED`; `GeoCatalog` und `install` verweigern weiterhin unsignierte Releases.
Die öffentliche Verteilung des kombinierten Deutschland-Datensatzes ist **nicht** freigegeben.
Weitere technische Hinweise: [Privater 16-Länder-Lookup](docs/PRIVATE_PREVIEW_DE.md).

## Ziel und Funktion

- PLZ (fünfstellig) → **alle** zugehörigen Gemeinden, auch wenn sich Gemeinden eine PLZ teilen.
- Ort/Gemeinde (AGS) ist obligatorisch; Stadtteil und feinere Untergliederung optional.
- Auf Wunsch möglichst spezifische **bereits vorhandene** Koordinate der Auswahl verwenden; sonst die nächste geeignete übergeordnete Subdivision, danach PLZ-/Ortspunkt, zuletzt Gemeinde-Zentroid.
- Exakten gewählten Bereich, Anzeigeort und Koordinatenherkunft **separat** zurückgeben.
- Umkreisberechnung mittels Haversine; dies ist eine geografische Näherung und keine genaue Wohnadresse.
- Kommandozeile für Installationsplanung, Installation, Nachschlagen und Integritätsprüfung.

Das Modul speichert **keine Benutzerkonten**. Konsumierende Anwendungen speichern PLZ, Ortsnamen, Referenzkoordinaten und optional die Subdivision selbst; empfohlen werden zusätzlich AGS, Datenstand und Koordinatenprovenienz. Niemals ungeprüfte Client-Koordinaten übernehmen.

## Installation aus GitHub (VCS)

Aus dem Verzeichnis einer **konsumierenden PHP-Anwendung** (bei Laravel: dem Verzeichnis mit `composer.json`) nach Veröffentlichung des Paketcodes im GitHub-Repository:

```powershell
composer config repositories.geo-plz-details vcs https://github.com/Kighlander1975/GEO_PLZ_Details.git
composer require 'kighlander/geo-plz-details:dev-master'
```

Dies ist der Entwicklungszweig `master`. Für Anwendungen im Produktivbetrieb ist nach vollständiger Freigabe ein **getestetes, versioniertes Tag** vorzuziehen; Composer wählt dann die Release-Version anhand der Git-Tags aus. Ein Eintrag bei Packagist ist für den gezeigten VCS-Weg nicht erforderlich.

**Composer installiert nur den Paketcode.** Weder Composer noch ein Paket-Skript verändert bei `composer require` ungefragt die Anwendungsdatenbank. Der nächste Schritt ist stets die explizite Installation.

## GEO-Datenbank installieren

Der Installer verwendet die bereits konfigurierte SQLite-/MariaDB-/MySQL-Datenbank der Anwendung, sofern erreichbar. Seine eigenen Tabellen heißen `geo_plz_*`; bestehende Anwendungstabellen bleiben unangetastet. Falls keine geeignete Anwendungsdatenbank existiert, erstellt er eine private SQLite-Datei **außerhalb von `vendor`**. Bei einer ausdrücklich konfigurierten, aber nicht erreichbaren MySQL-/MariaDB-Datenbank bricht er ab.

Das Datenrelease muss die vier Dateien `municipalities.csv.gz`, `places.csv.gz`, `subdivisions.csv.gz` und `place-subdivisions.csv.gz` enthalten. Das Archiv selbst ist separat bereitzustellen.

```powershell
# Zunächst nur Planung: keine DB-Änderung
php vendor/kighlander/geo-plz-details/bin/geo-plz-details.php install --release='C:\Pfad\de-2026-09-v1' --dry-run

# Erst nach Kontrolle der angezeigten Zieldatenbank:
php vendor/kighlander/geo-plz-details/bin/geo-plz-details.php install --release='C:\Pfad\de-2026-09-v1' --yes
```

Wurde das Paket via Composer installiert, ist auch das `vendor/bin`-Kommando verfügbar (unter Windows regelmäßig als `.bat`-Proxy). Mit `--project=DIR` lässt sich das Anwendungsverzeichnis explizit angeben.

**DB-Erkennung:** Laravel gecachte Konfiguration oder `.env` / `DB_URL`; für framework-unabhängige Anwendungen explizit `GEO_PLZ_DSN`, optional `GEO_PLZ_USERNAME` und `GEO_PLZ_PASSWORD`. Keine Zugangsdaten werden in das Installationsprotokoll geschrieben. Weitere Einzelheiten: [Installer-Vertrag](docs/INSTALLER.md).

## API für Anwendungen

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kighlander\GeoPlzDetails\GeoCatalog;

$geo = GeoCatalog::fromProject(__DIR__);
$orte = $geo->municipalitiesByPostalCode('49086');
$stadtteile = $geo->subdivisionsByPlace('49086', '03404000');
$auswahl = $geo->resolveLocation('49086', '03404000', $stadtteilKey ?? null);
$distanzKm = GeoCatalog::distanceKm(52.2, 8.1, 52.3, 8.2);
```

`resolveLocation()` liefert u.a. `postal_code`, `ags`, `municipality_name`, `selected_subdivision_key`, `selected_subdivision_name`, `display_name`, `latitude`, `longitude`, `coordinate_level`, `coordinate_reference` sowie `dataset_version`. Die tatsächlich ausgegebenen Koordinaten stammen aus dem am besten geeigneten **bereits importierten** Datensatz. Fehlt ein verlässlicher Punkt auf allen Ebenen, wird ein Fehler ausgelöst.

## CLI

Aufrufe aus dem Root der konsumierenden Anwendung (mit installierter GEO-Datenbank):

```powershell
php vendor/kighlander/geo-plz-details/bin/geo-plz-details.php status
php vendor/kighlander/geo-plz-details/bin/geo-plz-details.php check
php vendor/kighlander/geo-plz-details/bin/geo-plz-details.php lookup 49086
php vendor/kighlander/geo-plz-details/bin/geo-plz-details.php subdivisions 49086 03404000
php vendor/kighlander/geo-plz-details/bin/geo-plz-details.php resolve 49086 03404000
php vendor/kighlander/geo-plz-details/bin/geo-plz-details.php distance 52.2 8.1 52.3 8.2
```

Das `--db=PFAD`-Argument ermöglicht alternativ die Abfrage einer separaten, **unpräfixierten** SQLite-Katalogdatei. Die CLI unterstützt außerdem `--project=DIR`.

## Eigenständige SQLite-Datenbank und Dump

Mit vorhandenem freigegebenem GEO-Release:

```powershell
.\scripts\Build-GeoPlzDatabase.ps1 -ReleasePath 'C:\Pfad\de-2026-09-v1'
```

Dafür wird Python 3 benötigt. Es entstehen `data/geo-plz-details.sqlite`, `data/geo-plz-details.sql.gz` (SQLite-kompatibles SQL, **kein MariaDB-Dump**) und `data/build-report.json` mit SHA256-Nachweisen. Der Composer-Installer selbst erfordert kein Python; für MySQL/MariaDB importiert er direkt über PHP/PDO.

## Paketentwicklung und Tests

```powershell
composer validate --strict
.\scripts\Test-GeoPlzPackage.ps1
```

Die automatischen Tests nutzen **synthetische Testdaten**. Zusätzlich gibt es eine GitHub-Actions-CI. Der Installer muss vor einem Release mit aktivierten PHP-Treibern (`pdo_sqlite` / `pdo_mysql`) und einer isolierten Testdatenbank geprüft werden.

## Lizenzmodell und Datenfreigabe

**PHP-Code:** MIT License, Copyright (c) 2026 Kighlander.

Private und gewerbliche Nutzung, Veraenderung und Weitergabe
sind unter Einhaltung der MIT-Lizenz erlaubt. Copyright-
und Lizenzhinweise muessen bei Weitergabe erhalten bleiben.

**GEO-Daten:** Die Datenquellen besitzen eigenstaendige
Lizenz- und Copyrightbedingungen. Insbesondere unterliegen
OSM-/OpenPLZ-Ableitungen der ODbL 1.0.

Das historische kombinierte Deutschland-Datenrelease ist
nicht fuer die oeffentliche Weitergabe freigegeben.
Eine rechtlich gepruefte Datenveroeffentlichung benoetigt
eine separate Freigabeentscheidung.

Dokumentation:

- [MIT-Lizenz](LICENSE)
- [Quellen und Copyrights](THIRD_PARTY_NOTICES.md)
- [Datenlizenzmodell](docs/DATA_SOURCES_AND_LICENSE.md)
- [Release-Checkliste](docs/RELEASE_CHECKLIST.md)

## Veröffentlichung und Einschränkungen

- Derzeit **Preview-Code**. Noch keine stabile Composer-Version und keine zugesagte vollständige Deutschland-Datenabdeckung.
- Das aktuelle alte V1-CSV-Release enthält keine Ortsteilkoordinaten und keine echten Subdivision-Hierarchien; dann verwendet die Resolver-Methode zulässigerweise einen übergeordneten PLZ-/Ortspunkt. Das Modul kann spätere detailreichere Releases verarbeiten.
- **Code-Lizenz: MIT.** Copyright (c) 2026 Kighlander. Die Rechte der GEO-Datenquellen bleiben davon getrennt.
- Installation in eine bereits vorhandene Anwendungsdatenbank erstellt eigene Tabellen, löst aber keine alten Fremdtabellen auf und migriert keine Benutzerprofile.
- Einmal installierte Datenstände werden nicht still überschrieben. Updates benötigen einen gesonderten, geprüften Migrationspfad.

Siehe [Architektur](docs/ARCHITECTURE.md), [Daten und Lizenzen](docs/DATA_SOURCES_AND_LICENSE.md), [Installer](docs/INSTALLER.md) und [Release-Checkliste](docs/RELEASE_CHECKLIST.md).
