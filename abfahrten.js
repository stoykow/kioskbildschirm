// abfahrten.js
// Zug/Tram/Bus Abfahrten laden

const departureLimits = window.kioskDepartureLimits || { zug: 6, tram: 2, bus: 4 };

async function fetchDepartureBoard(type, limit) {
    for (let attempt = 0; attempt < 5; attempt++) {
        const response = await fetch(`abfahrten.php?typ=${type}&limit=${limit}`, { cache: 'no-store' });
        const departures = await response.json();
        if (!response.ok || !Array.isArray(departures)) {
            throw new Error('Departure board unavailable');
        }
        // Beim ersten Import warten parallele Anzeigen kurz auf denselben Cache.
        if (departures.length === 0 && response.headers.get('X-Abfahrten-Refresh') === 'busy' && attempt < 4) {
            await new Promise(resolve => setTimeout(resolve, 500));
            continue;
        }
        return departures;
    }
}

async function fetchTrains() {
    const trainDiv = document.getElementById('train');
    trainDiv.dataset.usesReplacementBus = '0';
    trainDiv.textContent = 'Lädt Zugdaten...';
    const limit = departureLimits.zug;
    try {
        let trainOnly = await fetchDepartureBoard('zug', limit);
        let usesReplacementBus = false;

        if (!Array.isArray(trainOnly) || trainOnly.length === 0) {
            trainOnly = await fetchDepartureBoard('ersatzverkehr', limit);
            usesReplacementBus = true;
        }

        const trainsWithDirection = trainOnly.filter(dep => dep && String(dep.richtung || '').trim() !== '');

        if (trainsWithDirection.length === 0) {
            trainDiv.textContent = 'Keine Zug-/Ersatzverkehr-Abfahrten gefunden.';
            return;
        }

        trainDiv.dataset.usesReplacementBus = usesReplacementBus ? '1' : '0';
        const title = usesReplacementBus ? 'Ersatzverkehr ab Görlitz Hbf' : 'Züge ab Görlitz Hbf';

        trainDiv.innerHTML = `
            <div class="train-title">${title}</div>
            ` + trainsWithDirection.slice(0, limit).map(dep => {
            const time = dep.anzeige_zeit || new Date(dep.tatsaechliche_zeit || dep.geplante_zeit).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
            const line = dep.linie || '';
            const direction = dep.richtung || '';
            const platform = dep.gleis ? `Gleis ${dep.gleis}` : '';
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
    const limit = departureLimits.tram;
    try {
        const departures = await fetchDepartureBoard('tram', limit);

        if (!Array.isArray(departures) || departures.length === 0) {
            tramDiv.textContent = 'Keine Straßenbahn-Abfahrten gefunden.';
            return;
        }

        tramDiv.innerHTML = `
            <div class="train-title">Tram ab Lutherstraße</div>
            ` + departures.slice(0, limit).map(dep => {
            const time = dep.anzeige_zeit || new Date(dep.tatsaechliche_zeit || dep.geplante_zeit).toLocaleTimeString('de-DE', {
                hour: '2-digit',
                minute: '2-digit'
            });
            const line = dep.linie || '';
            const direction = dep.richtung || '';

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
    const limit = departureLimits.bus;
    try {
        const departures = await fetchDepartureBoard('bus', limit);

        if (!Array.isArray(departures) || departures.length === 0) {
            busDiv.style.display = '';
            busDiv.textContent = 'Keine Bus-Abfahrten ab Bahnhof Südausgang gefunden.';
            return;
        }

        busDiv.style.display = '';
        busDiv.innerHTML = `
            <div class="train-title">Bus ab Bahnhof Südausgang</div>
            ` + departures.slice(0, limit).map(dep => {
            const time = dep.anzeige_zeit || new Date(dep.tatsaechliche_zeit || dep.geplante_zeit).toLocaleTimeString('de-DE', {
                hour: '2-digit',
                minute: '2-digit'
            });
            const line = dep.linie || '';
            const direction = dep.richtung || '';

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
