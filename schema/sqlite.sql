PRAGMA foreign_keys = ON;

CREATE TABLE metadata (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE municipalities (
    ags TEXT PRIMARY KEY CHECK (length(ags) = 8),
    country_code TEXT NOT NULL DEFAULT 'DE',
    ars TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    display_name TEXT,
    type_code TEXT NOT NULL,
    type_label TEXT NOT NULL,
    admin_postal_code TEXT,
    centroid_latitude REAL,
    centroid_longitude REAL,
    centroid_source_date TEXT,
    CHECK ((centroid_latitude IS NULL) = (centroid_longitude IS NULL))
);

CREATE TABLE places (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ags TEXT NOT NULL REFERENCES municipalities(ags),
    postal_code TEXT NOT NULL CHECK (length(postal_code) = 5),
    latitude REAL NOT NULL,
    longitude REAL NOT NULL,
    relation_source TEXT NOT NULL,
    coordinate_source TEXT NOT NULL,
    coordinate_method TEXT NOT NULL,
    coordinate_accuracy INTEGER,
    UNIQUE (ags, postal_code)
);
CREATE INDEX idx_places_postal ON places(postal_code, ags);

CREATE TABLE subdivisions (
    subdivision_key TEXT PRIMARY KEY,
    ags TEXT NOT NULL REFERENCES municipalities(ags),
    type TEXT NOT NULL,
    name TEXT NOT NULL,
    parent_subdivision_key TEXT REFERENCES subdivisions(subdivision_key),
    display_name TEXT,
    latitude REAL,
    longitude REAL,
    coordinate_source TEXT,
    coordinate_reference TEXT,
    coordinate_method TEXT,
    coordinate_quality TEXT,
    hierarchy_method TEXT,
    administrative_level INTEGER,
    place_type TEXT,
    source TEXT,
    source_reference TEXT,
    CHECK ((latitude IS NULL) = (longitude IS NULL))
);
CREATE INDEX idx_subdivisions_ags ON subdivisions(ags, parent_subdivision_key, name);

CREATE TABLE place_subdivisions (
    place_id INTEGER NOT NULL REFERENCES places(id),
    subdivision_key TEXT NOT NULL REFERENCES subdivisions(subdivision_key),
    PRIMARY KEY (place_id, subdivision_key)
);
CREATE INDEX idx_place_subdivisions_subdivision ON place_subdivisions(subdivision_key);
