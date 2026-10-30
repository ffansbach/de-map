# Überwachung: healthchecks.io und InfluxDB

*Für: wer sich benachrichtigen lassen oder Messwerte sammeln möchte.*  
[← Dokumentation](README.md) · [Übersicht](../README.md)

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
