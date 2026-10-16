<?php
declare(strict_types=1);

// Aufruf: php tests/reinigungsplan_test.php
require_once dirname(__DIR__) . '/reinigungsplan.php';

$failures = [];
$testCount = 0;
$groups = [
    ['id' => 7, 'name' => 'Erdgeschoss'],
    ['id' => 11, 'name' => 'Obergeschoss'],
    ['id' => 19, 'name' => 'Dachgeschoss'],
];

function check_same($expected, $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            'Erwartet: ' . var_export($expected, true) . PHP_EOL .
            'Erhalten: ' . var_export($actual, true)
        );
    }
}

function check_throws(callable $callback, string $expectedClass = Throwable::class): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        if (!$error instanceof $expectedClass) {
            throw new RuntimeException('Erwartet wurde ' . $expectedClass . ', erhalten wurde ' . get_class($error) . '.');
        }
        return;
    }
    throw new RuntimeException('Ungültige Eingabe wurde nicht abgewiesen.');
}

function run_case(string $name, callable $test): void
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

function date_at(string $value): DateTimeImmutable
{
    return new DateTimeImmutable($value, new DateTimeZone('Europe/Berlin'));
}

function expected_period(string $from, string $to, int $groupId, string $groupName, bool $current): array
{
    return [
        'von' => $from,
        'bis' => $to,
        'gruppe_id' => $groupId,
        'gruppe_name' => $groupName,
        'aktuell' => $current,
        'status' => $current ? 'current' : 'upcoming',
    ];
}

run_case('Datum wird in Europe/Berlin um Mitternacht gelesen', function (): void {
    $parsed = reinigungsplan_parse_date('2024-02-29');
    check_same(true, $parsed instanceof DateTimeImmutable);
    check_same('2024-02-29 00:00:00 Europe/Berlin', $parsed->format('Y-m-d H:i:s e'));
});

foreach (['1000-01-01', '9999-12-31'] as $boundaryDate) {
    run_case('Gültige DB-Datumsgrenze: ' . $boundaryDate, function () use ($boundaryDate): void {
        check_same($boundaryDate, reinigungsplan_parse_date($boundaryDate)->format('Y-m-d'));
    });
}

foreach (['', '0000-01-01', '0001-01-01', '0999-12-31', '10000-01-01', '2026-02-30', '2025-02-29', '2026-13-01', '2026-00-01', '2026-10-00', '2026-1-01', '16.10.2026', '2026-10-16 extra'] as $invalidDate) {
    run_case('Ungültiges Datum: ' . var_export($invalidDate, true), function () use ($invalidDate): void {
        check_throws(function () use ($invalidDate): void {
            reinigungsplan_parse_date($invalidDate);
        });
    });
}

$weekly = ['startdatum' => '2026-10-12', 'intervall' => 1, 'einheit' => 'wochen'];

run_case('Starttag, inklusive Endtage und zyklische Gruppenfolge', function () use ($weekly, $groups): void {
    check_same([
        expected_period('2026-10-12', '2026-10-18', 7, 'Erdgeschoss', true),
        expected_period('2026-10-19', '2026-10-25', 11, 'Obergeschoss', false),
        expected_period('2026-10-26', '2026-11-01', 19, 'Dachgeschoss', false),
        expected_period('2026-11-02', '2026-11-08', 7, 'Erdgeschoss', false),
    ], reinigungsplan_periods($weekly, $groups, date_at('2026-10-12')));
});

run_case('Am letzten Tag bleibt die bisherige Gruppe zuständig', function () use ($weekly, $groups): void {
    $periods = reinigungsplan_periods($weekly, $groups, date_at('2026-10-18 23:59:59'), 1);
    check_same([
        expected_period('2026-10-12', '2026-10-18', 7, 'Erdgeschoss', true),
    ], $periods);
});

run_case('Um Mitternacht wechselt die Zuständigkeit', function () use ($weekly, $groups): void {
    check_same([
        expected_period('2026-10-19', '2026-10-25', 11, 'Obergeschoss', true),
        expected_period('2026-10-26', '2026-11-01', 19, 'Dachgeschoss', false),
    ], reinigungsplan_periods($weekly, $groups, date_at('2026-10-19 00:00:00'), 2));
});

run_case('Vor Planbeginn sind die ersten vier Zeiträume zukünftig', function () use ($weekly, $groups): void {
    check_same([
        expected_period('2026-10-12', '2026-10-18', 7, 'Erdgeschoss', false),
        expected_period('2026-10-19', '2026-10-25', 11, 'Obergeschoss', false),
        expected_period('2026-10-26', '2026-11-01', 19, 'Dachgeschoss', false),
        expected_period('2026-11-02', '2026-11-08', 7, 'Erdgeschoss', false),
    ], reinigungsplan_periods($weekly, $groups, date_at('2026-10-11 23:59:59')));
});

$finiteWeekly = array_replace($weekly, ['enddatum' => '2026-11-01']);

run_case('Enddatum begrenzt die Vorschau vor Beginn auf genau drei Wochen', function () use ($finiteWeekly, $groups): void {
    check_same([
        expected_period('2026-10-12', '2026-10-18', 7, 'Erdgeschoss', false),
        expected_period('2026-10-19', '2026-10-25', 11, 'Obergeschoss', false),
        expected_period('2026-10-26', '2026-11-01', 19, 'Dachgeschoss', false),
    ], reinigungsplan_periods($finiteWeekly, $groups, date_at('2026-10-01'), 10));
});

run_case('Befristeter Wochenplan wiederholt nach der dritten Gruppe nicht automatisch', function () use ($finiteWeekly, $groups): void {
    check_same([
        expected_period('2026-10-12', '2026-10-18', 7, 'Erdgeschoss', true),
        expected_period('2026-10-19', '2026-10-25', 11, 'Obergeschoss', false),
        expected_period('2026-10-26', '2026-11-01', 19, 'Dachgeschoss', false),
    ], reinigungsplan_periods($finiteWeekly, $groups, date_at('2026-10-12')));
});

run_case('Am Enddatum bleibt die letzte Gruppe bis Tagesende zuständig', function () use ($finiteWeekly, $groups): void {
    check_same([
        expected_period('2026-10-26', '2026-11-01', 19, 'Dachgeschoss', true),
    ], reinigungsplan_periods($finiteWeekly, $groups, date_at('2026-11-01 23:59:59')));
});

foreach (['2026-11-02 00:00:00', '2026-12-01'] as $afterEnd) {
    run_case('Nach Ende erscheint kein weiterer Turnus: ' . $afterEnd, function () use ($finiteWeekly, $groups, $afterEnd): void {
        check_same([], reinigungsplan_periods($finiteWeekly, $groups, date_at($afterEnd)));
    });
}

run_case('Enddatum innerhalb einer Woche kürzt nur den letzten Zeitraum', function () use ($weekly, $groups): void {
    $plan = array_replace($weekly, ['enddatum' => '2026-10-28']);
    check_same([
        expected_period('2026-10-12', '2026-10-18', 7, 'Erdgeschoss', true),
        expected_period('2026-10-19', '2026-10-25', 11, 'Obergeschoss', false),
        expected_period('2026-10-26', '2026-10-28', 19, 'Dachgeschoss', false),
    ], reinigungsplan_periods($plan, $groups, date_at('2026-10-16')));
    check_same([
        expected_period('2026-10-26', '2026-10-28', 19, 'Dachgeschoss', true),
    ], reinigungsplan_periods($plan, $groups, date_at('2026-10-28 23:59:59')));
});

run_case('Gleiches Startdatum und Enddatum ergibt einen einzigen Reinigungstag', function () use ($weekly, $groups): void {
    $plan = array_replace($weekly, ['enddatum' => '2026-10-12']);
    check_same([
        expected_period('2026-10-12', '2026-10-12', 7, 'Erdgeschoss', true),
    ], reinigungsplan_periods($plan, $groups, date_at('2026-10-12 12:00:00')));
    check_same([], reinigungsplan_periods($plan, $groups, date_at('2026-10-13')));
});

run_case('Enddatum auf einem Turnusbeginn schließt diesen Tag der nächsten Gruppe ein', function () use ($weekly, $groups): void {
    $plan = array_replace($weekly, ['enddatum' => '2026-10-19']);
    check_same([
        expected_period('2026-10-12', '2026-10-18', 7, 'Erdgeschoss', false),
        expected_period('2026-10-19', '2026-10-19', 11, 'Obergeschoss', false),
    ], reinigungsplan_periods($plan, $groups, date_at('2026-10-11')));
    check_same([
        expected_period('2026-10-19', '2026-10-19', 11, 'Obergeschoss', true),
    ], reinigungsplan_periods($plan, $groups, date_at('2026-10-19 23:59:59')));
});

foreach ([null, ''] as $emptyEnd) {
    run_case('Leeres Enddatum erhält den unbegrenzten Rhythmus: ' . var_export($emptyEnd, true), function () use ($weekly, $groups, $emptyEnd): void {
        $today = date_at('2026-11-16');
        check_same(
            reinigungsplan_periods($weekly, $groups, $today),
            reinigungsplan_periods(array_replace($weekly, ['enddatum' => $emptyEnd]), $groups, $today)
        );
    });
}

foreach (['2026-10-11', '2026-02-30', '01.11.2026', '2026-13-01'] as $invalidEnd) {
    run_case('Ungültiges Enddatum wird als Validierungsfehler abgewiesen: ' . $invalidEnd, function () use ($weekly, $groups, $invalidEnd): void {
        check_throws(function () use ($weekly, $groups, $invalidEnd): void {
            reinigungsplan_periods(array_replace($weekly, ['enddatum' => $invalidEnd]), $groups, date_at('2026-10-16'));
        }, InvalidArgumentException::class);
    });
}

run_case('Befristeter Monatsplan behält Monatsendanker und inklusive Enddatum', function () use ($groups): void {
    $plan = ['startdatum' => '2025-01-31', 'enddatum' => '2025-03-31', 'intervall' => 1, 'einheit' => 'monate'];
    check_same([
        expected_period('2025-01-31', '2025-02-27', 7, 'Erdgeschoss', false),
        expected_period('2025-02-28', '2025-03-30', 11, 'Obergeschoss', false),
        expected_period('2025-03-31', '2025-03-31', 19, 'Dachgeschoss', false),
    ], reinigungsplan_periods($plan, $groups, date_at('2025-01-30')));
});

run_case('Befristeter Tagesplan endet bei Sommerzeitbeginn am lokalen Tagesende', function () use ($groups): void {
    $plan = ['startdatum' => '2026-03-28', 'enddatum' => '2026-03-29', 'intervall' => 1, 'einheit' => 'tage'];
    check_same([
        expected_period('2026-03-29', '2026-03-29', 11, 'Obergeschoss', true),
    ], reinigungsplan_periods($plan, $groups, date_at('2026-03-29 23:59:59')));
    check_same([], reinigungsplan_periods($plan, $groups, date_at('2026-03-30 00:00:00')));
});

foreach (['tage', 'wochen', 'monate'] as $unit) {
    run_case('Explizites Ende an der DB-Datumsgrenze verhindert Vorschauüberlauf: ' . $unit, function () use ($unit, $groups): void {
        $plan = ['startdatum' => '9999-12-31', 'enddatum' => '9999-12-31', 'intervall' => 1, 'einheit' => $unit];
        check_same([
            expected_period('9999-12-31', '9999-12-31', 7, 'Erdgeschoss', false),
        ], reinigungsplan_periods($plan, $groups, date_at('2026-10-16')));
        check_same([
            expected_period('9999-12-31', '9999-12-31', 7, 'Erdgeschoss', true),
        ], reinigungsplan_periods($plan, $groups, date_at('9999-12-31 23:59:59')));
    });
}

run_case('Drei Kalendertage und mehrere abgelaufene Gruppen', function () use ($groups): void {
    $plan = ['startdatum' => '2026-01-01', 'intervall' => 3, 'einheit' => 'tage'];
    check_same([
        expected_period('2026-01-07', '2026-01-09', 19, 'Dachgeschoss', true),
        expected_period('2026-01-10', '2026-01-12', 7, 'Erdgeschoss', false),
        expected_period('2026-01-13', '2026-01-15', 11, 'Obergeschoss', false),
        expected_period('2026-01-16', '2026-01-18', 19, 'Dachgeschoss', false),
    ], reinigungsplan_periods($plan, $groups, date_at('2026-01-07 12:00:00')));
});

run_case('Zwei Wochen als Rhythmus und PDO-Intervall als String', function () use ($groups): void {
    $plan = ['startdatum' => '2026-10-12', 'intervall' => '2', 'einheit' => 'wochen'];
    check_same([
        expected_period('2026-10-26', '2026-11-08', 11, 'Obergeschoss', true),
        expected_period('2026-11-09', '2026-11-22', 19, 'Dachgeschoss', false),
    ], reinigungsplan_periods($plan, $groups, date_at('2026-10-26'), 2));
});

run_case('Monatsende wird gekürzt und danach zum Startanker zurückgeführt', function () use ($groups): void {
    $plan = ['startdatum' => '2025-01-31', 'intervall' => 1, 'einheit' => 'monate'];
    check_same([
        expected_period('2025-02-28', '2025-03-30', 11, 'Obergeschoss', true),
        expected_period('2025-03-31', '2025-04-29', 19, 'Dachgeschoss', false),
        expected_period('2025-04-30', '2025-05-30', 7, 'Erdgeschoss', false),
        expected_period('2025-05-31', '2025-06-29', 11, 'Obergeschoss', false),
    ], reinigungsplan_periods($plan, $groups, date_at('2025-02-28')));
});

run_case('Tag vor verkürztem Monatsbeginn gehört zur ersten Gruppe', function () use ($groups): void {
    $plan = ['startdatum' => '2025-01-31', 'intervall' => 1, 'einheit' => 'monate'];
    check_same([
        expected_period('2025-01-31', '2025-02-27', 7, 'Erdgeschoss', true),
    ], reinigungsplan_periods($plan, $groups, date_at('2025-02-27 23:59:59'), 1));
});

run_case('Schaltjahr berücksichtigt den 29. Februar', function () use ($groups): void {
    $plan = ['startdatum' => '2024-01-31', 'intervall' => 1, 'einheit' => 'monate'];
    check_same([
        expected_period('2024-02-29', '2024-03-30', 11, 'Obergeschoss', true),
        expected_period('2024-03-31', '2024-04-29', 19, 'Dachgeschoss', false),
    ], reinigungsplan_periods($plan, $groups, date_at('2024-02-29'), 2));
});

run_case('Zweimonatiger Rhythmus bleibt am ursprünglichen Monatstag', function () use ($groups): void {
    $plan = ['startdatum' => '2025-01-31', 'intervall' => 2, 'einheit' => 'monate'];
    check_same([
        expected_period('2025-03-31', '2025-05-30', 11, 'Obergeschoss', true),
        expected_period('2025-05-31', '2025-07-30', 19, 'Dachgeschoss', false),
    ], reinigungsplan_periods($plan, $groups, date_at('2025-04-01'), 2));
});

run_case('Jahresrhythmus ab Schalttag kehrt im nächsten Schaltjahr zurück', function () use ($groups): void {
    $plan = ['startdatum' => '2024-02-29', 'intervall' => 12, 'einheit' => 'monate'];
    check_same([
        expected_period('2028-02-29', '2029-02-27', 11, 'Obergeschoss', true),
        expected_period('2029-02-28', '2030-02-27', 19, 'Dachgeschoss', false),
    ], reinigungsplan_periods($plan, $groups, date_at('2028-02-29'), 2));
});

run_case('Sommerzeitbeginn zählt lokale Kalendertage', function () use ($groups): void {
    $plan = ['startdatum' => '2026-03-28', 'intervall' => 1, 'einheit' => 'tage'];
    check_same([
        expected_period('2026-03-30', '2026-03-30', 19, 'Dachgeschoss', true),
        expected_period('2026-03-31', '2026-03-31', 7, 'Erdgeschoss', false),
    ], reinigungsplan_periods($plan, $groups, date_at('2026-03-30 00:00:00'), 2));
});

run_case('Sommerzeitende verlängert den lokalen Tag ohne vorzeitigen Wechsel', function () use ($groups): void {
    $plan = ['startdatum' => '2026-10-24', 'intervall' => 1, 'einheit' => 'tage'];
    check_same([
        expected_period('2026-10-25', '2026-10-25', 11, 'Obergeschoss', true),
        expected_period('2026-10-26', '2026-10-26', 19, 'Dachgeschoss', false),
    ], reinigungsplan_periods($plan, $groups, date_at('2026-10-25 23:59:59'), 2));
});

run_case('Gruppenreihenfolge entspricht der gespeicherten Reihenfolge', function () use ($weekly, $groups): void {
    check_same([
        expected_period('2026-10-12', '2026-10-18', 19, 'Dachgeschoss', true),
        expected_period('2026-10-19', '2026-10-25', 7, 'Erdgeschoss', false),
        expected_period('2026-10-26', '2026-11-01', 11, 'Obergeschoss', false),
    ], reinigungsplan_periods($weekly, [$groups[2], $groups[0], $groups[1]], date_at('2026-10-12'), 3));
});

run_case('Einzelne Gruppe bleibt über mehrere Zeiträume zuständig', function () use ($weekly, $groups): void {
    check_same([
        expected_period('2026-10-26', '2026-11-01', 7, 'Erdgeschoss', true),
        expected_period('2026-11-02', '2026-11-08', 7, 'Erdgeschoss', false),
    ], reinigungsplan_periods($weekly, [$groups[0]], date_at('2026-10-27'), 2));
});

foreach ([0, -1, 366, 'kein Intervall', '1.5'] as $invalidInterval) {
    run_case('Ungültiges Intervall: ' . var_export($invalidInterval, true), function () use ($weekly, $groups, $invalidInterval): void {
        $plan = array_replace($weekly, ['intervall' => $invalidInterval]);
        check_throws(function () use ($plan, $groups): void {
            reinigungsplan_periods($plan, $groups, date_at('2026-10-16'));
        });
    });
}

run_case('Unbekannte Einheit wird abgewiesen', function () use ($weekly, $groups): void {
    check_throws(function () use ($weekly, $groups): void {
        reinigungsplan_periods(array_replace($weekly, ['einheit' => 'jahre']), $groups, date_at('2026-10-16'));
    });
});

run_case('Ungültiges Startdatum im Plan wird abgewiesen', function () use ($weekly, $groups): void {
    check_throws(function () use ($weekly, $groups): void {
        reinigungsplan_periods(array_replace($weekly, ['startdatum' => '2026-02-30']), $groups, date_at('2026-10-16'));
    });
});

run_case('Plan ohne Gruppen wird abgewiesen', function () use ($weekly): void {
    check_throws(function () use ($weekly): void {
        reinigungsplan_periods($weekly, [], date_at('2026-10-16'));
    });
});

foreach (['tage', 'wochen', 'monate'] as $unit) {
    run_case('Vorschau jenseits von Jahr 9999 wird abgewiesen: ' . $unit, function () use ($unit, $groups): void {
        $plan = ['startdatum' => '9999-12-31', 'intervall' => 1, 'einheit' => $unit];
        check_throws(function () use ($plan, $groups): void {
            reinigungsplan_periods($plan, $groups, date_at('2026-10-16'));
        });
    });
}

run_case('Auch ein Überlauf erst am Ende der Vorschau wird abgewiesen', function () use ($groups): void {
    $plan = ['startdatum' => '9999-12-28', 'intervall' => 1, 'einheit' => 'tage'];
    check_throws(function () use ($plan, $groups): void {
        reinigungsplan_periods($plan, $groups, date_at('2026-10-16'));
    });
});

// Die SQL-Abfragen des Ladehelfers benötigen keine MySQL-spezifischen Funktionen.
// Jeder Test erhält seine eigene flüchtige Datenbank und berührt keine App-Daten.
function test_database(bool $withFixtures = true): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_STRINGIFY_FETCHES => true,
    ]);
    $pdo->exec('CREATE TABLE reinigungsplaene (
        id INTEGER PRIMARY KEY, titel TEXT, details TEXT, startdatum TEXT, enddatum TEXT NULL,
        intervall INTEGER, einheit TEXT, aktiv INTEGER,
        erstellt_am TEXT, aktualisiert_am TEXT
    )');
    $pdo->exec('CREATE TABLE benutzer_gruppen (id INTEGER PRIMARY KEY, name TEXT, aktiv INTEGER)');
    $pdo->exec('CREATE TABLE reinigungsplan_gruppen (plan_id INTEGER, gruppe_id INTEGER, position INTEGER)');
    if (!$withFixtures) {
        return $pdo;
    }

    $start = (new DateTimeImmutable('tomorrow', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
    $insertPlan = $pdo->prepare('INSERT INTO reinigungsplaene
        (id, titel, details, startdatum, intervall, einheit, aktiv, erstellt_am, aktualisiert_am)
        VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?)');
    foreach ([
        [30, 'Treppenhaus', 'Geländer gründlich reinigen.', 1],
        [12, 'Küche', 'Öfen, Spülen und große Flächen reinigen.', 0],
        [21, 'Eingang', 'Schmutzfänger leeren.', 1],
        [3, 'Treppenhaus', 'Oberes Stockwerk.', 1],
    ] as $plan) {
        $insertPlan->execute([$plan[0], $plan[1], $plan[2], $start, 'wochen', $plan[3], '2026-01-01 10:00:00', '2026-01-02 11:00:00']);
    }
    $insertGroup = $pdo->prepare('INSERT INTO benutzer_gruppen (id, name, aktiv) VALUES (?, ?, ?)');
    foreach ([[7, 'Großküche', 1], [19, 'Südflügel', 0], [11, 'Büro', 1]] as $group) {
        $insertGroup->execute($group);
    }
    $insertAssignment = $pdo->prepare('INSERT INTO reinigungsplan_gruppen (plan_id, gruppe_id, position) VALUES (?, ?, ?)');
    foreach ([[30, 7, 2], [21, 7, 0], [3, 11, 1], [30, 19, 0], [12, 11, 0], [3, 7, 0], [30, 11, 1]] as $assignment) {
        $insertAssignment->execute($assignment);
    }
    return $pdo;
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo '[SKIP] Sechs DB-Ladetests übersprungen: pdo_sqlite ist nicht verfügbar.' . PHP_EOL;
} else {
run_case('DB-Laden filtert standardmäßig aktive Pläne und sortiert Titel sowie ID', function (): void {
    $plans = reinigungsplan_load(test_database());
    check_same([21, 3, 30], array_column($plans, 'id'));
    check_same(['Eingang', 'Treppenhaus', 'Treppenhaus'], array_column($plans, 'titel'));
    check_same([1, 1, 1], array_column($plans, 'aktiv'));
    check_same([1, 1, 1], array_column($plans, 'intervall'));
    check_same([null, null, null], array_column($plans, 'enddatum'));
    check_same([false, false, false], array_column($plans, 'beendet'));
});

run_case('DB-Laden kann inaktive Pläne einschließen und bewahrt UTF-8-Texte', function (): void {
    $pdo = test_database();
    $pdo->exec('UPDATE reinigungsplaene SET enddatum = startdatum WHERE id = 12');
    $plans = reinigungsplan_load($pdo, false);
    check_same([21, 12, 3, 30], array_column($plans, 'id'));
    check_same('Küche', $plans[1]['titel']);
    check_same('Öfen, Spülen und große Flächen reinigen.', $plans[1]['details']);
    check_same(0, $plans[1]['aktiv']);
    check_same('2026-01-01 10:00:00', $plans[1]['erstellt_am']);
    check_same('2026-01-02 11:00:00', $plans[1]['aktualisiert_am']);
    check_same($plans[1]['startdatum'], $plans[1]['enddatum']);
    check_same(false, $plans[1]['beendet']);
    check_same(1, count($plans[1]['perioden']));
    check_same($plans[1]['enddatum'], $plans[1]['perioden'][0]['bis']);
});

run_case('DB-Laden ordnet Gruppen je Plan nach Position und behält inaktive Gruppen', function (): void {
    $plans = reinigungsplan_load(test_database());
    check_same([7], array_column($plans[0]['gruppen'], 'id'));
    check_same([7, 11], array_column($plans[1]['gruppen'], 'id'));
    check_same([
        ['id' => 19, 'name' => 'Südflügel', 'aktiv' => 0, 'position' => 0],
        ['id' => 11, 'name' => 'Büro', 'aktiv' => 1, 'position' => 1],
        ['id' => 7, 'name' => 'Großküche', 'aktiv' => 1, 'position' => 2],
    ], $plans[2]['gruppen']);
});

run_case('DB-Laden berechnet die Vorschau mit der gespeicherten Gruppenfolge', function (): void {
    $plans = reinigungsplan_load(test_database());
    $plan = $plans[2];
    check_same(4, count($plan['perioden']));
    check_same([19, 11, 7, 19], array_column($plan['perioden'], 'gruppe_id'));
    check_same(['Südflügel', 'Büro', 'Großküche', 'Südflügel'], array_column($plan['perioden'], 'gruppe_name'));
    check_same([false, false, false, false], array_column($plan['perioden'], 'aktuell'));
    check_same($plan['startdatum'], $plan['perioden'][0]['von']);
    $firstEnd = date_at($plan['startdatum'])->modify('+6 days')->format('Y-m-d');
    check_same($firstEnd, $plan['perioden'][0]['bis']);
});

run_case('DB-Laden liefert ohne aktive Pläne eine leere Liste', function (): void {
    $pdo = test_database();
    $pdo->exec('UPDATE reinigungsplaene SET aktiv = 0');
    check_same([], reinigungsplan_load($pdo));
    check_same(4, count(reinigungsplan_load($pdo, false)));
});

run_case('DB-Laden unterstützt eine noch leere Datenbank', function (): void {
    check_same([], reinigungsplan_load(test_database(false)));
});
}

echo PHP_EOL;
if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL . PHP_EOL, $failures) . PHP_EOL);
    fwrite(STDERR, count($failures) . ' von ' . $testCount . ' Tests fehlgeschlagen.' . PHP_EOL);
    exit(1);
}
echo 'Alle ' . $testCount . ' Tests erfolgreich.' . PHP_EOL;
