<?php
// Offene Aufgaben einschließlich der freitags fälligen Hausordnung laden.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/reinigungsplan_aufgaben.php';

try {
    $pdo = db_connect();
    $timezone = new DateTimeZone('Europe/Berlin');
    $now = new DateTimeImmutable('now', $timezone);
    reinigungsplan_sync_aufgaben($pdo, $now);

    $rows = $pdo->query(
        "SELECT a.id, a.titel, a.details, a.faellig_am, a.gruppe_id,
            g.name AS gruppe_name, UNIX_TIMESTAMP(a.erledigt_am) AS erledigt_timestamp,
            a.quelle_typ, a.quelle_datum, erledigungen.erledigt_namen
         FROM aufgaben a
         LEFT JOIN benutzer_gruppen g ON g.id = a.gruppe_id
         LEFT JOIN (
             SELECT ae.aufgabe_id, GROUP_CONCAT(b.name ORDER BY b.name SEPARATOR ', ') AS erledigt_namen
             FROM aufgaben_erledigt ae
             JOIN benutzer b ON b.id = ae.benutzer_id
             GROUP BY ae.aufgabe_id
         ) erledigungen ON erledigungen.aufgabe_id = a.id
         WHERE a.erledigt_am IS NULL
            OR a.erledigt_am >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 10 MINUTE)
         ORDER BY (a.erledigt_am IS NULL) DESC, (a.faellig_am IS NULL) ASC,
             a.faellig_am ASC, a.id ASC"
    )->fetchAll();

    $tasks = [];
    $otherTasks = 0;
    foreach ($rows as $row) {
        $sourceType = (string)($row['quelle_typ'] ?? '');
        $isCleaning = str_starts_with($sourceType, 'reinigungsplan_');
        $showFrom = null;
        if ($row['faellig_am']) {
            $due = new DateTimeImmutable($row['faellig_am'], $timezone);
            if ($isCleaning) {
                $showFrom = $due->modify('monday this week')->setTime(0, 0);
            } elseif (str_ends_with(strtolower($sourceType), '_rein')) {
                $showFrom = $due->setTime(6, 0);
            } else {
                $showFrom = $due->modify('-1 day')->setTime(0, 0);
            }
        }
        $doneAt = $row['erledigt_timestamp'] !== null
            ? (new DateTimeImmutable('@' . $row['erledigt_timestamp']))->setTimezone($timezone)->format('c')
            : null;
        if ($doneAt === null && $showFrom !== null && $now < $showFrom) {
            continue;
        }
        // Reinigung bleibt auch bei vielen anderen offenen Aufgaben sichtbar.
        // Andere Aufgaben behalten die bisherige Begrenzung auf acht Einträge.
        if (!$isCleaning && $otherTasks >= 8) {
            continue;
        }
        if (!$isCleaning) {
            $otherTasks++;
        }
        $tasks[] = [
            'id' => (int)$row['id'],
            'title' => $row['titel'],
            'details' => $row['details'],
            'due' => $row['faellig_am'],
            'show_from' => $showFrom?->format('c'),
            'group_id' => $row['gruppe_id'] !== null ? (int)$row['gruppe_id'] : null,
            'group' => $row['gruppe_name'],
            'done_at' => $doneAt,
            'done_by' => $row['erledigt_namen'],
            'source_type' => $row['quelle_typ'],
            'source_date' => $row['quelle_datum'],
        ];
    }
    echo json_encode(['tasks' => $tasks], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('Aufgaben konnten nicht geladen werden: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Die Aufgaben konnten nicht geladen werden.'], JSON_UNESCAPED_UNICODE);
}
