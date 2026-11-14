<?php
// Abfalltermine aus DB laden (inkl. Erledigt-Status)

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

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
$days = (int)($appConfig['termine_days'] ?? $appConfig['termine_abfall_days'] ?? 14);
if ($days <= 0) {
    $days = 14;
}

$timezone = new DateTimeZone('Europe/Berlin');
$todayDate = new DateTimeImmutable('today', $timezone);
$today = $todayDate->format('Y-m-d');
$in14 = $todayDate->modify('+' . $days . ' days')->format('Y-m-d');

$stmt = $pdo->prepare(
    "SELECT
        t.id,
        t.datum,
        t.summary,
        t.start_time,
        t.end_time,
        (SELECT MAX(UNIX_TIMESTAMP(ae.erledigt_am))
         FROM termine_abfall_erledigt ae
         WHERE ae.termin_id = t.id) AS erledigt_timestamp,
        (SELECT GROUP_CONCAT(b.name ORDER BY b.name SEPARATOR ', ')
         FROM termine_abfall_erledigt ae
         JOIN benutzer b ON b.id = ae.benutzer_id
         WHERE ae.termin_id = t.id) AS erledigt_namen,
        (SELECT MAX(UNIX_TIMESTAMP(ar.reingestellt_am))
         FROM termine_abfall_reingestellt ar
         WHERE ar.termin_id = t.id) AS reingestellt_timestamp,
        (SELECT GROUP_CONCAT(b2.name ORDER BY b2.name SEPARATOR ', ')
         FROM termine_abfall_reingestellt ar
         JOIN benutzer b2 ON b2.id = ar.benutzer_id
         WHERE ar.termin_id = t.id) AS reingestellt_namen
     FROM termine_abfall t
     WHERE t.datum BETWEEN :start AND :end
     ORDER BY t.datum ASC, t.start_time ASC, t.id ASC"
);
$stmt->execute([':start' => $today, ':end' => $in14]);
$events = [];
foreach ($stmt->fetchAll() as $row) {
    $doneAt = $row['erledigt_timestamp'] !== null
        ? (new DateTimeImmutable('@' . $row['erledigt_timestamp']))->setTimezone($timezone)->format('c')
        : null;
    $reinDoneAt = $row['reingestellt_timestamp'] !== null
        ? (new DateTimeImmutable('@' . $row['reingestellt_timestamp']))->setTimezone($timezone)->format('c')
        : null;
    $events[] = [
        'id' => (int)$row['id'],
        'date' => $row['datum'],
        'summary' => $row['summary'],
        'start' => $row['start_time'] ? substr($row['start_time'], 0, 5) : null,
        'end' => $row['end_time'] ? substr($row['end_time'], 0, 5) : null,
        'done_at' => $doneAt,
        'done_by' => $row['erledigt_namen'],
        'rein_done_at' => $reinDoneAt,
        'rein_done_by' => $row['reingestellt_namen'],
    ];
}

echo json_encode(['events' => $events], JSON_UNESCAPED_UNICODE);

