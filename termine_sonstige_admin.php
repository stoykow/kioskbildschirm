<?php
// Admin: Sonstige Termine verwalten (ohne Login)

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
    $id = (int)($_POST['id'] ?? 0);
    $datum = trim($_POST['datum'] ?? '');
    $titel = trim($_POST['titel'] ?? '');
    $hinweis = trim($_POST['hinweis'] ?? '');
    $start = trim($_POST['start_time'] ?? '');
    $end = trim($_POST['end_time'] ?? '');
    $requiresHome = !empty($_POST['requires_home']) ? 1 : 0;

    if ($datum !== '' && $titel !== '') {
        if ($id > 0) {
            $stmt = $pdo->prepare(
                "UPDATE termine_sonstige
                 SET datum = :datum, titel = :titel, hinweis = :hinweis,
                     start_time = :start_time, end_time = :end_time,
                     requires_home = :requires_home
                 WHERE id = :id"
            );
            $stmt->execute([
                ':datum' => $datum,
                ':titel' => $titel,
                ':hinweis' => $hinweis !== '' ? $hinweis : null,
                ':start_time' => $start !== '' ? $start : null,
                ':end_time' => $end !== '' ? $end : null,
                ':requires_home' => $requiresHome,
                ':id' => $id,
            ]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO termine_sonstige
                 (datum, titel, hinweis, start_time, end_time, requires_home)
                 VALUES (:datum, :titel, :hinweis, :start_time, :end_time, :requires_home)"
            );
            $stmt->execute([
                ':datum' => $datum,
                ':titel' => $titel,
                ':hinweis' => $hinweis !== '' ? $hinweis : null,
                ':start_time' => $start !== '' ? $start : null,
                ':end_time' => $end !== '' ? $end : null,
                ':requires_home' => $requiresHome,
            ]);
        }
    }
} elseif ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $delZ = $pdo->prepare("DELETE FROM termine_sonstige_zuhause WHERE termin_id = :id");
        $delZ->execute([':id' => $id]);
        $del = $pdo->prepare("DELETE FROM termine_sonstige WHERE id = :id");
        $del->execute([':id' => $id]);
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM termine_sonstige WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $editId]);
    $editRow = $stmt->fetch();
}

$rows = $pdo->query(
    "SELECT id, datum, titel, hinweis, start_time, end_time, requires_home
     FROM termine_sonstige
     ORDER BY datum DESC, start_time DESC, id DESC"
)->fetchAll();

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sonstige Termine</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; background: #f7f7f7; color: #111; }
        h1 { margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; background: #fff; }
        th, td { padding: 10px 12px; border-bottom: 1px solid #ddd; text-align: left; }
        th { background: #f0f0f0; }
        .row { display: flex; gap: 8px; flex-wrap: wrap; margin: 16px 0; }
        input[type=text], input[type=date], input[type=time] { padding: 8px; width: 100%; }
        .field { flex: 1 1 180px; }
        button { padding: 8px 12px; cursor: pointer; }
        .danger { color: #b00020; }
        .note { color: #555; font-size: 0.9rem; }
        .badge { padding: 2px 6px; border-radius: 6px; background: #eee; font-size: 0.85rem; }
        .badge-yes { background: #ffe0e0; color: #900; }
    </style>
</head>
<body>
    <h1>Sonstige Termine</h1>
    <p class="note">Ohne Login. Änderungen wirken sofort.</p>

    <form method="post">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?php echo h($editRow['id'] ?? ''); ?>">
        <div class="row">
            <div class="field">
                <label>Datum</label><br>
                <input type="date" name="datum" required value="<?php echo h($editRow['datum'] ?? ''); ?>">
            </div>
            <div class="field">
                <label>Titel</label><br>
                <input type="text" name="titel" required value="<?php echo h($editRow['titel'] ?? ''); ?>">
            </div>
            <div class="field">
                <label>Hinweis</label><br>
                <input type="text" name="hinweis" value="<?php echo h($editRow['hinweis'] ?? ''); ?>">
            </div>
            <div class="field">
                <label>Start</label><br>
                <input type="time" name="start_time" value="<?php echo h($editRow['start_time'] ?? ''); ?>">
            </div>
            <div class="field">
                <label>Ende</label><br>
                <input type="time" name="end_time" value="<?php echo h($editRow['end_time'] ?? ''); ?>">
            </div>
            <div class="field">
                <label>
                    <input type="checkbox" name="requires_home" value="1" <?php echo !empty($editRow['requires_home']) ? 'checked' : ''; ?>>
                    Zuhause erforderlich
                </label>
            </div>
        </div>
        <button type="submit">Speichern</button>
        <?php if ($editRow): ?>
            <a href="termine_sonstige_admin.php" style="margin-left:8px;">Abbrechen</a>
        <?php endif; ?>
    </form>

    <h2>Vorhandene Termine</h2>
    <table>
        <thead>
            <tr>
                <th>Datum</th>
                <th>Titel</th>
                <th>Hinweis</th>
                <th>Zeit</th>
                <th>Zuhause?</th>
                <th>Aktion</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($rows) === 0): ?>
                <tr><td colspan="6">Keine Termine</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo h($row['datum']); ?></td>
                        <td><?php echo h($row['titel']); ?></td>
                        <td><?php echo h($row['hinweis']); ?></td>
                        <td><?php echo h($row['start_time'] ? substr($row['start_time'],0,5) : ''); ?><?php echo $row['end_time'] ? ' - ' . h(substr($row['end_time'],0,5)) : ''; ?></td>
                        <td><?php echo !empty($row['requires_home']) ? '<span class="badge badge-yes">ja</span>' : '<span class="badge">nein</span>'; ?></td>
                        <td>
                            <a href="?edit=<?php echo h($row['id']); ?>">Bearbeiten</a>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo h($row['id']); ?>">
                                <button type="submit" class="danger">Löschen</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>
