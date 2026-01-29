// wetter.js
// Werte aus Home Assistant (via DB) anzeigen

// Geraete-Namen wie in ha_ingest.php gespeichert
const GEIGER_GERAET = 'multigeiger';
const TUER_GERAET = 'nukihub5';

// Entitaeten (aus HA)
const GEIGER_TEMPERATUR = 'sensor.geiger_temperatur';
const GEIGER_LUFTFEUCHTE = 'sensor.geiger_luftfeuchte';
const GEIGER_LUFTDRUCK = 'sensor.geiger_luftdruck';
const GEIGER_DOSIS = 'sensor.multigeiger_geiger_dosisleistung';

// Hier den richtigen Entitaetsnamen fuer die Haustuer eintragen
const TUER_STATUS = 'lock.haustur';

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

async function fetchHaGeraet(geraet) {
    const res = await fetch(`geraet_status.php?geraet=${encodeURIComponent(geraet)}&limit=1`);
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

async function fetchWeather() {
    try {
        const geiger = await fetchHaGeraet(GEIGER_GERAET);
        const tuer = await fetchHaGeraet(TUER_GERAET);

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

        document.getElementById('weather').innerHTML =
            `<div class="weather-title">Umwelt / Status</div>` +
            `<div class="weather-row">
                <span class="weather-label">Datum/Uhrzeit:</span>
                <span class="weather-value">
                    <span class="weather-status-time">${statusTime}</span>
                </span>
            </div>` +
            `<div class="weather-row">
                <span class="weather-label">Temp / Feuchte:</span>
                <span class="weather-value">${tempText} / ${humText}</span>
            </div>` +
            `<div class="weather-row">
                <span class="weather-label">Druck / Dosis:</span>
                <span class="weather-value">${pressText} / ${dosisText}</span>
            </div>` +
            `<div class="weather-row">
                <span class="weather-label">Haustür:</span>
                <span class="weather-value weather-status ${tuerInfo.className}">${tuerInfo.label}</span>
            </div>`;
    } catch (error) {
        document.getElementById('weather').textContent = 'Fehler beim Laden der HA-Daten';
        console.error('Fehler beim Laden der HA-Daten:', error);
    }
}
