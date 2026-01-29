<?php
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
    <link rel="stylesheet" href="kiosk.css">
</head>
<body>
    <div id="date-time"></div>
    
    <div id="main-flex">
        <div id="links">
            <div id="weather">Lädt Wetterdaten...</div>
            <div id="waste">Abfallkalender...</div>
        </div>
        <div id="rechts">
            <div id="train">Lädt Zugdaten...</div>
            <div id="tram">Lädt Tramdaten...</div>
            <div id="bus">Lädt Busdaten...</div>
        </div>
    </div>

    <script src="wetter.js?v=<?php echo time(); ?>"></script>
    <script src="abfall.js?v=<?php echo time(); ?>"></script>
    <script src="aufgaben.js?v=<?php echo time(); ?>"></script>
    <script src="abfahrten.js?v=<?php echo time(); ?>"></script>
    <script src="kiosk.js?v=<?php echo time(); ?>"></script>
    

</body>
</html>
