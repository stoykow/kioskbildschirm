<?php
// Sonstige Termine: Zuhause-Status setzen

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungueltiges JSON']);
    exit;
}

$terminId = isset($data['termin_id']) ? (int)$data['termin_id'] : 0;
$userIds = $data['user_ids'] ?? [];
$clear = !empty($data['clear']);

if ($terminId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'termin_id erforderlich']);
    exit;
}

if (is_array($userIds)) {
    $userIds = array_values(array_filter(array_map('intval', $userIds), fn($v) => $v > 0));
} else {
    $userIds = [];
}

if (count($userIds) === 0) {
    $clear = true;
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

    $checkTermin = $pdo->prepare("SELECT id FROM termine_sonstige WHERE id = :id LIMIT 1");
    $checkTermin->execute([':id' => $terminId]);
    if (!$checkTermin->fetch()) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'Unbekannter Termin']);
        exit;
    }

    if ($clear) {
        $del = $pdo->prepare("DELETE FROM termine_sonstige_zuhause WHERE termin_id = :termin_id");
        $del->execute([':termin_id' => $terminId]);
    } else {
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

        $del = $pdo->prepare("DELETE FROM termine_sonstige_zuhause WHERE termin_id = :termin_id");
        $del->execute([':termin_id' => $terminId]);

        $ins = $pdo->prepare(
            "INSERT INTO termine_sonstige_zuhause (termin_id, benutzer_id)
             VALUES (:termin_id, :benutzer_id)
             ON DUPLICATE KEY UPDATE gemeldet_am = VALUES(gemeldet_am)"
        );
        foreach ($validUsers as $uid) {
            $ins->execute([':termin_id' => $terminId, ':benutzer_id' => $uid]);
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
