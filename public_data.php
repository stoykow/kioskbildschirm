<?php
// Öffentliche Antworten enthalten ausschließlich die dafür freigegebenen Daten.

function kiosk_privacy_headers(): void {
    header('Cache-Control: private, no-store');
    header('Vary: Cookie, Authorization');
    header('Referrer-Policy: no-referrer');
}

function kiosk_public_weather_payload($payload): array {
    if (!is_array($payload)) {
        return [];
    }
    $allowed = [
        'sensor.geiger_temperatur' => ['°C', 'C', '°F', 'F'],
        'sensor.geiger_luftfeuchte' => ['%'],
        'sensor.geiger_luftdruck' => ['hPa', 'Pa', 'mbar'],
        'sensor.multigeiger_geiger_dosisleistung' => ['µSv/h', 'μSv/h', 'uSv/h', 'nSv/h', 'Sv/h'],
    ];
    $result = [];
    foreach ($allowed as $entityId => $units) {
        $entity = $payload[$entityId] ?? null;
        if (!is_array($entity)) {
            continue;
        }
        $state = $entity['state'] ?? null;
        if (!is_scalar($state) || (!is_numeric($state) && !in_array($state, ['unknown', 'unavailable'], true))) {
            $state = 'unavailable';
        }
        $unit = $entity['attributes']['unit_of_measurement'] ?? null;
        $result[$entityId] = [
            'state' => $state,
            'attributes' => ['unit_of_measurement' => in_array($unit, $units, true) ? $unit : $units[0]],
        ];
    }
    return $result;
}

function kiosk_public_waste_event(array $event): array {
    return array_intersect_key($event, array_flip(['id', 'date', 'summary', 'start', 'end']));
}

function kiosk_public_appointment(array $event): array {
    return array_intersect_key($event, array_flip(['id', 'date', 'title', 'hint', 'start', 'end']));
}
