# DynDNS for Netcup

## Schnellstart

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
