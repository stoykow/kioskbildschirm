<?php
// Liefert Abfahrten aus der DB als JSON

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

$type = $_GET['typ'] ?? '';
$limit = (int)($_GET['limit'] ?? 6);
if ($limit < 1 || $limit > 50) {
    $limit = 6;
}

$stopName = null;
$modeCondition = '1=1';
switch ($type) {
    case 'zug':
        $stopName = 'GÃƒÆ’Ã‚Â¶rlitz Hbf';
        $modeCondition = "(l.modus = 'train' OR l.produkt IN ('regional','regionalExp','interregional','national','nationalExpress','suburban')) AND (l.produkt IS NULL OR l.produkt NOT IN ('tram','bus'))";
        break;
    case 'tram':
        $stopName = 'LutherstraÃƒÆ’Ã…Â¸e';
        $modeCondition = "l.modus = 'tram'";
        break;
    case 'bus':
        $stopName = 'MelanchthonstraÃƒÆ’Ã…Â¸e';
        $modeCondition = "l.modus = 'bus'";
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
        l.name AS linie,
        l.modus,
        l.produkt
     FROM abfahrten a
     JOIN haltestellen h ON h.id = a.haltestelle_id
     JOIN linien l ON l.id = a.linie_id
     WHERE h.name = :stop_name
       AND {$modeCondition}
       AND COALESCE(a.tatsaechliche_zeit, a.geplante_zeit) >= NOW()
     ORDER BY a.geplante_zeit ASC
     LIMIT :limit"
);
$stmt->bindValue(':stop_name', $stopName, PDO::PARAM_STR);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();

$rows = $stmt->fetchAll();

$tz = new DateTimeZone('Europe/Berlin');
foreach ($rows as &$row) {
    $time = $row['tatsaechliche_zeit'] ?: $row['geplante_zeit'];
    if ($time) {
        $dt = new DateTimeImmutable($time, $tz);
        $row['anzeige_zeit'] = $dt->format('H:i');
    }
}
unset($row);

echo json_encode($rows);
