// wetter.js
// Wetterdaten laden

function getWindDirection(degree) {
    const directions = ['N', 'NNO', 'NO', 'ONO', 'O', 'OSO', 'SO', 'SSO', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];
    const index = Math.round(degree / 22.5) % 16;
    return directions[index];
}

function fetchWeather() {
    var apiKey = 'OPENWEATHER_API_KEY_PLACEHOLDER'; // Ihr API-Schluessel
    var url = `https://api.openweathermap.org/data/2.5/weather?lat=51.1508&lon=14.9684&appid=${apiKey}&lang=de&units=metric`;

    fetch(url)
        .then(response => response.json())
        .then(data => {
            const windDirection = getWindDirection(data.wind.deg);
            const weatherRows = [
                { label: 'Temperatur:', value: `${data.main.temp} °C` },
                { label: 'Gefuehlte Temperatur:', value: `${data.main.feels_like} °C` },
                { label: 'Wetterlage:', value: `${data.weather[0].description} (${data.clouds.all} %)` },
                { label: 'Luftdruck:', value: `${data.main.pressure} hPa` },
                { label: 'Luftfeuchtigkeit:', value: `${data.main.humidity} %` },
                { label: 'Windstaerke:', value: `${(data.wind.speed * 3.6).toFixed(2)} km/h` },
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
