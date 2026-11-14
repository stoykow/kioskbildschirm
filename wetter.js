// wetter.js
// Werte aus Home Assistant (via DB) anzeigen

// Geräte-Namen wie in ha_ingest.php gespeichert
const GEIGER_GERAET = 'multigeiger';
const TUER_GERAET = 'nukihub5';

// Entitäten (aus HA)
const GEIGER_TEMPERATUR = 'sensor.geiger_temperatur';
const GEIGER_LUFTFEUCHTE = 'sensor.geiger_luftfeuchte';
const GEIGER_LUFTDRUCK = 'sensor.geiger_luftdruck';
const GEIGER_DOSIS = 'sensor.multigeiger_geiger_dosisleistung';

// Entität der Haustür aus Home Assistant
const TUER_STATUS = 'lock.haustur';
let weatherGeigerCache = null;

function weatherEscape(value) {
    return String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function sanitizeUnit(unit) {
    if (!unit) {
        return '';
    }
    return unit.replace('μ', 'u').replace('µ', 'u');
}

function formatLockState(state) {
    if (!state) {
        return { label: 'n.v.', className: '' };
    }
    const s = String(state).toLowerCase();
    if (s === 'locked' || s === 'on' || s === 'true') {
        return { label: 'zugeschlossen', className: 'weather-status-closed' };
    }
    if (s === 'unlocked' || s === 'off' || s === 'false') {
        return { label: 'aufgeschlossen', className: 'weather-status-open' };
    }
    return { label: state, className: '' };
}

function formatNowDateTime() {
    const now = new Date();
    return now.toLocaleString('de-DE', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
    });
}

function updateStatusTime() {
    const el = document.getElementById('weather-status-time');
    if (!el) return;
    el.textContent = formatNowDateTime();
}

async function fetchHaGeraet(geraet) {
    if (geraet === TUER_GERAET && !window.kioskIsPrivate()) return null;
    const res = await window.kioskFetch(`geraet_status.php?geraet=${encodeURIComponent(geraet)}&limit=1`);
    if (!res.ok) throw new Error('Weather data unavailable');
    const data = await res.json();
    if (!Array.isArray(data) || data.length === 0) {
        return null;
    }
    return data[0].payload || null;
}

function pickValue(payload, entityId) {
    const e = payload && payload[entityId];
    if (!e) {
        return null;
    }
    const unit = sanitizeUnit(e.attributes && e.attributes.unit_of_measurement);
    return { value: e.state, unit };
}

function formatPressure(press) {
    if (!press) {
        return null;
    }
    const raw = parseFloat(press.value);
    if (Number.isNaN(raw)) {
        return { value: press.value, unit: press.unit || 'hPa' };
    }
    const unit = (press.unit || '').toLowerCase();
    if (unit === 'pa') {
        return { value: (raw / 100).toFixed(1), unit: 'hPa' };
    }
    if (unit === 'hpa') {
        return { value: raw.toFixed(1), unit: 'hPa' };
    }
    return { value: press.value, unit: press.unit || 'hPa' };
}

function renderWeather(geiger, tuer) {
        const temp = pickValue(geiger, GEIGER_TEMPERATUR);
        const hum = pickValue(geiger, GEIGER_LUFTFEUCHTE);
        const press = formatPressure(pickValue(geiger, GEIGER_LUFTDRUCK));
        const dosis = pickValue(geiger, GEIGER_DOSIS);
        const tuerStatus = tuer && tuer[TUER_STATUS] ? tuer[TUER_STATUS].state : null;
        const tuerInfo = formatLockState(tuerStatus);
        const statusTime = formatNowDateTime();

        const tempText = temp ? `${temp.value} ${temp.unit || 'C'}` : 'n.v.';
        const humText = hum ? `${hum.value} ${hum.unit || '%'}` : 'n.v.';
        const pressText = press ? `${press.value} ${press.unit || 'hPa'}` : 'n.v.';
        const dosisText = dosis ? `${dosis.value} ${dosis.unit || 'uSv/h'}` : 'n.v.';

        const container = document.getElementById('weather');
        if (!container) return;
        const doorRow = window.kioskIsPrivate() ? `<div class="weather-row" id="weather-door-row">
                <span class="weather-label">Haustür:</span>
                <span class="weather-value weather-status ${tuerInfo.className}">${weatherEscape(tuerInfo.label)}</span>
            </div>` : '';
        container.innerHTML =
            `<div class="weather-title">Umwelt / Status</div>` +
            `<div class="weather-row">
                <span class="weather-label">Datum/Uhrzeit:</span>
                <span class="weather-value">
                    <span id="weather-status-time" class="weather-status-time">${weatherEscape(statusTime)}</span>
                </span>
            </div>` +
            `<div class="weather-row">
                <span class="weather-label">Temp / Feuchte:</span>
                <span class="weather-value">${weatherEscape(tempText)} / ${weatherEscape(humText)}</span>
            </div>` +
            `<div class="weather-row">
                <span class="weather-label">Druck / Dosis:</span>
                <span class="weather-value">${weatherEscape(pressText)} / ${weatherEscape(dosisText)}</span>
            </div>` +
            doorRow;
        updateStatusTime();
}

async function fetchWeather() {
    try {
        const geiger = await fetchHaGeraet(GEIGER_GERAET);
        // Nur die vier öffentlichen Messwerte im Browser zwischenspeichern.
        weatherGeigerCache = {};
        for (const id of [GEIGER_TEMPERATUR, GEIGER_LUFTFEUCHTE, GEIGER_LUFTDRUCK, GEIGER_DOSIS]) {
            if (geiger && geiger[id]) {
                weatherGeigerCache[id] = {
                    state: geiger[id].state,
                    attributes: { unit_of_measurement: geiger[id].attributes?.unit_of_measurement },
                };
            }
        }
        const tuer = window.kioskIsPrivate() ? await fetchHaGeraet(TUER_GERAET) : null;
        renderWeather(weatherGeigerCache, tuer);
    } catch (error) {
        if (weatherGeigerCache) renderWeather(weatherGeigerCache, null);
        else {
            const container = document.getElementById('weather');
            if (container) container.textContent = 'Die Umweltdaten konnten gerade nicht geladen werden.';
        }
        console.error('Fehler beim Laden der HA-Daten:', error);
    }
}

window.kioskRegisterPrivateCleanup(() => renderWeather(weatherGeigerCache, null));
