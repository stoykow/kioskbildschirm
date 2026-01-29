<?php
// Abfalltermin als erledigt markieren

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

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungueltiges JSON']);
    exit;
}

$eventId = isset($data['event_id']) ? (int)$data['event_id'] : 0;
$userId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
$markUndone = !empty($data['undone']);

if ($eventId <= 0 || (!$markUndone && $userId <= 0)) {
    http_response_code(400);
    echo json_encode(['error' => 'event_id erforderlich (und user_id zum Erledigen)']);
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

try {
    $pdo->beginTransaction();

    if (!$markUndone) {
        $checkUser = $pdo->prepare("SELECT id FROM benutzer WHERE id = :id AND aktiv = 1 LIMIT 1");
        $checkUser->execute([':id' => $userId]);
        if (!$checkUser->fetch()) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'Unbekannter Benutzer']);
            exit;
        }
    }

    $checkEvent = $pdo->prepare("SELECT id FROM abfall_termine WHERE id = :id LIMIT 1");
    $checkEvent->execute([':id' => $eventId]);
    if (!$checkEvent->fetch()) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'Unbekannter Termin']);
        exit;
    }

    if ($markUndone) {
        $upd = $pdo->prepare(
            "UPDATE abfall_termine
             SET erledigt_von = NULL, erledigt_am = NULL
             WHERE id = :event_id"
        );
        $upd->execute([':event_id' => $eventId]);
    } else {
        $upd = $pdo->prepare(
            "UPDATE abfall_termine
             SET erledigt_von = :user_id, erledigt_am = NOW()
             WHERE id = :event_id"
        );
        $upd->execute([':user_id' => $userId, ':event_id' => $eventId]);
    }

    $pdo->commit();
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'DB Fehler']);
}
