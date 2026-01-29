// sonstige.js
// Sonstige Termine + Zuhause-Markierung

let sonstigeEventsCache = [];
let sonstigeUsersCache = null;
let sonstigeModalReady = false;
let sonstigeInteractive = false;

function sonstigeEscapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function sonstigeGetLabel(dateStr) {
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

    if (diff == 0) {
        return `Heute ${tagDatum}`;
    } else if (diff == 1) {
        return `Morgen ${tagDatum}`;
    } else if (diff == 2) {
        return `Uebermorgen ${tagDatum}`;
    } else if (diff < (7 - isoDay)) {
        return `am ${tag} ${tagDatum}`;
    } else if (diff < (14 - isoDay)) {
        return `naechster ${tag} ${tagDatum}`;
    } else {
        return `am ${tagDatum2}`;
    }
}

function buildSonstigeSection(entries, interactive) {
    if (!Array.isArray(entries) || entries.length === 0) {
        return {
            html: '<div class="sonstige-title">Sonstige Termine</div><div class="sonstige-row">Keine Termine gefunden</div>',
            bind: null
        };
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
        .sort((a, b) => new Date(a.date) - new Date(b.date));

    const html = `
        <div class="sonstige-title">Sonstige Termine</div>
        ${upcoming.length === 0 ? '<div class="sonstige-row">Keine Termine im Zeitraum</div>' :
            upcoming.map(e => {
                const rowClasses = ['sonstige-row'];
                if (interactive) rowClasses.push('sonstige-clickable');
                const dataAttr = interactive && e.id ? `data-termin-id="${e.id}"` : '';
                const time = e.start ? `<span class="sonstige-time">${sonstigeEscapeHtml(e.start)}${e.end ? ' - ' + sonstigeEscapeHtml(e.end) : ''}</span>` : '';
                const hint = e.hint ? `<span class="sonstige-hint">${sonstigeEscapeHtml(e.hint)}</span>` : '';
                const statusText = e.zuhause_by ? `Zuhause: ${sonstigeEscapeHtml(e.zuhause_by)}` : 'Zuhause: -';
                return `
                    <div class="${rowClasses.join(' ')}" ${dataAttr}>
                        <span class="sonstige-date">${sonstigeEscapeHtml(sonstigeGetLabel(e.date))}</span>
                        <span class="sonstige-type">${sonstigeEscapeHtml(e.title)}</span>
                        ${hint}
                        ${time}
                        <span class="sonstige-status">${statusText}</span>
                    </div>
                `;
            }).join('')
        }
    `;

    return {
        html: html,
        bind: interactive ? bindSonstigeRowHandlers : null
    };
}

function renderSonstige() {
    const sonstigeDiv = document.getElementById('sonstige');
    if (!sonstigeDiv) return;
    const section = buildSonstigeSection(sonstigeEventsCache, sonstigeInteractive);
    sonstigeDiv.innerHTML = section.html;
    if (section.bind) section.bind();
}

function fetchSonstigeUsers() {
    if (sonstigeUsersCache) {
        return Promise.resolve(sonstigeUsersCache);
    }
    return fetch('abfall_users.php')
        .then(res => res.json())
        .then(data => {
            if (!data || !Array.isArray(data.users)) {
                throw new Error('Invalid users response');
            }
            sonstigeUsersCache = data.users;
            return sonstigeUsersCache;
        });
}

function ensureSonstigeModal() {
    if (sonstigeModalReady) return;
    const modal = document.createElement('div');
    modal.id = 'sonstige-modal';
    modal.innerHTML = `
        <div class="sonstige-modal-card">
            <div class="sonstige-modal-title">Wer ist zuhause?</div>
            <div class="sonstige-modal-sub"></div>
            <div class="sonstige-user-list"></div>
            <div class="sonstige-modal-actions">
                <button class="sonstige-modal-confirm">OK</button>
                <button class="sonstige-modal-clear">Keiner zuhause</button>
                <button class="sonstige-modal-cancel">Abbrechen</button>
            </div>
        </div>
    `;
    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeSonstigeModal();
    });
    modal.querySelector('.sonstige-modal-cancel').addEventListener('click', closeSonstigeModal);
    modal.querySelector('.sonstige-modal-confirm').addEventListener('click', () => {
        const terminId = parseInt(modal.getAttribute('data-termin-id') || '0', 10);
        if (terminId > 0) {
            const selected = Array.from(modal.querySelectorAll('.sonstige-user-button.is-selected'))
                .map(btn => parseInt(btn.getAttribute('data-user-id') || '0', 10))
                .filter(id => id > 0);
            setSonstigeZuhause(terminId, selected);
        }
    });
    modal.querySelector('.sonstige-modal-clear').addEventListener('click', () => {
        const terminId = parseInt(modal.getAttribute('data-termin-id') || '0', 10);
        if (terminId > 0) {
            setSonstigeZuhause(terminId, [], true);
        }
    });
    document.body.appendChild(modal);
    sonstigeModalReady = true;
}

function openSonstigeModal(terminId) {
    const eventItem = sonstigeEventsCache.find(e => e.id === terminId);
    if (!eventItem) return;
    ensureSonstigeModal();
    const modal = document.getElementById('sonstige-modal');
    const title = modal.querySelector('.sonstige-modal-title');
    const sub = modal.querySelector('.sonstige-modal-sub');
    const list = modal.querySelector('.sonstige-user-list');

    title.textContent = eventItem.title;
    const dateText = eventItem.date ? sonstigeGetLabel(eventItem.date) : '';
    const timeText = eventItem.start ? `${eventItem.start}${eventItem.end ? ' - ' + eventItem.end : ''}` : '';
    const hintText = eventItem.hint ? eventItem.hint : '';
    const parts = [dateText, timeText, hintText].filter(Boolean);
    sub.textContent = parts.join(' | ');

    modal.setAttribute('data-termin-id', String(eventItem.id));
    list.innerHTML = '<div class="sonstige-loading">Benutzer werden geladen...</div>';

    fetchSonstigeUsers()
        .then(users => {
            list.innerHTML = '';
            const selected = new Set((eventItem.zuhause_ids || []).map(id => Number(id)));
            users.forEach(user => {
                const btn = document.createElement('button');
                btn.className = 'sonstige-user-button';
                btn.textContent = user.name;
                btn.setAttribute('data-user-id', String(user.id));
                if (selected.has(user.id)) {
                    btn.classList.add('is-selected');
                }
                btn.addEventListener('click', () => {
                    btn.classList.toggle('is-selected');
                });
                list.appendChild(btn);
            });
        })
        .catch(() => {
            list.innerHTML = '<div class="sonstige-loading">Fehler beim Laden der Benutzer</div>';
        });

    modal.classList.add('is-open');
}

function closeSonstigeModal() {
    const modal = document.getElementById('sonstige-modal');
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.removeAttribute('data-termin-id');
}

function setSonstigeZuhause(terminId, userIds, clear) {
    fetch('termine_sonstige_zuhause.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ termin_id: terminId, user_ids: userIds, clear: !!clear })
    })
        .then(res => res.json())
        .then(data => {
            if (!data || data.error) {
                throw new Error('Set zuhause failed');
            }
            const namesById = new Map((sonstigeUsersCache || []).map(u => [u.id, u.name]));
            const selectedNames = userIds.map(id => namesById.get(id)).filter(Boolean).sort();
            sonstigeEventsCache = sonstigeEventsCache.map(e => {
                if (e.id !== terminId) return e;
                return {
                    ...e,
                    zuhause_by: selectedNames.join(', '),
                    zuhause_ids: userIds
                };
            });
            closeSonstigeModal();
            renderSonstige();
        })
        .catch(() => {
            closeSonstigeModal();
        });
}

function bindSonstigeRowHandlers() {
    const rows = document.querySelectorAll('.sonstige-row[data-termin-id]');
    rows.forEach(row => {
        row.addEventListener('click', () => {
            const terminId = parseInt(row.getAttribute('data-termin-id') || '0', 10);
            if (terminId > 0) {
                openSonstigeModal(terminId);
            }
        });
    });
}

function fetchSonstige() {
    fetch('termine_sonstige_api.php')
        .then(res => res.json())
        .then(data => {
            if (!data || !Array.isArray(data.events)) {
                throw new Error('Invalid termine_sonstige_api response');
            }
            sonstigeEventsCache = data.events;
            sonstigeInteractive = true;
            renderSonstige();
        })
        .catch(() => {
            const sonstigeDiv = document.getElementById('sonstige');
            if (sonstigeDiv) {
                sonstigeDiv.textContent = 'Fehler beim Laden der sonstigen Termine';
            }
        });
}

window.hausordnungSonstige = {
    fetch: fetchSonstige
};
