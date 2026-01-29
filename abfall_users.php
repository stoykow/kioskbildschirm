<?php
// Abfall-Benutzerliste (aktive User)

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

$stmt = $pdo->query("SELECT id, name FROM benutzer WHERE aktiv = 1 ORDER BY name ASC");
$users = [];
foreach ($stmt->fetchAll() as $row) {
    $users[] = [
        'id' => (int)$row['id'],
        'name' => $row['name'],
    ];
}

echo json_encode(['users' => $users], JSON_UNESCAPED_UNICODE);
