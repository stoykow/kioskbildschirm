// abfall.js
// Abfallkalender laden + Erledigt-Markierung

let wasteEventsCache = [];
let tasksCache = [];
let wasteUsersCache = null;
let wasteModalReady = false;
let taskModalReady = false;
let wasteInteractive = false;

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

function buildWasteSection(entries, interactive) {
    if (!Array.isArray(entries) || entries.length === 0) {
        return {
            html: '<div class="waste-title">Abfallkalender</div><div class="waste-row">Keine Termine gefunden</div>',
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
        .sort((a, b) => new Date(a.date) - new Date(b.date))
        .slice(0, 3);

    const html = `
        <div class="waste-title">Abfallkalender</div>
        ${upcoming.length === 0 ? '<div class="waste-row">Keine Termine im Zeitraum</div>' :
            upcoming.map(e => {
                const rowClasses = ['waste-row'];
                if (interactive) rowClasses.push('waste-clickable');
                if (e.done_by) rowClasses.push('waste-done-row');
                const statusClass = e.done_by ? 'waste-status waste-status-done' : 'waste-status waste-status-open';
                const statusText = e.done_by ? `Erledigt: ${escapeHtml(e.done_by)}` : 'Unerledigt';
                const doneBadge = `<span class="${statusClass}">${statusText}</span>`;
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

    return {
        html,
        bind: interactive ? bindWasteRowHandlers : null
    };
}

function buildTasksSection(entries, interactive) {
    const title = '<div class="task-title">Aufgaben</div>';
    if (!Array.isArray(entries) || entries.length === 0) {
        return {
            html: `${title}<div class="task-row">Keine Aufgaben offen</div>`,
            bind: null
        };
    }

    const rows = entries.map(task => {
        const rowClasses = ['task-row'];
        if (interactive) rowClasses.push('task-clickable');
        if (task.done_by) rowClasses.push('task-row-done');
        const dataAttr = interactive ? `data-task-id="${task.id}"` : '';
        const dueText = task.due ? getLabel(task.due) : 'Ohne Datum';
        const titleText = escapeHtml(decodeHtmlEntities(task.title));
        const groupName = task.group ? decodeHtmlEntities(task.group) : 'offen';
        const groupText = `Zuständig: ${groupName}`;
        const doneText = task.done_by ? `Erledigt: ${decodeHtmlEntities(task.done_by)}` : 'Unerledigt';
        const statusClass = task.done_by ? 'task-status task-status-done' : 'task-status task-status-open';
        return `
            <div class="${rowClasses.join(' ')}" ${dataAttr}>
                <span class="task-name">${titleText}</span>
                <span class="task-meta">${escapeHtml(dueText)} - ${escapeHtml(groupText)}</span>
                <span class="${statusClass}">${escapeHtml(doneText)}</span>
            </div>
        `;
    }).join('');

    return {
        html: `${title}${rows}`,
        bind: interactive ? bindTaskRowHandlers : null
    };
}

function renderCombined() {
    const wasteDiv = document.getElementById('waste');
    if (!wasteDiv) return;
    const wasteSection = buildWasteSection(wasteEventsCache, wasteInteractive);
    const tasksSection = buildTasksSection(tasksCache, true);
    wasteDiv.innerHTML = `${wasteSection.html}${tasksSection.html}`;
    if (wasteSection.bind) wasteSection.bind();
    if (tasksSection.bind) tasksSection.bind();
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
                <button class="waste-modal-undone">Nicht erledigt</button>
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
    modal.querySelector('.waste-modal-undone').addEventListener('click', () => {
        const eventId = parseInt(modal.getAttribute('data-event-id') || '0', 10);
        if (eventId > 0) {
            markWasteUndone(eventId);
        }
    });
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
    const undoButton = modal.querySelector('.waste-modal-undone');

    title.textContent = eventItem.summary;
    sub.textContent = `${getLabel(eventItem.date)}${eventItem.start ? ' - ' + eventItem.start : ''}`;
    modal.setAttribute('data-event-id', String(eventItem.id));

    if (eventItem.done_by) {
        undoButton.style.display = 'inline-flex';
    } else {
        undoButton.style.display = 'none';
    }

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
        modal.removeAttribute('data-event-id');
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
            renderCombined();
            closeWasteModal();
        })
        .catch(() => {
            closeWasteModal();
        });
}

function markWasteUndone(eventId) {
    fetch('abfall_done.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ event_id: eventId, undone: true })
    })
        .then(response => response.json())
        .then(data => {
            if (!data || !data.ok) {
                throw new Error('Mark undone failed');
            }
            wasteEventsCache = wasteEventsCache.map(e => {
                if (e.id === eventId) {
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

function ensureTaskModal() {
    if (taskModalReady) return;
    const modal = document.createElement('div');
    modal.id = 'task-modal';
    modal.innerHTML = `
        <div class="task-modal-card">
            <div class="task-modal-title">Aufgabe</div>
            <div class="task-modal-sub"></div>
            <div class="task-user-list"></div>
            <div class="task-modal-actions">
                <button class="task-modal-confirm">OK</button>
                <button class="task-modal-cancel">Abbrechen</button>
            </div>
        </div>
    `;
    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            closeTaskModal();
        }
    });
    document.body.appendChild(modal);
    modal.querySelector('.task-modal-cancel').addEventListener('click', closeTaskModal);
    modal.querySelector('.task-modal-confirm').addEventListener('click', () => {
        const taskId = parseInt(modal.getAttribute('data-task-id') || '0', 10);
        if (taskId > 0) {
            const selected = Array.from(modal.querySelectorAll('.task-user-button.is-selected'))
                .map(btn => parseInt(btn.getAttribute('data-user-id') || '0', 10))
                .filter(id => id > 0);
            if (selected.length > 0) {
                markTaskDone(taskId, selected);
            }
        }
    });
    taskModalReady = true;
}

function openTaskModal(taskId) {
    const taskItem = tasksCache.find(t => t.id === taskId);
    if (!taskItem) return;

    ensureTaskModal();
    const modal = document.getElementById('task-modal');
    const title = modal.querySelector('.task-modal-title');
    const sub = modal.querySelector('.task-modal-sub');
    const list = modal.querySelector('.task-user-list');

    title.textContent = taskItem.title;
    const dueText = taskItem.due ? getLabel(taskItem.due) : 'Ohne Datum';
    const groupText = taskItem.group ? `Zustaendig: ${decodeHtmlEntities(taskItem.group)}` : 'Zustaendig: offen';
    const doneText = taskItem.done_by ? `Erledigt: ${decodeHtmlEntities(taskItem.done_by)}` : 'Unerledigt';
    sub.textContent = `${dueText} - ${groupText} - ${doneText}`;
    modal.setAttribute('data-task-id', String(taskItem.id));

    list.innerHTML = '<div class="task-loading">Benutzer werden geladen...</div>';
    modal.classList.add('is-open');

    fetchWasteUsers()
        .then(users => {
            if (!Array.isArray(users) || users.length === 0) {
                list.innerHTML = '<div class="task-loading">Keine Benutzer vorhanden</div>';
                return;
            }
            list.innerHTML = '';
            users.forEach(user => {
                const btn = document.createElement('button');
                btn.className = 'task-user-button';
                btn.textContent = user.name;
                btn.setAttribute('data-user-id', String(user.id));
                btn.addEventListener('click', () => {
                    btn.classList.toggle('is-selected');
                });
                list.appendChild(btn);
            });
        })
        .catch(() => {
            list.innerHTML = '<div class="task-loading">Fehler beim Laden der Benutzer</div>';
        });
}

function closeTaskModal() {
    const modal = document.getElementById('task-modal');
    if (modal) {
        modal.removeAttribute('data-task-id');
        modal.classList.remove('is-open');
    }
}

function markTaskDone(taskId, userIds) {
    fetch('aufgaben_done.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ task_id: taskId, user_ids: userIds })
    })
        .then(response => response.json())
        .then(data => {
            if (!data || !data.ok) {
                throw new Error('Mark task done failed');
            }
            const namesById = new Map((wasteUsersCache || []).map(u => [u.id, u.name]));
            tasksCache = tasksCache.map(t => {
                if (t.id !== taskId) return t;
                const existing = (t.done_by ? String(t.done_by).split(',').map(s => s.trim()) : []);
                const added = userIds.map(id => namesById.get(id)).filter(Boolean);
                const merged = Array.from(new Set([...existing, ...added]));
                return { ...t, done_by: merged.join(', '), done_at: new Date().toISOString() };
            });
            renderCombined();
            closeTaskModal();
        })
        .catch(() => {
            closeTaskModal();
        });
}

function bindTaskRowHandlers() {
    const rows = document.querySelectorAll('.task-row[data-task-id]');
    rows.forEach(row => {
        row.addEventListener('click', () => {
            const taskId = parseInt(row.getAttribute('data-task-id'), 10);
            if (taskId > 0) {
                openTaskModal(taskId);
            }
        });
    });
}

function fetchTasks() {
    fetch('aufgaben_api.php')
        .then(response => response.json())
        .then(data => {
            if (!data || !Array.isArray(data.tasks)) {
                throw new Error('Invalid aufgaben_api response');
            }
            tasksCache = data.tasks;
            renderCombined();
        })
        .catch(() => {
            tasksCache = [];
            renderCombined();
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
            wasteInteractive = true;
            renderCombined();
        })
        .catch(() => {
            fetch('enso.php')
                .then(response => response.json())
                .then(data => {
                    wasteEventsCache = data;
                    wasteInteractive = false;
                    renderCombined();
                })
                .catch(error => {
                    document.getElementById('waste').textContent = 'Fehler beim Laden des Abfallkalenders';
                    console.error('Fehler beim Laden des Abfallkalenders:', error);
                });
        });
}
