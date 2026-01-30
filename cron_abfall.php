<?php
// Cron: Abfalltermine online laden und in DB schreiben

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/config.php';

// Einstellungen (true/false)
$load_bio = false;
$load_restmuell = true;
$load_gelbe = true;
$load_papier = true;
$load_schadstoff = true;

// Nur diese Schadstoffmobil-Standorte (leer = alle)
$schadstoff_locations = [
    'Goerlitz, Sechsstaedteplatz',
];

$icsUrl = 'https://www.abfall-eglz.de/abfallkalender.html?ort=G%C3%B6rlitz&strasse=Lutherstra%C3%9Fe&ortsteil=&ics=1';

// DB via config.php

$context = stream_context_create([
    'http' => [
        'timeout' => 15,
        'user_agent' => 'hausordnung-abfall/1.0',
    ],
]);
$ics = @file_get_contents($icsUrl, false, $context);
if ($ics === false) {
    http_response_code(502);
    echo "ICS fetch failed.\n";
    exit;
}

try {
    $pdo = db_connect();
} catch (RuntimeException $e) {
    http_response_code(500);
    echo $e->getMessage() . "\n";
    exit;
} catch (PDOException $e) {
    http_response_code(500);
    echo "DB connect failed.\n";
    exit;
}

$lines = explode("\n", $ics);
$blocks = [];
$inEvent = false;
$current = [];

foreach ($lines as $line) {
    $line = trim($line);
    if ($line === 'BEGIN:VEVENT') {
        $inEvent = true;
        $current = [$line];
    } elseif ($line === 'END:VEVENT' && $inEvent) {
        $current[] = $line;
        $blocks[] = $current;
        $inEvent = false;
        $current = [];
    } elseif ($inEvent) {
        $current[] = $line;
    }
}

$upsert = $pdo->prepare(
    "INSERT INTO termine_abfall (uid, datum, summary, start_time, end_time)
     VALUES (:uid, :datum, :summary, :start_time, :end_time)
     ON DUPLICATE KEY UPDATE
       datum = VALUES(datum),
       summary = VALUES(summary),
       start_time = VALUES(start_time),
       end_time = VALUES(end_time)"
);

$taskSelect = $pdo->prepare(
    "SELECT id
     FROM aufgaben
     WHERE quelle_typ = :quelle_typ AND quelle_datum = :quelle_datum
     LIMIT 1"
);
$taskInsert = $pdo->prepare(
    "INSERT INTO aufgaben (titel, details, faellig_am, gruppe_id, quelle_typ, quelle_datum)
     VALUES (:titel, :details, :faellig_am, :gruppe_id, :quelle_typ, :quelle_datum)"
);
$taskUpdate = $pdo->prepare(
    "UPDATE aufgaben
     SET titel = :titel,
         details = :details,
         faellig_am = :faellig_am,
         gruppe_id = :gruppe_id
     WHERE id = :id"
);

$inserted = 0;
$skipped = 0;

function normalize_location($value) {
    $value = is_string($value) ? $value : '';
    if (function_exists('mb_strtolower')) {
        $value = mb_strtolower($value, 'UTF-8');
    } else {
        $value = strtolower($value);
    }
    return strtr($value, [
        'ä' => 'ae',
        'ö' => 'oe',
        'ü' => 'ue',
        'ß' => 'ss',
        'Ä' => 'ae',
        'Ö' => 'oe',
        'Ü' => 'ue',
    ]);
}

$pdo->beginTransaction();
try {
    foreach ($blocks as $block) {
        $event = [];
        $location = '';

        foreach ($block as $line) {
            if (str_starts_with($line, 'UID:')) {
                $event['uid'] = substr($line, 4);
            } elseif (str_starts_with($line, 'LOCATION:')) {
                $location = substr($line, 9);
            } elseif (str_starts_with($line, 'DTSTART;TZID=')) {
                $rawDateTime = substr($line, strpos($line, ':') + 1);
                $dt = DateTime::createFromFormat('Ymd\\THis', $rawDateTime);
                if ($dt !== false) {
                    $event['date'] = $dt->format('Y-m-d');
                    $event['start'] = $dt->format('H:i:s');
                }
            } elseif (str_starts_with($line, 'DTEND;TZID=')) {
                $rawDateTime = substr($line, strpos($line, ':') + 1);
                $dt = DateTime::createFromFormat('Ymd\\THis', $rawDateTime);
                if ($dt !== false) {
                    $event['end'] = $dt->format('H:i:s');
                }
            } elseif (str_starts_with($line, 'DTSTART;VALUE=DATE:')) {
                $rawDate = substr($line, 19);
                $dt = DateTime::createFromFormat('Ymd', $rawDate);
                if ($dt !== false) {
                    $event['date'] = $dt->format('Y-m-d');
                }
            } elseif (str_starts_with($line, 'SUMMARY:') && !isset($event['summary'])) {
                $event['summary'] = substr($line, 8);
            }
        }

        if (empty($event['uid']) || empty($event['date']) || empty($event['summary'])) {
            $skipped++;
            continue;
        }

        $summaryLower = mb_strtolower($event['summary']);

        if (!$load_bio && str_starts_with($summaryLower, 'bio')) {
            $skipped++;
            continue;
        }
        if (!$load_restmuell && str_starts_with($summaryLower, 'rest')) {
            $skipped++;
            continue;
        }
        if (!$load_gelbe && (str_contains($summaryLower, 'gelb') || str_contains($summaryLower, 'gelbe'))) {
            $skipped++;
            continue;
        }
        if (!$load_papier && (str_contains($summaryLower, 'papier') || str_contains($summaryLower, 'blau'))) {
            $skipped++;
            continue;
        }
        if (str_starts_with($summaryLower, 'schadstoffe')) {
            if (!$load_schadstoff) {
                $skipped++;
                continue;
            }
            if (!empty($schadstoff_locations)) {
                $normalizedLocation = normalize_location($location);
                $allowed = array_map('normalize_location', $schadstoff_locations);
                if (!in_array($normalizedLocation, $allowed, true)) {
                    $skipped++;
                    continue;
                }
            }
            $event['summary'] = 'Schadstoffmobil Sechsstädteplatz';
        }

        $upsert->execute([
            ':uid' => $event['uid'],
            ':datum' => $event['date'],
            ':summary' => $event['summary'],
            ':start_time' => $event['start'] ?? null,
            ':end_time' => $event['end'] ?? null,
        ]);

        if ($load_restmuell && str_starts_with($summaryLower, 'rest')) {
            $taskParams = [
                ':titel' => 'Restmuell rausstellen',
                ':details' => 'Bitte Restmuell rechtzeitig bereitstellen',
                ':faellig_am' => $event['date'],
                ':gruppe_id' => null,
                ':quelle_typ' => 'restmuell',
                ':quelle_datum' => $event['date'],
            ];
            $taskSelect->execute([
                ':quelle_typ' => $taskParams[':quelle_typ'],
                ':quelle_datum' => $taskParams[':quelle_datum'],
            ]);
            $existingTaskId = $taskSelect->fetchColumn();
            if ($existingTaskId) {
                $taskUpdate->execute([
                    ':id' => $existingTaskId,
                    ':titel' => $taskParams[':titel'],
                    ':details' => $taskParams[':details'],
                    ':faellig_am' => $taskParams[':faellig_am'],
                    ':gruppe_id' => $taskParams[':gruppe_id'],
                ]);
            } else {
                $taskInsert->execute($taskParams);
            }
        }

        $inserted++;
    }

    $cutoff = (new DateTimeImmutable('today'))->modify('-30 days')->format('Y-m-d');
    $cleanup = $pdo->prepare("DELETE FROM termine_abfall WHERE datum < :cutoff");
    $cleanup->execute([':cutoff' => $cutoff]);
    $cleanupTasks = $pdo->prepare(
        "DELETE a1
         FROM aufgaben a1
         JOIN aufgaben a2
           ON a1.quelle_typ = a2.quelle_typ
          AND a1.quelle_datum = a2.quelle_datum
          AND a1.id > a2.id
         WHERE a1.quelle_typ = 'restmuell'
           AND a1.quelle_datum IS NOT NULL"
    );
    $cleanupTasks->execute();

    $pdo->commit();
    echo "OK. Upserted {$inserted}, skipped {$skipped}.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo "Error: " . $e->getMessage() . "\n";
}

