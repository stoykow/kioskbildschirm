<?php
// Cron endpoint: fetch departures and store in MariaDB

header('Content-Type: text/plain; charset=utf-8');

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: '';
$dbUser = getenv('DB_USER') ?: '';
$dbPass = getenv('DB_PASS') ?: '';

if ($dbName === '' || $dbUser === '') {
    http_response_code(500);
    echo "DB env vars missing (DB_NAME/DB_USER).\n";
    exit;
}

$dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo "DB connect failed: " . $e->getMessage() . "\n";
    exit;
}

$stops = [
    [
        'external_id' => '8010131',
        'name' => 'Görlitz Hbf',
        'url' => 'https://v6.db.transport.rest/stops/8010131/departures?duration=180&results=20',
    ],
    [
        'external_id' => '977263',
        'name' => 'Lutherstraße',
        'url' => 'https://v6.db.transport.rest/stops/977263/departures?duration=60&results=20',
    ],
    [
        'external_id' => '977244',
        'name' => 'Melanchthonstraße',
        'url' => 'https://v6.db.transport.rest/stops/977244/departures?duration=60&results=20',
    ],
];

$pdo->beginTransaction();
try {
    $stopUpsert = $pdo->prepare(
        "INSERT INTO stops (external_id, name)
         VALUES (:external_id, :name)
         ON DUPLICATE KEY UPDATE name = VALUES(name)"
    );

    $lineUpsert = $pdo->prepare(
        "INSERT INTO lines (name, mode, product)
         VALUES (:name, :mode, :product)
         ON DUPLICATE KEY UPDATE mode = VALUES(mode), product = VALUES(product)"
    );

    $departureUpsert = $pdo->prepare(
        "INSERT INTO departures
         (stop_id, line_id, planned_when, when_actual, direction, platform, delay_seconds, cancelled, trip_id)
         VALUES
         (:stop_id, :line_id, :planned_when, :when_actual, :direction, :platform, :delay_seconds, :cancelled, :trip_id)
         ON DUPLICATE KEY UPDATE
           when_actual = VALUES(when_actual),
           delay_seconds = VALUES(delay_seconds),
           cancelled = VALUES(cancelled)"
    );

    foreach ($stops as $stop) {
        $stopUpsert->execute([
            ':external_id' => $stop['external_id'],
            ':name' => $stop['name'],
        ]);

        $stopId = $pdo->query(
            "SELECT id FROM stops WHERE external_id = " . $pdo->quote($stop['external_id']) . " LIMIT 1"
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
                ':mode' => $line['mode'] ?? null,
                ':product' => $line['product'] ?? null,
            ]);

            $lineId = $pdo->query(
                "SELECT id FROM lines WHERE name = " . $pdo->quote($lineName) . " LIMIT 1"
            )->fetchColumn();

            $planned = $dep['plannedWhen'] ?? $dep['when'] ?? null;
            $when = $dep['when'] ?? null;
            if ($planned === null) {
                continue;
            }

            $departureUpsert->execute([
                ':stop_id' => $stopId,
                ':line_id' => $lineId,
                ':planned_when' => $planned,
                ':when_actual' => $when,
                ':direction' => $dep['direction'] ?? null,
                ':platform' => $dep['platform'] ?? null,
                ':delay_seconds' => $dep['delay'] ?? null,
                ':cancelled' => !empty($dep['cancelled']) ? 1 : 0,
                ':trip_id' => $dep['tripId'] ?? null,
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
