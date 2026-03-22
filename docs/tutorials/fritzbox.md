# FRITZ!Box mit externem Script

Diese Anleitung ist fuer Nutzer einer FRITZ!Box.

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

- lass den eigentlichen Aufruf von deiner Synology, einem Raspberry Pi oder einem kleinen Linux-Host erledigen
- verwende dafuer den Token-Link aus dem Webinterface
