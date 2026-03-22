# Docker mit extra API-Port

Diese Variante ist richtig, wenn die Netcup-API-Zugangsdaten **nur lokal** auf deinem Docker-Host liegen sollen.

Dabei gilt:

- Das Admin-Webinterface laeuft lokal auf einem internen Port.
- Die oeffentliche DynDNS-API laeuft auf einem **zweiten Port**.
- Auf dem API-Port ist **kein** Webinterface erreichbar.

## Was wird benoetigt?

1. Docker-Container auf deiner Synology oder deinem Server
2. Portfreigabe nur fuer den API-Port

## 1. `.env` anlegen

Im Projektordner:

```bash
cp .env.example .env
```

Dann `.env` anpassen:

```env
APP_PORT=8080
API_PORT=8081
APP_SIGNING_SECRET=bitte-lang-und-zufaellig-waehlen
APP_TOKEN_MASTER_KEY=bitte-auch-lang-und-zufaellig-waehlen
APP_BASE_ZONE=deine-domain.de
DYNDNS_UPDATE_URL=http://IP-Docker-Server:8081/api/update.php

NETCUP_CUSTOMER_NUMBER=123456
NETCUP_API_KEY=dein-api-key
NETCUP_API_PASSWORD=dein-api-passwort
```

## 2. Container starten

```bash
docker compose --profile public-api up -d --build
```

Danach gilt:

- Admin-Webinterface intern: `http://IP-Docker-Server:8080`
- Oeffentliche API: `http://IP-Docker-Server:8081/api/update.php?token=DEIN_TOKEN`

## 3. Router und Firewall

Wichtig:

- Den Admin-Port `8080` nicht ins Internet freigeben
- Nur den API-Port `8081` nach aussen freigeben

Typisches Beispiel:

- `8080` nur intern im Heimnetz
- `8081` per Portweiterleitung nach aussen

## 4. DynDNS im Webinterface anlegen

Im Admin-Webinterface:

- `http://IP-Docker-Server:8080`

legst du deine Domains an oder importierst vorhandene Netcup-Records.

Der erzeugte Token wird spaeter fuer den DynDNS-Client verwendet.

## 5. DynDNS-Aufruf

Der spaetere Aufruf sieht dann so aus:

```text
http://IP-Docker-Server:8081/api/update.php?token=DEIN_TOKEN
```

Optional mit fester IP:

```text
http://IP-Docker-Server:8081/api/update.php?token=DEIN_TOKEN&ip=1.2.3.4
```

## Fuer wen ist diese Variante gut?

- Wenn die echten Netcup-Zugangsdaten nur lokal bleiben sollen
- Wenn du einen getrennten API-Port akzeptierst
- Wenn das Webinterface nicht oeffentlich erreichbar sein soll
