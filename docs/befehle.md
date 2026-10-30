# Befehle des Parsers

*Für: wer den Parser auf der Kommandozeile bedient.*  
[← Dokumentation](README.md) · [Übersicht](../README.md)

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
