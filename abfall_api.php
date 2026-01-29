<?php
// Abfalltermine aus DB laden (inkl. Erledigt-Status)

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

try {
    $pdo = db_connect();
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connect failed']);
    exit;
}

$appConfig = app_config_get($pdo);
$days = (int)($appConfig['termine_abfall_days'] ?? 14);
if ($days <= 0) {
    $days = 14;
}

$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$in14 = (new DateTimeImmutable('today'))->modify('+' . $days . ' days')->format('Y-m-d');

$stmt = $pdo->prepare(
    "SELECT
        t.id,
        t.datum,
        t.summary,
        t.start_time,
        t.end_time,
        t.erledigt_am,
        GROUP_CONCAT(b.name ORDER BY b.name SEPARATOR ', ') AS erledigt_namen
     FROM termine_abfall t
     LEFT JOIN termine_abfall_erledigt ae ON ae.termin_id = t.id
     LEFT JOIN benutzer b ON b.id = ae.benutzer_id
     WHERE t.datum BETWEEN :start AND :end
     GROUP BY t.id
     ORDER BY t.datum ASC, t.start_time ASC, t.id ASC"
);
$stmt->execute([':start' => $today, ':end' => $in14]);
$events = [];
foreach ($stmt->fetchAll() as $row) {
    $events[] = [
        'id' => (int)$row['id'],
        'date' => $row['datum'],
        'summary' => $row['summary'],
        'start' => $row['start_time'] ? substr($row['start_time'], 0, 5) : null,
        'end' => $row['end_time'] ? substr($row['end_time'], 0, 5) : null,
        'done_at' => $row['erledigt_am'],
        'done_by' => $row['erledigt_namen'],
    ];
}

echo json_encode(['events' => $events], JSON_UNESCAPED_UNICODE);

