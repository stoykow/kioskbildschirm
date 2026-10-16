# Hausordnung (Kiosk)

Kurzübersicht für den aktuellen Stand, damit man direkt weiß, wo es weitergeht.

## Was das System macht

- Kiosk-UI für Umwelt/Status, Abfall, Aufgaben, ÖPNV-Abfahrten
- Sonstige Termine (z. B. Schornsteinfeger) mit "Zuhause erforderlich"
- Wetter-Import (OpenWeather) + Forecast-Speicherung
- Aufgaben/Abfall mit Erledigt-Markierung
- Reinigungsplan mit wechselnden Bewohnergruppen und frei einstellbarem Rhythmus
- Admin-Seite zum Bearbeiten von Einstellungen und sonstigen Terminen
- Geräte-Registrierung (`device_register.php`)

## Wichtige Dateien

- `index.php` Kiosk-Startseite
- `kiosk.js` Initialisierung/Intervalle
- `kiosk.css` Styles
- `wetter.js` Umwelt/Status (kompakt, Haustür in eigener Zeile)
- `abfall.js` Abfall + Aufgaben (Abfall nur anzeigen, wenn vorhanden)
- `aufgaben.js` Aufgaben
- `reinigungsplan_aufgaben.php` erzeugt die freitags fälligen Reinigungsaufgaben
- `abfahrten.js` ÖPNV-Anzeige
- `abfahrten_helper.php` VVO-Abfahrtsimport, Zeitumrechnung und gemeinsamer Datenbankcache
- `sonstige.js` Sonstige Termine + Zuhause-Modal

Admin/Config:

- `admin.php` zentrale Admin-Seite (app_config + sonstige Termine)
- `reinigungsplan_admin.php` Reinigungspläne und Bewohnergruppen verwalten
- `reinigungsplan.php` gespeicherte Pläne laden und Zeiträume berechnen
- `config.php` zentrale Config + `db_connect()`

API/Endpoints:

- `abfall_api.php`, `abfall_done.php`, `abfall_users.php`
- `aufgaben_api.php`, `aufgaben_done.php`
- `reinigungsplan_api.php` aktive Reinigungspläne mit aktuellen und kommenden Zeiträumen
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
automatisch importiert. Sie enthält bereits die Tabellen für Reinigungspläne
einschließlich des optionalen Enddatums.

Bei einer bestehenden Datenbank die folgenden Migrationen in dieser Reihenfolge
importieren:

1. `database/migrations/002-reinigungsplan.sql` ergänzt die Tabellen für Reinigungspläne.
2. `database/migrations/003-reinigungsplan-ende.sql` ergänzt das optionale Enddatum.

Bereits ausgeführte Migrationen müssen nicht erneut importiert werden. Ist die
Migration 002 bereits ausgeführt, genügt die Migration 003. Nicht das komplette
`schema.sql` oder `database/init/001-schema.sql` erneut importieren. Die
Migrationen erhalten die vorhandenen Bewohnergruppen und Daten. Der Import ist
über phpMyAdmin möglich:

`http://192.168.112.30:28831/`

Der veröffentlichte Port kann über `APP_PORT` in einer lokalen `.env` geändert
werden. Für echte OpenWeather-Daten kann `OPENWEATHER_API` ebenfalls über `.env`
gesetzt werden.

## Abfahrten-Import

Die Abfahrten kommen aus dem gemeinsamen Abfahrtsmonitor von ZVON/VVO über
`https://webapi.vvo-online.de/dm`. Die bisherige Bahn-Schnittstelle blockiert
die Abrufe und wird nicht mehr verwendet.

Verwendete Haltestellen:

| Anzeige | VVO-Haltestelle | Vorhandene Datenbank-ID |
| --- | --- | --- |
| Görlitz Hbf | `14908885` | `8010131` |
| Lutherstraße | `14908970` | `977263` |
| Bahnhof Südausgang | `14908886` | `977198` |

`abfahrten.php` aktualisiert beim Abruf den gemeinsamen Cache bei Bedarf.
Ein Datenbank-Lock verhindert gleichzeitige Importe. Auch bei Quellfehlern
erfolgt höchstens ein automatischer Versuch pro Minute. Ein Host-Cronjob ist
dadurch für die laufende Kiosk-Anzeige nicht erforderlich. Bereits vorhandene
gültige Abfahrten bleiben erhalten, wenn eine Datenquelle ausfällt.

Die Uhrzeiten werden in `Europe/Berlin` gespeichert. Busse am Südausgang
werden auf die konfigurierten Linien A, B, C, D, E, F, N, 62 und 67 begrenzt.
Die Anzahl der angezeigten Abfahrten steht in `config.php` unter
`departureLimits` (Zug 6, Tram 2, Bus 4).

Die bestehenden `DATETIME`-Spalten speichern lokale Uhrzeiten ohne UTC-Offset.
Während der doppelt vorkommenden Stunde bei der Zeitumstellung können
zeitgleiche Fahrten deshalb zusammenfallen.

Import manuell auf dem Docker-Host im Container ausführen:

```bash
docker compose -f docker/docker-compose.yml --env-file docker/.env exec app php cron_abfahrten.php
```

Import per Browser ausführen:

`http://192.168.112.30:28830/cron_abfahrten.php`

`cron_abfahrten.php` verwendet denselben Import und dasselbe Minutenlimit.
Ein fehlgeschlagener Import liefert HTTP 503 anstelle eines irreführenden OK.
Die Parser- und Filterprüfungen laufen mit `php tests/abfahrten_test.php`.

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
- Link zur Verwaltung der Reinigungspläne

## Reinigungsrhythmus einrichten

In `admin.php` den Link zum Reinigungsplan öffnen oder direkt
`reinigungsplan_admin.php` aufrufen.

1. Vorhandene Bewohnergruppen verwenden oder neue Gruppen anlegen, zum Beispiel
   „Erdgeschoss“ und „Obergeschoss“.
2. Einen Plan mit Bezeichnung, Startdatum, optionalem Enddatum und optionalen
   Hinweisen anlegen. Das Enddatum gilt einschließlich. Ein leeres Enddatum
   bedeutet unbegrenzte Wiederholung.
3. Das Intervall zwischen 1 und 365 eingeben und Tage, Wochen oder Monate wählen.
4. Die Reihenfolge über Positionen festlegen, zum Beispiel 1, 2 und 3. Gruppen mit
   leerem Positionsfeld nehmen nicht teil. Die erste Gruppe beginnt am Startdatum.
5. Den Plan speichern. Die Vorschau zeigt den aktuellen und die drei folgenden
   Zeiträume, soweit sie durch das optionale Enddatum abgedeckt sind. Liegt der Start in der Zukunft,
   zeigt sie die ersten vier Zeiträume bis höchstens zum Enddatum.

Bei einem Wochenintervall und Start am Montag wechselt die Zuständigkeit jeden
Montag. Nach der letzten Gruppe beginnt die Reihenfolge von vorn, solange das
Enddatum noch nicht überschritten ist. Bei
Monatsintervallen gilt der ursprüngliche Starttag. In kürzeren Monaten wird das
Monatsende verwendet.

Die Reinigung erscheint im Kiosk unter **Aufgaben**. Jede Kalenderwoche entsteht
eine Aufgabe, die am Freitag fällig ist. Die zuständige Gruppe ergibt sich aus
der Planrotation an diesem Freitag. Die Aufgabe wird ab Montag derselben Woche
angezeigt und kann wie andere Aufgaben als erledigt markiert werden. Offene
überfällige Aufgaben bleiben sichtbar. Nach dem optionalen Enddatum entstehen
keine Aufgaben mit späterer Fälligkeit. Die Anzeige wird alle fünf Minuten
aktualisiert.

Pläne können pausiert und wieder aktiviert werden, der Kalender läuft dabei
weiter. Änderungen an Startdatum, Enddatum, Intervall oder Gruppenreihenfolge
aktualisieren offene Aufgaben mit heutiger oder zukünftiger Fälligkeit.
Erledigte und bereits überfällige Aufgaben behalten ihre Zuordnung und ihren
Status. Ein wiederholter Abruf erzeugt keine Doppelungen. Neue Aufgaben werden
nur für die aktuelle und die beiden folgenden Kalenderwochen vorbereitet.
Die Synchronisierung erfolgt über `aufgaben_api.php` und benötigt keinen Cronjob.

Die Berechnung lässt sich ohne Datenbank prüfen:

```bash
php tests/reinigungsplan_test.php
php tests/reinigungsplan_aufgaben_test.php
```

## GitHub CI/CD

Die Datei `.github/workflows/test-und-upload.yml` zeigt einen einfachen Ablauf:

- Repository auschecken
- Docker-Image bauen
- PHP-Syntax im Container prüfen
- Reinigungsrhythmus mit den CLI-Tests im Container prüfen
- Zeitparser und Haltestellenfilter für Abfahrten im Container prüfen
- Projektdateien inklusive DB-Schema und Migrationen als Artifact `hausordnung-kiosk` hochladen
- bei Push auf `main` nur die Webdateien per FTP nach `htdocs` hochladen

Für GitHub Actions müssen diese Repository-Secrets angelegt werden:

- `FTP_SERVER`
- `FTP_PORT`
- `FTP_USER`
- `FTP_PASS`

`docker/.env.example` zeigt die benötigten Namen. Die echte `.env` bleibt lokal
auf dem Docker-Host und wird nicht mit Git hochgeladen.

Die bisherige Datei `.vscode/sftp.json` ist lokal und wird ignoriert. Als Muster
liegt `.vscode/sftp.example.json` ohne echtes Passwort bei. Zugangsdaten gehören
in GitHub Secrets oder in eine lokale, nicht versionierte Konfiguration.

Hinweis: `192.168.112.30` ist eine private LAN-Adresse. Deshalb läuft der
Upload-Job im Workflow auf `runs-on: self-hosted`. Dieser Runner muss im gleichen
Netz wie der Docker-/FTP-Host laufen. Der Test-Job kann weiterhin auf
`ubuntu-latest` bei GitHub laufen.

Beim FTP-Server entspricht das Login-Verzeichnis dem Webverzeichnis
`/home/ftpuser`. GitHub lädt relativ dazu nach `./` hoch. GitLab verwendet
`FTP_SERVER_DIR=.`.
Die Container-Wurzel `/` ist kein Upload-Ziel. In der lokalen
`.vscode/sftp.json` und ihrer Beispielkonfiguration steht als absoluter
Zielpfad `/home/ftpuser`. Zugangsdaten bleiben in der ignorierten lokalen Datei.
Der automatische SFTP-Upload lässt Datenbankdateien, Tests und CI-Konfigurationen
aus; das vollständige Webpaket besteht aus den Dateien im Projektstamm mit den
Endungen PHP, JS, CSS und ICS sowie `.htaccess`.

## GitLab CI/CD

Das zweite Remote `origin` verweist auf
`https://gitlab.stoykow.de/webseiten/webseite-kiosk-hausordnung.git`. Die Pipeline in
`.gitlab-ci.yml` prüft PHP, JavaScript und die Reinigungsplan- und Abfahrtstests.
Anschließend erstellt sie ein Webpaket und ein getrenntes Datenbankpaket.
Nur das Webpaket wird auf `main` per FTP hochgeladen. Die Datenbankmigrationen
werden als Artifact bereitgestellt und nicht automatisch auf Produktionsdaten
angewendet.

Der FTP-Deploy verwendet `lftp`, temporäre Dateien und einen zuletzt geschriebenen
Versionsmarker. Das `resource_group`-Lock führt GitLab-Deploys nacheinander aus.
Nach dem Upload prüft die Pipeline die Commit-Version, die Startseite, die
statischen Dateien, die APIs und den Abfahrtenimport. Die erfolgreiche Antwort
des neuen Importers kann auch einen bereits aktuellen Cache melden.

Unter **Settings → CI/CD → Variables** werden diese Werte benötigt:

| Variable | Wert | Geheim |
| --- | --- | --- |
| `HOST` | `192.168.112.30`, ohne Protokoll oder Port | Nein |
| `FTP_PORT` | `28832` | Nein |
| `FTP_USER` | Bestehender FTP-Benutzer, zum Beispiel `ftpuser` | Nein |
| `FTP_PASS` | Das vorhandene FTP-Passwort | Ja |
| `FTP_SERVER_DIR` | `.` | Nein |

Die Variablen erhalten den Environment scope `production` oder `*` und werden
als **Protected** markiert. `main` muss dafür geschützt sein. Für `FTP_PASS`
zusätzlich **Masked and hidden** wählen und **Expand variable reference**
deaktivieren. Die öffentlichen Verbindungswerte haben Defaults in der Pipeline.
`FTP_SERVER` kann den Host und `APP_URL` die Adresse für die Liveprüfung
überschreiben. Die Datenbankpasswörter und API-Schlüssel bleiben in der lokalen
`docker/.env` auf dem Docker-Host.

Alle GitLab-Jobs verwenden den Runner-Tag `gitlab` und den Docker executor.
Der Runner muss GitLab, die Container-Registry sowie FTP und HTTP im lokalen
Netz erreichen. Für passives FTP müssen auch die Ports 28833 bis 28842 erreichbar
sein. Jobs benötigen keinen privilegierten Modus oder Docker-Socket.
Ein Runner, der Tests für Feature-Branches und Merge Requests ausführt, muss
auch ungeschützte Branches zulassen. Die Deploy-Variablen bleiben geschützt.
Ein manueller Pipeline-Start auf `main` führt ebenfalls den Deploy aus.

Der zusätzliche Compose-Dienst `abfahrten-import` ruft den gemeinsamen Import
alle 60 Sekunden auf. Das gleiche Datenbank-Lock und Minutenlimit verhindern
doppelte Abrufe durch diesen Dienst und die bedarfsgesteuerte Kiosk-Anzeige.

## GitHub-Projekt anlegen

Auf diesem Rechner ist die GitHub CLI (`gh`) aktuell nicht installiert. Direktes
Anlegen aus der Konsole ist deshalb hier nicht möglich. Vorgehen über GitHub:

1. Neues Repository auf GitHub anlegen, z. B. `hausordnung-kiosk`.
2. Dieses Projekt als neues Remote hinzufügen:

```bash
git remote add github https://github.com/DEIN-NAME/hausordnung-kiosk.git
git push -u github main
```

3. In GitHub unter `Settings` -> `Secrets and variables` -> `Actions` die
Secrets `FTP_SERVER`, `FTP_PORT`, `FTP_USER`, `FTP_PASS` und `FTP_SERVER_DIR`
anlegen.

Wenn `gh` später installiert und angemeldet ist:

```bash
gh repo create DEIN-NAME/hausordnung-kiosk --private --source . --remote github --push
```

## Datenbank - Tabellen (wichtigste)
Bestehende Kern-Tabellen:

- `benutzer`, `benutzer_gruppen`
- `aufgaben`, `aufgaben_erledigt`
- `reinigungsplaene` (Startdatum, optionales Enddatum, Intervall, Einheit und Aktivstatus)
- `reinigungsplan_gruppen` (Bewohnergruppen je Plan mit ihrer Reihenfolge)
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
- Reinigungsaufgaben erscheinen ab Montag unter Aufgaben und sind freitags fällig.

## Device-Registrierung
- `device_register.php` nimmt `device_name`, erzeugt `device_id`, speichert in `geraete_registrierungen`.
- `benutzer_id` wird manuell in der DB gesetzt.

## ToDo / Weiter
- Optional: `device_status.php` + Freischalt-Workflow
- Optional: Admin-Abschnitt für Geräte-Freigabe
- Optional: Admin-UI für sonstige Termine erweitern (Filter, Suche)
