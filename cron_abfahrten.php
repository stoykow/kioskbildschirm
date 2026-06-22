<?php
// Cron endpoint: Abfahrten abrufen und in MariaDB speichern

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/config.php';

try {
    $pdo = db_connect();
} catch (RuntimeException $e) {
    http_response_code(500);
    echo $e->getMessage() . "\n";
    exit;
} catch (PDOException $e) {
    http_response_code(500);
    echo "DB connect failed: " . $e->getMessage() . "\n";
    exit;
}

$stops = [
    [
        'external_id' => '8010131',
        'name' => 'Görlitz Hbf',
        'transports' => ['ICE', 'EC_IC', 'IR', 'REGIONAL'],
    ],
    [
        'external_id' => '977263',
        'name' => 'Lutherstraße',
        'transports' => ['BUS', 'TRAM', 'REGIONAL'],
    ],
];

$pdo->beginTransaction();
try {
    $stopUpsert = $pdo->prepare(
        "INSERT INTO abfahrten_haltestellen (externe_id, name)
         VALUES (:externe_id, :name)
         ON DUPLICATE KEY UPDATE name = VALUES(name)"
    );

    $lineUpsert = $pdo->prepare(
        "INSERT INTO abfahrten_linien (name, modus, produkt)
         VALUES (:name, :modus, :produkt)
         ON DUPLICATE KEY UPDATE modus = VALUES(modus), produkt = VALUES(produkt)"
    );

    $departureUpsert = $pdo->prepare(
        "INSERT INTO abfahrten
         (haltestelle_id, linie_id, geplante_zeit, tatsaechliche_zeit, richtung, gleis, verzoegerung_sekunden, ausfall, fahrt_id)
         VALUES
         (:haltestelle_id, :linie_id, :geplante_zeit, :tatsaechliche_zeit, :richtung, :gleis, :verzoegerung_sekunden, :ausfall, :fahrt_id)
         ON DUPLICATE KEY UPDATE
           tatsaechliche_zeit = VALUES(tatsaechliche_zeit),
           verzoegerung_sekunden = VALUES(verzoegerung_sekunden),
           ausfall = VALUES(ausfall)"
    );

    foreach ($stops as $stop) {
        $stopUpsert->execute([
            ':externe_id' => $stop['external_id'],
            ':name' => $stop['name'],
        ]);

        $stopId = $pdo->query(
            "SELECT id FROM abfahrten_haltestellen WHERE externe_id = " . $pdo->quote($stop['external_id']) . " LIMIT 1"
        )->fetchColumn();

        $departures = fetch_departures($stop['external_id'], $stop['transports']);
        if ($departures === null) {
            echo "Abruf fehlgeschlagen für Haltestelle {$stop['external_id']}\n";
            continue;
        }

        foreach ($departures as $dep) {
            $lineName = $dep['line_name'] ?? '';
            if ($lineName === '') {
                continue;
            }

            $lineUpsert->execute([
                ':name' => $lineName,
                ':modus' => $dep['mode'] ?? null,
                ':produkt' => $dep['product'] ?? null,
            ]);

            $lineId = $pdo->query(
                "SELECT id FROM abfahrten_linien WHERE name = " . $pdo->quote($lineName) . " LIMIT 1"
            )->fetchColumn();

            $plannedRaw = $dep['planned_when'] ?? $dep['when'] ?? null;
            $whenRaw = $dep['when'] ?? null;
            if ($plannedRaw === null) {
                continue;
            }

            $plannedDt = new DateTimeImmutable($plannedRaw);
            $planned = $plannedDt->format('Y-m-d H:i:s');
            $when = null;
            if ($whenRaw !== null) {
                $whenDt = new DateTimeImmutable($whenRaw);
                $when = $whenDt->format('Y-m-d H:i:s');
            }

            $departureUpsert->execute([
                ':haltestelle_id' => $stopId,
                ':linie_id' => $lineId,
                ':geplante_zeit' => $planned,
                ':tatsaechliche_zeit' => $when,
                ':richtung' => $dep['direction'] ?? null,
                ':gleis' => $dep['platform'] ?? null,
                ':verzoegerung_sekunden' => $dep['delay_seconds'] ?? null,
                ':ausfall' => !empty($dep['cancelled']) ? 1 : 0,
                ':fahrt_id' => $dep['journey_id'] ?? null,
            ]);
        }
    }

    $pdo->commit();
    echo "OK\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo "Error: " . $e->getMessage() . "\n";
}

function fetch_departures(string $stopExternalId, array $transports): ?array {
    $params = [
        'datum' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d'),
        'zeit' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('H:i:s'),
        'ortExtId' => $stopExternalId,
    ];

    $queryParts = [];
    foreach ($params as $key => $value) {
        $queryParts[] = rawurlencode($key) . '=' . rawurlencode($value);
    }
    foreach ($transports as $transport) {
        $queryParts[] = 'verkehrsmittel[]=' . rawurlencode($transport);
    }

    $url = 'https://int.bahn.de/web/api/reiseloesung/abfahrten?' . implode('&', $queryParts);
    $json = fetch_url($url);
    if ($json === null) {
        return null;
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        return null;
    }

    $entries = isset($data['entries']) && is_array($data['entries']) ? $data['entries'] : [];
    $departures = [];
    foreach ($entries as $entry) {
        $departures[] = normalize_bahn_departure($entry);
    }

    return $departures;
}

function fetch_url(string $url): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'hausordnung-kiosk/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body !== false && $status >= 200 && $status < 300) {
            return $body;
        }
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 20,
            'header' => "Accept: application/json\r\nUser-Agent: hausordnung-kiosk/1.0\r\n",
        ],
    ]);
    $body = @file_get_contents($url, false, $context);

    return $body === false ? null : $body;
}

function normalize_bahn_departure(array $entry): array {
    $transport = $entry['verkehrmittel'] ?? [];
    $lineName = $transport['linienNummer']
        ?? $transport['mittelText']
        ?? $transport['name']
        ?? '';
    $product = normalize_product($transport);
    $plannedWhen = $entry['zeit'] ?? null;
    $when = $entry['ezZeit'] ?? $plannedWhen;

    return [
        'line_name' => $lineName,
        'mode' => $product === 'tram' ? 'tram' : ($product === 'bus' ? 'bus' : 'train'),
        'product' => $product,
        'planned_when' => $plannedWhen,
        'when' => $when,
        'direction' => $entry['terminus'] ?? null,
        'platform' => $entry['gleis'] ?? null,
        'delay_seconds' => calculate_delay_seconds($plannedWhen, $when),
        'cancelled' => is_cancelled($entry['meldungen'] ?? []),
        'journey_id' => $entry['journeyId'] ?? null,
    ];
}

function normalize_product(array $transport): string {
    $category = strtoupper((string)($transport['produktGattung'] ?? ''));
    $shortText = strtoupper((string)($transport['kurzText'] ?? ''));
    $name = strtoupper((string)($transport['name'] ?? ''));

    if ($category === 'TRAM' || $shortText === 'STR') {
        return 'tram';
    }
    if ($category === 'BUS' || $category === 'ERSATZVERKEHR' || $shortText === 'BUS' || str_starts_with($name, 'BUS ')) {
        return 'bus';
    }

    return strtolower($category ?: 'train');
}

function calculate_delay_seconds(?string $plannedWhen, ?string $when): ?int {
    if ($plannedWhen === null || $when === null) {
        return null;
    }

    return (new DateTimeImmutable($when))->getTimestamp() - (new DateTimeImmutable($plannedWhen))->getTimestamp();
}

function is_cancelled(array $messages): bool {
    foreach ($messages as $message) {
        $text = strtolower((string)($message['text'] ?? ''));
        if (str_contains($text, 'ausfall') || str_contains($text, 'entfällt')) {
            return true;
        }
    }

    return false;
}
