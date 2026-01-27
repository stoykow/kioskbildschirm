<?php
$file = __DIR__ . '/Entsorgungstermine.ics';

if (!file_exists($file)) {
    http_response_code(404);
    echo json_encode(["error" => "Datei nicht gefunden!"]);
    exit;
}

$data = file_get_contents($file);
$lines = explode("\n", $data);

$blocks = [];
$inEvent = false;
$currentBlock = [];

foreach ($lines as $line) {
    $line = trim($line);

    if ($line === 'BEGIN:VEVENT') {
        $inEvent = true;
        $currentBlock = [$line];
    } elseif ($line === 'END:VEVENT' && $inEvent) {
        $currentBlock[] = $line;
        $blocks[] = $currentBlock;
        $inEvent = false;
        $currentBlock = [];
    } elseif ($inEvent) {
        $currentBlock[] = $line;
    }
}

//print_r($blocks);

// Events parsen
$parsedEvents = [];

foreach ($blocks as $block) {

    $event = [];
    $isSchadstoffSechsstädte = false;

    foreach ($block as $line) {
        if (str_starts_with($line, 'LOCATION:Görlitz, Sechsstädteplatz')) {
            $isSchadstoffSechsstädte = true;
        }
        if (str_starts_with($line, 'DTSTART;TZID=')) {
            $rawDateTime = substr($line, strpos($line, ':') + 1);
            $dt = DateTime::createFromFormat('Ymd\THis', $rawDateTime);
            if ($dt !== false) {
                $event['date'] = $dt->format('Y-m-d');
                $event['start'] = $dt->format('H:i');
            }
        } elseif (str_starts_with($line, 'DTEND;TZID=')) {
            $rawDateTime = substr($line, strpos($line, ':') + 1);
            $dt = DateTime::createFromFormat('Ymd\THis', $rawDateTime);
            if ($dt !== false) {
                $event['end'] = $dt->format('H:i');
            }
        } elseif (str_starts_with($line, 'DTSTART;VALUE=DATE:')) {
            $rawDate = substr($line, 19);
            $dt = DateTime::createFromFormat('Ymd', $rawDate);
            if ($dt !== false) {
                $event['date'] = $dt->format('Y-m-d');
            }
        } elseif (str_starts_with($line, 'DTEND;VALUE=DATE:')) {
            $rawDate = substr($line, 17);
            $dt = DateTime::createFromFormat('Ymd', $rawDate);
            if ($dt !== false) {
                $event['end'] = $dt->format('Y-m-d');
            }
        } elseif (str_starts_with($line, 'SUMMARY:') && !isset($event['summary'])) {
            $event['summary'] = substr($line, 8);
        }
    }

    // Filter: Schadstoffe nur, wenn Sechsstädteplatz, und Bio-Müll komplett raus
    if (!empty($event['date']) && !empty($event['summary'])) {
        $summaryLower = mb_strtolower($event['summary']);
        if (str_starts_with($summaryLower, 'bio')) {
            continue; // Bio-Müll nicht anzeigen
        }
        if (
            (str_starts_with($event['summary'], 'Schadstoffe') && $isSchadstoffSechsstädte)
            || !str_starts_with($event['summary'], 'Schadstoffe')
        ) {
            // Nur Schadstoffmobil Sechsstädteplatz oder alle anderen regulären Termine
            if ($isSchadstoffSechsstädte && str_starts_with($event['summary'], 'Schadstoffe')) {
                $event['summary'] = 'Schadstoffmobil Sechsstädteplatz';
            }
            $parsedEvents[] = $event;
        }
    }
}


// JSON-Ausgabe zur Kontrolle
header('Content-Type: application/json');
echo json_encode($parsedEvents, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

