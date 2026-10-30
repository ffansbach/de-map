# Admin-Oberfläche

*Für: wer den Parser betreibt und sehen möchte, was die Läufe gefunden haben.*  
[← Dokumentation](README.md) · [Übersicht](../README.md)

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

**Sicherheit:** Ohne Passwort antwortet die Oberfläche nur auf `127.0.0.1`/`::1` und nur bei direkten Anfragen: trägt eine Anfrage Proxy-Header (`X-Forwarded-For`, `Forwarded`, `X-Real-IP`, `Via` …), wird sie abgelehnt, denn hinter einem Reverse-Proxy auf demselben Rechner sieht jede Anfrage wie `127.0.0.1` aus. Hinter einem Proxy also immer ein Passwort setzen. Mit Passwort
verwendet sie HTTP Basic – sobald sie über das Netz erreichbar ist, **nur über HTTPS** betreiben. Sie
sendet strenge Sicherheitsheader (keine Skripte, `no-store`) und kann nichts verändern.
