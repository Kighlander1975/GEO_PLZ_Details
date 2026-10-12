#!/usr/bin/env python3
"""Offline package integration checks using a synthetic release, never real addresses."""
from __future__ import annotations

import csv
import gzip
import json
import shutil
import sqlite3
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent


def gz_csv(folder, name, fields, values):
    with gzip.open(folder / (name + '.csv.gz'), 'wt', encoding='utf-8', newline='') as output:
        writer = csv.DictWriter(output, fieldnames=fields)
        writer.writeheader()
        writer.writerows(values)


def run():
    with tempfile.TemporaryDirectory(prefix='geo-plz-details-test-') as base:
        base = Path(base)
        release = base / 'de-test-v1'
        release.mkdir()
        (release / 'manifest.json').write_text('{"fixture":true}\n', encoding='utf-8')
        gz_csv(release, 'municipalities',
               ['country_code','ags','ars','name','type_code','type_label','admin_postal_code',
                'centroid_latitude','centroid_longitude','centroid_source_date'], [
                   dict(country_code='DE',ags='03404000',ars='034040000000',name='Osnabrueck',
                        type_code='61',type_label='City',admin_postal_code='49086',
                        centroid_latitude='52.27',centroid_longitude='8.05',centroid_source_date='2024-12-31'),
                   dict(country_code='DE',ags='03405000',ars='034050000000',name='Second city',
                        type_code='61',type_label='City',admin_postal_code='49086',
                        centroid_latitude='52.4',centroid_longitude='8.2',centroid_source_date='2024-12-31'),
               ])
        gz_csv(release, 'places',
               ['ags','postal_code','latitude','longitude','relation_source',
                'coordinate_source','coordinate_method','coordinate_accuracy'], [
                   dict(ags='03404000',postal_code='49086',latitude='52.31',longitude='8.12',
                        relation_source='fixture',coordinate_source='fixture',coordinate_method='exact',coordinate_accuracy='1'),
                   dict(ags='03405000',postal_code='49086',latitude='52.41',longitude='8.21',
                        relation_source='fixture',coordinate_source='fixture',coordinate_method='exact',coordinate_accuracy='1'),
               ])
        gz_csv(release, 'subdivisions',
               ['ags','type','name','subdivision_key','parent_subdivision_key',
                'display_name','latitude','longitude','coordinate_source'], [
                   dict(ags='03404000',type='borough',name='Luestringen',subdivision_key='osm:r:1',
                        parent_subdivision_key='',display_name='Luestringen-Mitte',latitude='52.321',longitude='8.131',coordinate_source='fixture'),
                   dict(ags='03404000',type='neighbourhood',name='Luestringen-Ost',subdivision_key='osm:r:2',
                        parent_subdivision_key='osm:r:1',display_name='',latitude='52.325',longitude='8.137',coordinate_source='fixture'),
                   dict(ags='03404000',type='neighbourhood',name='Luestringen-WEST',subdivision_key='osm:r:3',
                        parent_subdivision_key='osm:r:1',display_name='',latitude='',longitude='',coordinate_source=''),
               ])
        gz_csv(release, 'place-subdivisions',
               ['ags','postal_code','subdivision_type','subdivision_name','subdivision_key'], [
                   dict(ags='03404000',postal_code='49086',subdivision_type='borough',subdivision_name='Luestringen',subdivision_key='osm:r:1'),
                   dict(ags='03404000',postal_code='49086',subdivision_type='neighbourhood',subdivision_name='Luestringen-Ost',subdivision_key='osm:r:2'),
                   dict(ags='03404000',postal_code='49086',subdivision_type='neighbourhood',subdivision_name='Luestringen-WEST',subdivision_key='osm:r:3'),
               ])
        dest = base / 'out'
        builder = [sys.executable, str(ROOT / 'scripts/build_database.py'),
                   '--release', str(release), '--output', str(dest)]
        first = subprocess.run(builder, capture_output=True, text=True, check=True)
        assert json.loads(first.stdout)['status'] == 'CREATED'
        second = subprocess.run(builder, capture_output=True, text=True, check=True)
        assert json.loads(second.stdout)['status'] == 'REUSED'
        db = dest / 'geo-plz-details.sqlite'
        dump = dest / 'geo-plz-details.sql.gz'
        conn = sqlite3.connect(db)
        conn.execute('PRAGMA foreign_keys=ON')
        assert conn.execute('PRAGMA integrity_check').fetchone()[0] == 'ok'
        assert conn.execute('SELECT COUNT(*) FROM municipalities').fetchone()[0] == 2
        assert conn.execute('SELECT COUNT(*) FROM places WHERE postal_code=?', ('49086',)).fetchone()[0] == 2
        assert conn.execute('SELECT COUNT(*) FROM subdivisions').fetchone()[0] == 3
        assert conn.execute('SELECT COUNT(*) FROM place_subdivisions').fetchone()[0] == 3
        assert conn.execute("SELECT parent_subdivision_key FROM subdivisions WHERE subdivision_key='osm:r:3'").fetchone()[0] == 'osm:r:1'
        conn.close()
        restored = sqlite3.connect(':memory:')
        with gzip.open(dump, 'rt', encoding='utf-8') as stream:
            restored.executescript(stream.read())
        assert restored.execute('SELECT COUNT(*) FROM places').fetchone()[0] == 2
        restored.close()
        with (dest / 'build-report.json').open('r', encoding='utf-8') as handle:
            report = json.load(handle)
        assert report['full_postal_c_acceptance'] is False
        assert report['counts']['subdivisions'] == 3
        php = shutil.which('php')
        if php:
            php_modules = subprocess.run([php, '-m'], capture_output=True, text=True, check=True).stdout.lower()
            if 'pdo_sqlite' in php_modules:
                subprocess.run([php, str(ROOT / 'tests/test_php_catalog.php'), str(db)], check=True)
                subprocess.run([php, str(ROOT / 'tests/test_installation.php'), str(release), str(base / 'test-fallback-app')], check=True)
            else:
                print('[SKIP] PHP runtime integration (pdo_sqlite not installed)')
        print('[PASS] Import, integrity, hierarchy, postcode multiplicity, SQL dump, lineage, safe resume')


if __name__ == '__main__':
    run()
