# DynDNS for Netcup

Dieses Projekt kann jetzt containerisiert betrieben und sicher auf GitHub veröffentlicht werden, ohne produktive Daten oder Secrets mitzuveröffentlichen.

## Was wurde umgestellt

- Docker-Setup mit `Dockerfile` und `docker-compose.yml`
- Konfiguration per Umgebungsvariablen statt fest eingebauter Zugangsdaten
- automatische SQLite-Initialisierung und Schema-Erweiterung beim Start
- sichere `.gitignore`-Regeln für Datenbank, Exporte, Logs und `.env`
- optionale Datenübernahme einer bestehenden SQLite-Datei beim ersten Containerstart

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

- [http://localhost:8080/domains.php](http://localhost:8080/domains.php)

## Datenübernahme von Synology

Deine Daten muessen nicht auf GitHub hochgeladen werden. Es gibt zwei sichere Wege:

### Variante A: Bestehende SQLite direkt uebernehmen

1. Kopiere deine bestehende Datei `dyndns.sqlite` nach:

```text
docker-import/dyndns.sqlite
```

2. Starte danach den Container neu:

```bash
docker compose up -d --build
```

Beim ersten Start wird die Datenbank automatisch nach `runtime/data/dyndns.sqlite` uebernommen, falls dort noch keine Datenbank existiert.

### Variante B: Laufende Daten dauerhaft ausserhalb des Images speichern

Der Container nutzt bereits Bind-Mounts:

- `./runtime/data` fuer SQLite
- `./runtime/export` fuer erzeugte Exportdateien

Damit bleiben deine Daten beim Update des Containers erhalten und werden wegen `.gitignore` nicht in Git aufgenommen.

## Sicher fuer GitHub

Nicht veroeffentlicht werden durch die `.gitignore` unter anderem:

- `.env`
- `data/*.sqlite`
- `runtime/*`
- `export/*.json`
- `*.sig`
- Logs

Wichtig: Vor dem ersten Push nur `.env.example` committen, niemals deine echte `.env`.

## Hinweise zur Konfiguration

Die wichtigsten Werte in `.env`:

- `APP_SIGNING_SECRET`
- `APP_TOKEN_MASTER_KEY`
- `APP_BASE_ZONE`
- `NETCUP_CUSTOMER_NUMBER`
- `NETCUP_API_KEY`
- `NETCUP_API_PASSWORD`
- `FTP_HOST`
- `FTP_USER`
- `FTP_PASS`

Der mitgelieferte Ordner `NetCup Server` wurde ebenfalls auf Umgebungsvariablen vorbereitet, damit auch dort keine echten Schluessel ins Repository muessen.
