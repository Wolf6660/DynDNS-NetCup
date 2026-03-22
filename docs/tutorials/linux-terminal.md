# Linux Terminal und Cron

Diese Anleitung ist fuer:

- Linux-Server
- Raspberry Pi
- Mini-PC
- Debian, Ubuntu, Proxmox-Host, usw.

## Einmaliger Test

Mit `curl`:

```bash
curl -fsS "https://deine-domain.tld/api/update.php?token=DEIN_TOKEN"
```

Oder bei Docker mit extra API-Port:

```bash
curl -fsS "http://DEINE-IP:8081/api/update.php?token=DEIN_TOKEN"
```

Wenn alles klappt, bekommst du eine Antwort wie:

```text
OK deine-domain updated to 1.2.3.4
```

## Automatisch per Cron

Cronjob bearbeiten:

```bash
crontab -e
```

Beispiel: alle 10 Minuten ausfuehren

```cron
*/10 * * * * curl -fsS "https://deine-domain.tld/api/update.php?token=DEIN_TOKEN" >/dev/null
```

## Wenn du IPv6 extra aktualisieren willst

Falls dein Geraet ueber IPv6 nach aussen geht und dein Endpunkt die aufrufende IP direkt verwenden soll, reicht derselbe Aufruf.

Wenn du eine feste IP mitgeben willst:

```bash
curl -fsS "https://deine-domain.tld/api/update.php?token=DEIN_TOKEN&ip=1.2.3.4"
```

## Tipp

Starte immer zuerst mit dem einmaligen Test.
Wenn der funktioniert, richte danach erst den Cronjob ein.
