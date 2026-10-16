<?php
require_once __DIR__ . '/config.php';

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kiosk Display</title>
    <link rel="stylesheet" href="kiosk.css?v=<?php echo filemtime(__DIR__ . '/kiosk.css'); ?>">
</head>
<body class="<?php echo $showMouse ? '' : 'hide-mouse'; ?>">
    <div id="main-flex">
        <div id="links">
            <div id="weather">Lädt Wetterdaten...</div>
            <section id="reinigungsplan" aria-label="Reinigungsplan" hidden></section>
            <div id="termine">Termine...</div>
        </div>
        <div id="rechts">
            <div id="train">Lädt Zugdaten...</div>
            <div id="tram">Lädt Tramdaten...</div>
            <div id="bus">Lädt Busdaten...</div>
        </div>
    </div>

    <script src="wetter.js?v=<?php echo time(); ?>"></script>
    <script src="abfall.js?v=<?php echo time(); ?>"></script>
    <script src="sonstige.js?v=<?php echo time(); ?>"></script>
    <script src="aufgaben.js?v=<?php echo time(); ?>"></script>
    <script src="reinigungsplan.js?v=<?php echo time(); ?>"></script>
    <script>
        window.kioskDepartureLimits = <?php echo json_encode([
            'zug' => departure_limit('zug'),
            'tram' => departure_limit('tram'),
            'bus' => departure_limit('bus'),
        ]); ?>;
    </script>
    <script src="abfahrten.js?v=<?php echo time(); ?>"></script>
    <script src="kiosk.js?v=<?php echo time(); ?>"></script>
    

</body>
</html>
