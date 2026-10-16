<?php
declare(strict_types=1);

// Aufruf: php tests/abfahrten_test.php
require_once dirname(__DIR__) . '/abfahrten_helper.php';

date_default_timezone_set('UTC');
$failures = [];
$testCount = 0;

function departure_check_same($expected, $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            'Erwartet: ' . var_export($expected, true) . PHP_EOL .
            'Erhalten: ' . var_export($actual, true)
        );
    }
}

function departure_check_throws(callable $callback, string $expectedClass = Throwable::class): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        if (!$error instanceof $expectedClass) {
            throw new RuntimeException('Erwartet wurde ' . $expectedClass . ', erhalten wurde ' . get_class($error) . '.');
        }
        return;
    }
    throw new RuntimeException('Fehlerhafte Fahrplandaten wurden nicht abgewiesen.');
}

function departure_run_case(string $name, callable $test): void
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

function departure_fixture(array $overrides = []): array
{
    return array_replace([
        'Id' => 'von:32002: :H:j26',
        'LineName' => '2',
        'Direction' => 'Biesnitz Landeskrone',
        'Platform' => ['Name' => 'Ri. Biesnitz/Landeskrone', 'Type' => 'Platform'],
        'Mot' => 'Tram',
        'ScheduledTime' => '/Date(1792164540000-0000)/',
        'RealTime' => '/Date(1792164480000-0000)/',
        'State' => 'InTime',
        'CancelReasons' => [],
    ], $overrides);
}

foreach ([
    '/Date(1792164540000-0000)/',
    '/Date(1792164540000+0200)/',
    '/Date(1792164540000)/',
    '2026-10-16T15:29:00Z',
    '2026-10-16T17:29:00+02:00',
    '2026-10-16 17:29:00',
] as $time) {
    departure_run_case('Zeit wird unabhängig von Serverzeitzone in Berlin gelesen: ' . $time, function () use ($time): void {
        $parsed = abfahrten_parse_time($time);
        departure_check_same(true, $parsed instanceof DateTimeImmutable);
        departure_check_same('2026-10-16 17:29:00 +02:00', $parsed->format('Y-m-d H:i:s P'));
        departure_check_same('Europe/Berlin', $parsed->getTimezone()->getName());
    });
}

foreach ([
    ['2026-03-29T00:59:00Z', '2026-03-29 01:59:00 +01:00'],
    ['2026-03-29T01:00:00Z', '2026-03-29 03:00:00 +02:00'],
    ['2026-10-25T00:30:00Z', '2026-10-25 02:30:00 +02:00'],
    ['2026-10-25T01:30:00Z', '2026-10-25 02:30:00 +01:00'],
] as [$time, $expected]) {
    departure_run_case('Zeitumstellung bleibt eindeutig: ' . $time, function () use ($time, $expected): void {
        departure_check_same($expected, abfahrten_parse_time($time)->format('Y-m-d H:i:s P'));
    });
}

departure_run_case('Fehlende Zeit bleibt null', function (): void {
    departure_check_same(null, abfahrten_parse_time(null));
    departure_check_same(null, abfahrten_parse_time(''));
});

foreach (['kein Zeitpunkt', '/Date(ungültig)/', '2026-02-30T12:00:00Z', '2026-10-16T25:00:00Z'] as $invalidTime) {
    departure_run_case('Ungültige Zeit wird abgewiesen: ' . $invalidTime, function () use ($invalidTime): void {
        departure_check_throws(function () use ($invalidTime): void {
            abfahrten_parse_time($invalidTime);
        }, UnexpectedValueException::class);
    });
}

departure_run_case('Straßenbahn aus offizieller Antwort wird mit früherer Echtzeit normalisiert', function (): void {
    $departure = abfahrten_normalize_vvo_departure(departure_fixture(), '14908970');
    foreach ([
        'source_stop_id' => '14908970',
        'line_name' => '2',
        'mode' => 'tram',
        'product' => 'tram',
        'planned_when' => '2026-10-16 17:29:00',
        'when' => '2026-10-16 17:28:00',
        'direction' => 'Biesnitz Landeskrone',
        'platform' => 'Ri. Biesnitz/Landeskrone',
        'delay_seconds' => -60,
        'cancelled' => false,
    ] as $field => $expected) {
        departure_check_same($expected, $departure[$field]);
    }
    departure_check_same(true, isset($departure['journey_id']) && $departure['journey_id'] !== '');
});

departure_run_case('Verspäteter Zug behält Gleis und reale Verspätung', function (): void {
    $entry = departure_fixture([
        'LineName' => 'RB65', 'Mot' => 'Train', 'Direction' => 'Zittau Bahnhof',
        'Platform' => ['Name' => '8', 'Type' => 'Railtrack'],
        'ScheduledTime' => '/Date(1792163880000-0000)/',
        'RealTime' => '/Date(1792164420000-0000)/', 'State' => 'Delayed',
    ]);
    $departure = abfahrten_normalize_vvo_departure($entry, '14908885');
    departure_check_same('train', $departure['mode']);
    departure_check_same('train', $departure['product']);
    departure_check_same('RB65', $departure['line_name']);
    departure_check_same('8', $departure['platform']);
    departure_check_same(540, $departure['delay_seconds']);
    departure_check_same('2026-10-16 17:18:00', $departure['planned_when']);
    departure_check_same('2026-10-16 17:27:00', $departure['when']);
});

foreach (['CityBus', 'IntercityBus', 'ClockBus'] as $mot) {
    departure_run_case('Bus-Verkehrsart wird als Bus normalisiert: ' . $mot, function () use ($mot): void {
        $departure = abfahrten_normalize_vvo_departure(departure_fixture(['Mot' => $mot, 'LineName' => '67']), '14908886');
        departure_check_same('bus', $departure['mode']);
        departure_check_same('bus', $departure['product']);
        departure_check_same('67', $departure['line_name']);
    });
}

departure_run_case('Fahrplan ohne Echtzeit bleibt mit Planzeit und fehlendem Bahnsteig nutzbar', function (): void {
    $entry = departure_fixture();
    unset($entry['RealTime'], $entry['Platform']);
    $departure = abfahrten_normalize_vvo_departure($entry, '14908970');
    departure_check_same('2026-10-16 17:29:00', $departure['when']);
    departure_check_same(0, $departure['delay_seconds']);
    departure_check_same(null, $departure['platform']);
});

foreach ([
    ['State' => 'Cancelled'],
    ['State' => 'Canceled'],
    ['CancelReasons' => [['Reason' => 'Fahrt fällt aus.']]],
] as $index => $cancelledFields) {
    departure_run_case('Ausfall bleibt erhalten: Variante ' . ($index + 1), function () use ($cancelledFields): void {
        $departure = abfahrten_normalize_vvo_departure(departure_fixture($cancelledFields), '14908970');
        departure_check_same(true, $departure['cancelled']);
    });
}

departure_run_case('Wiederverwendete Linienlauf-ID unterscheidet einzelne Abfahrten und Haltestellen', function (): void {
    $entry = departure_fixture();
    $first = abfahrten_normalize_vvo_departure($entry, '14908970');
    $same = abfahrten_normalize_vvo_departure($entry, '14908970');
    $later = abfahrten_normalize_vvo_departure(array_replace($entry, ['ScheduledTime' => '2026-10-16T17:49:00+02:00']), '14908970');
    $elsewhere = abfahrten_normalize_vvo_departure($entry, '14908886');
    departure_check_same($first['journey_id'], $same['journey_id']);
    departure_check_same(3, count(array_unique([$first['journey_id'], $later['journey_id'], $elsewhere['journey_id']])));
});

departure_run_case('Verspätung über doppelte Herbststunde zählt echte Sekunden', function (): void {
    $entry = departure_fixture([
        'ScheduledTime' => '2026-10-25T00:30:00Z',
        'RealTime' => '2026-10-25T01:30:00Z',
    ]);
    $departure = abfahrten_normalize_vvo_departure($entry, '14908970');
    departure_check_same(3600, $departure['delay_seconds']);
});

$tramStop = ['source_stop_ids' => ['14908970'], 'lines' => ['2'], 'kind' => 'tram'];
$busStop = ['source_stop_ids' => ['14908886'], 'lines' => ['67', 'B', 'N'], 'kind' => 'bus'];
$trainStop = ['source_stop_ids' => ['14908885'], 'kind' => 'train'];

departure_run_case('Linie 2 wird nur an der konfigurierten Straßenbahnhaltestelle angezeigt', function () use ($tramStop): void {
    $departure = abfahrten_normalize_vvo_departure(departure_fixture(), '14908970');
    departure_check_same(true, abfahrten_departure_matches_stop($departure, $tramStop));
    departure_check_same(false, abfahrten_departure_matches_stop(array_replace($departure, ['line_name' => '1']), $tramStop));
    departure_check_same(false, abfahrten_departure_matches_stop(array_replace($departure, ['source_stop_id' => '14908886']), $tramStop));
    departure_check_same(false, abfahrten_departure_matches_stop(array_replace($departure, ['mode' => 'bus', 'product' => 'bus']), $tramStop));
});

departure_run_case('Busfilter erlaubt 67, B und N und schließt benachbarte Linien aus', function () use ($busStop): void {
    $departure = abfahrten_normalize_vvo_departure(departure_fixture(['Mot' => 'ClockBus', 'LineName' => '67']), '14908886');
    foreach (['67', 'B', 'N'] as $line) {
        departure_check_same(true, abfahrten_departure_matches_stop(array_replace($departure, ['line_name' => $line]), $busStop));
    }
    departure_check_same(false, abfahrten_departure_matches_stop(array_replace($departure, ['line_name' => '62']), $busStop));
    departure_check_same(false, abfahrten_departure_matches_stop(array_replace($departure, ['source_stop_id' => '14908885']), $busStop));
    departure_check_same(false, abfahrten_departure_matches_stop(array_replace($departure, ['mode' => 'tram', 'product' => 'tram']), $busStop));
});

departure_run_case('Bahnfilter erlaubt Züge und Ersatzverkehr und schließt reguläre Stadtbusse aus', function () use ($trainStop): void {
    $train = abfahrten_normalize_vvo_departure(departure_fixture(['Mot' => 'Train', 'LineName' => 'RB65']), '14908885');
    departure_check_same(true, abfahrten_departure_matches_stop($train, $trainStop));
    foreach (['SEV', 'EV'] as $line) {
        $replacement = array_replace($train, ['mode' => 'bus', 'product' => 'bus', 'line_name' => $line]);
        departure_check_same(true, abfahrten_departure_matches_stop($replacement, $trainStop));
    }
    departure_check_same(false, abfahrten_departure_matches_stop(array_replace($train, ['mode' => 'bus', 'product' => 'bus', 'line_name' => 'B']), $trainStop));
    departure_check_same(false, abfahrten_departure_matches_stop(array_replace($train, ['mode' => 'tram', 'product' => 'tram', 'line_name' => '1']), $trainStop));
});

departure_run_case('Haltestellenkonfiguration verbindet bekannte DB-IDs mit bestätigten VVO-IDs', function (): void {
    $stops = abfahrten_stops();
    departure_check_same(['8010131', '977263', '977198'], array_column($stops, 'external_id'));
    departure_check_same(['14908885', '14908970', '14908886'], array_column($stops, 'vvo_id'));
    departure_check_same(['train', 'tram', 'bus'], array_column($stops, 'kind'));
    departure_check_same(true, in_array('ClockBus', $stops[2]['mot'], true));
    departure_check_same(true, in_array('67', $stops[2]['lines'], true));
});

departure_run_case('Erfolgreiche VVO-Antwort wird dekodiert', function (): void {
    $json = json_encode(['Status' => ['Code' => 'Ok'], 'Departures' => [departure_fixture()]], JSON_THROW_ON_ERROR);
    $departures = abfahrten_decode_vvo($json, '14908970');
    departure_check_same(1, count($departures));
    departure_check_same('2', $departures[0]['line_name']);
    departure_check_same('14908970', $departures[0]['source_stop_id']);
});

departure_run_case('Erfolgreiche leere Fahrplantafel ist zulässig', function (): void {
    departure_check_same([], abfahrten_decode_vvo('{"Status":{"Code":"Ok"},"Departures":[]}', '14908970'));
});

foreach ([
    'kein JSON',
    '{"Status":{"Code":"Error"},"Departures":[]}',
    '{"Status":{"Code":"Ok"}}',
    '{"Status":{"Code":"Ok"},"Departures":null}',
] as $invalidJson) {
    departure_run_case('Ungültige VVO-Antwort wird abgewiesen: ' . $invalidJson, function () use ($invalidJson): void {
        departure_check_throws(function () use ($invalidJson): void {
            abfahrten_decode_vvo($invalidJson, '14908970');
        });
    });
}

departure_run_case('Ungültiges Fahrtdatum wird nicht als leere Fahrplantafel verschluckt', function (): void {
    $json = json_encode([
        'Status' => ['Code' => 'Ok'],
        'Departures' => [departure_fixture(['ScheduledTime' => 'kein Zeitpunkt', 'RealTime' => null])],
    ], JSON_THROW_ON_ERROR);
    departure_check_throws(function () use ($json): void {
        abfahrten_decode_vvo($json, '14908970');
    });
});

echo PHP_EOL;
if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL . PHP_EOL, $failures) . PHP_EOL);
    fwrite(STDERR, count($failures) . ' von ' . $testCount . ' Tests fehlgeschlagen.' . PHP_EOL);
    exit(1);
}
echo 'Alle ' . $testCount . ' Tests erfolgreich.' . PHP_EOL;
