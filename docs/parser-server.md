# Parser-Server einrichten

*Für: wer den Parser betreibt: den Cronjob, der die Daten sammelt, prüft und an den Webserver schickt.*  
[← Dokumentation](README.md) · [Übersicht](../README.md)

Der Parser ist der aufwendige Teil des Projekts (ein Lauf dauert etwa 30–40 Sekunden). Er läuft auf der Kommandozeile, braucht kein Webserver-Programm und gehört zum [Produktivbetrieb in sechs Schritten](../README.md#produktivbetrieb-schritt-für-schritt): Die Schritte 1, 2, 4, 5 und 6 stehen hier, **Schritt 3 (der Webserver)** steht in [webserver.md](webserver.md). Voraussetzungen: siehe [README](../README.md#voraussetzungen).

## Schritt 1: Parser installieren

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
| `var/parser.sqlite` | Verlauf aller Läufe (wächst langsam: Einzelheiten älterer Läufe werden gelöscht, siehe [Betrieb](betrieb.md)) |
| `var/cache/` | was der Parser gelernt hat: gefundene Daten-Adressen, letzte gültige Knoten und API-Dateien |
| `var/out/` | die zuletzt erzeugten `result_*.json` (auch Quelle für ein späteres `bin/parser upload`) |
| `var/log/` | JSON-Logs, eine Datei pro Tag, 14 Tage lang |

Das Projektverzeichnis darf **nicht** als Ganzes über einen Webserver ausgeliefert werden (dort
liegen Konfiguration und Verlauf; das `.htaccess` schützt nur unter Apache, nginx siehe [Webserver](webserver.md)); für den Parser ist auf diesem Server gar kein Webserver nötig.
Läuft die Karte auf derselben Maschine, zeigt der Document Root des Webservers auf `web/`.

## Schritt 2: Upload-Geheimnis erzeugen

```bash
php bin/parser upload:secret
```

Der Befehl gibt eine zufällige 64-stellige Zeichenfolge aus. **Dieselbe** Zeichenfolge kommt in
Schritt 3 auf den Webserver und in Schritt 4 in die Parser-Konfiguration. Sie wird nie übertragen;
übertragen wird nur eine Signatur. Wenn sie jemals bekannt wird: neu erzeugen und auf beiden Seiten
ersetzen.

> **Jetzt Schritt 3:** den Webserver einrichten, siehe [webserver.md](webserver.md). Danach geht es hier mit Schritt 4 weiter.

## Schritt 4: Upload im Parser konfigurieren

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
[Referenz](konfiguration.md#configuploadlocaljson-parser).

## Schritt 5: Ersten Lauf starten und prüfen

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
[Fehlersuche](betrieb.md#betrieb-und-fehlersuche).

> Ein Lauf schreibt **nichts**, wenn das Ergebnis unplausibel klein ist (weniger als 10 Communities
> oder 1000 Knoten, `--min-communities` / `--min-nodes`). `--force` erzwingt das Schreiben, etwa für
> eine Test-Community.

## Schritt 6: Cronjob einrichten

Ein Lauf pro Stunde genügt. `flock` verhindert, dass sich zwei Läufe überschneiden:

```cron
0 * * * *  cd /srv/freifunk-karte && flock -n var/run.lock php bin/parser run --out=var/out --upload >> var/log/cron.log 2>&1
```

(`crontab -e` mit dem Benutzer, dem `var/` gehört.) `run` endet mit einem Fehlercode, wenn der Lauf
scheitert oder die Auslieferung misslingt; mit einem eingerichteten `MAILTO` oder
[healthchecks.io](ueberwachung.md#überwachung-healthchecksio-und-influxdb) bekommst du das mit.

## Wie geht es weiter?

- [Admin-Oberfläche](admin-oberflaeche.md) einrichten – die schnellste Art, die Läufe anzusehen
- [Überwachung](ueberwachung.md) mit healthchecks.io und InfluxDB
- [Betrieb und Fehlersuche](betrieb.md)
- alle Einstellungen: [Konfiguration: Referenz](konfiguration.md), alle Befehle: [Befehle des Parsers](befehle.md)
