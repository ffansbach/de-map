<h1 align="center">Freifunk-Karte</h1>

<p align="center">
  <strong>Alle Freifunk-Knoten Deutschlands auf einer Karte.</strong><br>
  Die Daten kommen direkt von den Communities – die Karte sammelt, prüft und zeigt sie an.
</p>

<p align="center">
  <a href="https://www.freifunk-karte.de/">Live-Karte</a> ·
  <a href="#schnellstart-lokal-ausprobieren">Schnellstart</a> ·
  <a href="#produktivbetrieb-schritt-für-schritt">Produktivbetrieb</a> ·
  <a href="#konfiguration-referenz">Konfiguration</a> ·
  <a href="#betrieb-und-fehlersuche">Fehlersuche</a>
</p>

<p align="center">
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
- [Optionale Bausteine](#optionale-bausteine) – Admin-Oberfläche, Überwachung, Statistik, Tracking
- [Konfiguration: Referenz](#konfiguration-referenz)
- [Betrieb und Fehlersuche](#betrieb-und-fehlersuche)
- [Meine Community fehlt auf der Karte](#meine-community-fehlt-auf-der-karte)
- [Entwicklung](#entwicklung)
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
   `result_*.json` geschrieben.
5. Der Upload liefert die Dateien an den Webserver; dort ersetzt `upload.php` sie atomar.
6. Der Lauf wird in der SQLite-Datei festgehalten und mit dem vorigen verglichen; Auffälliges löst
   Ereignisse aus (und ggf. einen Alarm bei healthchecks.io).

Regeln für die Daten: Knoten ohne Standort oder mit unsinnigen Koordinaten (0/0, außerhalb des
Wertebereichs) erscheinen nicht. Offline-Knoten bleiben drei Tage sichtbar, sofern der Zeitpunkt
ihres letzten Kontakts bekannt ist.

## Voraussetzungen

|  | Parser-Server | Webserver (Karte) |
|---|---|---|
| **PHP** | **8.4.1 oder neuer** (CLI)¹ | **7.2 oder neuer** |
| **Erweiterungen** | `curl`, `json`, `mbstring`, `pdo_sqlite`, `zlib`; `ftp` nur für den FTP-Upload | `zlib` (Upload), `json`; `mysqli` nur für die optionale Statistik |
| **Composer** | ja | nein |
| **Datenbank** | SQLite-Datei (wird selbst angelegt) | keine (nur optional MySQL/MariaDB für die Statistik) |
| **Netzwerk** | ausgehend HTTPS zu GitHub und zu allen Community-Servern | eingehend HTTPS vom Parser |
| **Software** | Cron, `flock` | Apache oder nginx mit PHP (FPM oder mod_php), HTTPS |

¹ In `composer.json` steht formal PHP 8.3, das mitgelieferte `composer.lock` enthält aber Pakete, die
8.4.1 verlangen. Getestet ist mit PHP 8.4 und 8.5.

Parser und Webserver können **auf derselben Maschine** laufen, müssen es aber nicht – und in der
Praxis ist es oft ein kleiner Container für den Parser und ein normaler Webspace für die Karte.
Wichtig ist nur, dass der Parser den Webserver per HTTPS erreicht und beide Uhren stimmen (Toleranz
±5 Minuten).

## Schnellstart: lokal ausprobieren

In fünf Minuten zur eigenen Karte auf dem Rechner (Parser und Webserver in einem Verzeichnis):

```bash
git clone <repository-url> freifunk-karte
cd freifunk-karte

composer install                          # Abhängigkeiten des Parsers
cp web/config.php_example.php web/config.php   # Karten-Einstellungen (Kachelserver, Startansicht)

php bin/parser run --out=web/cache        # holt alle Daten, ca. 30–60 Sekunden
php -S localhost:8000 -t web              # Entwicklungsserver für die Karte starten
```

Dann <http://localhost:8000/> öffnen. `bin/parser run` schreibt drei Dateien nach `web/cache/`
(`result_routers.json`, `result_communities.json`, `result_metacommunities.json`)
und legt den Verlauf in `var/parser.sqlite` ab. Mit `php bin/parser runs`
siehst du anschließend, was der Lauf gefunden hat.

> `--out` hat bewusst keinen Standardwert: Ein Testlauf soll nie versehentlich die Dateien einer
> laufenden Karte überschreiben.

## Produktivbetrieb Schritt für Schritt

Die Reihenfolge ist wichtig, weil das Geheimnis für den Upload auf beiden Seiten gebraucht wird.

| # | Schritt | Wo |
|---|---|---|
| 1 | [Parser installieren](#1-parser-installieren) | Parser-Server |
| 2 | [Upload-Geheimnis erzeugen](#2-upload-geheimnis-erzeugen) | Parser-Server |
| 3 | [Webserver einrichten](#3-webserver-einrichten) | Webserver |
| 4 | [Upload im Parser konfigurieren](#4-upload-im-parser-konfigurieren) | Parser-Server |
| 5 | [Ersten Lauf starten und prüfen](#5-ersten-lauf-starten-und-prüfen) | Parser-Server |
| 6 | [Cronjob einrichten](#6-cronjob-einrichten) | Parser-Server |

### 1. Parser installieren

```bash
git clone <repository-url> /srv/freifunk-karte     # Pfad frei wählbar
cd /srv/freifunk-karte
composer install --no-dev --optimize-autoloader
mkdir -p var
```

Das Verzeichnis `var/` muss für den Benutzer beschreibbar sein, unter dem der Cronjob läuft. Dort
entstehen:

| Pfad | Inhalt |
|---|---|
| `var/parser.sqlite` | Verlauf aller Läufe (wächst; `--keep-days` begrenzt ihn) |
| `var/cache/` | was der Parser gelernt hat: gefundene Daten-Adressen, letzte gültige Knoten und API-Dateien |
| `var/out/` | die zuletzt erzeugten `result_*.json` (auch Quelle für ein späteres `bin/parser upload`) |
| `var/log/` | JSON-Logs, eine Datei pro Tag, 14 Tage lang |

Das Projektverzeichnis darf **nicht** als Ganzes über einen Webserver ausgeliefert werden (dort
liegen Konfiguration und Verlauf); für den Parser ist auf diesem Server gar kein Webserver nötig.
Läuft die Karte auf derselben Maschine, zeigt der Document Root des Webservers auf `web/`.

### 2. Upload-Geheimnis erzeugen

```bash
php bin/parser upload:secret
```

Der Befehl gibt eine zufällige 64-stellige Zeichenfolge aus. **Dieselbe** Zeichenfolge kommt in
Schritt 3 auf den Webserver und in Schritt 4 in die Parser-Konfiguration. Sie wird nie übertragen;
übertragen wird nur eine Signatur. Wenn sie jemals bekannt wird: neu erzeugen und auf beiden Seiten
ersetzen.

### 3. Webserver einrichten

Alles, was auf den Webserver gehört, liegt im Verzeichnis **`web/`**. Es ist so aufgebaut, dass du es
fertig hochladen kannst – ohne Composer, ohne Build-Schritt, ohne Shell auf dem Server:

```
web/
├── index.php  data.php  fetch.php  favicon.ico    die Karte
├── css/  js/  img/  templates/                     Oberfläche (js/ enthält die mitgelieferten Bibliotheken)
├── upload.php                                      Empfänger für den Upload des Parsers
├── cache/                                          hier landen die Ergebnisdateien (anfangs leer)
├── log_to_db.php  lib/log.php                      Statistik (optional, braucht MySQL)
├── config.php_example.php                          Vorlage → wird zu config.php
└── upload.config.example.php                       Vorlage → wird zu upload.config.php
```

**1. Die zwei Vorlagen kopieren** (auf deinem Rechner, im Projektverzeichnis). Die Kopien stehen in der
`.gitignore`, weil sie Geheimnisse enthalten dürfen:

```bash
cp web/config.php_example.php web/config.php
cp web/upload.config.example.php web/upload.config.php
```

**2. `web/config.php` bearbeiten** – die Pflichtangaben für die Karte (alle Variablen siehe
[Referenz](#configphp-webserver)):

```php
$tileServerUrl = 'https://tile.openstreetmap.de/{z}/{x}/{y}.png';
$tileServerAttribution = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>';
$mapInitalView = array('latitude' => 51.16, 'longitude' => 10.45, 'zoom' => 6);
```

> Die Namensgebung `$mapInitalView` (ohne „i“ in „Initial“) ist historisch und muss so bleiben.
> Bei HTTPS-Seiten muss auch der Kachelserver per `https://` angesprochen werden, sonst blockiert
> der Browser die Kacheln.

**3. `web/upload.config.php` bearbeiten** – mindestens das Geheimnis aus Schritt 2 und das
Zielverzeichnis:

```php
return array(
    'secret' => 'DAS-GEHEIMNIS-AUS-SCHRITT-2',
    'target' => __DIR__ . '/cache',
    'stateDir' => '/var/lib/ffk-upload',    // beschreibbar, möglichst außerhalb des Web-Verzeichnisses
);
```

Weitere Schalter (Plausibilitätsgrenzen, Reverse-Proxy) stehen in der
[Referenz](#uploadconfigphp-webserver). Beide `.php`-Konfigurationsdateien geben nichts aus; ruft
jemand sie im Browser auf, sieht er nichts.

**4. Den Inhalt von `web/` hochladen** – in das Web-Verzeichnis deines Anbieters, per FTP-/SFTP-Programm
(z. B. FileZilla) oder, wenn du eine Shell hast, mit `rsync`:

```bash
rsync -av --exclude='*example*' --exclude='cache/*' web/ benutzer@webserver:/var/www/freifunk-karte/
```

Die beiden `*example*`-Vorlagen brauchst du auf dem Server nicht. Das Verzeichnis `cache/` muss dort
existieren (es ist im Upload enthalten) und **für PHP beschreibbar** sein – je nach Anbieter Rechte
`755`, `775` oder `777`; so streng wie möglich, aber so, dass PHP hineinschreiben kann. Auch `stateDir`
(siehe oben) legt der Empfänger bei Bedarf selbst an (Rechte `0700`); der Standard, das temporäre
Verzeichnis des Systems, funktioniert bei vielen Anbietern unverändert. Darin liegen nur leere Dateien
`nonce-…` ohne vertrauliche Inhalte – nimm trotzdem ein Verzeichnis, das nicht öffentlich abrufbar ist.

**Spätere Aktualisierungen:** Lade einfach die geänderten Dateien aus `web/` erneut hoch (oder alles).
Wichtig ist nur, dass dabei `config.php`, `upload.config.php` und der Inhalt von `cache/` auf dem
Server erhalten bleiben bzw. nicht durch leere Dateien ersetzt werden.

**HTTPS ist Pflicht** für `upload.php`; ohne HTTPS lehnt der Empfänger alles ab. Zusätzlich:

- **nginx:** der Upload ist gzip-komprimiert, liegt aber nahe an der Standardgrenze von 1 MB. Für
  `upload.php` deshalb `client_max_body_size 20m;` setzen.
- **Hinter einem Reverse-Proxy,** der TLS beendet: in `upload.config.php`
  `'trustForwardedProto' => true` setzen (der Proxy muss `X-Forwarded-Proto` senden).
- **Apache:** das `.htaccess` im Projektstamm sperrt `var/`, `config/`, `src/` und weitere private
  Ordner, falls doch einmal das ganze Projekt ausgeliefert wird. Es braucht `AllowOverride` und
  `mod_rewrite`; verlass dich aber nicht darauf, sondern lade nur den Inhalt von `web/` hoch.

**Prüfen:** ein GET auf den Empfänger muss abgelehnt werden, die Startseite muss laden:

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://DEINE-DOMAIN/upload.php     # erwartet: 405 (nur POST); 500 heißt: Konfiguration unvollständig
curl -s -o /dev/null -w '%{http_code}\n' https://DEINE-DOMAIN/               # erwartet: 200 (Karte ohne Daten)
```

### 4. Upload im Parser konfigurieren

Auf dem Parser-Server die Datei `config/upload.local.json` anlegen (sie ist von Git ausgenommen):

```bash
cp config/upload.example.json config/upload.local.json
```

Für den Upload per HTTPS reicht dies (den FTP-Block aus dem Beispiel löschen):

```json
{
    "publishers": [
        {
            "type": "https",
            "url": "https://DEINE-DOMAIN/upload.php",
            "secret": "DAS-GEHEIMNIS-AUS-SCHRITT-2",
            "required": true
        }
    ]
}
```

`required: true` bedeutet: Schlägt dieser Weg fehl, gilt der ganze Lauf als fehlgeschlagen
(Alarm, Exit-Code ≠ 0). Alle Felder, auch für den alten FTP-Weg und die Statistik, stehen in der
[Referenz](#configuploadlocaljson-parser).

### 5. Ersten Lauf starten und prüfen

```bash
php bin/parser run --out=var/out --upload -v
```

Du solltest sehen, wie das Verzeichnis geladen wird, in Runden die Karten abgefragt werden und am
Ende eine Zusammenfassung (Communities, Knoten, Dauer) samt dem Ergebnis der Auslieferung steht.
Anschließend:

```bash
php bin/parser runs                       # der Lauf muss mit "ok" in der Liste stehen
curl -s https://DEINE-DOMAIN/data.php | head -c 300      # liefert communities / allTheRouters ...
```

Die Karte unter `https://DEINE-DOMAIN/` zeigt jetzt die Knoten. Zeigt der Parser Fehler an, hilft die
[Fehlersuche](#betrieb-und-fehlersuche).

> Ein Lauf schreibt **nichts**, wenn das Ergebnis unplausibel klein ist (weniger als 10 Communities
> oder 1000 Knoten, `--min-communities` / `--min-nodes`). `--force` erzwingt das Schreiben, etwa für
> eine Test-Community.

### 6. Cronjob einrichten

Ein Lauf pro Stunde genügt. `flock` verhindert, dass sich zwei Läufe überschneiden:

```cron
0 * * * *  cd /srv/freifunk-karte && flock -n var/run.lock php bin/parser run --out=var/out --upload >> var/log/cron.log 2>&1
```

(`crontab -e` mit dem Benutzer, dem `var/` gehört.) `run` endet mit einem Fehlercode, wenn der Lauf
scheitert oder die Auslieferung misslingt; mit einem eingerichteten `MAILTO` oder
[healthchecks.io](#überwachung-healthchecksio-und-influxdb) bekommst du das mit.

## Optionale Bausteine

### Admin-Oberfläche

Eine schreibgeschützte Weboberfläche für den Betreiber: Übersicht, Läufe mit Vergleich zum vorigen,
Communities, **Karten ohne Daten** (nach Ursache gruppiert), die fest eingetragenen Communities und
alle Ereignisse. Sie liest nur `var/parser.sqlite`.

1. Passwort-Hash erzeugen und die Konfiguration anlegen:

   ```bash
   php bin/parser admin:password                                  # fragt nach dem Passwort (mind. 12 Zeichen)
   cp config/admin.example.json config/admin.local.json           # den ausgegebenen Hash eintragen
   ```

2. **Nur ansehen:** `php bin/parser admin:serve` startet die Oberfläche auf
   <http://127.0.0.1:8081/>. Von einem anderen Rechner per SSH-Tunnel:
   `ssh -L 8081:127.0.0.1:8081 benutzer@parser-server`.

3. **Dauerhaft:** das Verzeichnis `public-admin/` über einen Webserver mit PHP ausliefern, z. B. nginx
   mit PHP-FPM:

   ```nginx
   server {
       listen 127.0.0.1:8081;                  # oder die Adresse im vertrauenswürdigen Netz
       root /srv/freifunk-karte/public-admin;
       location / {
           include fastcgi_params;
           fastcgi_param SCRIPT_FILENAME $document_root/index.php;
           fastcgi_pass unix:/run/php/php8.4-fpm.sock;
       }
   }
   ```

   Läuft sie unter einem Unterpfad (z. B. `https://host/freifunk/public-admin/`) auf Apache, genügt
   das mitgelieferte `public-admin/.htaccess` (alles außer echten Dateien geht an `index.php`); der
   Pfad wird automatisch erkannt.

**Rechte:** Der Webserver-Benutzer muss `var/parser.sqlite` lesen **und** in `var/` schreiben dürfen
(SQLite legt auch beim Lesen eine `-shm`-Datei an) sowie in `var/log/` (für `admin.log`). Üblich ist
`chgrp www-data var var/log var/parser.sqlite; chmod 2775 var var/log; chmod 664 var/parser.sqlite`
und den Cron-Benutzer in die Gruppe `www-data` aufzunehmen.

**Sicherheit:** Ohne Passwort antwortet die Oberfläche nur auf `127.0.0.1`/`::1`. Mit Passwort
verwendet sie HTTP Basic – sobald sie über das Netz erreichbar ist, **nur über HTTPS** betreiben. Sie
sendet strenge Sicherheitsheader (keine Skripte, `no-store`) und kann nichts verändern.

### Überwachung: healthchecks.io und InfluxDB

Optional, in `config/integrations.local.json` (Vorlage: `config/integrations.example.json`):

```json
{
    "healthchecks": { "identifier": "00000000-0000-0000-0000-000000000000", "failOn": "alert" },
    "influxdb": { "host": "http://influx.example", "port": "8086", "token": "…", "bucket": "freifunk_karte", "org": "meine-org", "env": "live" }
}
```

- **healthchecks.io:** jeder Lauf meldet Start und Erfolg an `https://hc-ping.com/<identifier>`.
  Als Fehler gilt nur, was wirklich Aufmerksamkeit braucht (`failOn: alert`): ein großflächiger
  Ausfall, ein fehlgeschlagener oder verweigerter Lauf oder eine misslungene Auslieferung. Einzelne
  Warnungen stehen im Text der Meldung. Mit `warning` oder `info` wird die Schwelle gesenkt.
- **InfluxDB v2:** pro Lauf ein Messpunkt `parser_result_count` mit Knoten- und Communityzahl,
  Dauer, Speicher, Zahl der Abrufe und Ereignissen. `env` ist ein Tag, um z. B. Test und Produktion
  zu trennen.

### Statistik: Knotenzahl im Zeitverlauf

Der Reiter „Statistik“ zeigt die Entwicklung der Knotenzahl: pro Kalenderwoche ein Punkt für den
kleinsten und einen für den größten Wert. Er braucht MySQL/MariaDB auf dem Webserver. Nach jedem
erfolgreichen Upload ruft der Parser dazu `log_to_db.php` auf, das die aktuelle Knotenzahl in die
Tabelle `log` einträgt – ein neuer Punkt im Diagramm erscheint also erst mit der nächsten Woche.

Gezeigt werden nur Werte über 5000 Knoten, und eine Woche, deren Minimum unter 75 % des zuletzt
gezeigten fällt, wird als Messausfall (z. B. nicht erreichbare API-Dateien) ausgeblendet.

Die Tabelle ergibt sich aus den Abfragen im Code (ein fertiges Schema liegt nicht bei):

```sql
CREATE TABLE `log` (
  `id`    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `ts`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `count` INT UNSIGNED NOT NULL,
  KEY `ts` (`ts`)
);
```

Dazu in `web/config.php` `$dbAccess` und `$setDataLogPointToken` setzen (`log_to_db.php` und `lib/log.php`
liegen schon in `web/` und werden mit hochgeladen; sie brauchen nur PHP und `mysqli`, kein Composer),
und im Parser in
`config/upload.local.json`:

```json
"statsPingUrl": "https://DEINE-DOMAIN/log_to_db.php?token=DAS-TOKEN-AUS-CONFIG-PHP"
```

Ob der Eintrag ankommt, siehst du am schnellsten direkt in der Datenbank:
`SELECT MAX(ts), COUNT(*) FROM log;` – der neueste Zeitstempel sollte höchstens eine Stunde alt sein.
Für eine reine Karte kannst du diesen Teil weglassen; der Reiter „Statistik“ bleibt dann ohne Funktion.

### Besucherzählung (Tracking-Code)

In `config.php` kann `$trackingCode` einen HTML-/JavaScript-Schnipsel enthalten (z. B. für Matomo).
Er wird unverändert am Ende von `index.php` ausgegeben und nicht geprüft – er kommt ja aus deiner
eigenen Konfiguration. Leer lassen, wenn du nichts einbinden willst.

## Konfiguration: Referenz

Es gibt vier Orte, an denen etwas eingestellt wird. Nur die **fett** markierten Dateien sind für
einen funktionierenden Betrieb zwingend.

| Datei | Wo | Zweck | Pflicht |
|---|---|---|---|
| **`web/config.php`** | Webserver | Karte: Kachelserver, Startansicht (+ Statistik, Tracking) | ja |
| **`web/upload.config.php`** | Webserver | Empfänger: Geheimnis, Zielordner, Plausibilitätsgrenzen | ja |
| **`config/upload.local.json`** | Parser | Wohin und womit der Parser ausliefert | ja |
| `config/integrations.local.json` | Parser | healthchecks.io, InfluxDB | nein |
| `config/admin.local.json` | Parser | Admin-Oberfläche | nein |
| `config/*.json` (Listen) | Parser | Ausnahmen und Zuordnungen, [siehe unten](#handgepflegte-listen-in-config) | nein |
| `fixedCommunities.php` | Parser | Communities, die nicht im Verzeichnis stehen | nein |

Alle `*.local.json`, `config.php` und `upload.config.php` stehen in der `.gitignore`.

### `config.php` (Webserver)

| Variable | Pflicht | Bedeutung |
|---|---|---|
| `$tileServerUrl` | ja | URL-Muster des Kachelservers mit `{z}`, `{x}`, `{y}` (und optional `{s}`). Beispiel: `https://tile.openstreetmap.de/{z}/{x}/{y}.png`. Auf HTTPS-Seiten muss auch die Kachel-URL `https` sein. |
| `$tileServerAttribution` | ja | Quellenangabe als HTML, wie sie der Kachelanbieter verlangt (Lizenzpflicht). |
| `$mapInitalView` | ja | Startansicht: `array('latitude' => 51.16, 'longitude' => 10.45, 'zoom' => 6)`. Der Schreibfehler im Namen ist Absicht (historisch). |
| `$trackingCode` | nein | HTML/JS-Schnipsel, der am Ende der Seite ausgegeben wird. |
| `$dbAccess` | nein | Statistik: `array('host' => …, 'db' => …, 'user' => …, 'pass' => …)`; leeres Array = aus. |
| `$setDataLogPointToken` | nein | Statistik: Token, das `log_to_db.php` prüft. |

Ältere Installationen lassen den Parser zusätzlich mit `--legacy-config=config.php` eine `config.php`
im Projektstamm des Parser-Servers lesen; darin stehen dann `$environment`, `$healtcheckIdentifier`,
`$influxDB`, `$serverUpload` und `$setDataLogPointToken`. Diese Datei hat nichts mit `web/config.php`
zu tun und ist für neue Installationen nicht nötig – sie nutzen die JSON-Dateien in `config/`.

### `upload.config.php` (Webserver)

Die Datei gibt ein Array zurück (Vorlage: `web/upload.config.example.php`). Ein anderer Pfad lässt
sich über die Umgebungsvariable `FFK_UPLOAD_CONFIG` angeben.

| Schlüssel | Standard | Bedeutung |
|---|---|---|
| `secret` | – (**Pflicht**) | Gemeinsames Geheimnis, mindestens 32 Zeichen (`bin/parser upload:secret`). Ohne gültiges Geheimnis lehnt der Empfänger alles ab. |
| `target` | – (**Pflicht**) | Verzeichnis für die Ergebnisdateien; muss existieren und für PHP beschreibbar sein. Normalerweise `__DIR__ . '/cache'`. |
| `stateDir` | `<tmp>/ffk-upload` | Hier merkt sich der Empfänger verbrauchte Einmalwerte (Replay-Schutz). Beschreibbar, möglichst außerhalb des Web-Verzeichnisses. |
| `requireHttps` | `true` | Verlangt HTTPS. Nur für lokale Tests abschalten. |
| `trustForwardedProto` | `false` | `true`, wenn ein Reverse-Proxy TLS beendet und `X-Forwarded-Proto: https` setzt. |
| `expectedPath` | automatisch | Pfad, der in die Signatur eingeht; nur nötig, wenn ein Proxy den Pfad umschreibt. |
| `minCommunities` | `10` | Ein Upload mit weniger Communities wird abgelehnt. |
| `minRouters` | `1000` | Ein Upload mit weniger Knoten wird abgelehnt. |
| `maxShrink` | `0.5` | Ein Upload mit weniger als dieser Hälfte der bisherigen Knoten wird abgelehnt (`0` schaltet die Prüfung ab). |
| `maxBytes` | `20000000` | Größtes erlaubtes Paket auf der Leitung (Bytes). |
| `maxDecodedBytes` | `64000000` | Größtes erlaubtes entpacktes Paket (Schutz vor gzip-Bomben). |
| `timestampWindow` | `300` | Erlaubte Uhrabweichung in Sekunden. |

### `config/upload.local.json` (Parser)

```json
{
    "publishers": [ { … }, { … } ],
    "statsPingUrl": "https://DEINE-DOMAIN/log_to_db.php?token=…"
}
```

Jeder Eintrag in `publishers` ist ein Auslieferungsweg. Es dürfen mehrere nebeneinander stehen.

**`"type": "https"`** (empfohlen)

| Feld | Pflicht | Bedeutung |
|---|---|---|
| `url` | ja | Adresse von `upload.php`. Klartext-HTTP ist nur für `localhost` erlaubt. |
| `secret` | ja | Das Geheimnis aus `upload.config.php` (mindestens 32 Zeichen). |
| `required` | nein, Standard `true` | Bei `true` scheitert der Lauf, wenn dieser Weg scheitert; bei `false` gibt es nur eine Warnung. |

**`"type": "ftp"`** (älterer Weg, FTP über TLS)

| Feld | Pflicht | Bedeutung |
|---|---|---|
| `host` | ja | FTP-Server |
| `user`, `password` | ja | Zugangsdaten |
| `directory` | nein, Standard `/` | Zielordner auf dem Server |
| `port` | nein, Standard `21` | |
| `ssl` | nein, Standard `true` | FTP über TLS |
| `required` | nein, Standard `true` | wie oben |

**`statsPingUrl`** (optional): wird nach einer erfolgreichen Auslieferung aufgerufen, siehe
[Statistik](#statistik-knotenzahl-im-zeitverlauf). Scheitert der Aufruf, ist das nur eine Warnung.

Die Auslieferung gilt als gescheitert, wenn ein `required`-Weg scheitert oder wenn es Wege gibt und
keiner funktioniert. Die Dateien bleiben dann in `var/out/`, und `php bin/parser upload --from=var/out`
holt die Auslieferung nach.

### `config/integrations.local.json` (Parser)

| Feld | Bedeutung |
|---|---|
| `healthchecks.identifier` | UUID des Checks bei healthchecks.io |
| `healthchecks.failOn` | `alert` (Standard), `warning` oder `info`: ab welcher Schwere der Check fehlschlägt |
| `influxdb.host`, `port`, `token`, `bucket`, `org` | Zugang zu InfluxDB v2 (alle fünf nötig) |
| `influxdb.env` | Wert des Tags `env` (Standard `undefined`) |

Eine andere Datei lässt sich mit `--integrations=DATEI` angeben.

### `config/admin.local.json` (Parser)

| Feld | Standard | Bedeutung |
|---|---|---|
| `passwordHash` | – | von `php bin/parser admin:password`; ohne Hash nur Zugriff von `127.0.0.1` |
| `user` | `admin` | Benutzername |
| `db` | `var/parser.sqlite` | Pfad zur Verlaufsdatenbank |
| `timezone` | `Europe/Berlin` | Zeitzone der Anzeige |
| `title` | `Freifunk Karte - parser` | Titel in der Kopfzeile |
| `failedLoginDelayMs` | `300` | Bremse nach einem falschen Passwort |

Eine andere Datei lässt sich über die Umgebungsvariable `FFKARTE_ADMIN_CONFIG` angeben.

### Handgepflegte Listen in `config/`

| Datei | Format | Wofür |
|---|---|---|
| `skipped-communities.json` | `{"schluessel": "Grund"}` | Communities, die nicht abgefragt werden (z. B. dauerhaft kaputt). |
| `insecure-hosts.json` | `["host.example"]` | Server mit kaputtem Zertifikat, die ausnahmsweise ohne Prüfung abgefragt werden. Die TLS-Prüfung bleibt sonst immer an. |
| `metacommunities.json` | `{"Name": {"url": "https://…"}}` | Link einer Übergruppe festlegen oder mit `"url": null` unterdrücken. Ohne Eintrag wird der Link aus den Mitgliedern abgeleitet. |
| `source-assignments.json` | `[{"prefix": "karte.example/data/ort", "community": "ort", "backfill": true}]` | Teilen sich mehrere Communities einen Kartenserver, legt das fest, zu welcher Community die Knoten einer Daten-Adresse gehören. `backfill` füllt nach, was die spezifischen Quellen nicht hatten. |

### `fixedCommunities.php`

Für Communities, die **nicht** im Verzeichnis stehen, aber auf die Karte sollen. Pro Eintrag:

```php
$meine = new stdClass();
$meine->name = 'Freifunk Beispielstadt';
$meine->nameShort = 'Beispielstadt';
$meine->url = 'https://karte.beispielstadt.example/data/nodes.json';   // Adresse der Knotenliste
$meine->homePage = 'https://beispielstadt.example/';
$meine->parser = 'Ffmap';                                             // 'Ffmap' oder 'nodelist'
$parser->addAdditional('ff_beispielstadt', $meine);                    // Schlüssel der Community
```

Das Format der Daten wird trotzdem am Inhalt erkannt; `parser` ist nur ein Hinweis. Mit
`--no-fixed` ignoriert ein Lauf die Datei.

### Befehle des Parsers

`php bin/parser list` zeigt alle Befehle, `php bin/parser <befehl> --help` die Optionen.

| Befehl | Zweck |
|---|---|
| `run --out=DIR` | Daten holen und `result_*.json` nach `DIR` schreiben |
| `run … --upload` | … und anschließend ausliefern |
| `upload --from=DIR` | die Dateien eines früheren Laufs ausliefern |
| `upload:secret` | neues Upload-Geheimnis erzeugen |
| `runs [ID]` | Verlauf der Läufe; `--events` zeigt Warnungen und Alarme, `--community=KEY` eine Community |
| `compare ALT NEU` | zwei Ergebnisverzeichnisse vergleichen, nach dem was auf der Karte steht |
| `admin:password` / `admin:serve` | Admin-Oberfläche einrichten / ansehen |
| `sources:audit` | alle Datenquellen prüfen und die verwendeten Formate auflisten (nur lesend) |
| `fixtures:build` | anonymisierte Testdaten aus einem Audit erzeugen (für Entwickler) |

Die wichtigsten Optionen von `run`:

| Option | Standard | Bedeutung |
|---|---|---|
| `--out=DIR` | – (Pflicht) | Zielordner der Ergebnisdateien |
| `--upload` | aus | danach ausliefern (`--upload-config=DATEI`, sonst `config/upload.local.json`) |
| `--cache-dir=DIR` | `var/cache` | Gedächtnis des Parsers |
| `--db=DATEI` | `var/parser.sqlite` | Verlaufsdatenbank |
| `--log-file=DATEI` | `var/log/parser.log` | JSON-Log (eine Datei pro Tag, 14 Tage) |
| `--keep-days=N` | `0` | Läufe vollständig löschen, die älter sind als N Tage (`0` = alles behalten) |
| `--keep-detail-days=N` | `30` | bei Läufen, die älter sind als N Tage, die Einzelheiten löschen (Abrufe, Meldungen, probierte Adressen); Zusammenfassung, Ereignisse und das Ergebnis je Community bleiben (`0` = alles behalten) |
| `--concurrency=N` | `12` | gleichzeitige Abrufe |
| `--only=KEY` | – | nur eine Community abfragen |
| `--skip=A,B` | – | weitere Communities überspringen |
| `--min-communities`, `--min-nodes` | `10`, `1000` | kleinere Ergebnisse werden nicht geschrieben |
| `--force` | aus | trotzdem schreiben |
| `--no-history` | aus | den Lauf nicht festhalten, vergleichen oder melden (für Tests) |
| `--directory-url=URL` | Freifunk-Verzeichnis | ein anderes Community-Verzeichnis lesen |
| `--config-dir=DIR` | `config/` | Ordner mit den handgepflegten Listen |

## Betrieb und Fehlersuche

**Täglich nützlich**

```bash
php bin/parser runs                      # die letzten Läufe: Status, Knoten, Communities, Warnungen
php bin/parser runs --events             # was ist aufgefallen? (Community verloren, veraltete Daten, …)
php bin/parser runs 42                   # ein Lauf im Detail
php bin/parser runs --community=berlin   # wie hat sich eine Community entwickelt?
```

Die Admin-Oberfläche zeigt dasselbe grafisch; besonders hilfreich ist die Seite **Karten ohne
Daten**, die nach Ursache sortiert, warum eine Community fehlt.

**Veraltete Daten statt Lücken.** Antwortet ein Server nicht oder liefert er Fehler (keine Antwort,
408, 429, 5xx, leere oder keine JSON-Antwort), zeigt die Karte bis zu 24 Stunden lang die zuletzt
gültigen Daten dieser Community und meldet das als Ereignis `stale-data`. Eine nicht mehr vorhandene
Datei (404) und gültige, aber leere Listen werden dagegen geglaubt.

**Typische Probleme**

| Symptom | Ursache und Abhilfe |
|---|---|
| Karte lädt, aber ohne Knoten | `cache/` auf dem Webserver ist leer: Wurde der Upload ausgeführt (`php bin/parser runs`)? `curl …/data.php` zeigt `false`-Werte, wenn Dateien fehlen. |
| Upload: **401 unauthorized** | Geheimnis stimmt nicht, Uhren weichen mehr als 5 Minuten ab (NTP!), oder ein Proxy ändert den Pfad (`expectedPath` setzen). Der Grund steht nur im Fehlerlog des Webservers. |
| Upload: **400 HTTPS only** | Aufruf über HTTP, oder TLS endet an einem Proxy: `trustForwardedProto` setzen. |
| Upload: **413 too large** | Webserver-Grenze: bei nginx `client_max_body_size 20m;`. |
| Upload: **422** | Das Paket wurde inhaltlich abgelehnt (zu wenige Knoten/Communities, mehr als die Hälfte weniger als vorher, ungültige JSON-Form). Meldung in der Antwort, `php bin/parser runs` zeigt `delivery-failed`. |
| Upload: **500 not configured** | `upload.config.php` fehlt oder das Geheimnis ist kürzer als 32 Zeichen oder `target` existiert nicht. |
| Lauf endet mit „refused“ | Ergebnis unplausibel klein. Ursache klären (Verzeichnis nicht erreichbar?) statt `--force`. |
| Community fehlt | Admin-Oberfläche → *Karten ohne Daten* bzw. `runs --community=KEY`. Siehe [nächster Abschnitt](#meine-community-fehlt-auf-der-karte). |
| Admin-Oberfläche: „not set up correctly“ | Details im Fehlerlog des Webservers: meist fehlt `config/admin.local.json` oder `var/` ist für den Webserver-Benutzer nicht beschreibbar (siehe [Rechte](#admin-oberfläche)). |
| Zwei Läufe überschneiden sich | Cronjob ohne `flock` – siehe [Cronjob](#6-cronjob-einrichten). |

**Größe des Verlaufs.** Ein vollständig gespeicherter Lauf braucht etwa 0,5 MB, bei einem Lauf pro Stunde
wären das über 4 GB im Jahr. Deshalb löscht jeder Lauf die Einzelheiten von Läufen, die älter als 30 Tage
sind (`--keep-detail-days`): übrig bleiben pro Lauf nur noch etwa 60 KB, also rund 0,5 GB im Jahr plus die
letzten 30 Tage in voller Länge. Die Admin-Oberfläche weist bei einem solchen Lauf darauf hin. SQLite gibt
freien Platz nicht an das Dateisystem zurück; die Datei wächst aber nicht weiter, weil der Platz für neue
Läufe wiederverwendet wird. Wer sie nach dem ersten Verdichten verkleinern möchte (nicht während eines
Laufs): `php -r 'new PDO("sqlite:var/parser.sqlite")->exec("VACUUM");'`

**Sichern.** Wichtig ist nur `var/parser.sqlite` (der Verlauf) und deine Konfiguration. Alles andere
entsteht beim nächsten Lauf neu.

**Zurück zu einem früheren Stand.** `git checkout <Stand>` im Projektverzeichnis; die Ergebnisdateien
haben seit Jahren dasselbe Format. Der Verlauf in der SQLite-Datei bleibt erhalten.

## Meine Community fehlt auf der Karte

Der Weg auf die Karte führt über das **Freifunk-Verzeichnis**, nicht über dieses Projekt:

1. Deine Community steht im [Verzeichnis](https://github.com/freifunk/directory.api.freifunk.net)
   mit einer API-Datei (ffapi), und diese enthält unter `nodeMaps` mindestens eine Karte mit
   `technicalType` und `url`.
2. Hinter der Adresse muss eine Knotenliste erreichbar sein. Erkannt werden automatisch:
   - das **`nodelist`-Format** (bevorzugt; Schema in
     [`schema/nodelist-schema-1.0.1.json`](schema/nodelist-schema-1.0.1.json)),
   - `nodes.json` im Format **nodeinfo** (ffmap, meshviewer, hopglass),
   - das flache **`meshviewer.json`**.

   Zeigt die Adresse nur auf eine Karten-Seite, sucht der Parser selbst nach `config.json`,
   `meshviewer.json` und `nodes.json`.
3. Die Knoten brauchen einen gültigen Standort. Knoten ohne Koordinaten werden nicht gezeigt.

Wer selbst eine Knotenliste anbieten möchte, findet ein Beispiel im
[nodelist-Format](https://gist.github.com/StilgarBF/c21826994b775787f739) und PHP-Klassen für den
Export in [nodelistexport](https://github.com/StilgarBF/nodelistexport). Das Schema lässt sich z. B.
mit einem JSON-Schema-Validator gegen die eigenen Daten prüfen; der Parser selbst prüft nach dem
Schema nur weich: Abweichungen werden gemeldet, aber nicht abgelehnt.

Warum genau deine Community fehlt, zeigt `php bin/parser runs --community=SCHLUESSEL` bzw. die Seite
*Karten ohne Daten* der Admin-Oberfläche: dort steht, ob die API-Datei fehlt, keine Karte eingetragen
ist, ob unter der Adresse keine Daten gefunden wurden oder ob der Server nicht antwortet.

## Entwicklung

```bash
composer install               # mit Entwicklungswerkzeugen
composer check                 # Code-Stil (PHP-CS-Fixer) + Analyse (PHPStan, Level 8) + Tests (PHPUnit)
composer cs-fix                # Code-Stil automatisch korrigieren
vendor/bin/phpunit --filter testName tests/Pipeline/ParseRunTest.php   # einzelner Test
```

Die Tests laufen ohne Netzwerk gegen eine erfundene Freifunk-Welt (`tests/Legacy/World.php`). Einige
Tests starten einen echten PHP-Webserver (Admin-Oberfläche, Upload, `data.php`).

**Aufbau**

```
bin/parser            Einstieg der Kommandozeile
src/                  Parser, Auslieferung, Admin-Oberfläche (Namensraum FreifunkKarte\)
  Parser/ Model/        Formate lesen, Knoten prüfen und deduplizieren
  Http/ Source/         paralleles Abrufen, Datenquellen finden, Zwischenspeicher
  Pipeline/ Output/     der Lauf als Ganzes, Ergebnisdateien
  Run/ Log/             Verlauf (SQLite), Erkennung von Auffälligkeiten, Meldungen
  Publish/ Admin/       Auslieferung (HTTPS/FTP), Admin-Oberfläche
web/                  alles für den Webserver, fertig zum Hochladen (kein Composer nötig):
  index.php data.php fetch.php             die Karte (ohne Framework, ohne Build-Schritt)
  upload.php                               Empfänger für den Upload (eigenständig, ab PHP 7.2)
  log_to_db.php lib/log.php                die optionale Statistik (MySQL)
  js/ css/ img/ templates/                 Oberfläche; js/ enthält auch die mitgelieferten Bibliotheken
  cache/                                   Ergebnisdateien des Parsers
public-admin/         Einstieg der Admin-Oberfläche
config/ schema/       Listenkonfiguration und Beispiele, JSON-Schema der nodelist
fixedCommunities.php  Communities, die nicht im Verzeichnis stehen
tests/ PRD.md         Tests; Architektur, Entscheidungen und Fahrplan
```

`PRD.md` erklärt Entscheidungen und Hintergründe ausführlich (Englisch) und ist der beste Einstieg,
wenn du mitarbeiten willst. Fehler und Wünsche gern als Issue; Änderungen als Pull Request – bitte
mit Tests, und `composer check` muss durchlaufen.

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
