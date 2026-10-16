<?php
// Reinigungsrhythmen und die Reihenfolge der Bewohnergruppen verwalten.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/reinigungsplan.php';

session_start();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
if (empty($_SESSION['reinigungsplan_csrf'])) {
    $_SESSION['reinigungsplan_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['reinigungsplan_csrf'];

function reinigungsplan_admin_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function reinigungsplan_admin_redirect(string $status, int $id = 0): void {
    $url = 'reinigungsplan_admin.php?status=' . rawurlencode($status);
    if ($id > 0) {
        $url .= '&edit_plan=' . $id;
    }
    header('Location: ' . $url, true, 303);
    exit;
}

function reinigungsplan_admin_date(string $date): string {
    return reinigungsplan_parse_date($date)->format('d.m.Y');
}

$today = new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin'));
$form = [
    'id' => 0, 'titel' => 'Hausordnung', 'details' => '',
    'startdatum' => $today->format('Y-m-d'), 'enddatum' => '', 'intervall' => '1',
    'einheit' => 'wochen', 'aktiv' => 1, 'reihenfolge' => [],
];
$error = '';
$schemaMissing = false;
$loaded = false;
$groups = [];
$plans = [];
$pdo = null;
$editId = filter_var($_GET['edit_plan'] ?? 0, FILTER_VALIDATE_INT) ?: 0;

try {
    $pdo = db_connect();
    $groups = $pdo->query('SELECT id, name, aktiv FROM benutzer_gruppen ORDER BY name, id')->fetchAll();
    $plans = reinigungsplan_load($pdo, false);
    $loaded = true;
} catch (PDOException $e) {
    $schemaMissing = reinigungsplan_schema_missing($e);
    $error = $schemaMissing
        ? 'Die Datenbank für die Reinigungspläne muss noch eingerichtet werden.'
        : 'Die Reinigungspläne konnten nicht geladen werden.';
    http_response_code($schemaMissing ? 503 : 500);
} catch (Throwable $e) {
    $error = 'Die Reinigungspläne konnten nicht geladen werden.';
    http_response_code(500);
}

foreach ($plans as $plan) {
    if ((int)$plan['id'] === $editId) {
        $form = array_merge($form, array_intersect_key($plan, $form));
        foreach ($plan['gruppen'] as $group) {
            $form['reihenfolge'][(int)$group['id']] = (int)$group['position'];
        }
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) {
            throw new InvalidArgumentException('Das Formular ist abgelaufen. Bitte lade die Seite neu.');
        }
        if ($action === 'gruppe_anlegen') {
            $name = is_string($_POST['gruppenname'] ?? null) ? trim($_POST['gruppenname']) : '';
            if (!preg_match('/\A.{1,64}\z/us', $name)) {
                throw new InvalidArgumentException('Bitte gib einen Gruppennamen mit höchstens 64 Zeichen ein.');
            }
            $stmt = $pdo->prepare('INSERT INTO benutzer_gruppen (name) VALUES (:name)');
            try {
                $stmt->execute([':name' => $name]);
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? null) === 1062) {
                    throw new InvalidArgumentException('Eine Bewohnergruppe mit diesem Namen existiert bereits.');
                }
                throw $e;
            }
            reinigungsplan_admin_redirect('gruppe', $editId);
        } elseif ($action === 'plan_speichern') {
            $form = [
                'id' => filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT),
                'titel' => is_string($_POST['titel'] ?? null) ? trim($_POST['titel']) : '',
                'details' => is_string($_POST['details'] ?? null) ? trim($_POST['details']) : '',
                'startdatum' => is_string($_POST['startdatum'] ?? null) ? $_POST['startdatum'] : '',
                'enddatum' => is_string($_POST['enddatum'] ?? '') ? ($_POST['enddatum'] ?? '') : '',
                'intervall' => is_string($_POST['intervall'] ?? null) ? $_POST['intervall'] : '',
                'einheit' => is_string($_POST['einheit'] ?? null) ? $_POST['einheit'] : '',
                'aktiv' => !empty($_POST['aktiv']) ? 1 : 0,
                'reihenfolge' => is_array($_POST['reihenfolge'] ?? null) ? $_POST['reihenfolge'] : [],
            ];
            if ($form['id'] === false || $form['id'] < 0) {
                throw new InvalidArgumentException('Der ausgewählte Reinigungsplan ist ungültig.');
            }
            if (!preg_match('/\A.{1,255}\z/us', $form['titel'])) {
                throw new InvalidArgumentException('Bitte gib eine Bezeichnung mit höchstens 255 Zeichen ein.');
            }
            if (strlen($form['details']) > 65535) {
                throw new InvalidArgumentException('Der Hinweis ist zu lang. Bitte kürze ihn.');
            }
            reinigungsplan_parse_date($form['startdatum']);
            if (isset($_POST['enddatum']) && !is_string($_POST['enddatum'])) {
                throw new InvalidArgumentException('Bitte ein gültiges Enddatum eingeben.');
            }
            if ($form['enddatum'] !== '') {
                reinigungsplan_parse_date($form['enddatum']);
                if ($form['enddatum'] < $form['startdatum']) {
                    throw new InvalidArgumentException('Das Enddatum darf nicht vor dem Startdatum liegen.');
                }
            }
            $interval = filter_var($form['intervall'], FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 365],
            ]);
            if ($interval === false || !in_array($form['einheit'], ['tage', 'wochen', 'monate'], true)) {
                throw new InvalidArgumentException('Bitte wähle ein Intervall von 1 bis 365 und eine gültige Einheit.');
            }
            $groupById = array_column($groups, null, 'id');
            $existingGroupIds = [];
            foreach ($plans as $storedPlan) {
                if ((int)$storedPlan['id'] === (int)$form['id']) {
                    $existingGroupIds = array_column($storedPlan['gruppen'], 'id');
                    break;
                }
            }
            $ordered = [];
            foreach ($form['reihenfolge'] as $groupId => $position) {
                if ($position === '') {
                    continue;
                }
                $position = filter_var($position, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1, 'max_range' => 100000],
                ]);
                if ($position === false) {
                    throw new InvalidArgumentException('Die Reihenfolge muss aus positiven ganzen Zahlen bestehen.');
                }
                if (!isset($groupById[$groupId]) || (!(int)$groupById[$groupId]['aktiv'] && !in_array((int)$groupId, $existingGroupIds, true))) {
                    throw new InvalidArgumentException('Neu zugeordnete Bewohnergruppen müssen aktiv sein.');
                }
                if (isset($ordered[$position])) {
                    throw new InvalidArgumentException('Jede Position darf nur einmal vergeben werden.');
                }
                $ordered[$position] = $groupById[$groupId];
            }
            if (!$ordered) {
                throw new InvalidArgumentException('Bitte trage für mindestens eine Bewohnergruppe eine Position ein.');
            }
            ksort($ordered, SORT_NUMERIC);
            // Die Vorschau prüft den gesamten Rhythmus vor dem Speichern.
            reinigungsplan_periods($form, array_values($ordered), $today);
            $pdo->beginTransaction();
            if ($form['id'] > 0) {
                $stmt = $pdo->prepare('SELECT id FROM reinigungsplaene WHERE id = :id FOR UPDATE');
                $stmt->execute([':id' => $form['id']]);
                if (!$stmt->fetch()) {
                    throw new InvalidArgumentException('Der Reinigungsplan existiert nicht mehr.');
                }
                $stmt = $pdo->prepare(
                    'UPDATE reinigungsplaene SET titel = :titel, details = :details,
                     startdatum = :startdatum, enddatum = :enddatum, intervall = :intervall, einheit = :einheit,
                     aktiv = :aktiv WHERE id = :id'
                );
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO reinigungsplaene (titel, details, startdatum, enddatum, intervall, einheit, aktiv)
                     VALUES (:titel, :details, :startdatum, :enddatum, :intervall, :einheit, :aktiv)'
                );
            }
            $params = [
                ':titel' => $form['titel'], ':details' => $form['details'] !== '' ? $form['details'] : null,
                ':startdatum' => $form['startdatum'], ':intervall' => $interval,
                ':enddatum' => $form['enddatum'] !== '' ? $form['enddatum'] : null,
                ':einheit' => $form['einheit'], ':aktiv' => $form['aktiv'],
            ];
            if ($form['id'] > 0) {
                $params[':id'] = $form['id'];
            }
            $stmt->execute($params);
            $planId = $form['id'] > 0 ? $form['id'] : (int)$pdo->lastInsertId();
            $stmt = $pdo->prepare('DELETE FROM reinigungsplan_gruppen WHERE plan_id = :id');
            $stmt->execute([':id' => $planId]);
            $stmt = $pdo->prepare(
                'INSERT INTO reinigungsplan_gruppen (plan_id, gruppe_id, position) VALUES (:plan, :gruppe, :position)'
            );
            foreach (array_values($ordered) as $position => $group) {
                $stmt->execute([':plan' => $planId, ':gruppe' => $group['id'], ':position' => $position + 1]);
            }
            $pdo->commit();
            reinigungsplan_admin_redirect('gespeichert', $planId);
        } elseif ($action === 'plan_aktivieren') {
            $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
            if (!$id || $id < 1 || !in_array($_POST['aktiv'] ?? null, ['0', '1'], true)) {
                throw new InvalidArgumentException('Der ausgewählte Reinigungsplan ist ungültig.');
            }
            $stmt = $pdo->prepare('UPDATE reinigungsplaene SET aktiv = :aktiv WHERE id = :id');
            $stmt->execute([':id' => $id, ':aktiv' => (int)$_POST['aktiv']]);
            reinigungsplan_admin_redirect('status');
        } else {
            throw new InvalidArgumentException('Die gewählte Aktion ist ungültig.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e instanceof InvalidArgumentException
            ? $e->getMessage() : 'Die Änderung konnte nicht gespeichert werden. Bitte versuche es erneut.';
        http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
    }
}

$notices = [
    'gruppe' => 'Die Bewohnergruppe wurde angelegt.',
    'gespeichert' => 'Der Reinigungsrhythmus wurde gespeichert. Die Vorschau zeigt die kommenden Zuständigkeiten.',
    'status' => 'Der Status des Reinigungsplans wurde geändert.',
];
$notice = $notices[is_string($_GET['status'] ?? null) ? $_GET['status'] : ''] ?? '';
$unitLabels = ['tage' => 'Tage', 'wochen' => 'Wochen', 'monate' => 'Monate'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reinigungsrhythmus verwalten</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; margin: 0 auto; padding: 24px; max-width: 1080px; background: #f7f7f7; color: #111; }
        h1 { margin-bottom: 12px; } h2 { margin-top: 32px; }
        a { color: #125d91; } p { line-height: 1.5; }
        .section { padding: 20px; background: #fff; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 16px; }
        .note { color: #555; font-size: 0.95rem; }
        .message { padding: 12px 16px; border-radius: 6px; background: #e8f5e9; }
        .error { background: #ffebee; color: #8a1022; }
        .row { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 16px; align-items: end; }
        .field { flex: 1 1 180px; } label { display: block; margin-bottom: 6px; }
        input[type=text], input[type=date], input[type=number], select, textarea { padding: 9px; width: 100%; border: 1px solid #aaa; border-radius: 4px; font: inherit; }
        textarea { resize: vertical; } button { padding: 9px 14px; cursor: pointer; font: inherit; }
        .primary { background: #165b44; color: #fff; border: 1px solid #165b44; border-radius: 4px; }
        table { width: 100%; border-collapse: collapse; margin: 16px 0; }
        th, td { padding: 10px 8px; border-bottom: 1px solid #ddd; text-align: left; }
        th { background: #f5f5f5; } .order { max-width: 100px; }
        .checkbox { display: flex; gap: 8px; align-items: center; }
        .actions { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
        .table-scroll { overflow-x: auto; } .paused { color: #666; }
        @media (max-width: 600px) { body { padding: 16px; } .section { padding: 14px; } }
    </style>
</head>
<body>
    <nav><a href="admin.php">Zur Administration</a> · <a href="index.php">Zum Kiosk</a></nav>
    <h1>Reinigungsrhythmus</h1>
    <p>Lege fest, wie lange eine Bewohnergruppe zuständig ist und in welcher Reihenfolge sich die Gruppen abwechseln. Die zugehörige Reinigungsaufgabe ist jede Woche am Freitag fällig.</p>
    <?php if ($notice !== '' && $error === ''): ?>
        <p class="message" role="status"><?php echo reinigungsplan_admin_h($notice); ?></p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <p class="message error" role="alert"><?php echo reinigungsplan_admin_h($error); ?></p>
    <?php endif; ?>
    <?php if ($schemaMissing): ?>
        <p>Importiere die Datenbankmigrationen <code>database/migrations/002-reinigungsplan.sql</code> und anschließend <code>database/migrations/003-reinigungsplan-ende.sql</code> über phpMyAdmin. Bereits ausgeführte Migrationen können übersprungen werden. Lade anschließend diese Seite neu.</p>
    <?php endif; ?>
    <?php if ($loaded): ?>
    <h2>Bewohnergruppen</h2>
    <div class="section">
        <p class="note">Du kannst vorhandene Gruppen verwenden oder weitere Gruppen anlegen, zum Beispiel „Erdgeschoss“ oder „Familie Müller“.</p>
        <form method="post" action="reinigungsplan_admin.php<?php echo $editId > 0 ? '?edit_plan=' . $editId : ''; ?>">
            <input type="hidden" name="csrf" value="<?php echo reinigungsplan_admin_h($csrf); ?>">
            <input type="hidden" name="action" value="gruppe_anlegen">
            <div class="row">
                <div class="field"><label for="gruppenname">Name der neuen Gruppe</label><input type="text" id="gruppenname" name="gruppenname" maxlength="64" required></div>
                <button type="submit">Gruppe hinzufügen</button>
            </div>
        </form>
    </div>

    <h2><?php echo $form['id'] > 0 ? 'Reinigungsplan bearbeiten' : 'Reinigungsplan anlegen'; ?></h2>
    <div class="section">
        <form method="post" action="reinigungsplan_admin.php">
            <input type="hidden" name="csrf" value="<?php echo reinigungsplan_admin_h($csrf); ?>">
            <input type="hidden" name="action" value="plan_speichern">
            <input type="hidden" name="id" value="<?php echo reinigungsplan_admin_h($form['id']); ?>">
            <div class="row">
                <div class="field"><label for="titel">Bezeichnung</label><input type="text" name="titel" id="titel" maxlength="255" required value="<?php echo reinigungsplan_admin_h($form['titel']); ?>"></div>
                <div class="field"><label for="startdatum">Start der ersten Gruppe</label><input type="date" name="startdatum" id="startdatum" required value="<?php echo reinigungsplan_admin_h($form['startdatum']); ?>"></div>
                <div class="field"><label for="enddatum">Enddatum (optional, einschließlich)</label><input type="date" name="enddatum" id="enddatum" aria-describedby="enddatum-hinweis" value="<?php echo reinigungsplan_admin_h($form['enddatum']); ?>"></div>
            </div>
            <p class="note" id="enddatum-hinweis">Ohne Enddatum läuft der Rhythmus unbegrenzt. Mit Enddatum endet der Plan an diesem Tag. Danach ist noch keine weitere Reinigung festgelegt.</p>
            <div class="row">
                <div class="field"><label for="intervall">Wechsel alle</label><input type="number" name="intervall" id="intervall" min="1" max="365" required value="<?php echo reinigungsplan_admin_h($form['intervall']); ?>"></div>
                <div class="field"><label for="einheit">Einheit</label><select name="einheit" id="einheit"><?php foreach ($unitLabels as $value => $label): ?><option value="<?php echo $value; ?>"<?php echo $form['einheit'] === $value ? ' selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></div>
            </div>
            <label for="details">Was wird gereinigt? (optional)</label>
            <textarea name="details" id="details" rows="2"><?php echo reinigungsplan_admin_h($form['details']); ?></textarea>
            <p class="note">Beispiel: Bei „1 Woche“ und Start am Montag ist jede Gruppe von Montag bis Sonntag zuständig. Nach der letzten Gruppe beginnt die Reihenfolge wieder von vorn, solange das Enddatum noch nicht erreicht ist. Monatsintervalle orientieren sich am Starttag, bei kürzeren Monaten am Monatsende.</p>
            <h3>Reihenfolge</h3>
            <p class="note">Trage die Positionen 1, 2, 3 und so weiter ein. Ein leeres Feld bedeutet, dass die Gruppe nicht teilnimmt. Die erste Gruppe beginnt am Startdatum.</p>
            <?php if (array_filter($groups, fn($group) => !(int)$group['aktiv'])): ?><p class="note">Bereits zugeordnete inaktive Gruppen behalten ihre Position. Neue Zuordnungen sind nur für aktive Gruppen möglich.</p><?php endif; ?>
            <?php if (!$groups): ?>
                <p>Lege zuerst mindestens eine Bewohnergruppe an.</p>
            <?php else: ?>
                <table><thead><tr><th>Bewohnergruppe</th><th>Position</th></tr></thead><tbody>
                <?php foreach ($groups as $group): ?>
                    <tr><td><label for="gruppe-<?php echo (int)$group['id']; ?>"><?php echo reinigungsplan_admin_h($group['name']); ?><?php echo !(int)$group['aktiv'] ? ' (inaktiv)' : ''; ?></label></td><td><input class="order" type="number" id="gruppe-<?php echo (int)$group['id']; ?>" name="reihenfolge[<?php echo (int)$group['id']; ?>]" min="1" max="100000" value="<?php echo reinigungsplan_admin_h(is_scalar($form['reihenfolge'][$group['id']] ?? '') ? ($form['reihenfolge'][$group['id']] ?? '') : ''); ?>"></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
            <label class="checkbox"><input type="checkbox" name="aktiv" value="1"<?php echo $form['aktiv'] ? ' checked' : ''; ?>> Plan aktiv, wöchentliche Reinigungsaufgaben erzeugen</label>
            <p class="note">Änderungen an Startdatum, Enddatum, Intervall oder Reihenfolge aktualisieren offene Aufgaben mit heutiger oder zukünftiger Fälligkeit. Bereits erledigte und überfällige Aufgaben bleiben erhalten. Beim Pausieren läuft der Kalender weiter.</p>
            <div class="actions"><button class="primary" type="submit"<?php echo !$groups ? ' disabled' : ''; ?>>Rhythmus speichern</button><?php if ($form['id'] > 0): ?><a href="reinigungsplan_admin.php">Neuen Plan anlegen</a><?php endif; ?></div>
        </form>
    </div>

    <h2>Gespeicherte Pläne und Vorschau</h2>
    <?php if (!$plans): ?><p>Noch kein Reinigungsplan vorhanden.</p><?php endif; ?>
    <?php foreach ($plans as $plan): ?>
        <section class="section"<?php echo !(int)$plan['aktiv'] ? ' aria-label="Pausierter Plan"' : ''; ?>>
            <h3><?php echo reinigungsplan_admin_h($plan['titel']); ?><?php echo !(int)$plan['aktiv'] ? ' (pausiert)' : ''; ?></h3>
            <p>Wechsel alle <?php echo (int)$plan['intervall']; ?> <?php echo $unitLabels[$plan['einheit']]; ?>, Start am <?php echo reinigungsplan_admin_date($plan['startdatum']); ?>.</p>
            <?php if ($plan['enddatum'] !== null): ?><p>Geplant bis einschließlich <?php echo reinigungsplan_admin_date($plan['enddatum']); ?>. Danach ist noch keine weitere Reinigung festgelegt.</p><?php endif; ?>
            <?php if ($plan['details']): ?><p><?php echo nl2br(reinigungsplan_admin_h($plan['details'])); ?></p><?php endif; ?>
            <p class="note">Reihenfolge: <?php echo reinigungsplan_admin_h(implode(' → ', array_map(fn($group) => $group['name'] . (!(int)$group['aktiv'] ? ' (inaktiv)' : ''), $plan['gruppen']))); ?></p>
            <?php if ($plan['beendet']): ?>
                <p class="note">Der Plan ist beendet. Noch kein weiterer Reinigungsplan festgelegt.</p>
            <?php else: ?>
            <div class="table-scroll"><table><thead><tr><th>Von</th><th>Bis einschließlich</th><th>Zuständige Gruppe</th></tr></thead><tbody>
            <?php foreach ($plan['perioden'] as $period): ?>
                <tr><td><?php echo reinigungsplan_admin_date($period['von']); ?><?php echo $period['aktuell'] ? ' (aktuell)' : ''; ?></td><td><?php echo reinigungsplan_admin_date($period['bis']); ?></td><td><?php echo reinigungsplan_admin_h($period['gruppe_name']); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
            <div class="actions">
                <a href="reinigungsplan_admin.php?edit_plan=<?php echo (int)$plan['id']; ?>">Bearbeiten</a>
                <form method="post" action="reinigungsplan_admin.php">
                    <input type="hidden" name="csrf" value="<?php echo reinigungsplan_admin_h($csrf); ?>"><input type="hidden" name="action" value="plan_aktivieren"><input type="hidden" name="id" value="<?php echo (int)$plan['id']; ?>"><input type="hidden" name="aktiv" value="<?php echo (int)$plan['aktiv'] ? '0' : '1'; ?>">
                    <button type="submit"><?php echo (int)$plan['aktiv'] ? 'Pausieren' : 'Aktivieren'; ?></button>
                </form>
            </div>
        </section>
    <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
