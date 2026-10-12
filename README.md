# GEO_PLZ_Details

Wiederverwendbares, **framework-unabhängiges PHP-/Composer-Paket** zur Auswahl deutscher Postleitzahlen, Gemeinden und optionaler Stadtteile/Unterbereiche – inklusive belastbarer WGS84-Referenzkoordinaten für Umkreissuchen.

- **Entwickler:** Kighlander
- **Composer:** `kighlander/geo-plz-details`
- **PHP:** 8.2 oder neuer
**Status:** 0.3.0-Preview (noch kein öffentlich freigegebenes Datenrelease)

> [!IMPORTANT]
> Das Paket enthält bewusst **keine bundesweit befüllte Datenbank**. Die rechtliche Zulässigkeit der Weitergabe der zugrunde liegenden GEO-Daten ist noch zu prüfen. Ein vorhandenes, passendes und rechtmäßig verwendbares GEO-CSV-Release muss vor der Datenbankinstallation bereitgestellt werden. Die alte postalische C-Evidenz wird nicht ungeprüft veröffentlicht.

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

## Veröffentlichung und Einschränkungen

- Derzeit **Preview-Code**. Noch keine stabile Composer-Version und keine zugesagte vollständige Deutschland-Datenabdeckung.
- Das aktuelle alte V1-CSV-Release enthält keine Ortsteilkoordinaten und keine echten Subdivision-Hierarchien; dann verwendet die Resolver-Methode zulässigerweise einen übergeordneten PLZ-/Ortspunkt. Das Modul kann spätere detailreichere Releases verarbeiten.
- Die Nutzungs-/Verbreitungslizenz des **Codes** muss vor offener Wiederverwendung endgültig festgelegt werden (`proprietary` ist ein Platzhalter). Die Lizenzprüfung der **Daten** ist davon unabhängig.
- Installation in eine bereits vorhandene Anwendungsdatenbank erstellt eigene Tabellen, löst aber keine alten Fremdtabellen auf und migriert keine Benutzerprofile.
- Einmal installierte Datenstände werden nicht still überschrieben. Updates benötigen einen gesonderten, geprüften Migrationspfad.

Siehe [Architektur](docs/ARCHITECTURE.md), [Daten und Lizenzen](docs/DATA_SOURCES_AND_LICENSE.md), [Installer](docs/INSTALLER.md) und [Release-Checkliste](docs/RELEASE_CHECKLIST.md).
