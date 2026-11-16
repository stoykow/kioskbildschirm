<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
$frameAccess = kiosk_handle_entry_token();

$private = $frameAccess !== null || kiosk_is_private();
$csrf = $private && $frameAccess === null ? kiosk_csrf_token() : null;

header("Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");
header('Vary: Cookie, Authorization');
header('Referrer-Policy: no-referrer');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <title>Kiosk Display</title>
    <link rel="stylesheet" href="kiosk.css?v=<?php echo filemtime(__DIR__ . '/kiosk.css'); ?>">
</head>
<body class="<?php echo $showMouse ? '' : 'hide-mouse'; ?>">
    <nav class="kiosk-accessbar" aria-label="Privater Zugang">
        <a id="kiosk-login" href="zugang.php"<?php echo $private ? ' hidden' : ''; ?>>Privater Bereich</a>
    </nav>
    <div id="main-flex">
        <div id="links">
            <div id="weather">Lädt Wetterdaten...</div>
            <div id="termine">Termine...</div>
        </div>
        <div id="rechts">
            <div id="train">Lädt Zugdaten...</div>
            <div id="tram">Lädt Tramdaten...</div>
            <div id="bus">Lädt Busdaten...</div>
        </div>
    </div>

    <footer id="kiosk-legal" class="kiosk-legal" aria-label="Rechtliche Informationen"<?php echo $private ? ' hidden' : ''; ?>>
        <a href="impressum.php">Impressum</a>
        <a href="datenschutz.php">Datenschutz</a>
    </footer>

    <script>
        window.kioskAccess = <?php echo json_encode(['private' => $private, 'csrf' => $csrf, 'access' => $frameAccess], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>
    <script src="kiosk.js?v=<?php echo time(); ?>"></script>
    <script src="wetter.js?v=<?php echo time(); ?>"></script>
    <script src="abfall.js?v=<?php echo time(); ?>"></script>
    <script src="sonstige.js?v=<?php echo time(); ?>"></script>
    <script src="aufgaben.js?v=<?php echo time(); ?>"></script>
    <script>
        window.kioskDepartureLimits = <?php echo json_encode([
            'zug' => departure_limit('zug'),
            'tram' => departure_limit('tram'),
            'bus' => departure_limit('bus'),
        ]); ?>;
    </script>
    <script src="abfahrten.js?v=<?php echo time(); ?>"></script>
    

</body>
</html>
