<?php
// Manuelle und geplante Aktualisierung verwenden denselben Import wie der Kiosk.

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/abfahrten_helper.php';

try {
    $result = abfahrten_refresh(db_connect());
    if ($result['status'] === 'failed' || $result['status'] === 'partial') {
        http_response_code(503);
        echo "Die Abfahrten konnten nicht vollständig aktualisiert werden.\n";
        if ($result['errors']) {
            echo 'Betroffene Haltestellen: ' . implode(', ', $result['errors']) . "\n";
        }
    } elseif ($result['status'] === 'busy') {
        echo "Eine Aktualisierung läuft bereits.\n";
    } elseif ($result['status'] === 'cached') {
        echo "Der letzte Abruf liegt weniger als eine Minute zurück.\n";
    } else {
        echo 'OK: ' . $result['imported'] . " Abfahrten aktualisiert.\n";
    }
} catch (Throwable $error) {
    http_response_code(500);
    error_log('Abfahrtenimport fehlgeschlagen: ' . $error->getMessage());
    echo "Die Abfahrten konnten nicht aktualisiert werden.\n";
}
