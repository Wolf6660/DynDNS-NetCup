# OpenWrt Hotplug oder Cron

Diese Anleitung ist fuer Router mit OpenWrt.

## Einmaliger Test

Per SSH auf dem Router:

```bash
wget -qO- "https://deine-domain.tld/api/update.php?token=DEIN_TOKEN"
```

Oder:

```bash
curl "https://deine-domain.tld/api/update.php?token=DEIN_TOKEN"
```

## Variante A: Cronjob

Cronjob bearbeiten:

```bash
crontab -e
```

Beispiel fuer alle 10 Minuten:

```cron
*/10 * * * * wget -qO- "https://deine-domain.tld/api/update.php?token=DEIN_TOKEN" >/dev/null 2>&1
```

## Variante B: Bei neuer WAN-Verbindung

Du kannst auch ein kleines Script bei WAN-Events ausfuehren.
Das ist fuer viele DynDNS-Faelle sogar besser als ein reiner Cronjob.

Beispiel-Idee:

- WAN bekommt neue IP
- Script ruft den DynDNS-Link auf

## Empfehlung

Fuer Anfaenger ist ein einfacher Cronjob meist leichter zu pflegen als Hotplug-Scripte.
