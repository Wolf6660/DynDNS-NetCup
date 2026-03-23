# FRITZ!Box DynDNS

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
https://dyndns-domain.de/api/update.php?token=DEINTOKEN
```

Oder bei Docker mit extra API-Port:

```text
http://DEINE-IP:8081/api/update.php?token=DEINTOKEN
```

Wichtig:

- `DEINTOKEN` musst du durch deinen echten Token ersetzen
- `type=a` wird fuer dieses Projekt nicht benoetigt

### Benutzername und Passwort

Wenn deine URL diese Werte nicht verwendet, kannst du einfache Platzhalter eintragen:

- Benutzername: `dummy`
- Passwort: `dummy123`

Auch beim Domainnamen reicht ein Platzhalter, falls das Feld Pflicht ist.
