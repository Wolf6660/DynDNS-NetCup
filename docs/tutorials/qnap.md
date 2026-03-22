# QNAP Cronjob

Diese Anleitung ist fuer QNAP-NAS.

## Einmaliger Test

Per SSH auf dem QNAP:

```bash
curl "https://deine-domain.tld/api/update.php?token=DEIN_TOKEN"
```

Oder bei Docker mit extra API-Port:

```bash
curl "http://DEINE-IP:8081/api/update.php?token=DEIN_TOKEN"
```

## Cronjob anlegen

Je nach QNAP-Modell kann der Weg leicht unterschiedlich sein.
Der eigentliche Befehl ist aber immer derselbe:

```bash
curl -fsS "https://deine-domain.tld/api/update.php?token=DEIN_TOKEN" >/dev/null
```

Ein typischer Cron-Eintrag fuer alle 10 Minuten:

```cron
*/10 * * * * curl -fsS "https://deine-domain.tld/api/update.php?token=DEIN_TOKEN" >/dev/null
```

## Wichtig

Bei QNAP werden manuelle Cron-Aenderungen je nach Modell manchmal nach einem Neustart ueberschrieben.
Wenn moeglich, nutze die vom System vorgesehene Aufgabenplanung oder sichere deine Cron-Konfiguration dauerhaft.
