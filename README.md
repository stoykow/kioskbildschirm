# Hausordnung (Kiosk)

Kurzübersicht für den aktuellen Stand, damit man direkt weiß, wo es weitergeht.

## Was das System macht
- Kiosk-UI für Umwelt/Status, Abfall, Aufgaben, ÖPNV-Abfahrten
- Sonstige Termine (z. B. Schornsteinfeger) mit "Zuhause erforderlich"
- Wetter-Import (OpenWeather) + Forecast-Speicherung
- Aufgaben/Abfall mit Erledigt-Markierung
- Admin-Seite zum Bearbeiten von Einstellungen und sonstigen Terminen
- Geräte-Registrierung (`device_register.php`)

## Wichtige Dateien
- `index.php` Kiosk-Startseite
- `kiosk.js` Initialisierung/Intervalle
- `kiosk.css` Styles
- `wetter.js` Umwelt/Status (kompakt, Haustür in eigener Zeile)
- `abfall.js` Abfall + Aufgaben (Abfall nur anzeigen, wenn vorhanden)
- `aufgaben.js` Aufgaben
- `abfahrten.js` ÖPNV-Anzeige
- `sonstige.js` Sonstige Termine + Zuhause-Modal

Admin/Config:
- `admin.php` zentrale Admin-Seite (app_config + sonstige Termine)
- `config.php` zentrale Config + `db_connect()`

API/Endpoints:
- `abfall_api.php`, `abfall_done.php`, `abfall_users.php`
- `aufgaben_api.php`, `aufgaben_done.php`
- `termine_sonstige_api.php`, `termine_sonstige_zuhause.php`
- `geraet_status.php`, `ha_ingest.php`
- `device_register.php`
- Cron: `cron_wetter.php`, `cron_abfall.php`, `cron_abfahrten.php`

## Docker

Auf dem Docker-Host ausführen:

```bash
docker compose up --build -d
```

Wenn Docker auf `192.168.112.30` läuft, ist die Anwendung im lokalen Netz unter
dieser Adresse erreichbar:

`http://192.168.112.30:28830/`

Die Datenbank wird über `schema.sql` initialisiert. Die Docker-Umgebung nutzt
die Beispielwerte aus `docker-compose.yml`. Der veröffentlichte Port kann über
`APP_PORT` in einer lokalen `.env` geändert werden. Für echte OpenWeather-Daten
kann `OPENWEATHER_API` ebenfalls über `.env` gesetzt werden.

## Abfahrten-Import

`cron_abfahrten.php` nutzt jetzt die DB-Schnittstelle
`int.bahn.de/web/api/reiseloesung/abfahrten`.

Import manuell auf dem Docker-Host im Container ausführen:

```bash
docker compose exec app php cron_abfahrten.php
```

Import per Browser ausführen:

`http://192.168.112.30:28830/cron_abfahrten.php`

## Konfiguration
Secrets nur per ENV:
- `OPENWEATHER_API`
- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`

Alles andere per `app_config` (DB):
- `openweather_lat`, `openweather_lon`, `openweather_lang`, `openweather_units`
- `termine_sonstige_days`, `termine_abfall_days`
- `snow_task_lead_hours`, `snow_task_min_mm`, `snow_task_evening_hour`

Admin-Seite: `admin.php` (ohne Login)
- Einträge in `app_config` anlegen/aktualisieren
- Sonstige Termine anlegen/bearbeiten/löschen
- Löschen räumt `termine_sonstige_zuhause` auf

## CI/CD-Beispiel

Die Datei `.github/workflows/test-und-upload.yml` zeigt einen einfachen Ablauf:

- Repository auschecken
- Docker-Image bauen
- PHP-Syntax im Container prüfen
- Projektdateien als Artifact `hausordnung-kiosk` hochladen
- optional bei Push auf `main` per FTP über einen Self-hosted Runner hochladen

Für GitHub Actions müssen diese Repository-Secrets angelegt werden:

- `FTP_SERVER`
- `FTP_PORT`
- `FTP_USERNAME`
- `FTP_PASSWORD`
- `FTP_SERVER_DIR`

`.env.example` zeigt die benötigten Namen. Die echte `.env` bleibt lokal und
wird nicht mit Git hochgeladen.

Die bisherige Datei `.vscode/sftp.json` ist lokal und wird ignoriert. Als Muster
liegt `.vscode/sftp.example.json` ohne echtes Passwort bei. Zugangsdaten gehören
in GitHub Secrets oder in eine lokale, nicht versionierte Konfiguration.

Hinweis: `192.168.112.30` ist eine private LAN-Adresse. Deshalb läuft der
Upload-Job im Workflow auf `runs-on: self-hosted`. Dieser Runner muss im gleichen
Netz wie der Docker-/FTP-Host laufen. Der Test-Job kann weiterhin auf
`ubuntu-latest` bei GitHub laufen.

## Datenbank - Tabellen (wichtigste)
Bestehende Kern-Tabellen:
- `benutzer`, `benutzer_gruppen`
- `aufgaben`, `aufgaben_erledigt`
- `termine_abfall`, `termine_abfall_erledigt`
- `termine_sonstige` (mit `requires_home`)
- `termine_sonstige_zuhause` (wer ist zuhause)
- `wetter_weather` (aktuelles Wetter aus OpenWeather)
- `wetter_forecast` (3h Forecast)
- `abfall_termine` wurde umbenannt zu `termine_abfall`
- `abfall_erledigt` wurde umbenannt zu `termine_abfall_erledigt`
- `geraete`, `geraete_daten`
- `app_config`
- `geraete_registrierungen` (device_id + device_name + benutzer_id)

Hinweis: Tabelle `wetter_daten` wurde zu `wetter_weather` umbenannt.

## UI-Logik (kurz)
- Abfall und Sonstige werden nur angezeigt, wenn Termine vorhanden sind.
- Schadstoffmobil ist nicht klickbar und zeigt keinen Unerledigt-Status.
- Sonstige Termine: Status "Keiner zuhause" nur wenn `requires_home=1` und keine Auswahl.
- Haustür-Status farbig: aufgeschlossen rot, zugeschlossen grün.

## Device-Registrierung
- `device_register.php` nimmt `device_name`, erzeugt `device_id`, speichert in `geraete_registrierungen`.
- `benutzer_id` wird manuell in der DB gesetzt.

## ToDo / Weiter
- Optional: `device_status.php` + Freischalt-Workflow
- Optional: Admin-Abschnitt für Geräte-Freigabe
- Optional: Admin-UI für sonstige Termine erweitern (Filter, Suche)
