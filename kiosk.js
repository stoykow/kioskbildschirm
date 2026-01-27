// kiosk.js
// Enthält alle JavaScript-Funktionen für das Kiosk-Display

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


function getWindDirection(degree) {
    const directions = ['N', 'NNO', 'NO', 'ONO', 'O', 'OSO', 'SO', 'SSO', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];
    const index = Math.round(degree / 22.5) % 16;
    return directions[index];
}

function fetchWeather() {
    var apiKey = 'OPENWEATHER_API_KEY_PLACEHOLDER'; // Ihr API-Schlüssel
    var url = `https://api.openweathermap.org/data/2.5/weather?lat=51.1508&lon=14.9684&appid=${apiKey}&lang=de&units=metric`;

    fetch(url)
        .then(response => response.json())
        .then(data => {
            const windDirection = getWindDirection(data.wind.deg);
            const weatherRows = [
                { label: 'Temperatur:', value: `${data.main.temp} °C` },
                { label: 'Gefühlte Temperatur:', value: `${data.main.feels_like} °C` },
                { label: 'Wetterlage:', value: `${data.weather[0].description} (${data.clouds.all} %)` },
                { label: 'Luftdruck:', value: `${data.main.pressure} hPa` },
                { label: 'Luftfeuchtigkeit:', value: `${data.main.humidity} %` },
                { label: 'Windstärke:', value: `${(data.wind.speed * 3.6).toFixed(2)} km/h` },
                { label: 'Windrichtung:', value: `${windDirection} (${data.wind.deg}°)` }
            ];
            document.getElementById('weather').innerHTML =
                `<div class="weather-title">Wetter ${data.name}</div>` +
                weatherRows.map(row =>
                    `<div class="weather-row">
                        <span class="weather-label">${row.label}</span>
                        <span class="weather-value">${row.value}</span>
                    </div>`
                ).join('');
        })
        .catch(error => {
            document.getElementById('weather').textContent = 'Fehler beim Abrufen der Wetterdaten';
            console.error('Fehler beim Abrufen der Wetterdaten:', error);
        });
}

async function fetchWaste() {
    // Same-origin to avoid CORS issues
    fetch('enso.php') // Json-API für Abfallkalender
        .then(response => response.json())
        .then(data => {
            const wasteDiv = document.getElementById('waste');
            if (!Array.isArray(data) || data.length === 0) {
                wasteDiv.innerHTML = '<div class="waste-title">Abfallkalender</div><div class="waste-row">Keine Termine gefunden</div>';
                return;
            }
            const today = new Date();
            today.setHours(0,0,0,0);
            const in14 = new Date(today);
            in14.setDate(today.getDate() + 14);
            const upcoming = data
                .filter(e => {
                    const d = new Date(e.date);
                    return d >= today && d <= in14;
                })
                .sort((a, b) => new Date(a.date) - new Date(b.date))
                .slice(0, 3);

            function getLabel(dateStr) {
                const d = new Date(dateStr);
                d.setHours(0, 0, 0, 0);
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                const diff = Math.round((d - today) / (1000 * 60 * 60 * 24));
                const tagDatum = `(${d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit' })})`;
                const tagDatum2 = `${d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit' })}`;
                const wochentage = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
                const tag = wochentage[d.getDay()];
                const isoDay = d.getDay() === 0 ? 7 : d.getDay();

                if (diff === 0) {
                    return `Heute ${tagDatum}`;
                } else if (diff === 1) {
                    return `Morgen ${tagDatum}`;
                } else if (diff === 2) {
                    return `Übermorgen ${tagDatum}`;
                } else if (diff < (7 - isoDay)) {
                    return `am ${tag} ${tagDatum}`;
                } else if (diff < (14 - isoDay)) {
                    return `nächster ${tag} ${tagDatum}`;
                } else {
                    return `am ${tagDatum2}`;
                }
            }

            wasteDiv.innerHTML = `
                <div class="waste-title">Abfallkalender</div>
                ${upcoming.length === 0 ? '<div class="waste-row">Keine Termine im Zeitraum</div>' :
                    upcoming.map(e => `
                        <div class="waste-row">
                            <span class="waste-date">${getLabel(e.date)}</span>
                            <span class="waste-type">${e.summary}</span>
                            ${e.start ? `<span class=\"waste-time\">${e.start}${e.end ? ' - ' + e.end : ''}</span>` : ''}
                        </div>
                    `).join('')
                }
            `;
        })
        .catch(error => {
            document.getElementById('waste').textContent = 'Fehler beim Laden des Abfallkalenders';
            console.error('Fehler beim Laden des Abfallkalenders:', error);
        });
}

async function fetchTrains() {
    const trainDiv = document.getElementById('train');
    trainDiv.textContent = 'Lädt Zugdaten...';
    try {
        const response = await fetch('https://v6.db.transport.rest/stops/8010131/departures?duration=180&results=10');
        const data = await response.json();

        // Neues API-Format: departures ist ein Array
        const departures = Array.isArray(data.departures) ? data.departures : data;
        // Nur echte Züge: mode === 'train' und product === 'regional' oder 'national'
        const trainOnly = departures.filter(dep => dep.line?.mode === 'train' && (dep.line?.product === 'regional' || dep.line?.product === 'national'));

        if (!Array.isArray(trainOnly) || trainOnly.length === 0) {
            trainDiv.textContent = 'Keine Zugabfahrten gefunden.';
            return;
        }

        trainDiv.innerHTML = `
            <div class="train-title">Züge ab Görlitz Hbf</div>
            ` + trainOnly.slice(0, 6).map(dep => {
                const time = new Date(dep.when).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
                const line = dep.line?.name || '';
                const direction = dep.direction || '';
                const platform = dep.platform ? `Gleis ${dep.platform}` : '';
                return `
                    <div class="train-row">
                        <span class="train-time">${time}</span>
                        <span class="train-line">${line}</span>
                        <span class="train-direction">${direction}</span>
                        <span class="train-platform">${platform}</span>
                    </div>`;
            }).join('');

    } catch (e) {
        trainDiv.textContent = 'Fehler beim Laden der Zugdaten.';
        console.error('Fehler beim Laden der Zugdaten:', e);
    }
}

async function fetchTrams() {
    const tramDiv = document.getElementById('tram');
    tramDiv.textContent = 'Lädt Straßenbahn-Abfahrten...';
    try {
        const response = await fetch('https://v6.db.transport.rest/stops/977263/departures?duration=60&results=10');
        const data = await response.json();

        const departures = Array.isArray(data.departures) ? data.departures : data;

        if (!Array.isArray(departures) || departures.length === 0) {
            tramDiv.textContent = 'Keine Straßenbahn-Abfahrten gefunden.';
            return;
        }

        tramDiv.innerHTML = `
            <div class="train-title">Tram ab Lutherstraße</div>
            ` + departures.slice(0, 2).map(dep => {
                const time = new Date(dep.when).toLocaleTimeString('de-DE', {
                    hour: '2-digit',
                    minute: '2-digit'
                });
                const line = dep.line?.name || '';
                const direction = dep.direction || '';

                return `
                    <div class="tram-row">
                        <span class="train-time">${time}</span>
                        <span class="train-line">${line}</span>
                        <span class="train-direction">${direction}</span>
                    </div>`;
            }).join('');

    } catch (e) {
        tramDiv.textContent = 'Fehler beim Laden der Straßenbahn-Daten.';
        console.error('Fehler beim Laden der Straßenbahn-Daten:', e);
    }
}

async function fetchBus() {
    const busDiv = document.getElementById('bus');
    busDiv.textContent = 'Lädt Bus-Abfahrten...';
    try {
        const response = await fetch('https://v6.db.transport.rest/stops/977244/departures?duration=60&results=10');
        const data = await response.json();

        const departures = Array.isArray(data.departures) ? data.departures : data;

        if (!Array.isArray(departures) || departures.length === 0) {
            busDiv.textContent = 'Keine Bus-Abfahrten gefunden.';
            return;
        }

        busDiv.innerHTML = `
            <div class="train-title">Bus ab Melanchthonstraße</div>
            ` + departures.slice(0, 4).map(dep => {
                const time = new Date(dep.when).toLocaleTimeString('de-DE', {
                    hour: '2-digit',
                    minute: '2-digit'
                });
                const line = dep.line?.name || '';
                const direction = dep.direction || '';

                return `
                    <div class="bus-row">
                        <span class="train-time">${time}</span>
                        <span class="train-line">${line}</span>
                        <span class="train-direction">${direction}</span>
                    </div>`;
            }).join('');

    } catch (e) {
        busDiv.textContent = 'Fehler beim Laden der Bus-Daten.';
        console.error('Fehler beim Laden der Bus-Daten:', e);
    }
}

function initKiosk() {
    updateTime();
    fetchWeather();
    fetchTrains();
    fetchTrams();
    fetchBus();
    fetchWaste();
    setInterval(updateTime, 1000); // Aktualisiere die Uhrzeit jede Sekunde
    setInterval(fetchWeather, 600000); // Aktualisiere das Wetter alle 10 Minuten
    setInterval(fetchTrains, 300000); // Aktualisiere die Zugdaten alle 5 Minuten
    setInterval(fetchTrams, 300000); // Aktualisiere die Tramdaten alle 5 Minuten
    setInterval(fetchBus, 300000); // Aktualisiere die Busdaten alle 5 Minuten
    setInterval(fetchWaste, 300000); // Aktualisiere die Abfallkalender alle 5 Minuten
    setInterval(() => location.reload(), 1200000); // kompletter Reload alle 20 Minuten
}

window.onload = initKiosk;
