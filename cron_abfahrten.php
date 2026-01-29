<?php
// Cron endpoint: fetch departures and store in MariaDB

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
        'url' => 'https://v6.db.transport.rest/stops/8010131/departures',
    ],
    [
        'external_id' => '977263',
        'name' => 'Lutherstraße',
        'url' => 'https://v6.db.transport.rest/stops/977263/departures',
    ],
    [
        'external_id' => '977244',
        'name' => 'Melanchthonstraße',
        'url' => 'https://v6.db.transport.rest/stops/977244/departures',
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

        $json = @file_get_contents($stop['url']);
        if ($json === false) {
            echo "Fetch failed for stop {$stop['external_id']}\n";
            continue;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            echo "Invalid JSON for stop {$stop['external_id']}\n";
            continue;
        }

        $departures = isset($data['departures']) && is_array($data['departures']) ? $data['departures'] : $data;

        foreach ($departures as $dep) {
            $line = $dep['line'] ?? [];
            $lineName = $line['name'] ?? '';
            if ($lineName === '') {
                continue;
            }

            $lineUpsert->execute([
                ':name' => $lineName,
                ':modus' => $line['mode'] ?? null,
                ':produkt' => $line['product'] ?? null,
            ]);

            $lineId = $pdo->query(
                "SELECT id FROM abfahrten_linien WHERE name = " . $pdo->quote($lineName) . " LIMIT 1"
            )->fetchColumn();

            $plannedRaw = $dep['plannedWhen'] ?? $dep['when'] ?? null;
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
                ':verzoegerung_sekunden' => $dep['delay'] ?? null,
                ':ausfall' => !empty($dep['cancelled']) ? 1 : 0,
                ':fahrt_id' => $dep['tripId'] ?? null,
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
