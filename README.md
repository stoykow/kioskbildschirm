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
docker compose -f docker/docker-compose.yml --env-file docker/.env up -d
```

Wenn Docker auf `192.168.112.30` läuft, ist die Anwendung im lokalen Netz unter
dieser Adresse erreichbar:

`http://192.168.112.30:28830/`

FTP für Uploads läuft auf:

`192.168.112.30:28832`

Der FTP-Container schreibt direkt in:

`/srv/docker/hausordnung/data/htdocs`

Für passives FTP werden zusätzlich die Ports `28833-28842` verwendet.

Die Datenbankstruktur liegt in `schema.sql` und zusätzlich in
`database/init/001-schema.sql`.

Für eine frische MariaDB-Installation auf dem Docker-Host:

```bash
mkdir -p /srv/docker/hausordnung/data/htdocs
mkdir -p /srv/docker/hausordnung/data/db
mkdir -p /srv/docker/hausordnung/data/initdb
cp database/init/001-schema.sql /srv/docker/hausordnung/data/initdb/001-schema.sql
```

Die SQL-Datei wird nur beim ersten Start einer leeren MariaDB-Datenbank
automatisch importiert. Bei einer bereits bestehenden Datenbank kann sie über
phpMyAdmin importiert werden:

`http://192.168.112.30:28831/`

Der veröffentlichte Port kann über `APP_PORT` in einer lokalen `.env` geändert
werden. Für echte OpenWeather-Daten kann `OPENWEATHER_API` ebenfalls über `.env`
gesetzt werden.

## Abfahrten-Import

Docker Compose startet den Dienst `abfahrten-import`. Er ruft den Import intern
alle 60 Sekunden auf; die Ausgabe und Fehler stehen in den Container-Logs.
Die Aktualisierung der Anzeige allein importiert keine neuen Abfahrten.
Nach einer Änderung der Compose-Datei auf dem Docker-Host erneut ausführen:

```bash
docker compose -f docker/docker-compose.yml --env-file docker/.env up -d
```

`cron_abfahrten.php` nutzt jetzt die DB-Schnittstelle
`int.bahn.de/web/api/reiseloesung/abfahrten`.

Import manuell auf dem Docker-Host im Container ausführen:

```bash
docker compose -f docker/docker-compose.yml --env-file docker/.env exec app php cron_abfahrten.php
```

Import per Browser ausführen:

`http://192.168.112.30:28830/cron_abfahrten.php`

## Konfiguration

In `config.php` schaltet `$showMouse = true` den Mauszeiger ein;
`$showMouse = false` blendet ihn auch über Schaltflächen aus.
Nach dem Umschalten die Kiosk-Seite neu laden.

Die Busanzeige nutzt Bahnhof Südausgang, Görlitz (DB-ID `977198`).
Beide Haltestellenseiten (`977198` und `743766`) werden berücksichtigt;
mitgelieferte Abfahrten anderer Haltestellen werden beim Import verworfen.
`$cityBusLines` erlaubt nur die GVB-Stadtbuslinien A, B, C, D, E, F und N,
sofern sie an dieser Haltestelle abfahren. Regionalbusse werden beim Import
und bei der Ausgabe herausgefiltert. Nach der Umstellung den Abfahrten-Import
einmal ausführen oder den nächsten automatischen Import abwarten.

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

## GitLab CI/CD

Das Repository liegt auf GitLab. Die aktive Pipeline ist `.gitlab-ci.yml`;
GitLab führt die bisherige GitHub-Workflow-Datei nicht aus.

Die Pipeline prüft PHP- und JavaScript-Syntax sowie die Deploy-Werkzeuge.
Nach erfolgreichen Tests entsteht ein Webpaket ohne `.env`, SQL, Compose,
Git-Metadaten oder Zugangsdaten. Ein Push auf `main` lädt dieses Paket per FTP
hoch. Die Dateien werden einzeln über temporäre Dateien und Rename ersetzt;
der Versionsmarker wird zuletzt veröffentlicht. Deploys laufen nacheinander.
Danach werden die ausgelieferte Commit-Version, Startseite, statischen Dateien
und JSON-APIs geprüft sowie die Abfahrten einmal neu importiert. Ein fehlerhafter
Upload oder Live-Test lässt den Job fehlschlagen. Ein manueller Pipeline-Start
auf `main` führt ebenfalls den Deploy aus.

### Variablen in GitLab anlegen

Im Projekt unter **Settings → CI/CD → Variables** diese Variablen anlegen:

| Variable | Wert | Geheim |
| --- | --- | --- |
| `HOST` | `192.168.112.30` (ohne `http://` oder Port) | Nein |
| `FTP_PORT` | `28832` | Nein |
| `FTP_USER` | Bestehender FTP-Benutzer, z. B. `ftpuser` | Nein |
| `FTP_PASS` | Bestehendes Passwort dieses FTP-Benutzers | Ja |
| `FTP_SERVER_DIR` | `.` (Login-Verzeichnis, entspricht dem Webverzeichnis) | Nein |

Alle sind vom Typ **Variable**, Environment scope **production** (oder `*`), und
**Protected**. Für `FTP_PASS` zusätzlich **Masked and hidden** auswählen und
**Expand variable reference** ausschalten. `main` muss eine geschützte Branch
sein, damit der Deploy die geschützten Variablen erhält. Die drei öffentlichen
Verbindungswerte haben auch Defaults in der YAML; GitLab-Variablen überschreiben
diese. Pflicht sind insbesondere `FTP_USER` und `FTP_PASS`.
FTP verbindet sich mit `HOST:28832`; der Live-Test nutzt `http://HOST:28830/`.
Beim bestehenden FTP-Server liegt das Webverzeichnis unter `/home/FTP_USER`.
`.` bleibt nach der Anmeldung darin; `/` wäre die Container-Wurzel.
Optional überschreiben `FTP_SERVER` das FTP-Ziel und `APP_URL` die Testadresse.
Siehe [GitLab: CI/CD-Variablen](https://docs.gitlab.com/ci/variables/).

Es wird das vorhandene FTP-Passwort aus der Host-Konfiguration verwendet.
Für diesen FTP-Deploy werden keine Datenbankpasswörter oder SSH-Schlüssel
benötigt. `MARIADB_ROOT_PASSWORD`, `MARIADB_PASSWORD`, `HO_TOKEN` und
`OPENWEATHER_API` bleiben in der lokalen `docker/.env` auf dem Host.
Compose und Datenbankstruktur werden über diese Pipeline nicht ausgerollt.
Die echte `.env` und `.vscode/sftp.json` bleiben aus Git ausgeschlossen.

### Runner im lokalen Netz

Alle Jobs verwenden den Tag **gitlab**, passend zum bestehenden Projekt-Runner.
Ein passender GitLab Runner
muss `gitlab.stoykow.de`, die Container-Registry und den FTP-/Webserver erreichen
können. Er braucht den **Docker executor**. Die Jobs brauchen keinen
privilegierten Modus und keinen Docker-Socket.

Im GitLab-Projekt unter **Settings → CI/CD → Runners** einen Project Runner
mit Tag `gitlab` erstellen und **Run untagged jobs** ausschalten.
Für Tests auf Feature-Branches und Merge Requests muss er auch ungeschützte
Branches ausführen dürfen; die Deploy-Variablen bleiben trotzdem geschützt.
Siehe [GitLab: Runner erstellen](https://docs.gitlab.com/ci/runners/new_creation_workflow/).

Falls noch kein Runner installiert ist, kann er auf dem Docker-Host laufen:

```bash
docker run -d --name gitlab-runner --restart unless-stopped \
  -v /srv/docker/gitlab-runner/config:/etc/gitlab-runner \
  -v /var/run/docker.sock:/var/run/docker.sock \
  gitlab/gitlab-runner:alpine

docker exec -it gitlab-runner gitlab-runner register \
  --url https://gitlab.stoykow.de \
  --executor docker \
  --docker-image php:8.3-cli
```

Bei der Registrierung den gerade in GitLab erzeugten `glrt-…`-Token eingeben.
Dieser Token gehört zur Runner-Einrichtung, nicht in die Projekt-CI-Variablen.
Der Socket-Mount dient nur dem Runner zum Starten der Job-Container.
Siehe [GitLab: Runner im Container](https://docs.gitlab.com/runner/install/docker/).

Für FTP müssen vom Runner aus auch die passiven Ports `28833–28842` erreichbar
sein. Nach Anlegen der Variablen und des Runners unter **Build → Pipelines**
eine neue Pipeline für `main` starten. Ist ein Job `pending`, fehlen in der
Regel ein verfügbarer Runner oder der passende Tag.

Die Dateien `ci/package.py`, `ci/ftp_upload.py` und `ci/verify.py` übernehmen
Paketbau, Upload und Live-Prüfung. Die Deploy-Tests lassen sich lokal mit
`python -m unittest discover -s ci -p 'test_*.py' -v` ausführen.

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
