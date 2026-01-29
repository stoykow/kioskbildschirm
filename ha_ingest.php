<?php
// Webhook: Home Assistant sendet Daten an Hausordnung

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungueltiges JSON']);
    exit;
}

$geraetName = $data['geraet'] ?? '';
$typ = $data['typ'] ?? null;
$payload = $data['payload'] ?? null;
$token = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? null;
// Beispiel-Token (bitte anpassen)
$requiredToken = getenv('HO_TOKEN');

if ($geraetName === '' || $payload === null) {
    http_response_code(400);
    echo test_json_error('geraet und payload erforderlich');
    exit;
}

if ($token === null || $token !== $requiredToken) {
    http_response_code(403);
    echo test_json_error('token erforderlich oder ungueltig');
    exit;
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

// Geraet anlegen/holen
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("SELECT id, token FROM geraete WHERE name = :name LIMIT 1");
    $stmt->execute([':name' => $geraetName]);
    $geraet = $stmt->fetch();

    if (!$geraet) {
        $ins = $pdo->prepare("INSERT INTO geraete (name, token, zuletzt_gesehen) VALUES (:name, :token, NOW())");
        $ins->execute([':name' => $geraetName, ':token' => $requiredToken]);
        $geraetId = (int)$pdo->lastInsertId();
    } else {
        $geraetId = (int)$geraet['id'];
        $upd = $pdo->prepare("UPDATE geraete SET zuletzt_gesehen = NOW() WHERE id = :id");
        $upd->execute([':id' => $geraetId]);
    }

    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $insData = $pdo->prepare(
        "INSERT INTO geraete_daten (geraet_id, typ, payload_json) VALUES (:geraet_id, :typ, :payload_json)"
    );
    $insData->execute([
        ':geraet_id' => $geraetId,
        ':typ' => $typ,
        ':payload_json' => $payloadJson,
    ]);

    $pdo->commit();
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'DB Fehler']);
}

function test_json_error($msg) {
    return json_encode(['error' => $msg]);
}
