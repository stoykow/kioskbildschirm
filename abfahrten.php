<?php
// Liefert aktuelle Abfahrten. Der Cache wird bei Bedarf höchstens minütlich erneuert.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/abfahrten_helper.php';

$type = is_string($_GET['typ'] ?? null) ? $_GET['typ'] : '';
if (!in_array($type, ['zug', 'tram', 'bus', 'ersatzverkehr'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unbekannter typ']);
    exit;
}
$defaults = ['zug' => 6, 'tram' => 2, 'bus' => 4, 'ersatzverkehr' => 6];
$defaultLimit = function_exists('departure_limit') ? departure_limit($type) : $defaults[$type];
$limit = filter_var($_GET['limit'] ?? $defaultLimit, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 50],
]);
if ($limit === false) {
    $limit = $defaultLimit;
}

try {
    $pdo = db_connect();
    $refresh = abfahrten_refresh($pdo);
    header('X-Abfahrten-Refresh: ' . $refresh['status']);
    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
    $stops = abfahrten_stops();
    switch ($type) {
        case 'zug':
            $stopExternalId = $stops[0]['external_id'];
            $modeCondition = "(l.modus = 'train' AND (l.produkt IS NULL OR l.produkt <> 'tram'))";
            break;
        case 'tram':
            $stopExternalId = $stops[1]['external_id'];
            $modeCondition = "l.produkt = 'tram'";
            break;
        case 'bus':
            $stopExternalId = $stops[2]['external_id'];
            $lineSql = implode(', ', array_map([$pdo, 'quote'], $stops[2]['lines']));
            $modeCondition = $lineSql === '' ? '1=0' : "l.produkt = 'bus' AND l.name IN ({$lineSql})";
            break;
        default:
            $stopExternalId = $stops[0]['external_id'];
            $modeCondition = "l.produkt = 'bus'";
    }
    $statement = $pdo->prepare(
        "SELECT DISTINCT a.geplante_zeit, a.tatsaechliche_zeit, a.richtung, a.gleis,
            a.ausfall, l.name AS linie, l.modus, l.produkt
         FROM abfahrten a
         JOIN abfahrten_haltestellen h ON h.id = a.haltestelle_id
         JOIN abfahrten_linien l ON l.id = a.linie_id
         WHERE h.externe_id = :stop_id AND {$modeCondition}
           AND COALESCE(a.tatsaechliche_zeit, a.geplante_zeit) >= :now_time
         ORDER BY COALESCE(a.tatsaechliche_zeit, a.geplante_zeit) ASC LIMIT :limit"
    );
    $statement->bindValue(':stop_id', $stopExternalId, PDO::PARAM_STR);
    $statement->bindValue(':now_time', $now->format('Y-m-d H:i:s'), PDO::PARAM_STR);
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $time = $row['tatsaechliche_zeit'] ?: $row['geplante_zeit'];
        $row['anzeige_zeit'] = (new DateTimeImmutable($time, new DateTimeZone('Europe/Berlin')))->format('H:i');
    }
    unset($row);
    if (!$rows && ($refresh['status'] === 'failed' || in_array($stopExternalId, $refresh['errors'], true))) {
        http_response_code(503);
        echo json_encode(['error' => 'Die Abfahrten konnten derzeit nicht aktualisiert werden.'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
} catch (Throwable $error) {
    http_response_code(500);
    error_log('Abfahrtenanzeige fehlgeschlagen: ' . $error->getMessage());
    echo json_encode(['error' => 'Die Abfahrten konnten nicht geladen werden.'], JSON_UNESCAPED_UNICODE);
}
