<?php
declare(strict_types=1);

// Aufruf: php tests/reinigungsplan_aufgaben_test.php
require_once dirname(__DIR__) . '/reinigungsplan_aufgaben.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo '[SKIP] Aufgaben-Synchronisierung übersprungen: pdo_sqlite ist nicht verfügbar.' . PHP_EOL;
    exit(0);
}

date_default_timezone_set('UTC');
$failures = [];
$testCount = 0;

function sync_check_same($expected, $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            'Erwartet: ' . var_export($expected, true) . PHP_EOL .
            'Erhalten: ' . var_export($actual, true)
        );
    }
}

function sync_run_case(string $name, callable $test): void
{
    global $failures, $testCount;
    $testCount++;
    try {
        $test();
        echo '[OK] ' . $name . PHP_EOL;
    } catch (Throwable $error) {
        $failures[] = $name . ': ' . $error->getMessage();
        echo '[FEHLER] ' . $name . PHP_EOL;
    }
}

function sync_date(string $date): DateTimeImmutable
{
    return new DateTimeImmutable($date, new DateTimeZone('Europe/Berlin'));
}

function sync_fixture(array $overrides = []): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('CREATE TABLE benutzer_gruppen (id INTEGER PRIMARY KEY, name TEXT NOT NULL, aktiv INTEGER NOT NULL DEFAULT 1)');
    $pdo->exec('CREATE TABLE benutzer (id INTEGER PRIMARY KEY, name TEXT NOT NULL, gruppen_id INTEGER, aktiv INTEGER NOT NULL DEFAULT 1)');
    $pdo->exec('CREATE TABLE reinigungsplaene (
        id INTEGER PRIMARY KEY, titel TEXT NOT NULL, details TEXT, startdatum TEXT NOT NULL,
        enddatum TEXT, intervall INTEGER NOT NULL, einheit TEXT NOT NULL, aktiv INTEGER NOT NULL DEFAULT 1,
        erstellt_am TEXT DEFAULT CURRENT_TIMESTAMP, aktualisiert_am TEXT DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE TABLE reinigungsplan_gruppen (
        plan_id INTEGER NOT NULL REFERENCES reinigungsplaene(id) ON DELETE CASCADE,
        gruppe_id INTEGER NOT NULL REFERENCES benutzer_gruppen(id), position INTEGER NOT NULL,
        PRIMARY KEY (plan_id, position), UNIQUE (plan_id, gruppe_id)
    )');
    $pdo->exec('CREATE TABLE aufgaben (
        id INTEGER PRIMARY KEY AUTOINCREMENT, titel TEXT NOT NULL, details TEXT, faellig_am TEXT,
        gruppe_id INTEGER REFERENCES benutzer_gruppen(id), quelle_typ TEXT, quelle_datum TEXT,
        erledigt_von INTEGER REFERENCES benutzer(id), erledigt_am TEXT,
        erstellt_am TEXT DEFAULT CURRENT_TIMESTAMP, aktualisiert_am TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (quelle_typ, quelle_datum)
    )');
    $pdo->exec('CREATE TABLE aufgaben_erledigt (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        aufgabe_id INTEGER NOT NULL REFERENCES aufgaben(id),
        benutzer_id INTEGER NOT NULL REFERENCES benutzer(id), erledigt_am TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (aufgabe_id, benutzer_id)
    )');
    $insertGroup = $pdo->prepare('INSERT INTO benutzer_gruppen (id, name) VALUES (?, ?)');
    foreach ([[7, 'Erdgeschoss'], [11, 'Obergeschoss'], [19, 'Dachgeschoss']] as $group) {
        $insertGroup->execute($group);
    }
    $insertUser = $pdo->prepare('INSERT INTO benutzer (id, name, gruppen_id) VALUES (?, ?, ?)');
    $insertUser->execute([1, 'Müller', 7]);
    $insertUser->execute([2, 'Özdemir', 11]);
    sync_insert_plan($pdo, array_replace([
        'id' => 5, 'titel' => 'Hausflur', 'details' => 'Treppen und Geländer gründlich reinigen.',
        'startdatum' => '2026-10-12', 'enddatum' => '2026-11-01',
        'intervall' => 1, 'einheit' => 'wochen', 'aktiv' => 1,
    ], $overrides));
    return $pdo;
}

function sync_insert_plan(PDO $pdo, array $plan): void
{
    $insert = $pdo->prepare('INSERT INTO reinigungsplaene
        (id, titel, details, startdatum, enddatum, intervall, einheit, aktiv)
        VALUES (:id, :titel, :details, :startdatum, :enddatum, :intervall, :einheit, :aktiv)');
    $insert->execute($plan);
    $assignment = $pdo->prepare('INSERT INTO reinigungsplan_gruppen (plan_id, gruppe_id, position) VALUES (?, ?, ?)');
    foreach ([7, 11, 19] as $position => $groupId) {
        $assignment->execute([$plan['id'], $groupId, $position]);
    }
}

function sync_rows(PDO $pdo, ?string $source = 'reinigungsplan_5'): array
{
    $select = $pdo->prepare('SELECT id, titel, details, faellig_am, gruppe_id, quelle_typ, quelle_datum,
        erledigt_von, erledigt_am FROM aufgaben' . ($source !== null ? ' WHERE quelle_typ = :source' : '') .
        ' ORDER BY quelle_datum, id');
    $select->execute($source !== null ? [':source' => $source] : []);
    return $select->fetchAll();
}

function sync_by_date(PDO $pdo, string $date): array
{
    foreach (sync_rows($pdo) as $row) {
        if ($row['quelle_datum'] === $date) {
            return $row;
        }
    }
    throw new RuntimeException('Keine Reinigungsaufgabe für ' . $date . ' gefunden.');
}

function sync_reorder(PDO $pdo, array $groupIds, int $planId = 5): void
{
    $delete = $pdo->prepare('DELETE FROM reinigungsplan_gruppen WHERE plan_id = ?');
    $delete->execute([$planId]);
    $insert = $pdo->prepare('INSERT INTO reinigungsplan_gruppen (plan_id, gruppe_id, position) VALUES (?, ?, ?)');
    foreach ($groupIds as $position => $groupId) {
        $insert->execute([$planId, $groupId, $position]);
    }
}

sync_run_case('Erzeugt genau drei Freitage mit Gruppenfolge und UTF-8-Inhalt', function (): void {
    $pdo = sync_fixture();
    $result = reinigungsplan_sync_aufgaben($pdo, sync_date('2026-10-14'));
    $rows = sync_rows($pdo);
    sync_check_same(['2026-10-16', '2026-10-23', '2026-10-30'], array_column($rows, 'quelle_datum'));
    sync_check_same(['2026-10-16', '2026-10-23', '2026-10-30'], array_column($rows, 'faellig_am'));
    sync_check_same([7, 11, 19], array_column($rows, 'gruppe_id'));
    sync_check_same(3, $result['created']);
    foreach (['created', 'updated', 'deleted'] as $counter) {
        sync_check_same(true, is_int($result[$counter]));
    }
    foreach ($rows as $row) {
        sync_check_same(true, str_contains($row['titel'], 'Hausflur'));
        sync_check_same(true, str_contains((string)$row['details'], 'Geländer gründlich reinigen.'));
        sync_check_same(null, $row['erledigt_am']);
    }
});

sync_run_case('Wiederholter Abruf erzeugt keine Duplikate und behält Aufgaben-IDs', function (): void {
    $pdo = sync_fixture();
    reinigungsplan_sync_aufgaben($pdo, sync_date('2026-10-14'));
    $before = sync_rows($pdo);
    $result = reinigungsplan_sync_aufgaben($pdo, sync_date('2026-10-14'));
    sync_check_same($before, sync_rows($pdo));
    sync_check_same(0, $result['created']);
    sync_check_same(0, $result['deleted']);
});

sync_run_case('Auch am Samstag gehört der vergangene Freitag zum aktuellen Wochenfenster', function (): void {
    $pdo = sync_fixture();
    reinigungsplan_sync_aufgaben($pdo, sync_date('2026-10-17 12:00:00'));
    sync_check_same(['2026-10-16', '2026-10-23', '2026-10-30'], array_column(sync_rows($pdo), 'quelle_datum'));
});

sync_run_case('Neue Woche ergänzt einen Freitag und bewahrt die überfällige Aufgabe', function (): void {
    $pdo = sync_fixture(['enddatum' => null]);
    reinigungsplan_sync_aufgaben($pdo, sync_date('2026-10-14'));
    $overdue = sync_by_date($pdo, '2026-10-16');
    reinigungsplan_sync_aufgaben($pdo, sync_date('2026-10-19'));
    sync_check_same(['2026-10-16', '2026-10-23', '2026-10-30', '2026-11-06'], array_column(sync_rows($pdo), 'quelle_datum'));
    sync_check_same($overdue, sync_by_date($pdo, '2026-10-16'));
    sync_check_same(7, sync_by_date($pdo, '2026-11-06')['gruppe_id']);
});

sync_run_case('Wochenbeginn richtet sich auch bei UTC-Eingabe nach Europe/Berlin', function (): void {
    $pdo = sync_fixture(['enddatum' => null]);
    $utcSunday = new DateTimeImmutable('2026-10-18T22:30:00Z');
    reinigungsplan_sync_aufgaben($pdo, $utcSunday);
    sync_check_same(['2026-10-23', '2026-10-30', '2026-11-06'], array_column(sync_rows($pdo), 'quelle_datum'));
});

sync_run_case('Start nach dem ersten Freitag überspringt diesen Termin', function (): void {
    $pdo = sync_fixture(['startdatum' => '2026-10-17']);
    reinigungsplan_sync_aufgaben($pdo, sync_date('2026-10-14'));
    sync_check_same(['2026-10-23', '2026-10-30'], array_column(sync_rows($pdo), 'quelle_datum'));
    sync_check_same([7, 11], array_column(sync_rows($pdo), 'gruppe_id'));
});

sync_run_case('Enddatum am Freitag gilt einschließlich', function (): void {
    $pdo = sync_fixture(['enddatum' => '2026-10-23']);
    reinigungsplan_sync_aufgaben($pdo, sync_date('2026-10-14'));
    sync_check_same(['2026-10-16', '2026-10-23'], array_column(sync_rows($pdo), 'quelle_datum'));
});

sync_run_case('Plan ohne Freitag zwischen Start und Ende erzeugt keine Aufgabe', function (): void {
    $pdo = sync_fixture(['startdatum' => '2026-10-17', 'enddatum' => '2026-10-22']);
    reinigungsplan_sync_aufgaben($pdo, sync_date('2026-10-14'));
    sync_check_same([], sync_rows($pdo));
});

sync_run_case('Tagesrhythmus weist die Gruppe am Freitag statt am Abrufdatum zu', function (): void {
    $pdo = sync_fixture(['intervall' => 2, 'einheit' => 'tage']);
    reinigungsplan_sync_aufgaben($pdo, sync_date('2026-10-14'));
    sync_check_same([19, 19, 7], array_column(sync_rows($pdo), 'gruppe_id'));
});

sync_run_case('Geänderte Reihenfolge aktualisiert offene künftige Aufgaben ohne neue IDs', function (): void {
    $pdo = sync_fixture();
    reinigungsplan_sync_aufgaben($pdo, sync_date('2026-10-14'));
    $ids = array_column(sync_rows($pdo), 'id');
    sync_reorder($pdo, [19, 7, 11]);
    reinigungsplan_sync_aufgaben($pdo, sync_date('2026-10-14'));
    sync_check_same($ids, array_column(sync_rows($pdo), 'id'));
    sync_check_same([19, 7, 11], array_column(sync_rows($pdo), 'gruppe_id'));
});

sync_run_case('Erledigte Aufgabe und mehrere Erlediger bleiben bei Planänderung erhalten', function (): void {
    $pdo = sync_fixture();
    reinigungsplan_sync_aufgaben($pdo, sync_date('2026-10-14'));
    $id = sync_by_date($pdo, '2026-10-23')['id'];
    $pdo->prepare('UPDATE aufgaben SET erledigt_am = ?, erledigt_von = ? WHERE id = ?')->execute(['2026-10-14 10:00:00', 1, $id]);
    $insertDone = $pdo->prepare('INSERT INTO aufgaben_erledigt (aufgabe_id, benutzer_id, erledigt_am) VALUES (?, ?, ?)');
    $insertDone->execute([$id, 1, '2026-10-14 10:00:00']);
    $insertDone->execute([$id, 2, '2026-10-14 10:01:00']);
    $beforeTask = sync_by_date($pdo, '2026-10-23');
    $beforeDone = $pdo->query('SELECT * FROM aufgaben_erledigt ORDER BY id')->fetchAll();
    sync_reorder($pdo, [19, 7, 11]);
    $pdo->exec("UPDATE reinigungsplaene SET titel = 'Neuer Hausflur', details = 'Andere Reinigung' WHERE id = 5");
    reinigungsplan_sync_aufgaben($pdo, sync_date('2026-10-14'));
    sync_check_same($beforeTask, sync_by_date($pdo, '2026-10-23'));
    sync_check_same($beforeDone, $pdo->query('SELECT * FROM aufgaben_erledigt ORDER BY id')->fetchAll());
    sync_check_same(19, sync_by_date($pdo, '2026-10-16')['gruppe_id']);
});

foreach (['erledigt_von', 'einzelner Erledigungsvermerk'] as $completionKind) {
    sync_run_case('Auch einzelnes Erledigungssignal schützt vor Pausenbereinigung: ' . $completionKind, function () use ($completionKind): void {
        $pdo = sync_fixture();
        reinigungsplan_sync_aufgaben($pdo, sync_date('2026-10-14'));
        $id = sync_by_date($pdo, '2026-10-23')['id'];
        if ($completionKind === 'erledigt_von') {
            $pdo->prepare('UPDATE aufgaben SET erledigt_von = 1 WHERE id = ?')->execute([$id]);
        } else {
            $pdo->prepare('INSERT INTO aufgaben_erledigt (aufgabe_id, benutzer_id) VALUES (?, 1)')->execute([$id]);
        }
        $before = sync_by_date($pdo, '2026-10-23');
        $pdo->exec('UPDATE reinigungsplaene SET aktiv = 0 WHERE id = 5');
        reinigungsplan_sync_aufgaben($pdo, sync_date('2026-10-14'));
        sync_check_same([$before], sync_rows($pdo));
    });
}

sync_run_case('Pause entfernt offene künftige Aufgaben und bewahrt überfällige', function (): void {
    $pdo = sync_fixture();
    reinigungsplan_sync_aufgaben($pdo, sync_date('2026-10-14'));
    $overdue = sync_by_date($pdo, '2026-10-16');
    $pdo->exec('UPDATE reinigungsplaene SET aktiv = 0 WHERE id = 5');
    $result = reinigungsplan_sync_aufgaben($pdo, sync_date('2026-10-17'));
    sync_check_same([$overdue], sync_rows($pdo));
    sync_check_same(2, $result['deleted']);
});

sync_run_case('Verkürztes Ende bereinigt künftige Termine und bewahrt überfällige', function (): void {
    $pdo = sync_fixture();
    reinigungsplan_sync_aufgaben($pdo, sync_date('2026-10-14'));
    $overdue = sync_by_date($pdo, '2026-10-16');
    $pdo->exec("UPDATE reinigungsplaene SET enddatum = '2026-10-22' WHERE id = 5");
    reinigungsplan_sync_aufgaben($pdo, sync_date('2026-10-17'));
    sync_check_same([$overdue], sync_rows($pdo));
});

sync_run_case('Abgelaufener Plan erzeugt nichts Neues und behält offene Rückstände', function (): void {
    $pdo = sync_fixture();
    reinigungsplan_sync_aufgaben($pdo, sync_date('2026-10-14'));
    $before = sync_rows($pdo);
    reinigungsplan_sync_aufgaben($pdo, sync_date('2026-11-02'));
    sync_check_same($before, sync_rows($pdo));
});

sync_run_case('Planänderung schreibt bereits überfällige Aufgaben nicht um', function (): void {
    $pdo = sync_fixture();
    reinigungsplan_sync_aufgaben($pdo, sync_date('2026-10-14'));
    $overdue = sync_by_date($pdo, '2026-10-16');
    sync_reorder($pdo, [19, 7, 11]);
    reinigungsplan_sync_aufgaben($pdo, sync_date('2026-10-17'));
    sync_check_same($overdue, sync_by_date($pdo, '2026-10-16'));
    sync_check_same(7, sync_by_date($pdo, '2026-10-23')['gruppe_id']);
});

sync_run_case('Verschobener Start entfernt ungültige künftige Zuweisungen', function (): void {
    $pdo = sync_fixture();
    reinigungsplan_sync_aufgaben($pdo, sync_date('2026-10-14'));
    $pdo->exec("UPDATE reinigungsplaene SET startdatum = '2026-10-24' WHERE id = 5");
    reinigungsplan_sync_aufgaben($pdo, sync_date('2026-10-14'));
    sync_check_same(['2026-10-30'], array_column(sync_rows($pdo), 'quelle_datum'));
    sync_check_same(7, sync_by_date($pdo, '2026-10-30')['gruppe_id']);
});

sync_run_case('Mehrere Pläne besitzen getrennte Aufgabenquellen am selben Freitag', function (): void {
    $pdo = sync_fixture();
    sync_insert_plan($pdo, [
        'id' => 6, 'titel' => 'Waschküche', 'details' => 'Böden wischen.',
        'startdatum' => '2026-10-12', 'enddatum' => '2026-11-01',
        'intervall' => 1, 'einheit' => 'wochen', 'aktiv' => 1,
    ]);
    reinigungsplan_sync_aufgaben($pdo, sync_date('2026-10-14'));
    sync_check_same(3, count(sync_rows($pdo, 'reinigungsplan_5')));
    sync_check_same(3, count(sync_rows($pdo, 'reinigungsplan_6')));
    $otherPlan = sync_rows($pdo, 'reinigungsplan_6');
    $pdo->exec('UPDATE reinigungsplaene SET aktiv = 0 WHERE id = 5');
    reinigungsplan_sync_aufgaben($pdo, sync_date('2026-10-14'));
    sync_check_same([], sync_rows($pdo, 'reinigungsplan_5'));
    sync_check_same($otherPlan, sync_rows($pdo, 'reinigungsplan_6'));
});

sync_run_case('Bereinigung berührt weder manuelle Aufgaben noch andere Aufgabenquellen', function (): void {
    $pdo = sync_fixture();
    $insert = $pdo->prepare('INSERT INTO aufgaben (titel, faellig_am, quelle_typ, quelle_datum) VALUES (?, ?, ?, ?)');
    $insert->execute(['Fenster putzen', '2026-10-23', null, null]);
    $insert->execute(['Schnee räumen', '2026-10-23', 'schnee', '2026-10-23']);
    $before = sync_rows($pdo, null);
    $pdo->exec('UPDATE reinigungsplaene SET aktiv = 0 WHERE id = 5');
    reinigungsplan_sync_aufgaben($pdo, sync_date('2026-10-14'));
    sync_check_same($before, sync_rows($pdo, null));
});

echo PHP_EOL;
if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL . PHP_EOL, $failures) . PHP_EOL);
    fwrite(STDERR, count($failures) . ' von ' . $testCount . ' Tests fehlgeschlagen.' . PHP_EOL);
    exit(1);
}
echo 'Alle ' . $testCount . ' Tests erfolgreich.' . PHP_EOL;
