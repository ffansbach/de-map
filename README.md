<h1 align="center">Freifunk-Karte</h1>

<p align="center">
  <strong>Alle Freifunk-Knoten Deutschlands auf einer Karte.</strong><br>
  Die Daten kommen direkt von den Communities – die Karte sammelt, prüft und zeigt sie an.
</p>

<p align="center">
  <a href="https://www.freifunk-karte.de/">Live-Karte</a> ·
  <a href="#schnellstart-lokal-ausprobieren">Schnellstart</a> ·
  <a href="#produktivbetrieb-schritt-für-schritt">Produktivbetrieb</a> ·
  <a href="docs/konfiguration.md">Konfiguration</a> ·
  <a href="docs/betrieb.md">Fehlersuche</a> ·
  <a href="docs/README.md">Dokumentation</a> ·
  <a href="https://github.com/ffansbach/de-map/issues">Fehler melden</a>
</p>

<p align="center">
  <a href="https://github.com/ffansbach/de-map/actions/workflows/ci.yml"><img alt="CI" src="https://github.com/ffansbach/de-map/actions/workflows/ci.yml/badge.svg?branch=main"></a>
  <img alt="Lizenz: GPL v2" src="https://img.shields.io/badge/Lizenz-GPL%20v2-blue">
  <img alt="PHP 8.4+" src="https://img.shields.io/badge/PHP-8.4%2B-777BB4">
  <img alt="Seit 2015 in Betrieb" src="https://img.shields.io/badge/in%20Betrieb-seit%202015-brightgreen">
</p>

---

## Inhalt

- [Was ist das?](#was-ist-das)
- [So funktioniert es](#so-funktioniert-es)
- [Voraussetzungen](#voraussetzungen)
- [Schnellstart: lokal ausprobieren](#schnellstart-lokal-ausprobieren)
- [Produktivbetrieb Schritt für Schritt](#produktivbetrieb-schritt-für-schritt)
- [Dokumentation](#dokumentation)
- [Meine Community fehlt auf der Karte](#meine-community-fehlt-auf-der-karte)
- [Lizenz und Danksagung](#lizenz-und-danksagung)

## Was ist das?

Die Freifunk-Karte führt die Knotenlisten fast aller deutschen Freifunk-Communities auf einer
einzigen [Leaflet](https://leafletjs.com/)-Karte zusammen – derzeit mehr als 25.000 Knoten aus rund
280 Communities (Stand Oktober 2026).

**Für Besucher**

- Eine Karte für ganz Deutschland; Offline-Knoten sind grau markiert, Cluster zeigen die Verteilung von online, offline und unbekannt
- Clustering auch bei zehntausenden Knoten, Heatmaps für Router und Nutzer
- Direktlink auf die aktuelle Ansicht, GPX-Export (alle Knoten oder nur der sichtbare Ausschnitt)

**Für Betreiber**

- **Erkennt das Datenformat am Inhalt**, nicht am angegebenen Typ: `nodelist`, `nodeinfo`
  (ffmap, meshviewer, hopglass) und das flache `meshviewer.json`
- **Schnell:** alle ~700 Abrufe laufen parallel, ein kompletter Lauf dauert etwa 30–40 Sekunden
- **Ausfallsicher:** antwortet der Server einer Community vorübergehend nicht, bleiben ihre zuletzt
  bekannten Daten bis zu 24 Stunden auf der Karte
- **Nachvollziehbar:** jeder Lauf wird in einer SQLite-Datei festgehalten, eine Admin-Oberfläche
  zeigt, was sich verändert hat und welche Karten keine Daten liefern
- **Sicher:** TLS-Prüfung, Größenlimits, signierter Upload (HMAC) vom Parser zum Webserver
- **Schlank im Betrieb:** alles für den Webserver liegt fertig im Verzeichnis [`web/`](web/). Du lädst es
  hoch – ohne Datenbank, ohne Composer, ohne Build-Schritte, ohne Shell auf dem Server

## So funktioniert es

Die Arbeit ist zweigeteilt: Ein **Parser** holt und prüft die Daten (aufwendig, läuft per Cronjob),
der **Webserver** liefert nur fertige Dateien aus (billig, läuft bei jedem Seitenaufruf).

```
  Freifunk-Verzeichnis (directory.api.freifunk.net)
        │  directory.json → API-Datei je Community → Karten / Knotenlisten
        ▼
 ┌───────────────────── Parser-Server (PHP ≥ 8.4, CLI, Cronjob) ─────────────────────┐
 │  bin/parser run         holt alles parallel, erkennt Formate, entfernt Dubletten    │
 │  var/parser.sqlite      Verlauf aller Läufe        Admin-Oberfläche (nur lesend)    │
 └──────────────────────────────────────┬──────────────────────────────────────────────┘
                                        │  ein signierter, gzip-komprimierter POST (HTTPS)
                                        ▼
 ┌──────────────────── Webserver (PHP ≥ 7.2, kein Composer) ───────────────────────────┐
 │  upload.php  →  cache/result_*.json  →  data.php  →  index.php + js/meta_map.js      │
 └─────────────────────────────────────────────────────────────────────────────────────┘
                                        │
                                        ▼
                                 Browser (Leaflet)
```

Ein Lauf im Einzelnen:

1. Das Verzeichnis der Communities wird geladen (`directory.json` aus dem Repository
   [freifunk/directory.api.freifunk.net](https://github.com/freifunk/directory.api.freifunk.net)).
2. Für jede Community wird ihre API-Datei geholt und nach `nodeMaps` durchsucht.
3. Bei Karten-Seiten (z. B. Meshviewer) wird gesucht, wo die Daten liegen (`config.json`,
   `meshviewer.json`, `nodes.json`). Gefundene Adressen werden 7 Tage gemerkt.
4. Die Knoten werden gelesen, geprüft (Standort, Koordinaten, Status), dedupliziert und in
   `result_*.json` geschrieben (dazu `result_debug.json` für die öffentliche Debugview `debug.php`).
5. Der Upload liefert die Dateien an den Webserver; dort ersetzt `upload.php` sie atomar.
6. Der Lauf wird in der SQLite-Datei festgehalten und mit dem vorigen verglichen; Auffälliges löst
   Ereignisse aus (und ggf. einen Alarm bei healthchecks.io).

Regeln für die Daten: Knoten ohne Standort oder mit unsinnigen Koordinaten (0/0, außerhalb des
Wertebereichs) erscheinen nicht. Offline-Knoten bleiben drei Tage sichtbar, sofern der Zeitpunkt
ihres letzten Kontakts bekannt ist.

## Voraussetzungen

|  | Parser-Server | Webserver (Karte) |
|---|---|---|
| **PHP** | **8.4.1 oder neuer** (CLI) | **7.2 oder neuer** |
| **Erweiterungen** | `curl`, `json`, `mbstring`, `pdo_sqlite`, `zlib`; `ftp` nur für den FTP-Upload | `zlib` (Upload), `json`; `mysqli` nur für die optionale Statistik |
| **Composer** | ja | nein |
| **Datenbank** | SQLite-Datei (wird selbst angelegt) | keine (nur optional MySQL/MariaDB für die Statistik) |
| **Netzwerk** | ausgehend HTTPS zu GitHub und zu allen Community-Servern | eingehend HTTPS vom Parser |
| **Software** | Cron, `flock` | Apache oder nginx mit PHP (FPM oder mod_php), HTTPS |


Parser und Webserver können **auf derselben Maschine** laufen, müssen es aber nicht – und in der
Praxis ist es oft ein kleiner Container für den Parser und ein normaler Webspace für die Karte.
Wichtig ist nur, dass der Parser den Webserver per HTTPS erreicht und beide Uhren stimmen (Toleranz
±5 Minuten).

## Schnellstart: lokal ausprobieren

In fünf Minuten zur eigenen Karte auf dem Rechner (Parser und Webserver in einem Verzeichnis):

```bash
git clone https://github.com/ffansbach/de-map.git freifunk-karte
cd freifunk-karte

composer install                          # Abhängigkeiten des Parsers
cp web/config.php_example.php web/config.php   # Karten-Einstellungen (Kachelserver, Startansicht)

php bin/parser run --out=web/cache        # holt alle Daten, ca. 30–60 Sekunden
php -S localhost:8000 -t web              # Entwicklungsserver für die Karte starten
```

Dann <http://localhost:8000/> öffnen. `bin/parser run` schreibt vier Dateien nach `web/cache/`
(`result_routers.json`, `result_communities.json`, `result_metacommunities.json`, `result_debug.json`)
und legt den Verlauf in `var/parser.sqlite` ab. Mit `php bin/parser runs`
siehst du anschließend, was der Lauf gefunden hat.

> `--out` hat bewusst keinen Standardwert: Ein Testlauf soll nie versehentlich die Dateien einer
> laufenden Karte überschreiben.

## Produktivbetrieb Schritt für Schritt

Zwei Rechner (oder derselbe): der **Parser-Server** sammelt und prüft die Daten, der **Webserver** zeigt
die Karte. Die Reihenfolge ist wichtig, weil das Geheimnis für den Upload auf beiden Seiten gebraucht wird.

| # | Schritt | Wo | Anleitung |
|---|---|---|---|
| 1 | Parser installieren | Parser-Server | [Schritt 1](docs/parser-server.md#schritt-1-parser-installieren) |
| 2 | Upload-Geheimnis erzeugen | Parser-Server | [Schritt 2](docs/parser-server.md#schritt-2-upload-geheimnis-erzeugen) |
| 3 | Webserver einrichten (`web/` hochladen) | Webserver | [Schritt 3](docs/webserver.md) |
| 4 | Upload im Parser konfigurieren | Parser-Server | [Schritt 4](docs/parser-server.md#schritt-4-upload-im-parser-konfigurieren) |
| 5 | Ersten Lauf starten und prüfen | Parser-Server | [Schritt 5](docs/parser-server.md#schritt-5-ersten-lauf-starten-und-prüfen) |
| 6 | Cronjob einrichten | Parser-Server | [Schritt 6](docs/parser-server.md#schritt-6-cronjob-einrichten) |

**Das Wichtigste in Kürze**

- Der Webserver braucht nur den Inhalt des Verzeichnisses [`web/`](web/): PHP, ein FTP-Zugang, kein Composer.
- Auf dem Parser-Server laufen `composer install` und ein stündlicher Cronjob mit
  `php bin/parser run --out=var/out --upload`.
- Pflicht sind drei Einstellungen: Kachelserver und Startansicht in `web/config.php`, das Geheimnis in
  `web/upload.config.php` und dasselbe Geheimnis samt Adresse in `config/upload.local.json`. Alles andere
  ist optional und steht in der [Konfiguration: Referenz](docs/konfiguration.md).

## Dokumentation

Die Einzelheiten stehen in [`docs/`](docs/README.md), nach Aufgaben und Rollen sortiert:

| Du möchtest … | Lies … |
|---|---|
| den **Parser** einrichten und betreiben | [Parser-Server einrichten](docs/parser-server.md) |
| die **Karte** auf einen Webserver bringen | [Webserver einrichten](docs/webserver.md) |
| eine **Einstellung nachschlagen** | [Konfiguration: Referenz](docs/konfiguration.md), [Befehle des Parsers](docs/befehle.md) |
| sehen, **was die Läufe gefunden haben** | [Admin-Oberfläche](docs/admin-oberflaeche.md) |
| **benachrichtigt werden**, wenn etwas schiefgeht | [Überwachung](docs/ueberwachung.md) |
| im Alltag **Probleme beheben** | [Betrieb und Fehlersuche](docs/betrieb.md) |
| den Reiter **Statistik** nutzen | [Statistik](docs/statistik.md) |
| **mitentwickeln** | [Entwicklung](docs/entwicklung.md), [`PRD.md`](PRD.md) (Design und Entscheidungen, Englisch) |

## Meine Community fehlt auf der Karte

Der Weg auf die Karte führt über das **Freifunk-Verzeichnis**, nicht über dieses Projekt: Deine Community
steht im [Verzeichnis](https://github.com/freifunk/directory.api.freifunk.net) mit einer API-Datei, und diese
enthält unter `nodeMaps` mindestens eine Karte mit `technicalType` und `url`. Hinter der Adresse muss eine
Knotenliste in einem der erkannten Formate (`nodelist`, nodeinfo, flaches `meshviewer.json`) erreichbar sein,
und die Knoten brauchen einen gültigen Standort.

Alle Einzelheiten, das Schema der `nodelist` und wie du herausfindest, warum genau deine Community fehlt:
[Meine Community fehlt auf der Karte](docs/community.md).

## Lizenz und Danksagung

Das Projekt steht unter der **GNU General Public License v2** ([`LICENSE`](LICENSE)).

Die Karte nutzt – teils mitgeliefert in `js/` – diese Bibliotheken:
[Leaflet](https://leafletjs.com/),
[PruneCluster](https://github.com/SINTEF-9012/PruneCluster) (MIT),
[heatmap.js und Leaflet Heatmap Overlay](https://www.patrick-wied.at/static/heatmapjs/) (MIT/Beerware),
[Bootstrap](https://getbootstrap.com/), [jQuery](https://jquery.com/),
[Flot](https://www.flotcharts.org/) (MIT) und eine Implementierung des Graham-Scan-Algorithmus von
Brian Barnett. Der Zentrieren-Knopf nutzt ein Symbol von [Icons8](https://icons8.com/) (CC BY 3.0).

Kartendaten: © [OpenStreetMap-Mitwirkende](https://www.openstreetmap.org/copyright).
Die Knotendaten gehören den Freifunk-Communities, die sie veröffentlichen – danke dafür.
