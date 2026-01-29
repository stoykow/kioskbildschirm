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

echo "OK\n";
