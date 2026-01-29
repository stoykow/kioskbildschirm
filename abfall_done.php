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
$userIds = $data['user_ids'] ?? null;
$userId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
$markUndone = !empty($data['undone']);

if ($eventId <= 0 || (!$markUndone && $userId <= 0 && !is_array($userIds))) {
    http_response_code(400);
    echo json_encode(['error' => 'event_id erforderlich (und user_id(s) zum Erledigen)']);
    exit;
}

if (is_array($userIds)) {
    $userIds = array_values(array_filter(array_map('intval', $userIds), fn($v) => $v > 0));
} elseif ($userId > 0) {
    $userIds = [$userId];
} else {
    $userIds = [];
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
        $validUsers = [];
        $checkUser = $pdo->prepare("SELECT id FROM benutzer WHERE id = :id AND aktiv = 1 LIMIT 1");
        foreach ($userIds as $uid) {
            $checkUser->execute([':id' => $uid]);
            if ($checkUser->fetch()) {
                $validUsers[] = $uid;
            }
        }
        if (count($validUsers) === 0) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'Unbekannte Benutzer']);
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
        $del = $pdo->prepare("DELETE FROM abfall_erledigt WHERE termin_id = :event_id");
        $del->execute([':event_id' => $eventId]);
        $upd = $pdo->prepare(
            "UPDATE abfall_termine
             SET erledigt_von = NULL, erledigt_am = NULL
             WHERE id = :event_id"
        );
        $upd->execute([':event_id' => $eventId]);
    } else {
        $ins = $pdo->prepare(
            "INSERT INTO abfall_erledigt (termin_id, benutzer_id)
             VALUES (:event_id, :user_id)
             ON DUPLICATE KEY UPDATE erledigt_am = VALUES(erledigt_am)"
        );
        foreach ($userIds as $uid) {
            $ins->execute([':event_id' => $eventId, ':user_id' => $uid]);
        }
        $upd = $pdo->prepare(
            "UPDATE abfall_termine
             SET erledigt_am = NOW()
             WHERE id = :event_id"
        );
        $upd->execute([':event_id' => $eventId]);
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
