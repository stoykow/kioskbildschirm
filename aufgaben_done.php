<?php
// Aufgabe als erledigt markieren

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

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
$markUndone = !empty($data['undone']);

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

if (!$markUndone && count($userIds) === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'user_id(s) erforderlich']);
    exit;
}

function get_rein_task_info_from_type($type) {
    $type = is_string($type) ? strtolower($type) : '';
    if ($type === 'restmuell') {
        return ['type' => 'restmuell_rein', 'title' => 'Restmuell reinstellen'];
    }
    if ($type === 'papier') {
        return ['type' => 'papier_rein', 'title' => 'Papiertonne reinstellen'];
    }
    if ($type === 'gelb') {
        return ['type' => 'gelb_rein', 'title' => 'Gelbe Tonne reinstellen'];
    }
    if ($type === 'bio') {
        return ['type' => 'bio_rein', 'title' => 'Biotonne reinstellen'];
    }
    return null;
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

try {
    $pdo->beginTransaction();

    $validUsers = [];
    if (!$markUndone) {
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

    $checkTask = $pdo->prepare("SELECT id, quelle_typ, quelle_datum FROM aufgaben WHERE id = :id LIMIT 1");
    $checkTask->execute([':id' => $taskId]);
    $taskRow = $checkTask->fetch(PDO::FETCH_ASSOC);
    if (!$taskRow) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'Unbekannte Aufgabe']);
        exit;
    }

    if ($markUndone) {
        $del = $pdo->prepare("DELETE FROM aufgaben_erledigt WHERE aufgabe_id = :task_id");
        $del->execute([':task_id' => $taskId]);
        $upd = $pdo->prepare("UPDATE aufgaben SET erledigt_am = NULL WHERE id = :task_id");
        $upd->execute([':task_id' => $taskId]);
    } else {
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
    }

    $sourceType = $taskRow['quelle_typ'] ?? null;
    $sourceDate = $taskRow['quelle_datum'] ?? null;
    $reinInfo = $sourceType && !str_ends_with((string)$sourceType, '_rein')
        ? get_rein_task_info_from_type((string)$sourceType)
        : null;
    if ($reinInfo && $sourceDate) {
        if ($markUndone) {
            $delTask = $pdo->prepare(
                "DELETE FROM aufgaben
                 WHERE quelle_typ = :typ AND quelle_datum = :datum"
            );
            $delTask->execute([':typ' => $reinInfo['type'], ':datum' => $sourceDate]);
        } else {
            $taskUpsert = $pdo->prepare(
                "INSERT INTO aufgaben (titel, details, faellig_am, gruppe_id, quelle_typ, quelle_datum)
                 VALUES (:titel, :details, :faellig_am, :gruppe_id, :quelle_typ, :quelle_datum)
                 ON DUPLICATE KEY UPDATE
                   titel = VALUES(titel),
                   details = VALUES(details),
                   faellig_am = VALUES(faellig_am),
                   gruppe_id = VALUES(gruppe_id)"
            );
            $taskUpsert->execute([
                ':titel' => $reinInfo['title'],
                ':details' => 'Bitte nach Leerung wieder reinstellen',
                ':faellig_am' => $sourceDate,
                ':gruppe_id' => null,
                ':quelle_typ' => $reinInfo['type'],
                ':quelle_datum' => $sourceDate,
            ]);
        }
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
