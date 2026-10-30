de-map
======

http://www.freifunk-karte.de/

### Funktionsweise

Ein Cronjob startet stündlich `bin/parser run` (PHP-Kommandozeile, siehe PRD.md und CLAUDE.md).

Es wird die Freifunk-communityliste von https://raw.githubusercontent.com/freifunk/directory.api.freifunk.net/master/directory.json abgerufen. Für jede Community wird das api-file abgerufen und nach nodeMaps gesucht. Die Knotenliste wird am Inhalt erkannt (nodelist, nodeinfo von ffmap/meshviewer/hopglass, flaches meshviewer.json), nicht am angegebenen Typ.

Für die Knoten werden die notwendigen Informationen zusammengetragen und als `result_*.json` an den Webserver übertragen (`web/upload.php`). Die Karte (`index.php`, `js/meta_map.js`) liest nur diese Dateien über `data.php`.

Der Verlauf der Läufe und was daran auffällig ist, zeigt die Admin-Oberfläche (`public-admin/`, nur lesend).

### Wie kommen bisher noch fehlende Daten in die Karte

Wenn Communities im Api-file verzeichnet sind, aber in der Karte fehlen, dann wurde keine verwendpare nodemap gefunden.

Ausweg ist das anbieten einer extra node-liste in folgendem Format: gist.github.com/StilgarBF/c21826994b775787f739
Das JSON-Schema ist in schema/nodelist-schema.json zu finden, gegen das man die eigenen Daten validieren kann. Z.B. unter http://jsonschemalint.com können Daten und Schema aufeinander validiert werden. Eine Lösung für die Kommandozeile folgt.

Für den Export dieser Liste habe ich 2 PHP-Klassen beispielhaft implementiert https://github.com/StilgarBF/nodelistexport

Die Karte wird dieses Format in Kürze auch verarbeiten.
