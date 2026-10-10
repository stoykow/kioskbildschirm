<?php
// Liefert Abfahrten aus der DB als JSON

date_default_timezone_set('Europe/Berlin');
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

$type = is_string($_GET['typ'] ?? null) ? $_GET['typ'] : '';
$defaultLimit = departure_limit($type);
$limit = filter_var($_GET['limit'] ?? $defaultLimit, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 50],
]);
if ($limit === false) {
    $limit = $defaultLimit;
}

$now = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d H:i:s');

$stopExternalId = null;
$modeCondition = '1=1';
switch ($type) {
    case 'zug':
        $stopExternalId = '8010131';
        $modeCondition = "(l.modus = 'train' AND (l.produkt IS NULL OR l.produkt <> 'tram'))";
        break;
    case 'tram':
        $stopExternalId = '977263';
        $modeCondition = "l.produkt = 'tram'";
        break;
    case 'bus':
        $stopExternalId = $cityBusStopExternalId;
        $cityBusLineSql = implode(', ', array_map([$pdo, 'quote'], $cityBusLines));
        $modeCondition = $cityBusLineSql === ''
            ? '1=0'
            : "l.produkt = 'bus' AND l.name IN ({$cityBusLineSql})";
        break;
    case 'ersatzverkehr':
        $stopExternalId = '8010131';
        $modeCondition = "l.produkt = 'bus'";
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unbekannter typ']);
        exit;
}

$stmt = $pdo->prepare(
    "SELECT DISTINCT
        a.geplante_zeit,
        a.tatsaechliche_zeit,
        a.richtung,
        a.gleis,
        a.ausfall,
        l.name AS linie,
        l.modus,
        l.produkt
     FROM abfahrten a
     JOIN abfahrten_haltestellen h ON h.id = a.haltestelle_id
     JOIN abfahrten_linien l ON l.id = a.linie_id
     WHERE h.externe_id = :stop_id
       AND {$modeCondition}
       AND COALESCE(a.tatsaechliche_zeit, a.geplante_zeit) >= :now_time
     ORDER BY COALESCE(a.tatsaechliche_zeit, a.geplante_zeit) ASC
     LIMIT :limit"
);
$stmt->bindValue(':stop_id', $stopExternalId, PDO::PARAM_STR);
$stmt->bindValue(':now_time', $now, PDO::PARAM_STR);
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

echo json_encode($rows, JSON_UNESCAPED_UNICODE);
