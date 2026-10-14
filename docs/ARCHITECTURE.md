# GEO_PLZ_Details - Architektur (Preview)

## Separation

- `data/geo-plz-details.sqlite`: standalone, read-only application catalog, not application user data.
- `data/geo-plz-details.sql.gz`: equivalent SQLite-format SQL dump for reconstructing the catalog.
- `data/build-report.json`: SHA256 lineage for all supplied sources, database, SQL dump, and schema.
- `src/GeoCatalog.php`: Composer PSR-4 PHP API, without Laravel dependency.
- `bin/geo-plz-details.php`: platform-independent PHP CLI.
- `scripts/build_database.py`: deterministic relational import, schema checks, coordinate validation, foreign keys, safe resume.
- `schema/sqlite.sql`: standalone schema; importing does not modify host application's tables.

## User profile integration

The consuming application, not this public catalog, stores:

- `postal_code`: required, five-digit string including any leading zero.
- `municipality_ags`: required, exact eight-digit municipality identity.
- `locality_name`: required displayable locality/street-free locality label.
- `subdivision_key`: nullable; identifies user's precise optional selection.
- `latitude`, `longitude`: required WGS84 reference coordinates; snapshot for distance search.
- `coordinate_level`, `coordinate_reference`: provenance of chosen coordinate.
- `geo_dataset_version`: snapshot of the catalog version.

The application MUST recompute or validate location on the server using
`GeoCatalog::resolveLocation()`; it must not accept arbitrary client-supplied coordinates.
The app owns visibility and privacy settings for the approximate user location.

## Coordinate resolution

1. Coordinate of selected subdivision if present and valid.
2. Coordinate of its nearest ancestor subdivision if present and valid.
3. Coordinate of matched postal-code/municipality place.
4. Municipality centroid, if available.
5. Otherwise fail. Never invent a location or use coordinates from a different municipality.

Coordinates in the current released V1 CSV are available for postal places and some
municipality centroids. The V1 `subdivisions.csv.gz` does **not** contain separate
subdivision coordinates or parent hierarchy. Thus it legitimately uses fallback 3.
Future release inputs can include stable `subdivision_key`, `parent_subdivision_key`,
`latitude`, `longitude`, `display_name`, and source attributes; code supports these.

The display name is independent of the coordinates and exact selection. An explicit
subdivision display label wins, otherwise the nearest explicitly labelled ancestor,
otherwise the selected subdivision's own name; absent selection, an explicit
municipality display label or its actual municipality name wins.
Never synthesize an automatic `-Mitte` suffix or claim an administrative relationship.

## Hierarchy and postal relationships

- A postcode can belong to multiple municipalities; municipality selection is required.
- A subdivision is selectable only if explicitly linked to the given postcode and AGS.
- Parent/child hierarchy is optional. Source-provided parent links must be within AGS.
- No raw or pending Postal C observation is silently accepted as a production relation.
- Previous evidence stages are not modified by this package.
- Small conflicts in postal boundary evidence are tracked separately and do not
  invalidate already published, validated choices.

## Radius search

`GeoCatalog::distanceKm()` implements great-circle (Haversine) distance using mean
Earth radius 6371.0088 km. This is a place-level approximation, not an exact home
location. A host application's user search must index snapshot coordinates, prefilter
candidates geographically, and refine with this calculation. Never equate a user's
coordinate snapshot with a proven home address.

## Future release compatibility

Source V1 subdivision keys are deterministically derived from `AGS/type/name` and
prefixed `legacy:`. When a source contains explicit `subdivision_key` columns, those
stable keys take precedence; a future release must provide a mapping between legacy
and new keys when existing user references are being migrated.

This pre-release contains no Laravel-specific controllers, migrations for application
user profiles, DB-hosted MariaDB export, or finished A/B/C formal acceptance. Those
are separate integration/release tasks.

## Composer installer (v0.3 architecture refinement)

The Composer dependency installs code only. A separate, explicit installer first
reuses a configured MySQL/MariaDB/SQLite application database with namespaced
`geo_plz_*` tables; otherwise it creates a private SQLite fallback. It does not
change the original application's existing GEO migrations/tables or user records.
This replaces the initial v0.1 assumption that every application should receive
its own separate SQLite catalog. See `docs/INSTALLER.md`.


## D.52 – Signed Release-Gate und technische Vorschau

Seit dem D.46/D.52-Sicherheitsvertrag lehnt `GeoCatalog` alle alten, unsignierten
V1-/V2-Releases sowie manuell gesetzte Freigabefelder ab.
Ein echter produktiver Import benötigt einen signierten vollständigen Release-Nachweis
und einen separat genehmigten Herausgeber-Schlüssel (derzeit leer).

Für gezielte private Tests mit dem noch **nicht** fachlich freigegebenen 16-Länder-Stand
existiert die ausdrücklich aktivierbare `InternalPreview`-API. Die isolierte D.51-Datenbank
bleibt außerhalb des Composer-/Git-Repositories. Siehe `PRIVATE_PREVIEW_DE.md`.
