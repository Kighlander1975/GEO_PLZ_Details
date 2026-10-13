#!/usr/bin/env python3
"""Build a portable, audited SQLite GEO catalog and SQL dump from release CSVs."""

from __future__ import annotations

import argparse
import csv
import gzip
import hashlib
import json
import re
import sqlite3
import sys
import tempfile
from datetime import datetime, timezone
from pathlib import Path

EXPECTED_COLUMNS = {
    "municipalities": {"country_code", "ags", "ars", "name", "type_code", "type_label", "admin_postal_code", "centroid_latitude", "centroid_longitude", "centroid_source_date"},
    "places": {"ags", "postal_code", "latitude", "longitude", "relation_source", "coordinate_source", "coordinate_method", "coordinate_accuracy"},
    "subdivisions": {"ags", "type", "name"},
    "place-subdivisions": {"ags", "postal_code"},
}


def require(ok: bool, message: str) -> None:
    if not ok:
        raise ValueError(message)


def digest(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest().upper()


def rows_of(release: Path, dataset: str):
    path = release / (dataset + ".csv.gz")
    require(path.is_file(), f"Missing input file: {path}")
    with gzip.open(path, "rt", encoding="utf-8-sig", newline="") as stream:
        reader = csv.DictReader(stream)
        require(reader.fieldnames is not None, f"Missing CSV header: {path}")
        require(EXPECTED_COLUMNS[dataset] <= set(reader.fieldnames),
                f"Incompatible {dataset} schema: {reader.fieldnames}")
        if dataset == "place-subdivisions":
            headers = set(reader.fieldnames)
            has_key = "subdivision_key" in headers
            has_legacy = {"subdivision_type", "subdivision_name"} <= headers
            require(
                has_key or has_legacy,
                f"Missing place-subdivision identity columns: {path}"
            )
        for line_no, row in enumerate(reader, start=2):
            require(None not in row, f"Too many CSV columns: {path}:{line_no}")
            yield row


def val(row, name):
    raw = row.get(name)
    return raw.strip() if raw is not None and raw.strip() != "" else None


def coord(value, kind, where):
    if value is None:
        return None
    try:
        result = float(value)
    except ValueError as error:
        raise ValueError(f"Invalid {kind} coordinate: {where}: {value}") from error
    if not (abs(result) <= (90 if kind == "latitude" else 180)):
        raise ValueError(f"Out of range {kind} coordinate: {where}: {value}")
    return result


def coordinate_pair(row, lat_col, lon_col, where, required=False):
    latitude = coord(val(row, lat_col), "latitude", where)
    longitude = coord(val(row, lon_col), "longitude", where)
    require((latitude is None) == (longitude is None),
            f"Incomplete coordinate pair: {where}")
    require(not required or latitude is not None,
            f"Required coordinates missing: {where}")
    return latitude, longitude


def postcode(value, where, nullable=False):
    if nullable and value is None:
        return None
    require(bool(value) and re.fullmatch(r"[0-9]{5}", value) is not None,
            f"Invalid German postcode: {where}: {value!r}")
    return value


def ags_check(value, where):
    require(bool(value) and re.fullmatch(r"[0-9]{8}", value) is not None,
            f"Invalid AGS: {where}: {value!r}")
    return value


def legacy_subdivision_key(ags: str, typ: str, name: str) -> str:
    h = hashlib.sha256((typ + "\0" + name).encode("utf-8")).hexdigest()[:24]
    return "legacy:" + ags + ":" + h


def get_display_overrides(path: Path | None):
    if path is None:
        return {}, {}, None
    require(path.is_file(), f"Display override file not found: {path}")
    municipalities = {}
    subdivisions = {}
    with path.open("rt", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        require(reader.fieldnames is not None and
                {"kind", "ags", "type", "name", "display_name"} <= set(reader.fieldnames),
                "Display override header must include kind,ags,type,name,display_name")
        for row in reader:
            kind = val(row, "kind")
            ags = ags_check(val(row, "ags"), "display override")
            display = val(row, "display_name")
            require(display is not None, "Empty display override name")
            if kind == "municipality":
                require(ags not in municipalities, f"Duplicate municipality override: {ags}")
                municipalities[ags] = display
            elif kind == "subdivision":
                identity = (ags, val(row, "type"), val(row, "name"))
                require(all(identity), f"Incomplete subdivision override: {identity}")
                require(identity not in subdivisions, f"Duplicate subdivision override: {identity}")
                subdivisions[identity] = display
            else:
                raise ValueError(f"Invalid display override kind: {kind}")
    return municipalities, subdivisions, digest(path)


def populate(connection: sqlite3.Connection, release: Path, schema: Path,
             mun_displays: dict, sub_displays: dict, version: str,
             source_hashes: dict):
    connection.executescript(schema.read_text(encoding="utf-8"))
    connection.execute("BEGIN IMMEDIATE")
    for key, value in sorted({
        "format_version": "1",
        "dataset_version": version,
        "source_manifest_sha256": source_hashes.get("manifest.json", ""),
        "coordinate_system": "EPSG:4326",
        "subdivision_coordinate_fallback": "selected > ancestor > postal_place > municipality",
    }.items()):
        connection.execute("INSERT INTO metadata (key, value) VALUES (?, ?)", (key, value))

    municipality_ids = set()
    for row in rows_of(release, "municipalities"):
        ags = ags_check(val(row, "ags"), "municipalities")
        lat, lon = coordinate_pair(row, "centroid_latitude", "centroid_longitude", f"municipality {ags}")
        municipality_ids.add(ags)
        connection.execute("""INSERT INTO municipalities
            (ags,country_code,ars,name,display_name,type_code,type_label,admin_postal_code,
             centroid_latitude,centroid_longitude,centroid_source_date)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)""",
            (ags, val(row, "country_code"), val(row, "ars"), val(row, "name"),
             mun_displays.get(ags) or val(row, "display_name"), val(row, "type_code"), val(row, "type_label"),
             postcode(val(row, "admin_postal_code"), ags, nullable=True),
             lat, lon, val(row, "centroid_source_date")))
    require(len(municipality_ids) > 0, "Municipality dataset empty")

    place_ids = {}
    for row in rows_of(release, "places"):
        ags = ags_check(val(row, "ags"), "places")
        plz = postcode(val(row, "postal_code"), ags)
        lat, lon = coordinate_pair(row, "latitude", "longitude", f"place {ags}/{plz}", required=True)
        require(ags in municipality_ids, f"Place references unknown municipality: {ags}/{plz}")
        cursor = connection.execute("""INSERT INTO places
            (ags,postal_code,latitude,longitude,relation_source,coordinate_source,
             coordinate_method,coordinate_accuracy) VALUES (?,?,?,?,?,?,?,?)""",
            (ags, plz, lat, lon, val(row, "relation_source"), val(row, "coordinate_source"),
             val(row, "coordinate_method"), int(row["coordinate_accuracy"]) if val(row, "coordinate_accuracy") else None))
        place_ids[(ags, plz)] = cursor.lastrowid
    require(len(place_ids) > 0, "Places dataset empty")

    subdivision_keys = {}
    pending_parents = {}
    for row in rows_of(release, "subdivisions"):
        ags = ags_check(val(row, "ags"), "subdivisions")
        typ = val(row, "type")
        name = val(row, "name")
        require(typ is not None and name is not None, f"Subdivision identity missing: {ags}")
        key = val(row, "subdivision_key") or legacy_subdivision_key(ags, typ, name)
        require(ags in municipality_ids, f"Subdivision refers to unknown municipality: {key}")
        lat, lon = coordinate_pair(row, "latitude", "longitude", f"subdivision {key}")
        identity = (ags, typ, name)
        if identity in subdivision_keys:
            # V2 permits the same type/name below different parents, but in
            # that case the place-subdivisions CSV must provide explicit keys.
            require(val(row, "subdivision_key") is not None,
                    f"Duplicate V1 subdivision identity: {identity}. Explicit V2 key required.")
            subdivision_keys[identity] = None
        else:
            subdivision_keys[identity] = key
        parent = val(row, "parent_subdivision_key")
        if parent is not None:
            pending_parents[key] = parent
        level = val(row, "administrative_level")
        connection.execute("""INSERT INTO subdivisions
            (subdivision_key,ags,type,name,parent_subdivision_key,display_name,
             latitude,longitude,coordinate_source,coordinate_reference,coordinate_method,
             coordinate_quality,hierarchy_method,administrative_level,place_type,
             source,source_reference)
            VALUES (?,?,?,?,NULL,?,?,?,?,?,?,?,?,?,?,?,?)""",
            (key, ags, typ, name,
             sub_displays.get(identity) or val(row, "display_name"),
             lat, lon, val(row, "coordinate_source"), val(row, "coordinate_reference"),
             val(row, "coordinate_method"), val(row, "coordinate_quality"),
             val(row, "hierarchy_method"), int(level) if level else None,
             val(row, "place_type"), val(row, "source"), val(row, "source_reference")))

    for child_key, parent_key in pending_parents.items():
        child = connection.execute("SELECT ags FROM subdivisions WHERE subdivision_key=?", (child_key,)).fetchone()
        parent = connection.execute("SELECT ags FROM subdivisions WHERE subdivision_key=?", (parent_key,)).fetchone()
        require(parent is not None and child is not None and parent[0] == child[0],
                f"Invalid parent subdivision link: {child_key} -> {parent_key}")
        require(child_key != parent_key, f"Self-parent subdivision: {child_key}")
        connection.execute("UPDATE subdivisions SET parent_subdivision_key=? WHERE subdivision_key=?",
                           (parent_key, child_key))

    for row in rows_of(release, "place-subdivisions"):
        ags = ags_check(val(row, "ags"), "place-subdivisions")
        plz = postcode(val(row, "postal_code"), ags)
        place_id = place_ids.get((ags, plz))
        require(place_id is not None, f"Unknown place-subdivision place: {ags}/{plz}")
        key = val(row, "subdivision_key")
        if key is None:
            identity = (ags, val(row, "subdivision_type"), val(row, "subdivision_name"))
            key = subdivision_keys.get(identity)
        require(key is not None, f"Unknown subdivision for {ags}/{plz}: {row}")
        existing = connection.execute("SELECT ags FROM subdivisions WHERE subdivision_key=?", (key,)).fetchone()
        require(existing is not None and existing[0] == ags,
                f"Cross-municipality place-subdivision link: {ags}/{plz}/{key}")
        connection.execute("INSERT INTO place_subdivisions (place_id,subdivision_key) VALUES (?,?)",
                           (place_id, key))

    for ags in mun_displays:
        require(ags in municipality_ids, f"Unknown municipality display override: {ags}")
    for identity in sub_displays:
        require(identity in subdivision_keys and subdivision_keys[identity] is not None,
                f"Unknown or ambiguous subdivision display override: {identity}")

    for row in connection.execute("SELECT subdivision_key,parent_subdivision_key FROM subdivisions"):
        visited = {row[0]}
        parent = row[1]
        while parent:
            require(parent not in visited, f"Subdivision parent cycle: {row[0]}")
            visited.add(parent)
            next_row = connection.execute("SELECT parent_subdivision_key FROM subdivisions WHERE subdivision_key=?", (parent,)).fetchone()
            require(next_row is not None, f"Missing parent: {parent}")
            parent = next_row[0]

    connection.commit()
    check = connection.execute("PRAGMA integrity_check").fetchone()[0]
    require(check == "ok", f"SQLite integrity check: {check}")
    fk = connection.execute("PRAGMA foreign_key_check").fetchall()
    require(not fk, f"Foreign key check failed: {fk[:5]}")
    return {name: connection.execute(f"SELECT COUNT(*) FROM {name}").fetchone()[0]
            for name in ("municipalities", "places", "subdivisions", "place_subdivisions")}


def build(release: Path, dest: Path, override_path: Path | None):
    schema = Path(__file__).resolve().parent.parent / "schema/sqlite.sql"
    require(schema.is_file(), "Missing schema/sqlite.sql")
    require(release.is_dir(), f"Release not found: {release}")
    dest.mkdir(parents=True, exist_ok=True)
    db = dest / "geo-plz-details.sqlite"
    dump = dest / "geo-plz-details.sql.gz"
    report_path = dest / "build-report.json"
    manifest = release / "manifest.json"
    required = [release / (key + ".csv.gz") for key in EXPECTED_COLUMNS]
    for path in required:
        require(path.is_file(), f"Release input missing: {path}")
    source_hashes = {p.name: digest(p) for p in required}
    if manifest.is_file():
        source_hashes["manifest.json"] = digest(manifest)
    display_mun, display_sub, override_hash = get_display_overrides(override_path)
    if override_hash:
        source_hashes["display-overrides.csv"] = override_hash
    schema_sha = digest(schema)
    if report_path.is_file() and db.is_file() and dump.is_file():
        existing = json.loads(report_path.read_text(encoding="utf-8"))
        require(existing["dataset_version"] == release.name and
                existing["source_sha256"] == source_hashes and
                existing["schema_sha256"] == schema_sha and
                existing["database_sha256"] == digest(db) and
                existing["sql_dump_sha256"] == digest(dump),
                "Existing database is different. Refusing to overwrite. Use a fresh destination directory.")
        print(json.dumps({"status": "REUSED", **existing}, ensure_ascii=False, indent=2))
        return
    require(not any(p.exists() for p in (db, dump, report_path)),
            "Partial output exists. Refusing to overwrite; inspect or use a new directory.")

    with tempfile.TemporaryDirectory(prefix="geo-plz-details-build-", dir=dest) as tmpname:
        tmp = Path(tmpname)
        tmp_db = tmp / "geo-plz-details.sqlite"
        tmp_dump = tmp / "geo-plz-details.sql.gz"
        conn = sqlite3.connect(tmp_db)
        try:
            conn.execute("PRAGMA foreign_keys = ON")
            counts = populate(conn, release, schema, display_mun, display_sub, release.name, source_hashes)
            with gzip.open(tmp_dump, "wt", encoding="utf-8", newline="\n", compresslevel=9) as handle:
                for line in conn.iterdump():
                    handle.write(line + "\n")
        finally:
            conn.close()
        report = {
            "format_version": 1,
            "dataset_version": release.name,
            "counts": counts,
            "source_sha256": source_hashes,
            "schema_sha256": schema_sha,
            "database_sha256": digest(tmp_db),
            "sql_dump_sha256": digest(tmp_dump),
            "coordinate_system": "EPSG:4326",
            "full_postal_c_acceptance": False,
            "production_postal_c_release": False,
            "note": "Only relationships present in the supplied release are exported. No pending C observations are accepted.",
        }
        tmp_report = tmp / "build-report.json"
        tmp_report.write_text(json.dumps(report, indent=2, ensure_ascii=False, sort_keys=True) + "\n", encoding="utf-8")
        # Output files are published only after all checks succeed.
        tmp_db.replace(db)
        tmp_dump.replace(dump)
        tmp_report.replace(report_path)
    print(json.dumps({"status": "CREATED", **report}, ensure_ascii=False, indent=2))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--release", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--display-overrides", type=Path)
    args = parser.parse_args()
    build(args.release.resolve(), args.output.resolve(), args.display_overrides.resolve() if args.display_overrides else None)


if __name__ == "__main__":
    try:
        main()
    except (ValueError, sqlite3.DatabaseError, OSError) as exc:
        print(f"[FAIL] {exc}", file=sys.stderr)
        sys.exit(1)
