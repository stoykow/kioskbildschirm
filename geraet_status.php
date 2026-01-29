<?php
// Gibt die letzten Daten fuer ein Geraet zurueck

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

$geraetName = $_GET['geraet'] ?? '';
$limit = (int)($_GET['limit'] ?? 1);
if ($limit < 1 || $limit > 50) {
    $limit = 1;
}

if ($geraetName === '') {
    http_response_code(400);
    echo json_encode(['error' => 'geraet erforderlich']);
    exit;
}

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
    "SELECT d.zeit, d.typ, d.payload_json
     FROM geraete_daten d
     JOIN geraete g ON g.id = d.geraet_id
     WHERE g.name = :name
     ORDER BY d.zeit DESC
     LIMIT :limit"
);
$stmt->bindValue(':name', $geraetName, PDO::PARAM_STR);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();

$rows = $stmt->fetchAll();
foreach ($rows as &$row) {
    $row['payload'] = json_decode($row['payload_json'], true);
    unset($row['payload_json']);
}

echo json_encode($rows);
