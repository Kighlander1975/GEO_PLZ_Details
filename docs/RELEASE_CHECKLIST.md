# Checkliste vor dem ersten öffentlichen Composer-Release

Diese Entwicklungsbasis ist **noch kein vollständig freigegebenes Datenprodukt**.

## Git-Repository

- [ ] Im Git-Root liegt eine gültige `composer.json` mit `kighlander/geo-plz-details`.
- [ ] `composer validate --strict` erfolgreich.
- [ ] `php -l` über **alle** PHP-Dateien erfolgreich, PHP-Testläufe erfolgreich.
- [ ] Python-Synthesetests, SQLite-Builder und SQL-Dump roundtrip erfolgreich.
- [ ] Installation in isolierter SQLite-Anwendungsdatenbank getestet; vorhandene Fremdtabellen unberührt.
- [ ] Installation in isolierter MariaDB/MySQL-Datenbank getestet; keine Fremdtabellen verändert.
- [ ] Installation mit absichtlich unerreichbarer konfigurierter DB bricht sicher ab.
- [ ] `.gitignore`, `git ls-files`, `git status` auf Secrets, Logs, Dump-Dateien und Downloads prüfen.
- [ ] Alte oder unerwünschte Firmenkennungen in **allen** versionierten Dateien ausschließen.
- [ ] Auf `master` committen, GitHub pushen, CI grünen Status prüfen.

## Produkt und rechtliche Freigabe

- [x] MIT-Code-Lizenz festgelegt, `LICENSE` erstellt und `composer.json` auf MIT umgestellt.
- [ ] Für jede GEO-Eingangsquelle Lizenz, Stand, Attribution und Weitergaberecht dokumentiert.
- [ ] Festlegen, wie Nutzer einen freigegebenen Datensatz beziehen können (z.B. separates versioniertes Datenrelease); keine impliziten Downloads.
- [ ] Reproduzierbarkeit, Fingerprints und Abdeckung der veröffentlichten Daten prüfen.
- [ ] Keine ungeprüfte Postal-C-Evidenz unbemerkt akzeptieren.
- [ ] Public README / Beispiel-Installationen an tatsächliche verfügbare Datensätze anpassen.
- [ ] Erst jetzt Git-Tag wie `v0.3.0` beziehungsweise später `v1.0.0` und optional Packagist.

## Installation aus GitHub

Bis ein stabiler Git-Tag existiert, kann das Repository als Composer-VCS-Abhängigkeit mit Branch `dev-master` verwendet werden:

```powershell
composer config repositories.geo-plz-details vcs https://github.com/Kighlander1975/GEO_PLZ_Details.git
composer require 'kighlander/geo-plz-details:dev-master'
```

Für private GitHub-Repositories benötigen die konsumierenden Systeme entsprechende Zugriffsberechtigungen. Ein VCS-Repository muss im **konsumierenden Projekt** konfiguriert werden (Root-Only-Eigenschaft von Composer).
## Datenlizenz-Freigabe

Die Lizenzstrategie ist in
`docs/DATA_SOURCES_AND_LICENSE.md` dokumentiert.

Offene Freigabebedingungen:

- [ ] Konkrete Destatis-Produktrechte verifiziert.
- [ ] GeoNames-Felder und Koordinatenrechte geprueft.
- [ ] CC-BY-/ODbL-Kombination rechtlich geklaert.
- [ ] Nicht freigegebene Daten ausgeschlossen.
- [ ] Copyrights und Attributionen vollstaendig.
- [ ] ODbL-Pflichten dokumentiert und umgesetzt.
- [ ] Reproduzierbares Datenrelease erstellt.
- [ ] Manifest, Quellen und SHA256 kontrolliert.
- [ ] Oeffentliche Datenfreigabe ausdruecklich erteilt.

Die MIT-Code-Lizenz ersetzt keine Datenfreigabe.
