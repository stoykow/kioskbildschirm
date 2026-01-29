// abfall.js
// Abfallkalender laden + Erledigt-Markierung

let wasteEventsCache = [];
let wasteUsersCache = null;
let wasteModalReady = false;

function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

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

function renderWasteRows(entries, interactive) {
    const wasteDiv = document.getElementById('waste');
    if (!Array.isArray(entries) || entries.length === 0) {
        wasteDiv.innerHTML = '<div class="waste-title">Abfallkalender</div><div class="waste-row">Keine Termine gefunden</div>';
        return;
    }

    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const in14 = new Date(today);
    in14.setDate(today.getDate() + 14);

    const upcoming = entries
        .filter(e => {
            const d = new Date(e.date);
            return d >= today && d <= in14;
        })
        .sort((a, b) => new Date(a.date) - new Date(b.date))
        .slice(0, 3);

    wasteDiv.innerHTML = `
        <div class="waste-title">Abfallkalender</div>
        ${upcoming.length === 0 ? '<div class="waste-row">Keine Termine im Zeitraum</div>' :
            upcoming.map(e => {
                const rowClasses = ['waste-row'];
                if (interactive) rowClasses.push('waste-clickable');
                if (e.done_by) rowClasses.push('waste-done-row');
                const doneBadge = e.done_by ? `<span class="waste-done">Erledigt: ${escapeHtml(e.done_by)}</span>` : '';
                const time = e.start ? `<span class="waste-time">${escapeHtml(e.start)}${e.end ? ' - ' + escapeHtml(e.end) : ''}</span>` : '';
                const dataAttr = interactive && e.id ? `data-event-id="${e.id}"` : '';
                return `
                    <div class="${rowClasses.join(' ')}" ${dataAttr}>
                        <span class="waste-date">${escapeHtml(getLabel(e.date))}</span>
                        <span class="waste-type">${escapeHtml(e.summary)}</span>
                        ${time}
                        ${doneBadge}
                    </div>
                `;
            }).join('')
        }
    `;

    if (interactive) {
        bindWasteRowHandlers();
    }
}

function fetchWasteUsers() {
    if (wasteUsersCache) {
        return Promise.resolve(wasteUsersCache);
    }
    return fetch('abfall_users.php')
        .then(response => response.json())
        .then(data => {
            if (!data || !Array.isArray(data.users)) {
                throw new Error('Invalid users response');
            }
            wasteUsersCache = data.users;
            return wasteUsersCache;
        });
}

function ensureWasteModal() {
    if (wasteModalReady) return;
    const modal = document.createElement('div');
    modal.id = 'waste-modal';
    modal.innerHTML = `
        <div class="waste-modal-card">
            <div class="waste-modal-title">Abfalltermin</div>
            <div class="waste-modal-sub"></div>
            <div class="waste-user-list"></div>
            <div class="waste-modal-actions">
                <button class="waste-modal-cancel">Abbrechen</button>
            </div>
        </div>
    `;
    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            closeWasteModal();
        }
    });
    document.body.appendChild(modal);
    modal.querySelector('.waste-modal-cancel').addEventListener('click', closeWasteModal);
    wasteModalReady = true;
}

function openWasteModal(eventId) {
    const eventItem = wasteEventsCache.find(e => e.id === eventId);
    if (!eventItem) return;

    ensureWasteModal();
    const modal = document.getElementById('waste-modal');
    const title = modal.querySelector('.waste-modal-title');
    const sub = modal.querySelector('.waste-modal-sub');
    const list = modal.querySelector('.waste-user-list');

    title.textContent = eventItem.summary;
    sub.textContent = `${getLabel(eventItem.date)}${eventItem.start ? ' · ' + eventItem.start : ''}`;

    list.innerHTML = '<div class="waste-loading">Benutzer werden geladen...</div>';
    modal.classList.add('is-open');

    fetchWasteUsers()
        .then(users => {
            if (!Array.isArray(users) || users.length === 0) {
                list.innerHTML = '<div class="waste-loading">Keine Benutzer vorhanden</div>';
                return;
            }
            list.innerHTML = '';
            users.forEach(user => {
                const btn = document.createElement('button');
                btn.className = 'waste-user-button';
                btn.textContent = user.name;
                btn.addEventListener('click', () => markWasteDone(eventItem.id, user));
                list.appendChild(btn);
            });
            if (eventItem.done_by) {
                const info = document.createElement('div');
                info.className = 'waste-done-info';
                info.textContent = `Bereits erledigt von ${eventItem.done_by}`;
                list.appendChild(info);
            }
        })
        .catch(() => {
            list.innerHTML = '<div class="waste-loading">Fehler beim Laden der Benutzer</div>';
        });
}

function closeWasteModal() {
    const modal = document.getElementById('waste-modal');
    if (modal) {
        modal.classList.remove('is-open');
    }
}

function markWasteDone(eventId, user) {
    fetch('abfall_done.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ event_id: eventId, user_id: user.id })
    })
        .then(response => response.json())
        .then(data => {
            if (!data || !data.ok) {
                throw new Error('Mark done failed');
            }
            wasteEventsCache = wasteEventsCache.map(e => {
                if (e.id === eventId) {
                    return { ...e, done_by: user.name, done_at: new Date().toISOString() };
                }
                return e;
            });
            renderWasteRows(wasteEventsCache, true);
            closeWasteModal();
        })
        .catch(() => {
            closeWasteModal();
        });
}

function bindWasteRowHandlers() {
    const rows = document.querySelectorAll('.waste-row[data-event-id]');
    rows.forEach(row => {
        row.addEventListener('click', () => {
            const eventId = parseInt(row.getAttribute('data-event-id'), 10);
            if (eventId > 0) {
                openWasteModal(eventId);
            }
        });
    });
}

function fetchWaste() {
    fetch('abfall_api.php')
        .then(response => response.json())
        .then(data => {
            if (!data || !Array.isArray(data.events)) {
                throw new Error('Invalid abfall_api response');
            }
            wasteEventsCache = data.events;
            renderWasteRows(wasteEventsCache, true);
        })
        .catch(() => {
            fetch('enso.php')
                .then(response => response.json())
                .then(data => {
                    renderWasteRows(data, false);
                })
                .catch(error => {
                    document.getElementById('waste').textContent = 'Fehler beim Laden des Abfallkalenders';
                    console.error('Fehler beim Laden des Abfallkalenders:', error);
                });
        });
}
