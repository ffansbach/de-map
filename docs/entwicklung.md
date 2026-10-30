# Entwicklung

*Für: wer am Projekt mitarbeiten möchte.*  
[← Dokumentation](README.md) · [Übersicht](../README.md)

```bash
composer install               # mit Entwicklungswerkzeugen
composer check                 # Code-Stil (PHP-CS-Fixer) + Analyse (PHPStan, Level 8) + Tests (PHPUnit)
composer cs-fix                # Code-Stil automatisch korrigieren
composer docs                  # Links und Anker in README.md und docs/*.md prüfen (auch Teil von composer check)
vendor/bin/phpunit --filter testName tests/Pipeline/ParseRunTest.php   # einzelner Test
```

Die Tests laufen ohne Netzwerk gegen eine erfundene Freifunk-Welt (`tests/Support/World.php`). Einige
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
tests/ docs/ PRD.md   Tests; Dokumentation; Architektur, Entscheidungen und Fahrplan
```

[`PRD.md`](../PRD.md) erklärt Entscheidungen und das Design ausführlich (Englisch) und ist der beste Einstieg,
wenn du mitarbeiten willst; [`history.md`](history.md) hält fest, was der alte Parser falsch gemacht hat. Fehler und Wünsche gern als Issue; Änderungen als Pull Request – bitte
mit Tests, und `composer check` muss durchlaufen.

**Regeln im Repository**

- Zeilenenden: LF, durchgesetzt von `.gitattributes`.
- Jede Änderung an Einrichtung, Konfiguration, Auslieferung oder dem, was Betreiber sehen, aktualisiert im selben Commit die [README](../README.md) und das passende Dokument in `docs/`; die dort genannten Befehle werden ausprobiert. `composer docs` (`bin/check-docs`) prüft, dass alle Links und Anker funktionieren.
- Die Dateien in `web/` müssen ohne Composer und ohne Zugriff auf das übrige Projekt laufen (der Webspace hat weder SSH noch Composer): Änderungen daran probierst du mit einer Kopie nur von `web/` aus.
