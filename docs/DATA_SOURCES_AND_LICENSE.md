# Datenherkunft, Nutzungsrechte und Veröffentlichung

Der GEO-Katalog wird aus einem lokalen versionierten Release erzeugt, nicht durch einen unkontrollierten Download während der Paketinstallation. Die Eingangsdateien `municipalities.csv.gz`, `places.csv.gz`, `subdivisions.csv.gz` und `place-subdivisions.csv.gz` werden gehasht und im Bericht nachvollziehbar referenziert.

Die bisherige Datenbasis kombiniert amtliche Gemeinde-/AGS-Informationen, Postleitzahlrelationen aus OpenPLZ, vorhandene Ortskoordinaten und OSM-abgeleitete Ortsteilnamen. **Die Lizenzbedingungen der verschiedenen Quellen sind getrennt zu prüfen** (einschließlich Datenbankrechten und Attribution).

**Noch nicht freigegeben:** ein bevölkerter Deutschland-Dump, Rohdaten/Downloads, räumliche Zwischenstände und ungeprüfte postalische C-Zuordnungen. Es darf kein Dump oder Datenarchiv ins öffentliche Git-Repository hochgeladen werden, bis Quellversionen, Rechte und Pflichten dokumentiert sowie bestätigt wurden.

**Quellcode-Lizenz:** derzeit `proprietary` als Platzhalter für den nicht freigegebenen Entwicklungsstand. Vor öffentlicher Nachnutzung muss der Repository-Inhaber ausdrücklich eine Code-Lizenz wählen, etwa MIT oder Apache-2.0 (jeweils separat von Datenlizenzen), und die entsprechende `LICENSE`-Datei bereitstellen. Das Paket gibt derzeit **keine freie Lizenz** vor.

Die technischen SHA256-Protokolle beweisen Dateiidentität, nicht Rechtskonformität oder amtliche Korrektheit. Die bisherige Postal-C-Evidenz bleibt ausdrücklich nicht final akzeptiert.
