<?php
// Debug endpoint: dump raw departures
// NOTE: Remove or protect this file in production.

date_default_timezone_set('Europe/Berlin');
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

$dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connect failed']);
    exit;
}

$limit = (int)($_GET['limit'] ?? 50);
if ($limit < 1 || $limit > 500) {
    $limit = 50;
}

$stop = trim($_GET['stop'] ?? '');
$stopId = trim($_GET['stop_id'] ?? '');

$sql = "SELECT
            a.geplante_zeit,
            a.tatsaechliche_zeit,
            a.richtung,
            a.gleis,
            a.ausfall,
            l.name AS linie,
            l.modus,
            l.produkt,
            h.name AS haltestelle
        FROM abfahrten a
        JOIN abfahrten_haltestellen h ON h.id = a.haltestelle_id
        JOIN abfahrten_linien l ON l.id = a.linie_id";

$params = [];
if ($stopId !== '') {
    $sql .= " WHERE h.externe_id = :stop_id";
    $params[':stop_id'] = $stopId;
} elseif ($stop !== '') {
    $sql .= " WHERE h.name = :stop_name";
    $params[':stop_name'] = $stop;
}

$sql .= " ORDER BY a.geplante_zeit DESC LIMIT :limit";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();

$rows = $stmt->fetchAll();
echo json_encode($rows, JSON_UNESCAPED_UNICODE);
