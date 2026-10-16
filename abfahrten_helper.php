<?php
// Gemeinsamer Import für Cron und die bedarfsgesteuerte Aktualisierung im Kiosk.

function abfahrten_stops(): array {
    global $cityBusStopExternalId, $cityBusSourceStopIds, $cityBusLines;
    return [
        [
            'external_id' => '8010131', 'vvo_id' => '14908885',
            'name' => 'Görlitz Hbf', 'kind' => 'train',
            'mot' => ['Train', 'SuburbanRailway', 'IntercityBus'],
        ],
        [
            'external_id' => '977263', 'vvo_id' => '14908970',
            'name' => 'Lutherstraße', 'kind' => 'tram', 'mot' => ['Tram'],
        ],
        [
            'external_id' => $cityBusStopExternalId ?? '977198', 'vvo_id' => '14908886',
            'name' => 'Bahnhof Südausgang, Görlitz', 'kind' => 'bus',
            'mot' => ['CityBus', 'IntercityBus', 'ClockBus'],
            'lines' => $cityBusLines ?? ['A', 'B', 'C', 'D', 'E', 'F', 'N', '62', '67'],
            'source_stop_ids' => $cityBusSourceStopIds ?? ['977198', '743766'],
        ],
    ];
}

function abfahrten_parse_time(?string $value): ?DateTimeImmutable {
    if ($value === null || trim($value) === '') {
        return null;
    }
    $timezone = new DateTimeZone('Europe/Berlin');
    // Microsoft JSON enthält einen absoluten Unix-Zeitpunkt in Millisekunden.
    // Der angehängte Offset darf nicht ein zweites Mal addiert werden.
    if (preg_match('~^/Date\((-?[0-9]+)(?:[+-][0-9]{4})?\)/$~D', $value, $matches)) {
        $seconds = (int)floor((int)$matches[1] / 1000);
        return (new DateTimeImmutable('@' . $seconds))->setTimezone($timezone);
    }
    if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}[T ][0-9]{2}:[0-9]{2}(?::[0-9]{2}(?:\.[0-9]+)?)?(?:Z|[+-][0-9]{2}:?[0-9]{2})?$/D', $value)) {
        throw new UnexpectedValueException('Die Datenquelle lieferte eine ungültige Abfahrtszeit.');
    }
    try {
        $date = new DateTimeImmutable($value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) {
            throw new UnexpectedValueException('Die Datenquelle lieferte eine ungültige Abfahrtszeit.');
        }
        return $date->setTimezone($timezone);
    } catch (Exception $error) {
        throw new UnexpectedValueException('Die Datenquelle lieferte eine ungültige Abfahrtszeit.', 0, $error);
    }
}

function abfahrten_normalize_vvo_departure(array $entry, string $sourceStopId): array {
    $planned = abfahrten_parse_time($entry['ScheduledTime'] ?? null);
    $actual = abfahrten_parse_time($entry['RealTime'] ?? null) ?? $planned;
    $line = trim((string)($entry['LineName'] ?? ''));
    if (!$planned || $line === '') {
        throw new UnexpectedValueException('Die Datenquelle lieferte eine Abfahrt ohne Linie oder Planzeit.');
    }
    $mot = (string)($entry['Mot'] ?? '');
    if ($mot === 'Tram') {
        $product = 'tram';
    } elseif (in_array($mot, ['Train', 'SuburbanRailway'], true)) {
        $product = 'train';
    } elseif (in_array($mot, ['CityBus', 'IntercityBus', 'ClockBus', 'RegionalBus', 'Bus', 'HailedSharedTaxi'], true)) {
        $product = 'bus';
    } else {
        // Andere Verkehrsmittel werden durch den Haltestellenfilter verworfen.
        $product = 'other';
    }
    $cancelled = in_array($entry['State'] ?? '', ['Canceled', 'Cancelled'], true)
        || !empty($entry['CancelReasons']);
    return [
        'source_stop_id' => $sourceStopId,
        'line_name' => $line,
        'mode' => $product,
        'product' => $product,
        'planned_when' => $planned->format('Y-m-d H:i:s'),
        'when' => $actual->format('Y-m-d H:i:s'),
        'direction' => (string)($entry['Direction'] ?? ''),
        'platform' => isset($entry['Platform']['Name']) ? (string)$entry['Platform']['Name'] : null,
        'delay_seconds' => $actual->getTimestamp() - $planned->getTimestamp(),
        'cancelled' => $cancelled,
        // VVO-Ids bezeichnen den Linienlauf. Planzeit und Haltestelle ergänzen
        // die Fahrtkennung, damit mehrere Abfahrten derselben Linie stabil sind.
        'journey_id' => (string)($entry['Id'] ?? '') . '|' . $sourceStopId . '|' . $planned->getTimestamp(),
    ];
}

function abfahrten_departure_matches_stop(array $departure, array $stop): bool {
    if (isset($stop['lines']) && !in_array($departure['line_name'], $stop['lines'], true)) {
        return false;
    }
    if (isset($stop['source_stop_ids']) && !in_array($departure['source_stop_id'], $stop['source_stop_ids'], true)) {
        return false;
    }
    $product = $departure['product'];
    switch ($stop['kind'] ?? '') {
        case 'train':
            return $product === 'train' || ($product === 'bus' && preg_match('/^(?:SEV|EV)(?:$|[\s0-9])|Ersatzverkehr/i', $departure['line_name']));
        case 'tram':
            return $product === 'tram';
        case 'bus':
            return $product === 'bus';
    }
    return true;
}

function abfahrten_decode_vvo(string $json, string $sourceStopId): array {
    try {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new UnexpectedValueException('Die Datenquelle lieferte kein gültiges JSON.', 0, $error);
    }
    if (!is_array($data) || ($data['Status']['Code'] ?? null) !== 'Ok'
        || !isset($data['Departures']) || !is_array($data['Departures'])) {
        throw new UnexpectedValueException('Die Datenquelle lieferte keine gültige Abfahrtstafel.');
    }
    $departures = [];
    foreach ($data['Departures'] as $entry) {
        if (!is_array($entry)) {
            throw new UnexpectedValueException('Die Datenquelle lieferte eine ungültige Abfahrt.');
        }
        $departures[] = abfahrten_normalize_vvo_departure($entry, $sourceStopId);
    }
    return $departures;
}

function abfahrten_fetch_vvo(array $stop): array {
    $body = json_encode([
        'stopid' => $stop['vvo_id'], 'limit' => 100, 'mot' => $stop['mot'],
        'shorttermchanges' => true, 'format' => 'json',
    ], JSON_THROW_ON_ERROR);
    $url = 'https://webapi.vvo-online.de/dm';
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_USERAGENT => 'hausordnung-kiosk/1.1',
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json; charset=utf-8'],
        ]);
        $json = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($json === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Die Abfahrtstafel ist derzeit nicht erreichbar (HTTP ' . $status . ').');
        }
    } else {
        $context = stream_context_create(['http' => [
            'method' => 'POST', 'timeout' => 8, 'ignore_errors' => true,
            'header' => "Accept: application/json\r\nContent-Type: application/json; charset=utf-8\r\nUser-Agent: hausordnung-kiosk/1.1\r\n",
            'content' => $body,
        ]]);
        $json = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+([0-9]{3})/', $header, $matches)) {
                $status = (int)$matches[1];
            }
        }
        if ($json === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Die Abfahrtstafel ist derzeit nicht erreichbar (HTTP ' . $status . ').');
        }
    }
    return abfahrten_decode_vvo($json, $stop['external_id']);
}

function abfahrten_config_set(PDO $pdo, string $key, string $value): void {
    $statement = $pdo->prepare(
        'INSERT INTO app_config (config_key, config_value) VALUES (:key, :value)
         ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
    );
    $statement->execute([':key' => $key, ':value' => $value]);
}

/** Eine erfolgreich geladene Haltestellentafel atomar ersetzen. */
function abfahrten_store_stop(PDO $pdo, array $stop, array $departures, DateTimeImmutable $now): int {
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            'INSERT INTO abfahrten_haltestellen (externe_id, name) VALUES (:external, :name)
             ON DUPLICATE KEY UPDATE name = VALUES(name)'
        );
        $statement->execute([':external' => $stop['external_id'], ':name' => $stop['name']]);
        $statement = $pdo->prepare('SELECT id FROM abfahrten_haltestellen WHERE externe_id = :external');
        $statement->execute([':external' => $stop['external_id']]);
        $stopId = (int)$statement->fetchColumn();
        // Vergangene Abfahrten bleiben erhalten. Zukünftige Einträge bilden
        // genau die neue Tafel ab, auch bei geänderten Gleisen oder Ausfällen.
        $statement = $pdo->prepare(
            'DELETE FROM abfahrten WHERE haltestelle_id = :stop
             AND COALESCE(tatsaechliche_zeit, geplante_zeit) >= :now'
        );
        $statement->execute([':stop' => $stopId, ':now' => $now->format('Y-m-d H:i:s')]);
        $lineUpsert = $pdo->prepare(
            'INSERT INTO abfahrten_linien (name, modus, produkt) VALUES (:name, :mode, :product)
             ON DUPLICATE KEY UPDATE modus = VALUES(modus), produkt = VALUES(produkt)'
        );
        $lineSelect = $pdo->prepare('SELECT id FROM abfahrten_linien WHERE name = :name');
        $insert = $pdo->prepare(
            'INSERT INTO abfahrten
             (haltestelle_id, linie_id, geplante_zeit, tatsaechliche_zeit, richtung, gleis, verzoegerung_sekunden, ausfall, fahrt_id)
             VALUES (:stop, :line, :planned, :actual, :direction, :platform, :delay, :cancelled, :journey)
             ON DUPLICATE KEY UPDATE tatsaechliche_zeit = VALUES(tatsaechliche_zeit),
             verzoegerung_sekunden = VALUES(verzoegerung_sekunden), ausfall = VALUES(ausfall), fahrt_id = VALUES(fahrt_id)'
        );
        $count = 0;
        $seen = [];
        foreach ($departures as $departure) {
            if (!abfahrten_departure_matches_stop($departure, $stop) || $departure['when'] < $now->format('Y-m-d H:i:s')) {
                continue;
            }
            $key = json_encode([$departure['line_name'], $departure['planned_when'], $departure['direction'], $departure['platform']]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $lineUpsert->execute([':name' => $departure['line_name'], ':mode' => $departure['mode'], ':product' => $departure['product']]);
            $lineSelect->execute([':name' => $departure['line_name']]);
            $insert->execute([
                ':stop' => $stopId, ':line' => (int)$lineSelect->fetchColumn(),
                ':planned' => $departure['planned_when'], ':actual' => $departure['when'],
                ':direction' => $departure['direction'], ':platform' => $departure['platform'],
                ':delay' => $departure['delay_seconds'], ':cancelled' => $departure['cancelled'] ? 1 : 0,
                ':journey' => $departure['journey_id'],
            ]);
            $count++;
        }
        $pdo->commit();
        return $count;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

/** Höchstens ein automatischer Abrufversuch je Minute, auch bei Quellfehlern. */
function abfahrten_refresh(PDO $pdo, bool $force = false, ?callable $fetch = null, ?DateTimeImmutable $now = null): array {
    $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->setTimezone(new DateTimeZone('Europe/Berlin'));
    $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $lockName = 'hausordnung_abfahrten_' . substr(hash('sha256', $database), 0, 32);
    $lock = $pdo->prepare('SELECT GET_LOCK(:name, 0)');
    $lock->execute([':name' => $lockName]);
    if ((int)$lock->fetchColumn() !== 1) {
        return ['status' => 'busy', 'imported' => 0, 'errors' => []];
    }
    try {
        $settings = $pdo->query(
            "SELECT config_key, config_value FROM app_config WHERE config_key IN ('abfahrten_last_attempt', 'abfahrten_last_status', 'abfahrten_last_errors')"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $lastAttempt = (int)($settings['abfahrten_last_attempt'] ?? 0);
        if (!$force && $lastAttempt <= $now->getTimestamp() && $now->getTimestamp() - $lastAttempt < 60) {
            $lastStatus = $settings['abfahrten_last_status'] ?? 'failed';
            $lastErrors = json_decode($settings['abfahrten_last_errors'] ?? '[]', true);
            return [
                'status' => in_array($lastStatus, ['failed', 'partial'], true) ? $lastStatus : 'cached',
                'imported' => 0, 'errors' => is_array($lastErrors) ? $lastErrors : [],
            ];
        }
        abfahrten_config_set($pdo, 'abfahrten_last_attempt', (string)$now->getTimestamp());
        abfahrten_config_set($pdo, 'abfahrten_last_status', 'failed');
        $fetch = $fetch ?? 'abfahrten_fetch_vvo';
        $errors = [];
        $count = 0;
        $successes = 0;
        foreach (abfahrten_stops() as $stop) {
            try {
                $departures = $fetch($stop);
                $count += abfahrten_store_stop($pdo, $stop, $departures, $now);
                $successes++;
            } catch (Throwable $error) {
                $errors[] = $stop['external_id'];
                error_log('Abfahrtenimport für ' . $stop['external_id'] . ' fehlgeschlagen: ' . $error->getMessage());
            }
        }
        $status = !$errors ? 'updated' : ($successes > 0 ? 'partial' : 'failed');
        abfahrten_config_set($pdo, 'abfahrten_last_errors', json_encode($errors, JSON_THROW_ON_ERROR));
        abfahrten_config_set($pdo, 'abfahrten_last_status', $status);
        if (!$errors) {
            abfahrten_config_set($pdo, 'abfahrten_last_success', (string)$now->getTimestamp());
        }
        return ['status' => $status, 'imported' => $count, 'errors' => $errors];
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
        $release->execute([':name' => $lockName]);
    }
}
