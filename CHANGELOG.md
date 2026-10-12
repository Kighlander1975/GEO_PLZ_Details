# Changelog

## 0.3.0-preview (2026-10-12)

- Neues eigenständiges Projekt `GEO_PLZ_Details` von Kighlander.
- Durchgängige Composer-Identität `kighlander/geo-plz-details`, PSR-4-Namespace `Kighlander\GeoPlzDetails\` und Kommando `geo-plz-details`.
- Eigene Tabellennamen `geo_plz_*`, neue private Speicherverzeichnisse und Umgebungsvariablen `GEO_PLZ_*` vor der ersten Paketveröffentlichung.
- Vorbereitete CI, Composer-VCS-Dokumentation und Prüfungen gegen versehentliche Veröffentlichung von Quell-/Arbeitsdaten.
- Vorversionen wurden nie veröffentlicht; keine Abwärtskompatibilität zu Vorab-Identitäten zugesagt.
- Keine Freigabe ungeprüfter Post-C-Evidenz oder bundesweiter Datenpakete.

## 0.2.0-preview

- Expliziter Composer-Installer mit Laravel-/PDO-Datenbankerkennung, `--dry-run` und SQLite-Fallback.
- Idempotenzprüfung, Fremdtabellenschutz, vier CSV-GEO-Release-Imports.

## 0.1.0-preview

- PHP-Geo-Katalog, CLI, unabhängiger SQLite-Builder, komprimierter SQL-Dump und SHA256-Nachweis.
