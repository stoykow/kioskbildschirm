<?php
// Abfalltermine aus DB laden (inkl. Erledigt-Status)

header('Content-Type: application/json; charset=utf-8');

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: '';
$dbUser = getenv('DB_USER') ?: '';
$dbPass = getenv('DB_PASS') ?: '';

if ($dbName === '' || $dbUser === '') {
    http_response_code(500);
    echo json_encode(['error' => 'DB env vars missing (DB_NAME/DB_USER)']);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connect failed']);
    exit;
}

$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$in14 = (new DateTimeImmutable('today'))->modify('+14 days')->format('Y-m-d');

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

