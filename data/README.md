# Dataset output

This package ZIP does not include a populated Germany-wide database.

- Composer installer: app-owned `geo_plz_*` tables in the configured MySQL/MariaDB
  or SQLite database, otherwise an app-private SQLite fallback outside `vendor`.
- Standalone builder (optional, Python): `data/geo-plz-details.sqlite`,
  `data/geo-plz-details.sql.gz`, and `data/build-report.json`.

These generated artifacts are excluded from Git until redistribution permissions
and required source attribution are reviewed.
