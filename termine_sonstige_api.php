<?php
// Sonstige Termine aus DB laden (inkl. Zuhause-Status)

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
$days = (int)($appConfig['termine_sonstige_days'] ?? 14);
if ($days <= 0) {
    $days = 14;
}

$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$in14 = (new DateTimeImmutable('today'))->modify('+' . $days . ' days')->format('Y-m-d');

$stmt = $pdo->prepare(
    "SELECT
        t.id,
        t.datum,
        t.titel,
        t.hinweis,
        t.start_time,
        t.end_time,
        t.requires_home,
        GROUP_CONCAT(b.name ORDER BY b.name SEPARATOR ', ') AS zuhause_namen,
        GROUP_CONCAT(b.id ORDER BY b.name SEPARATOR ',') AS zuhause_ids
     FROM termine_sonstige t
     LEFT JOIN termine_sonstige_zuhause sz ON sz.termin_id = t.id
     LEFT JOIN benutzer b ON b.id = sz.benutzer_id
     WHERE t.datum BETWEEN :start AND :end
     GROUP BY t.id
     ORDER BY t.datum ASC, t.start_time ASC, t.id ASC"
);
$stmt->execute([':start' => $today, ':end' => $in14]);
$events = [];
foreach ($stmt->fetchAll() as $row) {
    $ids = [];
    if (!empty($row['zuhause_ids'])) {
        $ids = array_values(array_filter(array_map('intval', explode(',', $row['zuhause_ids']))));
    }
    $events[] = [
        'id' => (int)$row['id'],
        'date' => $row['datum'],
        'title' => $row['titel'],
        'hint' => $row['hinweis'],
        'start' => $row['start_time'] ? substr($row['start_time'], 0, 5) : null,
        'end' => $row['end_time'] ? substr($row['end_time'], 0, 5) : null,
        'requires_home' => (int)($row['requires_home'] ?? 0) === 1,
        'zuhause_by' => $row['zuhause_namen'],
        'zuhause_ids' => $ids,
    ];
}

echo json_encode(['events' => $events], JSON_UNESCAPED_UNICODE);
