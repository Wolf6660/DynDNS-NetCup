# Tutorials fuer DynDNS-Clients

In diesem Ordner findest du einfache Beispiele, wie ein Geraet spaeter den DynDNS-Update-Link aufrufen kann.

## Bevor du startest

Du brauchst immer:

- deine persoenliche DynDNS-URL
- den Token aus dem Webinterface

Ein typischer Aufruf sieht so aus:

```text
https://deine-domain.tld/api/update.php?token=DEIN_TOKEN
```

Oder bei der Docker-API-Variante:

```text
http://DEINE-IP:8081/api/update.php?token=DEIN_TOKEN
```

## Verfuegbare Anleitungen

- [Linux Terminal und Cron](linux-terminal.md)
- [Synology DSM Aufgabenplanung](synology-dsm.md)
- [QNAP Cronjob](qnap.md)
- [FRITZ!Box DynDNS](fritzbox.md)
- [OpenWrt Hotplug oder Cron](openwrt.md)

## Welches Tutorial ist richtig?

- `Linux Terminal`: wenn du den Update-Link auf einem Linux-Server oder Raspberry Pi ausfuehren willst
- `Synology DSM`: wenn deine Synology selbst die DynDNS-Aktualisierung uebernehmen soll
- `QNAP`: wenn du ein QNAP-NAS nutzt
- `FRITZ!Box`: wenn deine FRITZ!Box den DynDNS-Link direkt aufrufen soll
- `OpenWrt`: wenn dein Router OpenWrt verwendet

## Wichtiger Hinweis

Jeder Client bekommt spaeter nur den Token-Link.
Der Client braucht **keine** echten Netcup-Zugangsdaten.
