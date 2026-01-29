<?php
// Aufgabe als erledigt markieren

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

$taskId = isset($data['task_id']) ? (int)$data['task_id'] : 0;
$userIds = $data['user_ids'] ?? null;
$userId = isset($data['user_id']) ? (int)$data['user_id'] : 0;

if ($taskId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'task_id erforderlich']);
    exit;
}

if (is_array($userIds)) {
    $userIds = array_values(array_filter(array_map('intval', $userIds), fn($v) => $v > 0));
} elseif ($userId > 0) {
    $userIds = [$userId];
} else {
    $userIds = [];
}

if (count($userIds) === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'user_id(s) erforderlich']);
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

    $checkTask = $pdo->prepare("SELECT id FROM aufgaben WHERE id = :id LIMIT 1");
    $checkTask->execute([':id' => $taskId]);
    if (!$checkTask->fetch()) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'Unbekannte Aufgabe']);
        exit;
    }

    $ins = $pdo->prepare(
        "INSERT INTO aufgaben_erledigt (aufgabe_id, benutzer_id)
         VALUES (:task_id, :user_id)
         ON DUPLICATE KEY UPDATE erledigt_am = VALUES(erledigt_am)"
    );
    foreach ($validUsers as $uid) {
        $ins->execute([':task_id' => $taskId, ':user_id' => $uid]);
    }

    $upd = $pdo->prepare(
        "UPDATE aufgaben
         SET erledigt_am = NOW()
         WHERE id = :task_id"
    );
    $upd->execute([':task_id' => $taskId]);

    $pdo->commit();
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'DB Fehler']);
}
