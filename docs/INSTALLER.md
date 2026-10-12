# Installer contract (v0.3 preview)

## Trigger and detection

Composer never runs a dependency's `post-install-cmd`. Run `geo-plz-details install`
explicitly after installing the PHP package in the consuming application's root.
The default dry run detects the database but makes no modifications.

Detection order:
1. Explicit `--sqlite` selects fallback regardless of app configuration.
2. Generic `GEO_PLZ_DSN` (with environment-sourced secrets).
3. Laravel `bootstrap/cache/config.php` active database connection.
4. Application `.env`, overridden by process `DB_*` variables / `DB_URL`.
5. If no already established database exists, use private SQLite fallback.

No TCP port scanning, no guessing authentication, no reading unrelated databases.
An explicitly configured inaccessible database fails rather than falling back.

## Database behavior

- Existing SQLite / MySQL / MariaDB: create five `geo_plz_*` tables in the
  existing database. Existing app tables (`users`, `geo_places`, etc.) stay intact.
- No app DB configured: create `storage/app/private/geo-plz-details/geo-plz-details.sqlite` for
  Laravel, otherwise `.geo-plz-details/geo-plz-details.sqlite`, outside `vendor` and public webroot.
- The installer requires the four CSV.gz release files and an explicit or single
  unambiguous dataset version. Data are never fetched automatically from the web.
- Exactly matching existing installed data may be reused. Partial/different
  table sets block the install. No implicit upgrade or drop/recreate.
- New tables created during a failed import are cleaned up if possible; a failed
  cleanup may require manual inspection before retrying. Never drop existing tables.
- The installed configuration stores DB driver/target fingerprint, source hashes,
  release version and table prefix, but **not credentials**.
- Existing PostgreSQL and SQL Server configurations are not yet supported.

## API and user data separation

The package provides only generic catalog data and does not create application
user-profile tables. Applications are responsible for storing required user data:
`postal_code`, `locality_name`, WGS84 `latitude/longitude`, and the optional
subdivision, plus recommended stable municipality ID, dataset version and point
provenance. Validate all inputs server-side through `resolveLocation`.

## Publication status

Redistribution conditions of imported municipal / postcode / OSM datasets must
be reviewed separately. Composer package *source code* can be published without
the generated nationwide database. Generated data must not be published until
licenses, provenance and required attribution are verified.

See `docs/RELEASE_CHECKLIST.md` for the publication and testing gates.
