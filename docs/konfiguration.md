# Konfiguration: Referenz

*Für: alle, die etwas einstellen: jede Konfigurationsdatei und jeder Schlüssel.*  
[← Dokumentation](README.md) · [Übersicht](../README.md)

Es gibt vier Orte, an denen etwas eingestellt wird. Nur die **fett** markierten Dateien sind für
einen funktionierenden Betrieb zwingend.

| Datei | Wo | Zweck | Pflicht |
|---|---|---|---|
| **`web/config.php`** | Webserver | Karte: Kachelserver, Startansicht (+ Statistik, Tracking) | ja |
| **`web/upload.config.php`** | Webserver | Empfänger: Geheimnis, Zielordner, Plausibilitätsgrenzen | ja |
| **`config/upload.local.json`** | Parser | Wohin und womit der Parser ausliefert | ja |
| `config/integrations.local.json` | Parser | healthchecks.io, InfluxDB | nein |
| `config/admin.local.json` | Parser | Admin-Oberfläche | nein |
| `config/*.json` (Listen) | Parser | Ausnahmen und Zuordnungen, [Beschreibung weiter unten in dieser Datei](#handgepflegte-listen-in-config) | nein |
| `fixedCommunities.php` | Parser | Communities, die nicht im Verzeichnis stehen | nein |

Alle `*.local.json`, `config.php` und `upload.config.php` stehen in der `.gitignore`.

## `config.php` (Webserver)

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

## `upload.config.php` (Webserver)

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

## `config/upload.local.json` (Parser)

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
[Statistik](statistik.md#statistik-knotenzahl-im-zeitverlauf). Scheitert der Aufruf, ist das nur eine Warnung.

Die Auslieferung gilt als gescheitert, wenn ein `required`-Weg scheitert oder wenn es Wege gibt und
keiner funktioniert. Die Dateien bleiben dann in `var/out/`, und `php bin/parser upload --from=var/out`
holt die Auslieferung nach.

## `config/integrations.local.json` (Parser)

| Feld | Bedeutung |
|---|---|
| `healthchecks.identifier` | UUID des Checks bei healthchecks.io |
| `healthchecks.failOn` | `alert` (Standard), `warning` oder `info`: ab welcher Schwere der Check fehlschlägt |
| `influxdb.host`, `port`, `token`, `bucket`, `org` | Zugang zu InfluxDB v2 (alle fünf nötig) |
| `influxdb.env` | Wert des Tags `env` (Standard `undefined`) |

Eine andere Datei lässt sich mit `--integrations=DATEI` angeben.

## `config/admin.local.json` (Parser)

| Feld | Standard | Bedeutung |
|---|---|---|
| `passwordHash` | – | von `php bin/parser admin:password`; ohne Hash nur Zugriff von `127.0.0.1` |
| `user` | `admin` | Benutzername |
| `db` | `var/parser.sqlite` | Pfad zur Verlaufsdatenbank |
| `timezone` | `Europe/Berlin` | Zeitzone der Anzeige |
| `title` | `Freifunk Karte - parser` | Titel in der Kopfzeile |
| `failedLoginDelayMs` | `300` | Bremse nach einem falschen Passwort |

Eine andere Datei lässt sich über die Umgebungsvariable `FFKARTE_ADMIN_CONFIG` angeben.

## Handgepflegte Listen in `config/`

| Datei | Format | Wofür |
|---|---|---|
| `skipped-communities.json` | `{"schluessel": "Grund"}` | Communities, die nicht abgefragt werden (z. B. dauerhaft kaputt). |
| `insecure-hosts.json` | `["host.example"]` | Server mit kaputtem Zertifikat, die ausnahmsweise ohne Prüfung abgefragt werden. Die TLS-Prüfung bleibt sonst immer an. |
| `metacommunities.json` | `{"Name": {"url": "https://…"}}` | Link einer Übergruppe festlegen oder mit `"url": null` unterdrücken. Ohne Eintrag wird der Link aus den Mitgliedern abgeleitet. |
| `source-assignments.json` | `[{"prefix": "karte.example/data/ort", "community": "ort", "backfill": true}]` | Teilen sich mehrere Communities einen Kartenserver, legt das fest, zu welcher Community die Knoten einer Daten-Adresse gehören. `backfill` füllt nach, was die spezifischen Quellen nicht hatten. |

## `fixedCommunities.php`

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

Die Optionen der Kommandozeile stehen in [Befehle des Parsers](befehle.md).
