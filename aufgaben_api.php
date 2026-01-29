<?php
// Aufgaben (offen) laden

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

$stmt = $pdo->prepare(
    "SELECT
        a.id,
        a.titel,
        a.details,
        a.faellig_am,
        g.name AS gruppe_name,
        a.erledigt_am,
        GROUP_CONCAT(b.name ORDER BY b.name SEPARATOR ', ') AS erledigt_namen
     FROM aufgaben a
     LEFT JOIN benutzer_gruppen g ON g.id = a.gruppe_id
     LEFT JOIN aufgaben_erledigt ae ON ae.aufgabe_id = a.id
     LEFT JOIN benutzer b ON b.id = ae.benutzer_id
     GROUP BY a.id
     ORDER BY (a.erledigt_am IS NULL) DESC, (a.faellig_am IS NULL) ASC, a.faellig_am ASC, a.id ASC
     LIMIT 8"
);
$stmt->execute();

$tasks = [];
foreach ($stmt->fetchAll() as $row) {
    $tasks[] = [
        'id' => (int)$row['id'],
        'title' => $row['titel'],
        'details' => $row['details'],
        'due' => $row['faellig_am'],
        'group' => $row['gruppe_name'],
        'done_at' => $row['erledigt_am'],
        'done_by' => $row['erledigt_namen'],
    ];
}

echo json_encode(['tasks' => $tasks], JSON_UNESCAPED_UNICODE);
