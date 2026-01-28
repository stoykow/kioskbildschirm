// abfahrten.js
// Zug/Tram/Bus Abfahrten laden

async function fetchTrains() {
    const trainDiv = document.getElementById('train');
    trainDiv.textContent = 'Laedt Zugdaten...';
    try {
        const response = await fetch('abfahrten.php?typ=zug&limit=6');
        const trainOnly = await response.json();

        if (!Array.isArray(trainOnly) || trainOnly.length === 0) {
            trainDiv.textContent = 'Keine Zugabfahrten gefunden.';
            return;
        }

        trainDiv.innerHTML = `
            <div class="train-title">Zuege ab Goerlitz Hbf</div>
            ` + trainOnly.slice(0, 6).map(dep => {
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
    tramDiv.textContent = 'Laedt Strassenbahn-Abfahrten...';
    try {
        const response = await fetch('abfahrten.php?typ=tram&limit=4');
        const departures = await response.json();

        if (!Array.isArray(departures) || departures.length === 0) {
            tramDiv.textContent = 'Keine Strassenbahn-Abfahrten gefunden.';
            return;
        }

        tramDiv.innerHTML = `
            <div class="train-title">Tram ab Bahnhof</div>
            ` + departures.slice(0, 4).map(dep => {
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
        tramDiv.textContent = 'Fehler beim Laden der Strassenbahn-Daten.';
        console.error('Fehler beim Laden der Strassenbahn-Daten:', e);
    }
}

async function fetchBus() {
    const busDiv = document.getElementById('bus');
    busDiv.textContent = 'Laedt Bus-Abfahrten...';
    try {
        const response = await fetch('abfahrten.php?typ=bus&limit=6');
        const departures = await response.json();

        if (!Array.isArray(departures) || departures.length === 0) {
            busDiv.textContent = 'Keine Bus-Abfahrten gefunden.';
            return;
        }

        busDiv.innerHTML = `
            <div class="train-title">Bus ab Bahnhof</div>
            ` + departures.slice(0, 6).map(dep => {
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