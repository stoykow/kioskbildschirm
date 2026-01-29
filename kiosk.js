// kiosk.js
// Initialisierung

function initKiosk() {
    fetchWeather();
    fetchWaste();
    fetchSonstige();
    fetchTasks();
    fetchTrains();
    fetchTrams();
    fetchBus();
    setInterval(updateStatusTime, 1000); // Datum/Uhrzeit im Status jede Sekunde
    setInterval(fetchWeather, 600000); // Aktualisiere das Wetter alle 10 Minuten
    setInterval(fetchTrains, 300000); // Aktualisiere die Zugdaten alle 5 Minuten
    setInterval(fetchTrams, 300000); // Aktualisiere die Tramdaten alle 5 Minuten
    setInterval(fetchBus, 300000); // Aktualisiere die Busdaten alle 5 Minuten
    setInterval(fetchWaste, 300000); // Aktualisiere die Abfallkalender alle 5 Minuten
    setInterval(fetchSonstige, 300000); // Aktualisiere die sonstigen Termine alle 5 Minuten
    setInterval(fetchTasks, 300000); // Aktualisiere die Aufgaben alle 5 Minuten
    setInterval(() => location.reload(), 1200000); // kompletter Reload alle 20 Minuten
}

window.onload = initKiosk;
