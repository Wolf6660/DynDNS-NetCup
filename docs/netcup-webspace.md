# Netcup Webspace Variante

Diese Variante ist richtig, wenn deine Synology oder dein Docker-Server **nicht** von aussen erreichbar sein soll.

Dabei gilt:

- Das Admin-Webinterface laeuft lokal im Docker-Container auf deiner Synology.
- Der oeffentliche DynDNS-Aufruf laeuft ueber Dateien auf deinem Netcup-Webspace.
- Die Netcup-API-Zugangsdaten liegen in dieser Variante serverseitig auf dem Netcup-Webspace.

## Was wird benoetigt?

1. Docker-Container auf deiner Synology
2. Netcup-Webspace
3. FTP-Zugang zum Webspace

## 1. Docker lokal starten

Im Projektordner:

```bash
cp .env.example .env
```

Dann `.env` anpassen:

```env
APP_PORT=8080
APP_SIGNING_SECRET=bitte-lang-und-zufaellig-waehlen
APP_TOKEN_MASTER_KEY=bitte-auch-lang-und-zufaellig-waehlen
APP_BASE_ZONE=deine-domain.de
DYNDNS_UPDATE_URL=https://deine-domain.tld/api/update.php

NETCUP_CUSTOMER_NUMBER=123456
NETCUP_API_KEY=dein-api-key
NETCUP_API_PASSWORD=dein-api-passwort

UPLOAD_METHOD=ftp
UPLOAD_REMOTE_JSON_PATH=/data/dyndns_config.json
UPLOAD_REMOTE_SIG_PATH=/data/dyndns_config.sig
FTP_HOST=ftp.deine-domain.de
FTP_PORT=21
FTP_SSL=false
FTP_USER=dein-ftp-benutzer
FTP_PASS=dein-ftp-passwort
FTP_PASSIVE=true
```

Container starten:

```bash
docker compose up -d --build
```

Danach ist das Admin-Webinterface lokal erreichbar:

- `http://IP-Docker-Server:8080`

## 2. Dateien auf den Netcup-Webspace hochladen

Lade den Inhalt aus dem Ordner `NetCup Server` auf deinen Netcup-Webspace hoch.

Die Struktur sollte dort so aussehen:

```text
/api/update.php
/api/debug_sig.php
/sec/signing.php
/sec/netcup_env.php
/lib/dyndns_update.php
/data/
/export/
```

Wichtig:

- Der Ordner `/data/` muss vorhanden sein.
- Dorthin laedt der Docker-Container spaeter die Dateien `dyndns_config.json` und `dyndns_config.sig`.

## 3. Dateien auf dem Webspace anpassen

### `sec/signing.php`

Hier muss derselbe Wert stehen wie in deiner `.env` bei `APP_SIGNING_SECRET`.

Beispiel:

```php
<?php
return [
    'signing_secret' => 'hier-genau-der-wert-aus-APP_SIGNING_SECRET',
];
```

### `sec/netcup_env.php`

Hier muessen deine echten Netcup-API-Zugangsdaten eingetragen werden.

Beispiel:

```php
<?php
return [
    'endpoint' => 'https://ccp.netcup.net/run/webservice/servers/endpoint.php?JSON',
    'customer_number' => '123456',
    'api_key' => 'dein-api-key',
    'api_password' => 'dein-api-passwort',
];
```

## 4. DynDNS im Webinterface anlegen

Danach gehst du in das Webinterface:

- `http://IP-Docker-Server:8080`

Und legst dort deine Domain an oder importierst bestehende Eintraege.

Wichtig:

- Beim Anlegen wird ein Token erzeugt.
- Dieser Token ist spaeter fuer den DynDNS-Client bestimmt.

## 5. DynDNS-Aufruf

Der spaetere Aufruf sieht dann so aus:

```text
https://deine-domain.tld/api/update.php?token=DEIN_TOKEN
```

Optional mit fester IP:

```text
https://deine-domain.tld/api/update.php?token=DEIN_TOKEN&ip=1.2.3.4
```

## Fuer wen ist diese Variante gut?

- Wenn deine Synology nicht von aussen erreichbar sein soll
- Wenn dir ein oeffentlicher Endpunkt auf dem Netcup-Webspace lieber ist
