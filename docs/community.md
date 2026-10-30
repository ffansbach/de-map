# Meine Community fehlt auf der Karte

*Für: Freifunk-Communities, die auf die Karte möchten oder nicht auftauchen.*  
[← Dokumentation](README.md) · [Übersicht](../README.md)

Der Weg auf die Karte führt über das **Freifunk-Verzeichnis**, nicht über dieses Projekt:

1. Deine Community steht im [Verzeichnis](https://github.com/freifunk/directory.api.freifunk.net)
   mit einer API-Datei (ffapi), und diese enthält unter `nodeMaps` mindestens eine Karte mit
   `technicalType` und `url`.
2. Hinter der Adresse muss eine Knotenliste erreichbar sein. Erkannt werden automatisch:
   - das **`nodelist`-Format** (bevorzugt; Schema in
     [`schema/nodelist-schema-1.0.1.json`](../schema/nodelist-schema-1.0.1.json)),
   - `nodes.json` im Format **nodeinfo** (ffmap, meshviewer, hopglass),
   - das flache **`meshviewer.json`**.

   Zeigt die Adresse nur auf eine Karten-Seite, sucht der Parser selbst nach `config.json`,
   `meshviewer.json` und `nodes.json`.
3. Die Knoten brauchen einen gültigen Standort. Knoten ohne Koordinaten werden nicht gezeigt.

**Nachsehen, was der Parser gefunden hat:** Auf der Debugview der Karte (`/debug.php`) wählst du deine
Community aus und siehst, ob Knoten gefunden wurden, wie viele übernommen oder als Dublette bzw. wegen
fehlendem Standort aussortiert wurden – oder, wenn keine Daten ankommen, welche Adressen abgefragt wurden
und was sie geantwortet haben. Die Ansicht wird bei jedem Parserlauf (stündlich) aktualisiert.

Wer selbst eine Knotenliste anbieten möchte, findet ein Beispiel im
[nodelist-Format](https://gist.github.com/StilgarBF/c21826994b775787f739) und PHP-Klassen für den
Export in [nodelistexport](https://github.com/StilgarBF/nodelistexport). Das Schema lässt sich z. B.
mit einem JSON-Schema-Validator gegen die eigenen Daten prüfen; der Parser selbst prüft nach dem
Schema nur weich: Abweichungen werden gemeldet, aber nicht abgelehnt.

Warum genau deine Community fehlt, zeigt `php bin/parser runs --community=SCHLUESSEL` bzw. die Seite
*Karten ohne Daten* der Admin-Oberfläche: dort steht, ob die API-Datei fehlt, keine Karte eingetragen
ist, ob unter der Adresse keine Daten gefunden wurden oder ob der Server nicht antwortet.
