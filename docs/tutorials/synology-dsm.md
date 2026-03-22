# Synology DSM Aufgabenplanung

Diese Anleitung ist fuer Synology-NAS mit DSM.

## Ziel

Die Synology ruft den DynDNS-Link automatisch in einem festen Intervall auf.

## 1. Update-Link bereitlegen

Beispiel:

```text
https://deine-domain.tld/api/update.php?token=DEIN_TOKEN
```

Oder bei Docker mit extra API-Port:

```text
http://DEINE-IP:8081/api/update.php?token=DEIN_TOKEN
```

## 2. Aufgabenplanung oeffnen

In DSM:

1. `Systemsteuerung`
2. `Aufgabenplaner`
3. `Erstellen`
4. `Geplante Aufgabe`
5. `Benutzerdefiniertes Script`

## 3. Aufgabe einrichten

Name zum Beispiel:

```text
DynDNS Update
```

Benutzer:

```text
root
```

Zeitplan:

- zum Beispiel alle 10 Minuten

## 4. Script eintragen

```bash
curl -fsS "https://deine-domain.tld/api/update.php?token=DEIN_TOKEN"
```

Oder fuer die Docker-API-Variante:

```bash
curl -fsS "http://DEINE-IP:8081/api/update.php?token=DEIN_TOKEN"
```

## 5. Speichern und testen

Nach dem Speichern kannst du die Aufgabe einmal manuell starten.

Wenn alles richtig ist, wird der DNS-Eintrag aktualisiert.

## Tipp

Wenn du mehrere Domains hast, legst du pro Token am besten eine eigene Aufgabe an.
