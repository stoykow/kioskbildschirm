// abfall.js
// Abfallkalender laden

function fetchWaste() {
    // Same-origin to avoid CORS issues
    fetch('enso.php') // Json-API fuer Abfallkalender
        .then(response => response.json())
        .then(data => {
            const wasteDiv = document.getElementById('waste');
            if (!Array.isArray(data) || data.length === 0) {
                wasteDiv.innerHTML = '<div class="waste-title">Abfallkalender</div><div class="waste-row">Keine Termine gefunden</div>';
                return;
            }
            const today = new Date();
            today.setHours(0, 0, 0, 0);
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
                    return `Uebermorgen ${tagDatum}`;
                } else if (diff < (7 - isoDay)) {
                    return `am ${tag} ${tagDatum}`;
                } else if (diff < (14 - isoDay)) {
                    return `naechster ${tag} ${tagDatum}`;
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
                            ${e.start ? `<span class="waste-time">${e.start}${e.end ? ' - ' + e.end : ''}</span>` : ''}
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
