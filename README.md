# Hausordnung (Kiosk)

Kurzuebersicht fuer den aktuellen Stand, damit man direkt weiss, wo es weitergeht.

## Was das System macht
- Kiosk-UI fuer Umwelt/Status, Abfall, Aufgaben, OePNV-Abfahrten
- Sonstige Termine (z.B. Schornsteinfeger) mit "Zuhause erforderlich"
- Wetter-Import (OpenWeather) + Forecast-Speicherung
- Aufgaben/Abfall mit Erledigt-Markierung
- Admin-Seite zum Bearbeiten von Einstellungen + Sonstigen Terminen
- Geraete-Registrierung (device_register.php)

## Wichtige Dateien
- `index.php` Kiosk-Startseite
- `kiosk.js` Initialisierung/Intervalle
- `kiosk.css` Styles
- `wetter.js` Umwelt/Status (kompakt, Haustuer in eigener Zeile)
- `abfall.js` Abfall + Aufgaben (Abfall nur anzeigen, wenn vorhanden)
- `aufgaben.js` Aufgaben
- `abfahrten.js` OePNV-Anzeige
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

## Konfiguration
Secrets nur per ENV:
- `OPENWEATHER_API`
- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`

Alles andere per `app_config` (DB):
- `openweather_lat`, `openweather_lon`, `openweather_lang`, `openweather_units`
- `termine_sonstige_days`, `termine_abfall_days`
- `snow_task_lead_hours`, `snow_task_min_mm`, `snow_task_evening_hour`

Admin-Seite: `admin.php` (ohne Login)
- Eintraege in `app_config` anlegen/aktualisieren
- Sonstige Termine anlegen/bearbeiten/loeschen
- Loeschen raeumt `termine_sonstige_zuhause` auf

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
- Haustuer-Status farbig: aufgeschlossen rot, zugeschlossen gruen.

## Device-Registrierung
- `device_register.php` nimmt `device_name`, erzeugt `device_id`, speichert in `geraete_registrierungen`.
- `benutzer_id` wird manuell in der DB gesetzt.

## ToDo / Weiter
- Optional: `device_status.php` + Freischalt-Workflow
- Optional: Admin-Abschnitt fuer Geraete-Freigabe
- Optional: Admin-UI fuer sonstige Termine erweitern (Filter, Suche)
