// abfall.js
// Abfallkalender laden + Erledigt-Markierung

let wasteEventsCache = [];
let wasteUsersCache = null;
let wasteModalReady = false;
let wasteInteractive = false;
let wasteLoadError = false;

function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function decodeHtmlEntities(value) {
    if (value === null || value === undefined) return '';
    const textarea = document.createElement('textarea');
    textarea.innerHTML = String(value);
    return textarea.value;
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

    if (diff == 0) {
        return `Heute ${tagDatum}`;
    } else if (diff == 1) {
        return `Morgen ${tagDatum}`;
    } else if (diff == 2) {
        return `Übermorgen ${tagDatum}`;
    } else if (diff < (7 - isoDay)) {
        return `am ${tag} ${tagDatum}`;
    } else if (diff < (14 - isoDay)) {
        return `nächster ${tag} ${tagDatum}`;
    } else {
        return `am ${tagDatum2}`;
    }
}

function parseDateLocal(dateStr) {
    if (!dateStr) return null;
    const parts = String(dateStr).split('-').map(n => parseInt(n, 10));
    if (parts.length !== 3 || parts.some(n => Number.isNaN(n))) return null;
    return new Date(parts[0], parts[1] - 1, parts[2]);
}

function getShowFrom(dateStr) {
    const target = parseDateLocal(dateStr);
    if (!target) return null;
    const start = new Date(target.getTime());
    start.setDate(start.getDate() - 1);
    start.setHours(0, 0, 0, 0);
    return start;
}

function parseTimestamp(value) {
    if (!value) return null;
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? null : d;
}

function shouldShowWasteEvent(eventItem, phase, ignoreShowFrom) {
    const now = new Date();
    const eventDate = parseDateLocal(eventItem.date);
    const today = new Date(now.getTime());
    today.setHours(0, 0, 0, 0);
    // Der ICS-Fallback kann auch vergangene Termine enthalten.
    if (!eventDate || eventDate < today) return false;
    if (!window.kioskIsPrivate()) return true;
    if (!ignoreShowFrom) {
        const showFrom = getShowFrom(eventItem.date);
        if (showFrom && now < showFrom) return false;
    }

    // Eine frühe Rausgestellt-Markierung erledigt nicht die künftige Leerung.
    if (phase !== 'reinstellen') return true;

    const doneAt = parseTimestamp(eventItem.rein_done_at);
    if (doneAt) {
        const until = new Date(doneAt.getTime() + 10 * 60 * 1000);
        return now <= until;
    }
    return true;
}

function getWastePhase(dateStr) {
    if (!window.kioskIsPrivate()) return 'rausstellen';
    const target = parseDateLocal(dateStr);
    if (!target) return 'rausstellen';
    const now = new Date();
    const sameDay = now.getFullYear() === target.getFullYear()
        && now.getMonth() === target.getMonth()
        && now.getDate() === target.getDate();
    if (sameDay && now.getHours() >= 6) {
        return 'reinstellen';
    }
    return 'rausstellen';
}

function getDisplayLabel(dateStr, phase) {
    if (phase === 'reinstellen') {
        return 'Heute';
    }
    return getLabel(dateStr);
}

function getDisplaySummary(summary, phase) {
    const base = String(summary || '');
    if (phase !== 'reinstellen') {
        return base;
    }
    if (base.toLowerCase().includes('reinstellen')) {
        return base;
    }
    return `${base} reinstellen`;
}

function buildWasteRow(e, interactive) {
    interactive = interactive && window.kioskIsPrivate();
    const isSchadstoff = String(e.summary || '').toLowerCase().startsWith('schadstoffmobil');
    const phase = getWastePhase(e.date);
    const rowClasses = ['waste-row'];
    if (isSchadstoff) rowClasses.push('waste-row-muted');
    if (interactive && !isSchadstoff) rowClasses.push('waste-clickable');
    const time = e.start ? `<span class="waste-time">${escapeHtml(e.start)}${e.end ? ' - ' + escapeHtml(e.end) : ''}</span>` : '';
    const dataAttr = interactive && !isSchadstoff && e.id ? `data-event-id="${escapeHtml(e.id)}"` : '';
    const summary = isSchadstoff ? e.summary : getDisplaySummary(e.summary, phase);
    const status = window.kioskIsPrivate() && !isSchadstoff && phase === 'rausstellen' && (e.done_at || e.done_by)
        ? '<span class="waste-status waste-status-done">Bereits rausgestellt</span>'
        : '';
    return `
        <div class="${rowClasses.join(' ')}" ${dataAttr}>
            <span class="waste-date">${escapeHtml(getDisplayLabel(e.date, phase))}</span>
            <span class="waste-type">${escapeHtml(summary)}</span>
            ${time}
            ${status}
        </div>
    `;
}

function buildSonstigeRow(e, interactive) {
    interactive = interactive && window.kioskIsPrivate();
    const rowClasses = ['sonstige-row'];
    if (interactive) rowClasses.push('sonstige-clickable');
    const dataAttr = interactive && e.id ? `data-termin-id="${e.id}"` : '';
    const time = e.start ? `<span class="sonstige-time">${escapeHtml(e.start)}${e.end ? ' - ' + escapeHtml(e.end) : ''}</span>` : '';
    const hint = e.hint ? `<span class="sonstige-hint">${escapeHtml(e.hint)}</span>` : '';
    const needsHome = window.kioskIsPrivate() && !!e.requires_home;
    const hasHome = e.zuhause_by && String(e.zuhause_by).trim() !== '';
    const showStatus = needsHome;
    const statusClass = hasHome ? 'sonstige-status' : 'sonstige-status sonstige-status-warn';
    const statusText = hasHome ? `Zuhause: ${escapeHtml(e.zuhause_by)}` : 'Keiner zuhause';
    const statusHtml = showStatus ? `<span class="${statusClass}">${statusText}</span>` : '';
    return `
        <div class="${rowClasses.join(' ')}" ${dataAttr}>
            <span class="sonstige-date">${escapeHtml(getLabel(e.date))}</span>
            <span class="sonstige-type">${escapeHtml(e.title)}</span>
            ${hint}
            ${time}
            ${statusHtml}
        </div>
    `;
}

function buildTermineSection(wasteEntries, sonstigeEntries, interactiveWaste, interactiveSonstige) {
    const wasteList = Array.isArray(wasteEntries) ? wasteEntries : [];
    const sonstigeList = Array.isArray(sonstigeEntries) ? sonstigeEntries : [];
    if (wasteList.length === 0 && sonstigeList.length === 0) {
        return { html: '', bind: null };
    }

    const combined = [];
    wasteList.forEach(item => {
        const phase = getWastePhase(item.date);
        if (!shouldShowWasteEvent(item, phase, true)) return;
        combined.push({
            type: 'waste',
            date: item.date,
            time: item.start || '',
            data: item
        });
    });
    sonstigeList.forEach(item => {
        combined.push({
            type: 'sonstige',
            date: item.date,
            time: item.start || '',
            data: item
        });
    });

    if (combined.length === 0) {
        return { html: '', bind: null };
    }

    combined.sort((a, b) => {
        const da = new Date(`${a.date}T${a.time || '00:00'}`);
        const db = new Date(`${b.date}T${b.time || '00:00'}`);
        if (da < db) return -1;
        if (da > db) return 1;
        return String(a.type).localeCompare(String(b.type));
    });

    const html = `
        <div class="waste-title">Termine</div>
        ${combined.map(item => (
            item.type === 'waste'
                ? buildWasteRow(item.data, interactiveWaste)
                : buildSonstigeRow(item.data, interactiveSonstige)
        )).join('')}
    `;

    return {
        html,
        bind: () => {
            if (interactiveWaste) bindWasteRowHandlers();
            if (interactiveSonstige && typeof window.hausordnungBindSonstige === 'function') {
                window.hausordnungBindSonstige();
            }
        }
    };
}

function getTasksSection() {
    if (window.hausordnungTasks && typeof window.hausordnungTasks.buildSection == 'function') {
        return window.hausordnungTasks.buildSection();
    }
    return { html: '', bind: null };
}

function renderCombined() {
    const termineDiv = document.getElementById('termine');
    if (!termineDiv) return;
    const sonstigeEntries = window.hausordnungSonstigeEvents || [];
    const sonstigeInteractive = window.hausordnungSonstigeInteractive === true;
    const termineSection = buildTermineSection(wasteEventsCache, sonstigeEntries, wasteInteractive, sonstigeInteractive);
    const tasksSection = getTasksSection();
    let termineHtml = termineSection.html;
    if (wasteLoadError) {
        if (!termineHtml) termineHtml = '<div class="waste-title">Termine</div>';
        termineHtml += '<div class="waste-row" role="status">Die Abfalltermine konnten gerade nicht aktualisiert werden.</div>';
    }
    termineDiv.innerHTML = `${termineHtml}${tasksSection.html}`;
    if (termineSection.bind) termineSection.bind();
    if (tasksSection.bind) tasksSection.bind();
}

window.hausordnungRenderCombined = renderCombined;

function fetchWasteUsers() {
    if (!window.kioskIsPrivate()) return Promise.reject(new Error('Private access required'));
    if (wasteUsersCache) {
        return Promise.resolve(wasteUsersCache);
    }
    return window.kioskFetch('abfall_users.php')
        .then(response => {
            if (!response.ok) throw new Error('Users unavailable');
            return response.json();
        })
        .then(data => {
            if (!window.kioskIsPrivate()) throw new Error('Private access required');
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
                <button class="waste-modal-confirm">OK</button>
                <button class="waste-modal-undone">Nicht erledigt</button>
                <button class="waste-modal-cancel">Abbrechen</button>
            </div>
        </div>
    `;
    modal.addEventListener('click', (e) => {
        if (e.target == modal) {
            closeWasteModal();
        }
    });
    document.body.appendChild(modal);
    modal.querySelector('.waste-modal-cancel').addEventListener('click', closeWasteModal);
    modal.querySelector('.waste-modal-undone').addEventListener('click', () => {
        const eventId = parseInt(modal.getAttribute('data-event-id') || '0', 10);
        if (eventId > 0) {
            markWasteUndone(eventId);
        }
    });
    modal.querySelector('.waste-modal-confirm').addEventListener('click', () => {
        const eventId = parseInt(modal.getAttribute('data-event-id') || '0', 10);
        if (eventId > 0) {
            const selected = Array.from(modal.querySelectorAll('.waste-user-button.is-selected'))
                .map(btn => parseInt(btn.getAttribute('data-user-id') || '0', 10))
                .filter(id => id > 0);
            if (selected.length > 0) {
                markWasteDone(eventId, selected);
            }
        }
    });
    wasteModalReady = true;
}

function openWasteModal(eventId) {
    if (!window.kioskIsPrivate()) return;
    const eventItem = wasteEventsCache.find(e => e.id == eventId);
    if (!eventItem) return;

    ensureWasteModal();
    const modal = document.getElementById('waste-modal');
    const title = modal.querySelector('.waste-modal-title');
    const sub = modal.querySelector('.waste-modal-sub');
    const list = modal.querySelector('.waste-user-list');
    const undoButton = modal.querySelector('.waste-modal-undone');

    const action = getWastePhase(eventItem.date);
    const doneBy = action === 'reinstellen' ? eventItem.rein_done_by : eventItem.done_by;
    title.textContent = getDisplaySummary(eventItem.summary, action);
    sub.textContent = `${getDisplayLabel(eventItem.date, action)}${eventItem.start ? ' - ' + eventItem.start : ''}`;
    modal.setAttribute('data-event-id', String(eventItem.id));
    modal.setAttribute('data-action', action);

    if (doneBy) {
        undoButton.style.display = 'inline-flex';
    } else {
        undoButton.style.display = 'none';
    }

    list.innerHTML = '<div class="waste-loading">Benutzer werden geladen...</div>';
    modal.classList.add('is-open');

    fetchWasteUsers()
        .then(users => {
            if (!window.kioskIsPrivate()) return;
            if (!Array.isArray(users) || users.length == 0) {
                list.innerHTML = '<div class="waste-loading">Keine Benutzer vorhanden</div>';
                return;
            }
            list.innerHTML = '';
            users.forEach(user => {
                const btn = document.createElement('button');
                btn.className = 'waste-user-button';
                btn.textContent = user.name;
                btn.setAttribute('data-user-id', String(user.id));
                btn.addEventListener('click', () => {
                    btn.classList.toggle('is-selected');
                });
                list.appendChild(btn);
            });
            if (doneBy) {
                const info = document.createElement('div');
                info.className = 'waste-done-info';
                info.textContent = `Bereits erledigt von ${doneBy}`;
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
        modal.removeAttribute('data-event-id');
        modal.classList.remove('is-open');
    }
}

function markWasteDone(eventId, userIds) {
    if (!window.kioskIsPrivate()) return;
    const modal = document.getElementById('waste-modal');
    const action = modal ? (modal.getAttribute('data-action') || 'rausstellen') : 'rausstellen';
    window.kioskFetch('abfall_done.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ event_id: eventId, user_ids: userIds, action })
    })
        .then(response => response.json())
        .then(data => {
            if (!window.kioskIsPrivate()) return;
            if (!data || !data.ok) {
                throw new Error('Mark done failed');
            }
            const namesById = new Map((wasteUsersCache || []).map(u => [u.id, u.name]));
            wasteEventsCache = wasteEventsCache.map(e => {
                if (e.id != eventId) return e;
                const added = userIds.map(id => namesById.get(id)).filter(Boolean);
                if (action === 'reinstellen') {
                    const existingRein = (e.rein_done_by ? String(e.rein_done_by).split(',').map(s => s.trim()) : []);
                    const mergedRein = Array.from(new Set([...existingRein, ...added]));
                    return { ...e, rein_done_by: mergedRein.join(', '), rein_done_at: new Date().toISOString() };
                }
                const existing = (e.done_by ? String(e.done_by).split(',').map(s => s.trim()) : []);
                const merged = Array.from(new Set([...existing, ...added]));
                return { ...e, done_by: merged.join(', '), done_at: new Date().toISOString() };
            });
            renderCombined();
            closeWasteModal();
        })
        .catch(() => {
            closeWasteModal();
        });
}

function markWasteUndone(eventId) {
    if (!window.kioskIsPrivate()) return;
    const modal = document.getElementById('waste-modal');
    const action = modal ? (modal.getAttribute('data-action') || 'rausstellen') : 'rausstellen';
    window.kioskFetch('abfall_done.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ event_id: eventId, undone: true, action })
    })
        .then(response => response.json())
        .then(data => {
            if (!window.kioskIsPrivate()) return;
            if (!data || !data.ok) {
                throw new Error('Mark undone failed');
            }
            wasteEventsCache = wasteEventsCache.map(e => {
                if (e.id == eventId && action === 'reinstellen') {
                    return { ...e, rein_done_by: null, rein_done_at: null };
                }
                if (e.id == eventId) {
                    return { ...e, done_by: null, done_at: null };
                }
                return e;
            });
            renderCombined();
            closeWasteModal();
        })
        .catch(() => {
            closeWasteModal();
        });
}

function bindWasteRowHandlers() {
    if (!window.kioskIsPrivate()) return;
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
    window.kioskFetch('abfall_api.php')
        .then(response => {
            if (!response.ok) throw new Error('Waste events unavailable');
            return response.json();
        })
        .then(data => {
            if (!data || !Array.isArray(data.events)) {
                throw new Error('Invalid abfall_api response');
            }
            wasteEventsCache = window.kioskIsPrivate() ? data.events : data.events.map(publicWasteEvent);
            wasteInteractive = window.kioskIsPrivate();
            wasteLoadError = false;
            renderCombined();
        })
        .catch(() => {
            window.kioskFetch('enso.php')
                .then(response => {
                    if (!response.ok) throw new Error('Waste fallback unavailable');
                    return response.json();
                })
                .then(data => {
                    if (!Array.isArray(data)) throw new Error('Invalid waste fallback response');
                    wasteEventsCache = window.kioskIsPrivate() ? data : data.map(publicWasteEvent);
                    wasteInteractive = false;
                    wasteLoadError = false;
                    renderCombined();
                })
                .catch(error => {
                    wasteLoadError = true;
                    renderCombined();
                    console.error('Fehler beim Laden des Abfallkalenders:', error);
                });
        });
}

function publicWasteEvent(event) {
    return { id: event.id, date: event.date, summary: event.summary, start: event.start, end: event.end };
}

window.kioskRegisterPrivateCleanup(() => {
    wasteEventsCache = wasteEventsCache.map(publicWasteEvent);
    wasteUsersCache = null;
    wasteInteractive = false;
    const modal = document.getElementById('waste-modal');
    if (modal) modal.remove();
    wasteModalReady = false;
    renderCombined();
});
