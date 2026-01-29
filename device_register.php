<?php
// Geräte-Registrierung (nur Name)

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

$deviceName = trim($data['device_name'] ?? $data['name'] ?? '');
if ($deviceName === '') {
    http_response_code(400);
    echo json_encode(['error' => 'device_name erforderlich']);
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

try {
    $deviceId = bin2hex(random_bytes(16));
    $stmt = $pdo->prepare(
        "INSERT INTO geraete_registrierungen (device_id, device_name)
         VALUES (:device_id, :device_name)"
    );
    $stmt->execute([
        ':device_id' => $deviceId,
        ':device_name' => $deviceName,
    ]);

    echo json_encode(['ok' => true, 'device_id' => $deviceId], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB Fehler']);
}
