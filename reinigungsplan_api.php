<?php
// Nur lesen: Die API legt keine Tabellen oder Aufgaben an.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/reinigungsplan.php';

try {
    $plans = [];
    foreach (reinigungsplan_load(db_connect()) as $plan) {
        $groups = [];
        foreach ($plan['gruppen'] as $group) {
            $groups[] = [
                'id' => $group['id'],
                'name' => $group['name'],
                'active' => $group['aktiv'] === 1,
                'position' => $group['position'],
            ];
        }
        $periods = [];
        foreach ($plan['perioden'] as $period) {
            $periods[] = [
                'start' => $period['von'],
                'end' => $period['bis'],
                'group_id' => $period['gruppe_id'],
                'group_name' => $period['gruppe_name'],
                'current' => $period['aktuell'],
                'status' => $period['status'],
            ];
        }
        $plans[] = [
            'id' => $plan['id'],
            'title' => $plan['titel'],
            'details' => $plan['details'],
            'start_date' => $plan['startdatum'],
            'interval' => $plan['intervall'],
            'unit' => $plan['einheit'],
            'active' => $plan['aktiv'] === 1,
            'groups' => $groups,
            'periods' => $periods,
        ];
    }
    echo json_encode(['plans' => $plans], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    if (reinigungsplan_schema_missing($error)) {
        http_response_code(503);
        $response = [
            'error' => 'Die Datenbank für Reinigungspläne ist noch nicht eingerichtet. Bitte die Datenbankmigration ausführen.',
            'code' => 'schema_missing',
        ];
    } else {
        http_response_code(500);
        $response = [
            'error' => 'Die Reinigungspläne konnten nicht geladen werden.',
            'code' => 'load_failed',
        ];
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
}
