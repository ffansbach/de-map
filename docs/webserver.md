# Schritt 3: Webserver einrichten

*Für: wer die Karte ausliefert. Gebraucht werden nur PHP und ein FTP-/SFTP-Zugang; Composer und eine Shell auf dem Server sind nicht nötig.*  
[← Dokumentation](README.md) · [Übersicht](../README.md)

Alles für den Webserver liegt im Verzeichnis `web/` des Projekts und wird so hochgeladen, wie es ist. Das hier ist Schritt 3 des [Produktivbetriebs](../README.md#produktivbetrieb-schritt-für-schritt); davor steht [Schritt 2 (Geheimnis erzeugen)](parser-server.md#schritt-2-upload-geheimnis-erzeugen), danach [Schritt 4 (Upload im Parser konfigurieren)](parser-server.md#schritt-4-upload-im-parser-konfigurieren).

Alles, was auf den Webserver gehört, liegt im Verzeichnis **`web/`**. Es ist so aufgebaut, dass du es
fertig hochladen kannst – ohne Composer, ohne Build-Schritt, ohne Shell auf dem Server:

```
web/
├── index.php  data.php  fetch.php  favicon.ico    die Karte
├── debug.php                                       Debugview: was der Parser über eine Community herausgefunden hat
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
[Referenz](konfiguration.md#configphp-webserver)):

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
[Referenz](konfiguration.md#uploadconfigphp-webserver). Beide `.php`-Konfigurationsdateien geben nichts aus; ruft
jemand sie im Browser auf, sieht er nichts.

**4. Den Inhalt von `web/` hochladen** – in das Web-Verzeichnis deines Anbieters, per FTP-/SFTP-Programm
(z. B. FileZilla) oder, wenn du eine Shell hast, mit `rsync`:

```bash
rsync -av --exclude='*example*' --exclude='cache/*' web/ benutzer@webserver:/var/www/freifunk-karte/
```

Die beiden `*example*`-Vorlagen brauchst du auf dem Server nicht. Das Verzeichnis `cache/` muss dort
existieren (es ist im Upload enthalten) und **für PHP beschreibbar** sein – je nach Anbieter Rechte
`755`, `775` oder `777`; so streng wie möglich, aber so, dass PHP hineinschreiben kann. Auch das Verzeichnis `stateDir`
legt der Empfänger bei Bedarf selbst an (Rechte `0700`). Ohne Angabe ist es `.upload-state` im `target`-Verzeichnis
(`cache/`), also nicht im gemeinsam genutzten `/tmp`, wo bei Shared Hosting ein anderer Benutzer das Verzeichnis
blockieren könnte. Darin liegen nur leere Dateien `nonce-…` ohne vertrauliche Inhalte; wer will, stellt in
`upload.config.php` ein Verzeichnis außerhalb des Web-Verzeichnisses ein (siehe oben).

**Spätere Aktualisierungen:** Lade einfach die geänderten Dateien aus `web/` erneut hoch (oder alles).
Wichtig ist nur, dass dabei `config.php`, `upload.config.php` und der Inhalt von `cache/` auf dem
Server erhalten bleiben bzw. nicht durch leere Dateien ersetzt werden.

**Debugview:** `debug.php` liest `cache/result_debug.json` (vom Parser mit hochgeladen) und zeigt
Besuchern pro Community, was der Parser gefunden hat. Sie braucht weder `config.php` noch eine Datenbank.
Wer sie nicht öffentlich haben möchte, löscht `debug.php` auf dem Webserver.

**HTTPS ist Pflicht** für `upload.php`; ohne HTTPS lehnt der Empfänger alles ab. Zusätzlich:

- **nginx:** der Upload ist gzip-komprimiert, liegt aber nahe an der Standardgrenze von 1 MB. Für
  `upload.php` deshalb `client_max_body_size 20m;` setzen.
- **Hinter einem Reverse-Proxy,** der TLS beendet: in `upload.config.php`
  `'trustForwardedProto' => true` setzen (der Proxy muss `X-Forwarded-Proto` senden).
- **Apache:** das `.htaccess` im Projektstamm sperrt `var/`, `config/`, `src/` und weitere private
  Ordner, falls doch einmal das ganze Projekt ausgeliefert wird. Es braucht `AllowOverride` und
  `mod_rewrite`; verlass dich aber nicht darauf, sondern lade nur den Inhalt von `web/` hoch.
- **nginx (und alles ohne `.htaccess`):** das `.htaccess` wirkt dort **nicht**. Würde das ganze Projekt
  ausgeliefert, wären `var/parser.sqlite` (Verlauf), `config/` (Passwort-Hash, Tokens) und der Quellcode
  abrufbar. Der Document Root gehört deshalb immer auf `web/` (die Karte) bzw. `public-admin/` (die
  Admin-Oberfläche), nie auf das Projektverzeichnis. Wenn es sich gar nicht vermeiden lässt:

  ```nginx
  location ~ ^/(var|config|src|vendor|bin|tests|schema|\.git)(/|$) { deny all; }
  location ~ ^/(composer\.(json|lock)|phpunit\.xml|phpstan.*\.neon)$ { deny all; }
  location ~ /\.(?!well-known) { deny all; }
  ```

**Prüfen:** ein GET auf den Empfänger muss abgelehnt werden, die Startseite muss laden:

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://DEINE-DOMAIN/upload.php     # erwartet: 405 (nur POST); 500 heißt: Konfiguration unvollständig
curl -s -o /dev/null -w '%{http_code}\n' https://DEINE-DOMAIN/               # erwartet: 200 (Karte ohne Daten)
```

## Besucherzählung (Tracking-Code)

In `config.php` kann `$trackingCode` einen HTML-/JavaScript-Schnipsel enthalten (z. B. für Matomo).
Er wird unverändert am Ende von `index.php` ausgegeben und nicht geprüft – er kommt ja aus deiner
eigenen Konfiguration. Leer lassen, wenn du nichts einbinden willst.

## Statistik (optional)

Der Reiter „Statistik“ der Karte braucht zusätzlich MySQL/MariaDB: siehe [Statistik](statistik.md).
