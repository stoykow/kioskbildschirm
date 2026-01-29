<?php
// Abfalltermine aus ICS in die DB schreiben

header('Content-Type: text/plain; charset=utf-8');

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: '';
$dbUser = getenv('DB_USER') ?: '';
$dbPass = getenv('DB_PASS') ?: '';

if ($dbName === '' || $dbUser === '') {
    http_response_code(500);
    echo "DB env vars missing (DB_NAME/DB_USER).\n";
    exit;
}

$file = __DIR__ . '/Entsorgungstermine.ics';
if (!file_exists($file)) {
    http_response_code(404);
    echo "ICS file not found.\n";
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo "DB connect failed.\n";
    exit;
}

$lines = file($file, FILE_IGNORE_NEW_LINES);
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
    "INSERT INTO abfall_termine (uid, datum, summary, start_time, end_time)
     VALUES (:uid, :datum, :summary, :start_time, :end_time)
     ON DUPLICATE KEY UPDATE
       datum = VALUES(datum),
       summary = VALUES(summary),
       start_time = VALUES(start_time),
       end_time = VALUES(end_time)"
);

$taskUpsert = $pdo->prepare(
    "INSERT INTO aufgaben (titel, details, faellig_am, gruppe_id, quelle_typ, quelle_datum)
     VALUES (:titel, :details, :faellig_am, :gruppe_id, :quelle_typ, :quelle_datum)
     ON DUPLICATE KEY UPDATE
       titel = VALUES(titel),
       details = VALUES(details),
       faellig_am = VALUES(faellig_am),
       gruppe_id = VALUES(gruppe_id)"
);

$inserted = 0;
$skipped = 0;

$pdo->beginTransaction();
try {
    foreach ($blocks as $block) {
        $event = [];
        $isSchadstoffSechstaedte = false;

        foreach ($block as $line) {
            if (str_starts_with($line, 'LOCATION:GÃ¶rlitz, SechsstÃ¤dteplatz')) {
                $isSchadstoffSechstaedte = true;
            }

            if (str_starts_with($line, 'UID:')) {
                $event['uid'] = substr($line, 4);
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
        if (str_starts_with($summaryLower, 'bio')) {
            $skipped++;
            continue;
        }

        if (str_starts_with($event['summary'], 'Schadstoffe') && !$isSchadstoffSechstaedte) {
            $skipped++;
            continue;
        }

        if ($isSchadstoffSechstaedte && str_starts_with($event['summary'], 'Schadstoffe')) {
            $event['summary'] = 'Schadstoffmobil SechsstÃ¤dteplatz';
        }

        $upsert->execute([
            ':uid' => $event['uid'],
            ':datum' => $event['date'],
            ':summary' => $event['summary'],
            ':start_time' => $event['start'] ?? null,
            ':end_time' => $event['end'] ?? null,
        ]);

        $summaryLower = mb_strtolower($event['summary']);
        if (str_starts_with($summaryLower, 'rest')) {
            $taskUpsert->execute([
                ':titel' => 'Restmuell rausstellen',
                ':details' => 'Bitte Restmuell rechtzeitig bereitstellen',
                ':faellig_am' => $event['date'],
                ':gruppe_id' => null,
                ':quelle_typ' => 'restmuell',
                ':quelle_datum' => $event['date'],
            ]);
        }
        $inserted++;
    }

    $cutoff = (new DateTimeImmutable('today'))->modify('-30 days')->format('Y-m-d');
    $cleanup = $pdo->prepare("DELETE FROM abfall_termine WHERE datum < :cutoff");
    $cleanup->execute([':cutoff' => $cutoff]);

    $pdo->commit();
    echo "OK. Upserted {$inserted}, skipped {$skipped}.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo "Error: " . $e->getMessage() . "\n";
}
