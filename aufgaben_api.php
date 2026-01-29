<?php
// Aufgaben (offen) laden

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

$stmt = $pdo->prepare(
    "SELECT a.id, a.titel, a.details, a.faellig_am, g.name AS gruppe_name
     FROM aufgaben a
     LEFT JOIN benutzer_gruppen g ON g.id = a.gruppe_id
     WHERE a.erledigt_am IS NULL
     ORDER BY (a.faellig_am IS NULL) ASC, a.faellig_am ASC, a.id ASC
     LIMIT 6"
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
    ];
}

echo json_encode(['tasks' => $tasks], JSON_UNESCAPED_UNICODE);
