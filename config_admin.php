<?php
// Simple config admin (no auth)

header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/config.php';

try {
    $pdo = db_connect();
} catch (RuntimeException $e) {
    http_response_code(500);
    echo '<h1>DB config missing</h1><p>' . htmlspecialchars($e->getMessage()) . '</p>';
    exit;
} catch (PDOException $e) {
    http_response_code(500);
    echo '<h1>DB connect failed</h1>';
    exit;
}

$action = $_POST['action'] ?? '';
if ($action === 'save') {
    $key = trim($_POST['config_key'] ?? '');
    $value = trim($_POST['config_value'] ?? '');
    if ($key !== '') {
        $stmt = $pdo->prepare(
            "INSERT INTO app_config (config_key, config_value)
             VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)"
        );
        $stmt->execute([':k' => $key, ':v' => $value]);
    }
} elseif ($action === 'delete') {
    $key = trim($_POST['config_key'] ?? '');
    if ($key !== '') {
        $stmt = $pdo->prepare("DELETE FROM app_config WHERE config_key = :k");
        $stmt->execute([':k' => $key]);
    }
}

$rows = $pdo->query("SELECT config_key, config_value FROM app_config ORDER BY config_key ASC")->fetchAll();
$effective = app_config_get($pdo);

$knownKeys = [
    'openweather_lat',
    'openweather_lon',
    'openweather_lang',
    'openweather_units',
    'termine_sonstige_days',
    'termine_abfall_days',
    'snow_task_lead_hours',
    'snow_task_min_mm',
    'snow_task_evening_hour',
];

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>App Config</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; background: #f7f7f7; color: #111; }
        h1 { margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; background: #fff; }
        th, td { padding: 10px 12px; border-bottom: 1px solid #ddd; text-align: left; }
        th { background: #f0f0f0; }
        .row { display: flex; gap: 8px; margin: 16px 0; }
        input[type=text] { padding: 8px; width: 100%; }
        button { padding: 8px 12px; cursor: pointer; }
        .note { color: #555; font-size: 0.9rem; }
        .danger { color: #b00020; }
    </style>
</head>
<body>
    <h1>App Config</h1>
    <p class="note">Secrets wie <strong>OPENWEATHER_API</strong> bleiben in der ENV. Alles andere hier pflegen.</p>

    <form method="post">
        <input type="hidden" name="action" value="save">
        <div class="row">
            <input type="text" name="config_key" placeholder="config_key (z.B. termine_sonstige_days)" required>
            <input type="text" name="config_value" placeholder="config_value (z.B. 14)" required>
            <button type="submit">Speichern</button>
        </div>
    </form>

    <h2>Aktuelle Einträge</h2>
    <table>
        <thead>
            <tr>
                <th>Key</th>
                <th>Value</th>
                <th>Aktion</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($rows) === 0): ?>
                <tr><td colspan="3">Keine Einträge</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo h($row['config_key']); ?></td>
                        <td><?php echo h($row['config_value']); ?></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="config_key" value="<?php echo h($row['config_key']); ?>">
                                <button type="submit" class="danger">Löschen</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <h2>Effektive Werte (Defaults + DB)</h2>
    <table>
        <thead>
            <tr>
                <th>Key</th>
                <th>Value</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($knownKeys as $key): ?>
                <tr>
                    <td><?php echo h($key); ?></td>
                    <td><?php echo h($effective[$key] ?? ''); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
