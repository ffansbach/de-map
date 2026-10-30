# Betrieb und Fehlersuche

*Für: wer die Karte und den Parser laufend betreibt.*  
[← Dokumentation](README.md) · [Übersicht](../README.md)

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
| Community fehlt | Admin-Oberfläche → *Karten ohne Daten* bzw. `runs --community=KEY`. Siehe [Meine Community fehlt auf der Karte](community.md). Anfragende können selbst in der öffentlichen Debugview `/debug.php` nachsehen. |
| Admin-Oberfläche: „not set up correctly“ | Details im Fehlerlog des Webservers: meist fehlt `config/admin.local.json` oder `var/` ist für den Webserver-Benutzer nicht beschreibbar (siehe [Rechte](admin-oberflaeche.md#admin-oberfläche)). |
| Zwei Läufe überschneiden sich | Cronjob ohne `flock` – siehe [Cronjob](parser-server.md#schritt-6-cronjob-einrichten). |

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
