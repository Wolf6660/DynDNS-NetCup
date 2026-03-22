# DynDNS for Netcup

Ein DynDNS-Verwaltungstool fuer Netcup mit Webinterface, Token-Verwaltung und zwei moeglichen Betriebsarten.

## Fuer wen ist dieses Projekt?

Dieses Projekt ist fuer dich, wenn du:

- DynDNS-Eintraege bei Netcup verwalten willst
- Tokens statt echter Zugangsdaten an Clients ausgeben willst
- ein einfaches Webinterface fuer Domains und Tokens haben moechtest

## Was macht das Projekt?

Das Projekt bietet:

- ein Admin-Webinterface im Docker-Container
- Verwaltung von Domains, Tokens und Exportdateien
- DynDNS-Updates per Token
- zwei moegliche Betriebsarten fuer den oeffentlichen Update-Endpunkt

## Schnellstart fuer Anfaenger

### 1. Projekt holen

Mit Git:

```bash
git clone https://github.com/Wolf6660/DynDNS-NetCup.git
cd DynDNS-NetCup
```

Oder als ZIP von GitHub herunterladen und entpacken.

### 2. `.env` anlegen

```bash
cp .env.example .env
```

### 3. Die wichtigsten Werte in `.env` aendern

```env
APP_PORT=8080
APP_SIGNING_SECRET=bitte-lang-und-zufaellig-waehlen
APP_TOKEN_MASTER_KEY=bitte-auch-lang-und-zufaellig-waehlen
APP_BASE_ZONE=deine-domain.de
DYNDNS_MODE=netcup_webspace
DYNDNS_UPDATE_URL=
TRUST_PROXY_HEADERS=false

NETCUP_CUSTOMER_NUMBER=123456
NETCUP_API_KEY=dein-api-key
NETCUP_API_PASSWORD=dein-api-passwort
```

### 4. Docker starten

```bash
docker compose up -d --build
```

### 5. Webinterface aufrufen

- `http://IP-Docker-Server:8080`

## Welche Betriebsart soll ich nehmen?

### Variante A: Netcup-Webspace

Nimm diese Variante, wenn deine Synology **nicht** von aussen erreichbar sein soll.

Anleitung:

- [Netcup Webspace Variante](docs/netcup-webspace.md)

### Variante B: Docker mit extra API-Port

Nimm diese Variante, wenn die echten Netcup-Zugangsdaten **nur lokal** auf deinem Docker-Host bleiben sollen.

Anleitung:

- [Docker mit extra API-Port](docs/docker-public-api.md)

## Was ist der Unterschied?

- `Netcup-Webspace`: kein externer Zugriff auf deine Synology noetig, aber die Netcup-API-Daten liegen auf dem Webspace
- `Docker extra API-Port`: Netcup-API-Daten bleiben lokal, dafuer braucht die API einen getrennten von aussen erreichbaren Port

## Welche Einstellung muss ich in `.env` setzen?

In deiner `.env` gibt es jetzt einen klaren Schalter:

```env
DYNDNS_MODE=netcup_webspace
```

Moegliche Werte:

- `netcup_webspace` = Update ueber Dateien auf dem Netcup-Webspace
- `local_api` = Update direkt ueber Docker auf extra API-Port

## Wofuer ist `DYNDNS_UPDATE_URL`?

`DYNDNS_UPDATE_URL` ist optional.

Sie wird nur fuer die Test-Funktion im Webinterface verwendet.
Wenn du in der Admin-Oberflaeche auf `Update testen` klickst, wird diese URL mit dem passenden Token aufgerufen.

Beispiele:

```env
# Netcup-Webspace
DYNDNS_UPDATE_URL=https://deine-domain.tld/api/update.php

# Lokale Docker-API
DYNDNS_UPDATE_URL=http://IP-Docker-Server:24081/api/update.php
```

Wichtig:

- Dort wird **kein** Token fest eingetragen
- Das Webinterface haengt den Token beim Test automatisch an

Hinweis:

- Bei `local_api` kann man diese URL meist leicht aus Server-IP und `API_PORT` ableiten
- Bei `netcup_webspace` sollte hier die echte oeffentliche Update-URL eingetragen werden

## Reverse Proxy und echte WAN-IP

Wenn dein DynDNS-Aufruf ueber einen Reverse Proxy laeuft, sieht das Backend sonst oft nur die interne Proxy-IP statt deiner echten WAN-IP.

Dafuer gibt es in der `.env` diesen Schalter:

```env
TRUST_PROXY_HEADERS=true
```

Dann wertet die lokale Docker-API die Header `X-Forwarded-For` und `X-Real-IP` aus.

Wichtig:

- Nur aktivieren, wenn wirklich ein vertrauenswuerdiger Reverse Proxy davor sitzt
- Alternativ kann ein Client die IP auch direkt mit `?ip=` uebergeben

## Wo ist das Webinterface?

Das Admin-Webinterface ist immer hier:

- `http://IP-Docker-Server:8080`

## Tutorials fuer DynDNS-Clients

Wenn du spaeter wissen willst, wie Router, NAS oder Linux-Systeme den DynDNS-Link automatisch aufrufen koennen, findest du hier passende Beispiele:

- [Tutorial-Uebersicht](docs/tutorials/README.md)

## Wichtiger Hinweis fuer Anfaenger

Wenn du nicht sicher bist, welche Variante du brauchst:

- Nimm `Netcup-Webspace`, wenn dein Docker-Server nicht aus dem Internet erreichbar sein soll
- Nimm `Docker mit extra API-Port`, wenn die echten Zugangsdaten nur lokal bleiben sollen
