# GEO_PLZ_Details - Datenlizenzen und Freigaberegeln

Stand: 2026-10-12

## 1. Grundsatz

Der PHP-Quellcode steht unter MIT.

Copyright (c) 2026 Kighlander.

Die Datenbank und ihre Quelldaten haben
eigenstaendige Lizenz- und Copyrightbedingungen.

Die MIT-Lizenz des Codes ist keine Lizenz
fuer fremde geografische Daten.

## 2. OpenStreetMap und OpenPLZ

OpenStreetMap contributors:
https://www.openstreetmap.org/copyright

ODbL 1.0:
https://opendatacommons.org/licenses/odbl/1-0/

OpenPLZ:
https://github.com/openpotato/openplzapi.data

Geofabrik:
https://download.geofabrik.de/

Historischer OSM-Datenstand: 2026-09-01.

Die ODbL erlaubt auch gewerbliche Nutzung.
Abgeleitete Datenbanken unterliegen den
anwendbaren ODbL-Bedingungen.

Diese umfassen insbesondere Attribution,
Share-Alike und gegebenenfalls die
Bereitstellung der abgeleiteten Datenbank
oder lizenzgemaess zulaessiger Alternativen.

## 3. GeoNames

Originalquelle:
https://download.geonames.org/export/zip/DE.zip

Lizenzhinweise:
https://download.geonames.org/export/zip/readme.txt

Die Original-README bezeichnet die Daten
ausdruecklich als CC BY 4.0, verlinkt jedoch
noch auf CC BY 3.0.

Diese Inkonsistenz ist dokumentiert.

Eine pauschale Kompatibilitaet zwischen
CC-BY-Datenbankrechten und ODbL wird
nicht vorausgesetzt.

GeoNames-Daten und daraus abgeleitete
Koordinaten duerfen daher nur dann in
einen oeffentlichen kombinierten Release
einfliessen, wenn eine tragfaehige
Rechtsgrundlage fuer die Kombination
und deren konkrete Verbreitung besteht.

Alternativen sind beispielsweise:
- nachgewiesene gesonderte Erlaubnis,
- rechtlich und technisch getrennte Daten,
- Ersatz durch kompatible Quellen,
- Ausschluss entsprechender Inhalte.

Die Entscheidung muss releasebezogen
und nachvollziehbar dokumentiert werden.

## 4. Destatis

Verwendete amtliche Quellen:
- Gemeindeverzeichnis GV100AD
- Gemeindeverzeichnis-Auszug

Herausgeber:
Statistisches Bundesamt und gegebenenfalls
Statistische Aemter der Laender.

https://www.destatis.de/

Allgemeine Nutzungsbedingungen:
https://www.destatis.de/DE/Service/Impressum/copyright-allgemein.html

Die allgemeinen Veroeffentlichungsbedingungen
ermoeglichen grundsaetzlich Weiterverwendung
unter Beachtung der geltenden Quellenhinweise.

Vor einem oeffentlichen kombinierten Release
sind die Bedingungen des konkreten Produkts,
Drittrechte und eventuelle Einschraenkungen
zu verifizieren.

Die Quellen werden nicht allein aufgrund
allgemeiner Nutzungsbedingungen automatisch
unter ODbL gestellt.

## 5. Deutsche Post

Historische Aenderungs-PDFs:
Deutsche Post AG.

Keine pauschale Weitergabelizenz bestaetigt.

Die Originaldokumente werden nicht Bestandteil
des oeffentlichen Composer-Pakets.

Die historische Relation

31536 / AGS 03241012

hat den Herkunftscode deutsche_post_delta.

Eine unabhaengige amtliche Bestaetigung
durch die Stadt Neustadt am Ruebenberge
wurde dokumentiert.

Siehe docs/POSTAL_EVIDENCE.md.

Historische Provenienz darf nicht
nachtraeglich ohne Nachweis umgeschrieben werden.

## 6. Historische Datenbasis

Release: de-2026-09-v1.

PLZ-Gemeinde-Beziehungen:

- 12.745 openplz
- 86 geonames_verified
- 5 openplz_remapped
- 2 manual_review
- 1 deutsche_post_delta

Gesamt: 12.839 Beziehungen.

Diese Werte bezeichnen relation_source.

ACHTUNG:
Eine Beziehung aus OpenPLZ kann trotzdem
Koordinaten aus GeoNames verwenden.

Deshalb muss die Datenherkunft mindestens
auch fuer Koordinaten, Namen, Zuordnungen
und weitere uebernommene Inhalte
feldbezogen geprueft werden.

Das historische kombinierte Release
ist kein oeffentlich freigegebenes Datenprodukt.

## 7. Verbindliche Freigaberegeln

Vor jeder oeffentlichen Datenveroeffentlichung:

1. Exakte Quellstaende und URLs nachweisen.
2. Quell-Fingerprints und SHA256 dokumentieren.
3. Feldbezogene Datenherkunft pruefen.
4. Lizenz und Rechteinhaber jeder Quelle erfassen.
5. Rechtliche Kombinierbarkeit verifizieren.
6. Erforderliche Copyrights und Attributionen beilegen.
7. ODbL-Verpflichtungen erfuellen.
8. Alle nicht freigegebenen Bestandteile ausschliessen.
9. Reproduzierbaren Build dokumentieren.
10. Release-Manifest und Integritaet verifizieren.
11. Datenrelease ausdruecklich freigeben.

Technisch gruene Tests allein ersetzen
keine rechtliche Datenfreigabe.

## 8. Aktueller Status

CODE_LICENSE: MIT
CODE_LICENSE_STATUS: FINAL

DATA_LICENSE_POLICY: DOCUMENTED
OSM_DERIVED_LICENSE: ODbL-1.0
GEONAMES_NOTICE: CC-BY-4.0_WITH_OLD_3.0_LINK
DESTATIS_PRODUCT_RIGHTS: REVIEW_REQUIRED
DEUTSCHE_POST_DATA_REUSE: NOT_CLEARED

HISTORICAL_MIXED_RELEASE: NOT_APPROVED
PUBLIC_DATA_RELEASE: BLOCKED

Die Lizenzstrategie ist festgelegt.

Die konkrete oeffentliche Freigabe einer
kombinierten Deutschland-Datenbank bleibt
von einer separaten Rechtepruefung abhaengig.
