// kiosk.js
// Initialisierung und Uhrzeit

function updateTime() {
    var now = new Date();

    var formattedDate = now.toLocaleDateString('de-DE', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit'
    });

    var formattedTime = now.toLocaleTimeString('de-DE', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
    });

    document.getElementById('date-time').textContent = `${formattedDate} ${formattedTime}`;
}

function initKiosk() {
    updateTime();
    fetchWeather();
    fetchWaste();
    fetchTrains();
    fetchTrams();
    fetchBus();
    setInterval(updateTime, 1000); // Aktualisiere die Uhrzeit jede Sekunde
    setInterval(fetchWeather, 600000); // Aktualisiere das Wetter alle 10 Minuten
    setInterval(fetchTrains, 300000); // Aktualisiere die Zugdaten alle 5 Minuten
    setInterval(fetchTrams, 300000); // Aktualisiere die Tramdaten alle 5 Minuten
    setInterval(fetchBus, 300000); // Aktualisiere die Busdaten alle 5 Minuten
    setInterval(fetchWaste, 300000); // Aktualisiere die Abfallkalender alle 5 Minuten
    setInterval(() => location.reload(), 1200000); // kompletter Reload alle 20 Minuten
}

window.onload = initKiosk;