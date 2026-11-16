<?php
header('Content-Type: text/html; charset=utf-8');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; base-uri 'none'; form-action 'none'");
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Datenschutz | Hausordnung</title>
    <link rel="stylesheet" href="rechtliches.css">
</head>
<body>
<main>
    <nav aria-label="Seitennavigation"><a href="index.php">Zur Hausanzeige</a><a href="impressum.php">Impressum</a></nav>
    <h1>Datenschutzerklärung</h1>
    <p>Diese Hinweise gelten für die Hausanzeige auf hausordnung.nik0.de, einschließlich des geschützten Bereichs und der zugehörigen Schnittstellen.</p>

    <h2>1. Verantwortlicher</h2>
    <address>
        Nikolay Stoykow<br>
        Lutherstraße 29<br>
        02826 Görlitz<br>
        Deutschland
    </address>
    <p>Telefon: <a href="tel:+493581877779">+49 3581 877779</a><br>
    E-Mail: <a href="mailto:kontakt2026@stoykow.de">kontakt2026@stoykow.de</a></p>

    <h2>2. Zweck und öffentlicher Bereich</h2>
    <p>Die Website wird privat und derzeit nicht kommerziell betrieben. Sie dient der gemeinsamen Hausorganisation. Ohne Zugangscode werden Umweltwerte, allgemeine Termine und Abfahrten angezeigt. Aufgabenverteilung, Bewohnernamen, Erledigt- und Anwesenheitsmarkierungen sowie der Haustürstatus werden nur im geschützten Bereich bereitgestellt. Eine öffentliche Registrierung gibt es nicht.</p>

    <h2>3. Eigener Server und technische Zugriffsdaten</h2>
    <p>Ich betreibe die Anwendung auf eigener Serverhardware an meinem Wohnsitz in Deutschland. Die Website und ihre Datenbank laufen in Docker-Containern hinter einem selbst betriebenen HAProxy. Für das Hosting der Anwendung ist kein externer Webhosting-Anbieter beauftragt.</p>
    <p>Beim Aufruf werden technisch erforderliche Verbindungs- und Anfragedaten verarbeitet, insbesondere IP-Adresse, Zeitpunkt, angeforderte URL, Antwortstatus und übertragene Datenmenge. Hinzu kommen vom Browser übermittelte Angaben, etwa Browserkennung und gegebenenfalls die vorher besuchte Seite. Diese Verarbeitung dient der Auslieferung der Inhalte sowie dem sicheren und zuverlässigen Betrieb.</p>
    <p>HAProxy führt lokale HTTP-Zugriffsprotokolle. Sie enthalten insbesondere die IP-Adresse und den Port der Verbindung, Zeitpunkt, HTTP-Anfrage, Antwortstatus, Datenmenge und technische Angaben zur Bearbeitung. Die protokollierte URL kann auch Queryparameter enthalten, insbesondere den Zugangscode eines erstmalig aufgerufenen Token-Links.</p>
    <p>Die HAProxy-Protokolle werden lokal in <code>/var/log/haproxy.log</code> geschrieben. Eingestellt sind eine tägliche Rotation und sieben Archivdateien. Die jeweils ältesten Archive werden bei weiteren Rotationen gelöscht. Da leere Dateien nicht rotiert werden, ergibt sich daraus keine feste Höchstspeicherdauer von sieben Kalendertagen.</p>
    <p>Auch Apache protokolliert Zugriffe und technische Fehler. Die Zugriffsdaten umfassen insbesondere die anfragende Adresse, Zeitpunkt, HTTP-Anfrage einschließlich URL-Parametern, Antwortstatus, Datenmenge sowie gegebenenfalls Referer und User-Agent. Als anfragende Adresse kann die Adresse des vorgeschalteten Proxys erscheinen. Fehlerprotokolle können ebenfalls Verbindungs- und Anfragedaten enthalten.</p>
    <p>Apache gibt die Protokolle an die Standardausgabe und Fehlerausgabe des Containers weiter. Docker speichert diese lokal mit dem Logtreiber <code>json-file</code>. Für den Anwendungscontainer sind eine Rotation bei 50 MiB und höchstens fünf Protokolldateien einschließlich der aktuellen Datei eingestellt. Die ältesten Dateien werden beim Überschreiten dieser Anzahl entfernt. Eine feste zeitliche Löschfrist ist dafür nicht eingestellt; die tatsächliche Dauer hängt vom Protokollaufkommen ab.</p>
    <p>Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO. Mein berechtigtes Interesse liegt in der Bereitstellung der Hausanzeige sowie dem Schutz vor Störungen und Missbrauch.</p>

    <h2>4. Bewohner, Aufgaben und Hausautomation</h2>
    <p>Im geschützten Bereich werden Namen und interne Kennungen von Bewohnern, Gruppenzuordnungen, Reinigungspläne, Aufgaben und Fälligkeitstermine verarbeitet. Bei Erledigt-Markierungen werden die ausgewählten Personen und der Zeitpunkt gespeichert. Bei Abfallterminen betrifft dies auch das Heraus- und Reinstellen der Tonnen. Bei sonstigen Terminen können Anwesenheitszusagen mit den zugeordneten Personen gespeichert werden.</p>
    <p>Über die eigene Home-Assistant-Anbindung werden außerdem Gerätekennungen, Zeitpunkte sowie übermittelte Sensor- und Gerätezustände gespeichert und angezeigt. Dazu gehört im geschützten Bereich der Status der Haustür. Diese Informationen können Rückschlüsse auf Abläufe und Anwesenheit im Haus ermöglichen.</p>
    <p>Zweck ist die Abstimmung der Hausorganisation und die Bereitstellung der gewünschten Statusanzeige. Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO. Das berechtigte Interesse liegt in der gemeinsamen Organisation von Reinigung, Entsorgung und Terminen sowie der Information der berechtigten Bewohner über den Hausstatus.</p>
    <p>Die Auswahl eines Namens bei einer Markierung ist keine persönliche Anmeldung. Die Anwendung kann daher nicht sicher feststellen, welche Person die Markierung tatsächlich vorgenommen hat.</p>
    <p>Die Anwendung löscht diese Datensätze nicht automatisch allein aufgrund eines abgelaufenen Termins. Sie werden für die laufende Organisation und die erforderliche Nachvollziehbarkeit vorgehalten. Entfällt dieser Zweck, sind nicht mehr benötigte personenbezogene Einträge zu löschen oder zu anonymisieren. Eine feste automatische Löschfrist ist derzeit nicht eingerichtet. Berichtigungs- und Löschungsanliegen können an den oben genannten Kontakt gerichtet werden; gesetzliche Pflichten und die Rechte anderer Personen bleiben zu berücksichtigen.</p>

    <h2>5. Zugangscode und Sitzungs-Cookie</h2>
    <p>Für den geschützten Bereich wird ein gemeinsamer Zugangscode verwendet. Wer den vollständigen Zugangslink oder eine gültige Zugangsberechtigung besitzt, kann die geschützten Inhalte abrufen und die dort verfügbaren Verwaltungs- und Markierungsfunktionen nutzen. Geben Sie den Zugang deshalb nur an berechtigte Personen weiter.</p>
    <p>Beim normalen Zugang wird der Code serverseitig geprüft und anschließend aus der URL entfernt. Das Cookie <code>hausordnung_kiosk</code> enthält eine Sitzungskennung. Serverseitig werden der Anmeldestatus, dessen Ablaufzeit, ein Hash zur Zuordnung zum aktuellen Zugangscode und ein Schutzwert gegen ungewollte Änderungsanfragen verarbeitet. Die maximale Laufzeit beträgt 30 Tage. Browser oder Server können die Sitzung früher beenden. Beim Abmelden wird diese Sitzung beendet.</p>
    <p>Das Cookie wird bei HTTPS mit Secure und HttpOnly gesetzt. Für die Einbettung in Home Assistant werden SameSite=None und Partitioned verwendet, soweit der Browser dies unterstützt. Die Speicherung und der Zugriff dienen ausschließlich dem ausdrücklich angeforderten geschützten Zugang (§ 25 Abs. 2 Nr. 2 TDDDG). Die anschließende Verarbeitung erfolgt nach Art. 6 Abs. 1 lit. f DSGVO zur Zugangskontrolle und zum Schutz der Hausdaten.</p>

    <h2>6. Tablet-Zugang ohne Cookies</h2>
    <p>Für Tablets mit blockierten Drittanbieter-Cookies kann der Zugangslink mit <code>FRAME=1</code> verwendet werden. Der Server tauscht den Zugangscode gegen eine signierte Berechtigung mit einer maximalen Laufzeit von zehn Jahren (3650 Tagen). Der Hauptzugangscode wird dabei nicht an JavaScript ausgegeben.</p>
    <p>Die abgeleitete Berechtigung wird im URL-Fragment nach dem Zeichen <code>#</code> gehalten, damit sie bei automatischen Reloads erhalten bleibt. Fragmente werden nicht als Teil der HTTP-Anfrage an den Server übertragen. Für geschützte Schnittstellen sendet die Anwendung die Berechtigung im Authorization-Header an dieselbe Domain. Die Berechtigung enthält einen Ablaufzeitpunkt und eine zufällige Kennung, keinen Bewohnernamen. Sie ist kein Tracking-Kennzeichen und wird nicht für Werbung oder Nutzungsanalysen verwendet.</p>
    <p>Die Kiosk-Anzeige enthält keinen Abmeldebutton. Zum Verlassen des Tablet-Zugangs kann die öffentliche Adresse ohne Zugangscode und ohne URL-Fragment neu geöffnet werden. Bereits kopierte Berechtigungen bleiben bis zu ihrem Ablauf oder einem Wechsel des Hauptzugangscodes gültig. Ein Wechsel des Zugangscodes macht sowohl bestehende Sitzungen als auch abgeleitete Tablet-Berechtigungen ungültig. Für die ausdrücklich gewünschte Speicherung und den Zugriff gilt § 25 Abs. 2 Nr. 2 TDDDG; Rechtsgrundlage für die Zugangskontrolle ist Art. 6 Abs. 1 lit. f DSGVO.</p>
    <p>Der ursprüngliche Link mit dem Zugangscode kann in Server-/Proxy-Protokollen, einer Browserchronik oder einer hinterlegten Dashboard-Konfiguration enthalten sein. Behandeln Sie ihn deshalb wie einen Zugangscode. Die Anwendung setzt eine Referrer-Policy, die die Weitergabe der aufgerufenen URL als Referer unterbindet.</p>

    <h2>7. Wetter, Abfalltermine und Verkehrsdaten</h2>
    <p>Wetterdaten werden serverseitig von OpenWeather, Abfahrten vom VVO-Abfahrtsmonitor und Abfalltermine aus dem Angebot der Abfallentsorgung des Landkreises Görlitz abgerufen. Die Anbieter erhalten dabei die ausgehende IP-Adresse des Servers und die für die jeweilige Abfrage erforderlichen Angaben, beispielsweise den konfigurierten Ort oder die Haltestelle. Besucher-IP-Adressen, Bewohnernamen, Aufgaben und Haustürzustände werden für diese Abfragen nicht mitgesendet. Ihr Browser ruft diese Daten über die eigene Anwendung ab.</p>
    <p>Beim bewussten Öffnen eines externen Links stellt Ihr Browser eine Verbindung zum jeweiligen Anbieter her. Dabei erhält dieser die technisch erforderlichen Verbindungsdaten. Für seine weitere Verarbeitung gelten dessen Datenschutzhinweise.</p>

    <h2>8. Kontaktaufnahme</h2>
    <p>Bei einer Kontaktaufnahme per E-Mail oder Telefon werden die mitgeteilten Kontaktdaten und Inhalte zur Bearbeitung und Beantwortung verarbeitet. Bei E-Mails wirken die an der Übermittlung beteiligten Mailanbieter mit. Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO aufgrund meines berechtigten Interesses an der Bearbeitung der Anfrage. Die Daten werden gelöscht, sobald die Bearbeitung abgeschlossen ist und keine weitere erforderliche Korrespondenz oder gesetzliche Aufbewahrung entgegensteht.</p>

    <h2>9. Empfänger, Analyse und automatisierte Entscheidungen</h2>
    <p>Der Betreiber verarbeitet die Daten zur Verwaltung und zum Betrieb der Anwendung. Berechtigte Personen mit Zugang können die jeweils angezeigten privaten Inhalte sehen. Bei einer Anzeige auf einem gemeinsam genutzten Tablet können auch Personen in dessen Sichtbereich diese Inhalte wahrnehmen. Ein Zugangscode verhindert das Mitlesen am Bildschirm nicht.</p>
    <p>Für das Hosting werden die Anwendungsdaten auf der eigenen Infrastruktur in Deutschland gespeichert. Die vorstehend beschriebenen Verbindungen zu Datenanbietern, externen Links und Mailanbietern bleiben davon unabhängig. Eine Weitergabe an berechtigte Stellen aufgrund gesetzlicher Verpflichtungen erfolgt auf Grundlage von Art. 6 Abs. 1 lit. c DSGVO.</p>
    <p>Die Anwendung bindet keine Analyse-, Werbe- oder Social-Media-Tracking-Tools ein. Es erfolgen keine automatisierten Entscheidungen einschließlich Profiling im Sinne von Art. 22 DSGVO.</p>

    <h2>10. Ihre Rechte</h2>
    <p>Unter den jeweiligen gesetzlichen Voraussetzungen haben Sie das Recht auf Auskunft (Art. 15 DSGVO), Berichtigung (Art. 16 DSGVO), Löschung (Art. 17 DSGVO), Einschränkung der Verarbeitung (Art. 18 DSGVO) und Datenübertragbarkeit (Art. 20 DSGVO). Eine gegebenenfalls erteilte Einwilligung können Sie mit Wirkung für die Zukunft widerrufen, ohne dass die Rechtmäßigkeit der bisherigen Verarbeitung berührt wird (Art. 7 Abs. 3 DSGVO).</p>
    <p><strong>Widerspruchsrecht:</strong> Soweit eine Verarbeitung auf Art. 6 Abs. 1 lit. f DSGVO beruht, können Sie aus Gründen Ihrer besonderen Situation nach Art. 21 DSGVO widersprechen. Zur Ausübung Ihrer Rechte genügt eine Nachricht an <a href="mailto:kontakt2026@stoykow.de">kontakt2026@stoykow.de</a>.</p>
    <p>Sie haben außerdem das Recht, sich nach Art. 77 DSGVO bei einer Datenschutzaufsichtsbehörde zu beschweren, insbesondere am Ort Ihres gewöhnlichen Aufenthalts, Ihres Arbeitsplatzes oder des vermuteten Verstoßes. Für den Betreiber in Sachsen ist die <a href="https://www.datenschutz.sachsen.de/" rel="noreferrer">Sächsische Datenschutz- und Transparenzbeauftragte</a> zuständig.</p>
    <p class="stand">Stand: 16. November 2026</p>
    <nav aria-label="Weitere Seiten"><a href="index.php">Zur Hausanzeige</a><a href="impressum.php">Impressum</a></nav>
</main>
</body>
</html>
