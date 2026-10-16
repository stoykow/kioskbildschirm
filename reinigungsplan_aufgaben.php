<?php
// Reinigungspläne nutzen dieselben Aufgaben und Erledigungen wie andere Arbeiten.
require_once __DIR__ . '/reinigungsplan.php';

/**
 * Erzeugt für jeden aktiven Plan höchstens drei Aufgaben: den Freitag dieser
 * Kalenderwoche und die beiden folgenden Freitage, jeweils in Europe/Berlin.
 * Bereits vorhandene spätere Aufgaben werden anhand des aktuellen Plans geprüft.
 * Erledigte und überfällige Aufgaben bleiben unverändert. Rückgabe enthält die
 * Anzahlen created, updated und deleted. Eine äußere Transaktion bleibt erhalten.
 */
function reinigungsplan_sync_aufgaben(PDO $pdo, ?DateTimeImmutable $today = null): array {
    $timezone = new DateTimeZone('Europe/Berlin');
    $today = ($today ?? new DateTimeImmutable('today', $timezone))->setTimezone($timezone)->setTime(0, 0);
    $monday = $today->modify('-' . ((int)$today->format('N') - 1) . ' days');
    $todayValue = $today->format('Y-m-d');
    $prefix = 'reinigungsplan_';
    $stats = ['created' => 0, 'updated' => 0, 'deleted' => 0];
    $ownTransaction = !$pdo->inTransaction();
    $transactionStarted = false;
    $savepointStarted = false;
    try {
        $locking = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        if ($ownTransaction) {
            $pdo->beginTransaction();
            $transactionStarted = true;
        } else {
            $pdo->exec('SAVEPOINT reinigungsplan_aufgaben_sync');
            $savepointStarted = true;
        }
        // Dieselbe Sperrreihenfolge für parallele GETs und die Planverwaltung.
        $plans = $pdo->query(
            'SELECT id, titel, details, startdatum, enddatum, intervall, einheit, aktiv
             FROM reinigungsplaene ORDER BY id' . $locking
        )->fetchAll(PDO::FETCH_ASSOC);
        $groupRows = $pdo->query(
            'SELECT pg.plan_id, g.id, g.name, g.aktiv
             FROM reinigungsplan_gruppen pg
             INNER JOIN benutzer_gruppen g ON g.id = pg.gruppe_id
             ORDER BY pg.plan_id, pg.position' . $locking
        )->fetchAll(PDO::FETCH_ASSOC);
        $groups = [];
        foreach ($groupRows as $group) {
            $groups[(int)$group['plan_id']][] = $group;
        }
        $planBySource = [];
        foreach ($plans as $plan) {
            $source = $prefix . $plan['id'];
            if ((int)$plan['id'] < 1 || strlen($source) > 32) {
                throw new UnexpectedValueException('Der gespeicherte Reinigungsplan ist ungültig.');
            }
            $plan['gruppen'] = $groups[(int)$plan['id']] ?? [];
            $planBySource[$source] = $plan;
        }
        // Die Rotation wird gezielt am jeweiligen Freitag berechnet. Dadurch
        // funktionieren auch Tages- und Monatsrhythmen ohne historisches Backfill.
        $taskForFriday = static function (array $plan, DateTimeImmutable $friday): ?array {
            if (!(int)$plan['aktiv'] || $friday->format('N') !== '5') {
                return null;
            }
            $start = reinigungsplan_parse_date((string)$plan['startdatum']);
            $end = empty($plan['enddatum']) ? null : reinigungsplan_parse_date((string)$plan['enddatum']);
            if ($end !== null && $end < $start) {
                throw new UnexpectedValueException('Der gespeicherte Reinigungszeitraum ist ungültig.');
            }
            if ($friday < $start || ($end !== null && $friday > $end)) {
                return null;
            }
            $periods = reinigungsplan_periods($plan, $plan['gruppen'], $friday, 1);
            if (!$periods || !$periods[0]['aktuell']) {
                return null;
            }
            return [
                'titel' => (string)$plan['titel'],
                'details' => $plan['details'] === '' ? null : $plan['details'],
                'faellig_am' => $friday->format('Y-m-d'),
                'gruppe_id' => (int)$periods[0]['gruppe_id'],
            ];
        };
        $desired = [];
        foreach ($planBySource as $source => $plan) {
            for ($week = 0; $week < 3; $week++) {
                $friday = $monday->modify('+' . (4 + 7 * $week) . ' days');
                if ((int)$friday->format('Y') < 1000 || (int)$friday->format('Y') > 9999) {
                    continue;
                }
                $task = $taskForFriday($plan, $friday);
                if ($task !== null) {
                    $desired[$source . ':' . $task['faellig_am']] = $task + [
                        'quelle_typ' => $source, 'quelle_datum' => $task['faellig_am'],
                    ];
                }
            }
        }
        $select = $pdo->prepare(
            'SELECT a.*, EXISTS (SELECT 1 FROM aufgaben_erledigt ae WHERE ae.aufgabe_id = a.id) AS hat_erledigungen
             FROM aufgaben a
             WHERE a.quelle_typ LIKE :prefix
               AND (a.faellig_am >= :week_start OR a.quelle_datum >= :source_week_start)
             ORDER BY a.id' . $locking
        );
        $select->execute([
            ':prefix' => $prefix . '%', ':week_start' => $monday->format('Y-m-d'),
            ':source_week_start' => $monday->format('Y-m-d'),
        ]);
        $existing = [];
        foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // LIKE dient nur zur Vorauswahl. Ausschließlich eigene numerische
            // Planquellen bearbeiten, keine ähnlich benannten manuellen Aufgaben.
            if (!preg_match('/^reinigungsplan_[1-9][0-9]*$/D', (string)$row['quelle_typ'])) {
                continue;
            }
            $key = $row['quelle_typ'] . ':' . $row['quelle_datum'];
            $existing[$key] = $row;
            $due = $row['faellig_am'] ?? $row['quelle_datum'];
            if ($due === null || $due < $todayValue || !isset($planBySource[$row['quelle_typ']])) {
                continue;
            }
            try {
                $friday = reinigungsplan_parse_date((string)$row['quelle_datum']);
            } catch (InvalidArgumentException $error) {
                continue;
            }
            $task = $taskForFriday($planBySource[$row['quelle_typ']], $friday);
            if ($task !== null) {
                // Nur vorhandene spätere Aufgaben prüfen, neue Aufgaben entstehen
                // weiterhin ausschließlich innerhalb des Dreiwochenfensters.
                $desired[$key] = $task + [
                    'quelle_typ' => $row['quelle_typ'], 'quelle_datum' => $row['quelle_datum'],
                ];
            }
        }
        $insert = $pdo->prepare(
            'INSERT INTO aufgaben (titel, details, faellig_am, gruppe_id, quelle_typ, quelle_datum)
             VALUES (:titel, :details, :due, :group_id, :source_type, :source_date)'
        );
        $selectOne = $pdo->prepare(
            'SELECT a.*, EXISTS (SELECT 1 FROM aufgaben_erledigt ae WHERE ae.aufgabe_id = a.id) AS hat_erledigungen
             FROM aufgaben a WHERE a.quelle_typ = :source_type AND a.quelle_datum = :source_date' . $locking
        );
        $update = $pdo->prepare(
            'UPDATE aufgaben SET titel = :titel, details = :details, faellig_am = :due, gruppe_id = :group_id
             WHERE id = :id AND erledigt_am IS NULL AND erledigt_von IS NULL
               AND NOT EXISTS (SELECT 1 FROM aufgaben_erledigt ae WHERE ae.aufgabe_id = aufgaben.id)'
        );
        $delete = $pdo->prepare(
            'DELETE FROM aufgaben WHERE id = :id AND COALESCE(faellig_am, quelle_datum) >= :today
               AND erledigt_am IS NULL AND erledigt_von IS NULL
               AND NOT EXISTS (SELECT 1 FROM aufgaben_erledigt ae WHERE ae.aufgabe_id = aufgaben.id)'
        );
        foreach ($desired as $key => $task) {
            $params = [
                ':titel' => $task['titel'], ':details' => $task['details'],
                ':due' => $task['faellig_am'], ':group_id' => $task['gruppe_id'],
            ];
            $row = $existing[$key] ?? null;
            if ($row === null) {
                try {
                    $insert->execute($params + [':source_type' => $task['quelle_typ'], ':source_date' => $task['quelle_datum']]);
                    $stats['created']++;
                    continue;
                } catch (PDOException $error) {
                    $state = (string)($error->errorInfo[0] ?? $error->getCode());
                    if (!in_array($state, ['23000', '23505'], true)) {
                        throw $error;
                    }
                    // Der Unique-Key schützt auch vor weiteren Erzeugern, die
                    // nicht an unserer Plansperre teilnehmen.
                    $selectOne->execute([':source_type' => $task['quelle_typ'], ':source_date' => $task['quelle_datum']]);
                    $row = $selectOne->fetch(PDO::FETCH_ASSOC);
                    if (!$row) {
                        throw $error;
                    }
                }
            }
            $due = $row['faellig_am'] ?? $row['quelle_datum'];
            if ($row['erledigt_am'] !== null || $row['erledigt_von'] !== null || (int)$row['hat_erledigungen']
                || ($due !== null && $due < $todayValue)) {
                continue;
            }
            if ($row['titel'] !== $task['titel'] || $row['details'] !== $task['details']
                || $row['faellig_am'] !== $task['faellig_am'] || (int)$row['gruppe_id'] !== $task['gruppe_id']) {
                $update->execute($params + [':id' => (int)$row['id']]);
                $stats['updated'] += $update->rowCount();
            }
        }
        foreach ($existing as $key => $row) {
            if (!isset($desired[$key])) {
                $delete->execute([':id' => (int)$row['id'], ':today' => $todayValue]);
                $stats['deleted'] += $delete->rowCount();
            }
        }
        if ($ownTransaction) {
            $pdo->commit();
        } else {
            $pdo->exec('RELEASE SAVEPOINT reinigungsplan_aufgaben_sync');
        }
        return $stats;
    } catch (Throwable $error) {
        if ($transactionStarted && $pdo->inTransaction()) {
            $pdo->rollBack();
        } elseif ($savepointStarted && $pdo->inTransaction()) {
            $pdo->exec('ROLLBACK TO SAVEPOINT reinigungsplan_aufgaben_sync');
            $pdo->exec('RELEASE SAVEPOINT reinigungsplan_aufgaben_sync');
        }
        throw new RuntimeException('Die Reinigungsaufgaben konnten nicht synchronisiert werden.', 0, $error);
    }
}
