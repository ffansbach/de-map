# Statistik: Knotenzahl im Zeitverlauf

*Für: wer den Reiter „Statistik“ der Karte nutzen möchte (optional).*  
[← Dokumentation](README.md) · [Übersicht](../README.md)

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
