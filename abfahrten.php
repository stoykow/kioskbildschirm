<?php
// Liefert Abfahrten aus der DB als JSON

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

$type = $_GET['typ'] ?? '';
$limit = (int)($_GET['limit'] ?? 6);
if ($limit < 1 || $limit > 50) {
    $limit = 6;
}

$stopName = null;
switch ($type) {
    case 'zug':
        $stopName = 'Görlitz Hbf';
        break;
    case 'tram':
        $stopName = 'Lutherstraße';
        break;
    case 'bus':
        $stopName = 'Melanchthonstraße';
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unbekannter typ']);
        exit;
}

$stmt = $pdo->prepare(
    "SELECT
        a.geplante_zeit,
        a.tatsaechliche_zeit,
        a.richtung,
        a.gleis,
        a.ausfall,
        l.name AS linie
     FROM abfahrten a
     JOIN haltestellen h ON h.id = a.haltestelle_id
     JOIN linien l ON l.id = a.linie_id
     WHERE h.name = :stop_name
       AND a.geplante_zeit >= (NOW() - INTERVAL 2 HOUR)
     ORDER BY a.geplante_zeit ASC
     LIMIT :limit"
);
$stmt->bindValue(':stop_name', $stopName, PDO::PARAM_STR);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();

$rows = $stmt->fetchAll();
echo json_encode($rows);
