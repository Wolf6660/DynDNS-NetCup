# FRITZ!Box mit externem Script

Diese Anleitung ist fuer Nutzer einer FRITZ!Box.

## Einfache Variante direkt in der FRITZ!Box

In vielen Faellen kannst du den DynDNS-Aufruf direkt in der FRITZ!Box eintragen.

Menue:

```text
Internet -> Freigaben -> DynDNS
```

Dann:

- `DynDNS benutzen` aktivieren
- `Anbieter`: `Benutzerdefiniert`

### Update-URL

Trage als Update-URL am besten dieses Format ein:

```text
https://dyndns-domain.de/api/update.php?token=DEINTOKEN&ip=<ipaddr>
```

Oder bei Docker mit extra API-Port:

```text
http://DEINE-IP:8081/api/update.php?token=DEINTOKEN&ip=<ipaddr>
```

Wichtig:

- `DEINTOKEN` musst du durch deinen echten Token ersetzen
- `<ipaddr>` ist der Platzhalter der FRITZ!Box fuer die aktuelle externe IP
- `type=a` wird fuer dieses Projekt nicht benoetigt

### Benutzername und Passwort

Wenn deine URL diese Werte nicht verwendet, kannst du einfache Platzhalter eintragen:

- Benutzername: `dummy`
- Passwort: `dummy123`

Auch beim Domainnamen reicht ein Platzhalter, falls das Feld Pflicht ist.

## Wichtiger Punkt

Eine FRITZ!Box kann nicht beliebig jedes externe DynDNS-Script so flexibel ausfuehren wie ein normaler Linux-Server.
Am einfachsten ist es deshalb oft, den DynDNS-Aufruf auf einem kleinen Geraet im Heimnetz laufen zu lassen:

- Raspberry Pi
- Mini-PC
- NAS
- Linux-Container

## Empfohlene Loesung

Nutze ein kleines Linux-System im Heimnetz und verwende dort das Tutorial:

- [Linux Terminal und Cron](linux-terminal.md)

## Wenn du unbedingt ueber die FRITZ!Box arbeiten willst

Dann gibt es meist zwei Wege:

1. das eingebaute DynDNS-Menue der FRITZ!Box mit einem kompatiblen Anbieterformat
2. ein externes Hilfsscript auf einem anderen Geraet im Heimnetz

Da dieses Projekt mit Token-URL arbeitet, ist der zweite Weg in der Praxis meist einfacher und robuster.

## Empfehlung fuer Anfaenger

Wenn du eine FRITZ!Box hast:

- probiere zuerst die direkte DynDNS-Konfiguration in der FRITZ!Box
- wenn das nicht sauber funktioniert, lass den eigentlichen Aufruf von deiner Synology, einem Raspberry Pi oder einem kleinen Linux-Host erledigen
- verwende dafuer den Token-Link aus dem Webinterface
