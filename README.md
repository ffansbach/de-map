de-map
======

http://www.freifunk-karte.de/

### Funktionsweise

Es wird die Freifunk-communityliste von https://raw.githubusercontent.com/freifunk/directory.api.freifunk.net/master/directory.json abgerufen.

Für jede Community wird das api-file abgerufen und nach nodeMaps - Knoten gesucht.

Nodemaps vom Typ Netmon, ffmap und openwifimap werden genutzt und jeweils per API oder bekanntem ajax-Verweis die Liste der Knoten abgerufen.

Für die Knoten werden notwendige Informationen zusammengetragen und in einer Datenstruktur abgelegt.
Diese wird in einem Dateicache abgelegt und 24 Stunden lang genutzt.

### Wie kommen bisher noch fehlende Daten in die Karte

Wenn Communities im Api-file verzeichnet sind, aber in der Karte fehlen, dann wurde keine verwendpare nodemap gefunden.

Ausweg ist das anbieten einer extra node-liste in folgendem Format: gist.github.com/StilgarBF/c21826994b775787f739

Für den Export dieser Liste habe ich 2 PHP-Klassen beispielhaft implementiert https://github.com/StilgarBF/nodelistexport

Die Karte wird dieses Format in Kürze auch verarbeiten.
