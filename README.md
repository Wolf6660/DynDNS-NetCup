# DynDNS for Netcup

## Schnellstart

### Projektdateien auf den Docker-Server kopieren

Es gibt dafuer zwei einfache Wege:

#### Variante 1: Direkt von GitHub klonen

Wenn auf deinem Docker-Server `git` installiert ist, kannst du das Projekt direkt laden:

```bash
git clone git@github.com:Wolf6660/DynDNS-NetCup.git
cd DynDNS-NetCup
```

Falls du lieber HTTPS statt SSH nutzt:

```bash
git clone https://github.com/Wolf6660/DynDNS-NetCup.git
cd DynDNS-NetCup
```

#### Variante 2: ZIP von GitHub herunterladen

Wenn du kein `git` auf dem Server nutzen willst:

1. Repository auf GitHub oeffnen
2. Auf `Code` klicken
3. `Download ZIP` waehlen
4. ZIP-Datei auf deinen Docker-Server oder deine Synology kopieren
5. Dort in einen Ordner entpacken

Wichtig:

- Danach musst du in den entpackten Projektordner wechseln.
- Alle folgenden Befehle werden immer in diesem Ordner ausgefuehrt.

1. Beispielkonfiguration kopieren:

```bash
cp .env.example .env
```

2. `.env` mit deinen echten Werten füllen.

3. Container starten:

```bash
docker compose up -d --build
```

4. Admin-Oberfläche öffnen:

- [http://IP-Docker-Server:8080](http://IP-Docker-Server:8080)

## Netcup Webspace einrichten

Zusaetzlich zum Docker-Container auf deiner Synology brauchst du die Dateien aus dem Ordner `NetCup Server`.
Diese Dateien muessen auf deinen Netcup-Webspace hochgeladen werden.

### Wofuer sind diese Dateien?

Diese Dateien stellen die kleine DynDNS-API bereit:

- `api/update.php`
- `api/debug_sig.php`
- `sec/signing.php`
- `sec/netcup_env.php`

Diese API macht spaeter folgendes:

- sie liest die vom Docker-Container hochgeladene Konfiguration
- sie prueft die Signatur
- sie prueft den DynDNS-Token
- sie aktualisiert danach den DNS-Eintrag bei Netcup

### Welche Dateien muessen zu Netcup hochgeladen werden?

Lade den Inhalt aus dem Ordner `NetCup Server` auf deinen Webspace hoch.
Die Zielstruktur sollte zum Beispiel so aussehen:

```text
/api/update.php
/api/debug_sig.php
/sec/signing.php
/sec/netcup_env.php
/data/
/export/
```

Wichtig:

- Der Ordner `/data/` muss auf dem Webspace vorhanden sein.
- In diesen Ordner laedt der Docker-Container spaeter die Dateien `dyndns_config.json` und `dyndns_config.sig`.
- Diese beiden Dateien werden von `api/update.php` gelesen.

### Was muss auf dem Netcup-Webspace angepasst werden?

Nach dem Hochladen musst du auf dem Webspace diese beiden Dateien anpassen:

- `sec/signing.php`
- `sec/netcup_env.php`

#### `sec/signing.php`

Hier muss derselbe Wert eingetragen werden wie in deiner Docker-`.env` bei:

```env
APP_SIGNING_SECRET=
```

Beispiel:

```php
<?php
return [
    'signing_secret' => 'hier-genau-derselbe-wert-wie-in-APP_SIGNING_SECRET',
];
```

#### `sec/netcup_env.php`

Hier muessen deine echten Netcup-API-Daten eingetragen werden:

- `customer_number`
- `api_key`
- `api_password`

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

Wenn dein Webspace keine Umgebungsvariablen nutzt, trage die echten Werte direkt in diese beiden PHP-Dateien ein.

### Welche FTP-Werte muessen im Docker-Container stimmen?

Dein Docker-Container auf der Synology laedt die Exportdateien auf den Netcup-Webspace hoch.
Deshalb muessen diese Werte in deiner `.env` zu deinem Webspace passen:

```env
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

### Wie sieht der DynDNS-Aufruf spaeter aus?

Wenn alles eingerichtet ist, kann der DynDNS-Client spaeter diese URL aufrufen:

```text
https://deine-domain.tld/api/update.php?token=DEIN_TOKEN
```

Optional kannst du auch eine IP direkt mitgeben:

```text
https://deine-domain.tld/api/update.php?token=DEIN_TOKEN&ip=1.2.3.4
```

Wenn keine `ip` uebergeben wird, verwendet das Script automatisch die IP des aufrufenden Geraets.

## Hinweise zur Konfiguration

Die Datei `.env` muss an deine eigene Umgebung angepasst werden. Am einfachsten ist es, wenn du zuerst die Vorlage kopierst:

```bash
cp .env.example .env
```

Danach oeffnest du `.env` und aenderst mindestens diese Werte:

```env
# Port vom Docker-Container auf deiner Synology
# Beispiel: 8080 bedeutet Aufruf ueber http://IP-Docker-Server:8080
APP_PORT=8080

# Frei waehlen. Wird zum Signieren der Exportdateien verwendet.
# Sollte lang und zufaellig sein.
APP_SIGNING_SECRET=bitte-hier-ein-langes-geheimes-passwort-eintragen

# Frei waehlen. Wird fuer die lokale Verschluesselung von Tokens verwendet.
# Ebenfalls lang und zufaellig waehlen.
APP_TOKEN_MASTER_KEY=bitte-hier-einen-zweiten-langen-geheimen-schluessel-eintragen

# Deine Hauptdomain bzw. DNS-Zone bei Netcup
# Beispiel: example.de
APP_BASE_ZONE=deine-domain.de

# Deine Netcup Kundennummer
NETCUP_CUSTOMER_NUMBER=123456

# Dein Netcup API Key
NETCUP_API_KEY=hier-dein-netcup-api-key

# Dein Netcup API Passwort
NETCUP_API_PASSWORD=hier-dein-netcup-api-passwort

# Upload-Methode fuer die Exportdateien
# In deinem Fall normalerweise ftp
UPLOAD_METHOD=ftp

# FTP-Server oder Zielhost
FTP_HOST=ftp.deine-domain.de

# FTP-Port, meistens 21
FTP_PORT=21

# FTPS nur aktivieren, wenn dein Server das wirklich nutzt
FTP_SSL=false

# FTP-Benutzername
FTP_USER=dein-ftp-benutzer

# FTP-Passwort
FTP_PASS=dein-ftp-passwort

# Passive Verbindung ist in den meisten Faellen richtig
FTP_PASSIVE=true
```

Wenn du unsicher bist, kannst du dich an diese einfache Regel halten:

- Alles mit `deine-...`, `hier-...` oder `bitte-...` musst du anpassen.
- Alles andere kann erstmal so bleiben.

Nach dem Speichern kannst du den Container starten:

```bash
docker compose up -d --build
```

Danach ist die Oberflaeche ueber diese Adresse erreichbar:

- `http://IP-Docker-Server:8080`
