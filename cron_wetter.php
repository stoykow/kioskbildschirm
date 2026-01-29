<?php
// Cron: Wetterdaten (OpenWeather) laden und in DB speichern

header('Content-Type: text/plain; charset=utf-8');

// OpenWeather Config
$apiKey = 'OPENWEATHER_API_KEY_PLACEHOLDER';
$lat = '51.1508';
$lon = '14.9684';
$lang = 'de';
$units = 'metric';

$url = "https://api.openweathermap.org/data/2.5/weather?lat={$lat}&lon={$lon}&appid={$apiKey}&lang={$lang}&units={$units}";

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: '';
$dbUser = getenv('DB_USER') ?: '';
$dbPass = getenv('DB_PASS') ?: '';

if ($dbName === '' || $dbUser === '') {
    http_response_code(500);
    echo "DB env vars missing (DB_NAME/DB_USER).\n";
    exit;
}

$context = stream_context_create([
    'http' => [
        'timeout' => 15,
        'user_agent' => 'hausordnung-wetter/1.0',
    ],
]);
$json = @file_get_contents($url, false, $context);
if ($json === false) {
    http_response_code(502);
    echo "Weather fetch failed.\n";
    exit;
}

$data = json_decode($json, true);
if (!is_array($data)) {
    http_response_code(502);
    echo "Weather JSON invalid.\n";
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

$main = $data['main'] ?? [];
$wind = $data['wind'] ?? [];
$clouds = $data['clouds'] ?? [];
$weather0 = is_array($data['weather'] ?? null) && count($data['weather']) > 0 ? $data['weather'][0] : [];

$insert = $pdo->prepare(
    "INSERT INTO wetter_daten
     (temperatur_c, gefuehlt_c, luftdruck_hpa, luftfeuchte_prozent, wind_ms, wind_boeen_ms, wolken_prozent,
      regen_1h_mm, schnee_1h_mm, wetter_main, wetter_beschreibung, payload_json)
     VALUES
     (:temperatur_c, :gefuehlt_c, :luftdruck_hpa, :luftfeuchte_prozent, :wind_ms, :wind_boeen_ms, :wolken_prozent,
      :regen_1h_mm, :schnee_1h_mm, :wetter_main, :wetter_beschreibung, :payload_json)"
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

$insert->execute([
    ':temperatur_c' => $main['temp'] ?? null,
    ':gefuehlt_c' => $main['feels_like'] ?? null,
    ':luftdruck_hpa' => $main['pressure'] ?? null,
    ':luftfeuchte_prozent' => $main['humidity'] ?? null,
    ':wind_ms' => $wind['speed'] ?? null,
    ':wind_boeen_ms' => $wind['gust'] ?? null,
    ':wolken_prozent' => $clouds['all'] ?? null,
    ':regen_1h_mm' => $data['rain']['1h'] ?? null,
    ':schnee_1h_mm' => $data['snow']['1h'] ?? null,
    ':wetter_main' => $weather0['main'] ?? null,
    ':wetter_beschreibung' => $weather0['description'] ?? null,
    ':payload_json' => $json,
]);

// Forecast: pruefen, ob in der kommenden Nacht Schnee faellt (naechste 36h)
$forecastUrl = "https://api.openweathermap.org/data/2.5/forecast?lat={$lat}&lon={$lon}&appid={$apiKey}&lang={$lang}&units={$units}";
$forecastJson = @file_get_contents($forecastUrl, false, $context);
if ($forecastJson !== false) {
    $forecast = json_decode($forecastJson, true);
    if (is_array($forecast) && is_array($forecast['list'] ?? null)) {
        $tzOffset = (int)($forecast['city']['timezone'] ?? 0); // Sekunden
        $nowTs = time();
        $maxLead = 36 * 3600;
        $snowDueDate = null;
        $snowLocalTs = null;

        foreach ($forecast['list'] as $item) {
            $dt = $item['dt'] ?? null;
            if (!$dt || $dt < $nowTs || ($dt - $nowTs) > $maxLead) {
                continue;
            }
            $snow = 0.0;
            if (isset($item['snow']['3h'])) {
                $snow = (float)$item['snow']['3h'];
            } elseif (isset($item['snow']['1h'])) {
                $snow = (float)$item['snow']['1h'];
            }
            if ($snow <= 0) {
                continue;
            }
            $localTs = $dt + $tzOffset;
            $hour = (int)gmdate('G', $localTs);
            if (!($hour >= 18 || $hour < 6)) {
                continue;
            }
            $snowLocalTs = $localTs;
            $dueDate = gmdate('Y-m-d', $localTs);
            if ($hour >= 18) {
                $dueDate = gmdate('Y-m-d', $localTs + 86400);
            }
            $snowDueDate = $dueDate;
            break;
        }

        if ($snowDueDate) {
            $whenText = $snowLocalTs ? gmdate('Y-m-d H:i', $snowLocalTs) : '';
            $details = $whenText !== ''
                ? "Schneefall erwartet (Prognose {$whenText})"
                : "Schneefall erwartet (Prognose)";
            $taskUpsert->execute([
                ':titel' => 'Schnee schieben',
                ':details' => $details,
                ':faellig_am' => $snowDueDate,
                ':gruppe_id' => null,
                ':quelle_typ' => 'schnee',
                ':quelle_datum' => $snowDueDate,
            ]);
        }
    }
}

echo "OK\n";
