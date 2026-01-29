<?php
// app config helper (env for secrets, DB for settings)

function env_value($key, $default = null) {
    $val = getenv($key);
    if ($val === false || $val === '') {
        return $default;
    }
    return $val;
}

function db_config() {
    return [
        'host' => env_value('DB_HOST', 'localhost'),
        'name' => env_value('DB_NAME', ''),
        'user' => env_value('DB_USER', ''),
        'pass' => env_value('DB_PASS', ''),
    ];
}

function db_connect() {
    $cfg = db_config();
    if ($cfg['name'] === '' || $cfg['user'] === '') {
        throw new RuntimeException('DB env vars missing (DB_NAME/DB_USER).');
    }
    return new PDO(
        "mysql:host={$cfg['host']};dbname={$cfg['name']};charset=utf8mb4",
        $cfg['user'],
        $cfg['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

function load_app_config(PDO $pdo, array $defaults) {
    try {
        $stmt = $pdo->query("SELECT config_key, config_value FROM app_config");
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $defaults[$row['config_key']] = $row['config_value'];
        }
    } catch (Throwable $e) {
        // ignore config table errors, fall back to defaults
    }
    return $defaults;
}

function app_config_get(PDO $pdo = null) {
    $defaults = [
        'openweather_lat' => '51.1508',
        'openweather_lon' => '14.9684',
        'openweather_lang' => 'de',
        'openweather_units' => 'metric',
        'termine_sonstige_days' => '14',
        'termine_abfall_days' => '14',
        'snow_task_lead_hours' => '36',
        'snow_task_min_mm' => '0',
        'snow_task_evening_hour' => '18',
    ];

    if ($pdo) {
        return load_app_config($pdo, $defaults);
    }

    return $defaults;
}
