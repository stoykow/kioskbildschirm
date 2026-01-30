// aufgaben.js
// Aufgaben laden + Erledigt-Markierung

let tasksCache = [];
let taskUsersCache = null;
let taskModalReady = false;

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

function getShowFrom(task) {
    if (!task || !task.due) return null;
    const target = parseDateLocal(task.due);
    if (!target) return null;
    const isRein = task.source_type && String(task.source_type).toLowerCase().endsWith('_rein');
    if (isRein) {
        target.setHours(6, 0, 0, 0);
        return target;
    }
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

function shouldShowTask(task) {
    const now = new Date();
    const doneAt = parseTimestamp(task.done_at);
    if (doneAt) {
        const until = new Date(doneAt.getTime() + 10 * 60 * 1000);
        return now <= until;
    }
    const showFrom = getShowFrom(task);
    if (showFrom && now < showFrom) return false;
    if (!task.due) return true;
    return true;
}

function buildTasksSection(entries, interactive) {
    const title = '<div class="task-title">Aufgaben</div>';
    if (!Array.isArray(entries) || entries.length === 0) {
        return {
            html: `${title}<div class="task-row">Keine Aufgaben offen</div>`,
            bind: null
        };
    }

    const visibleEntries = entries.filter(task => shouldShowTask(task));
    if (visibleEntries.length === 0) {
        return {
            html: `${title}<div class="task-row">Keine Aufgaben offen</div>`,
            bind: null
        };
    }

    const rows = visibleEntries.map(task => {
        const rowClasses = ['task-row'];
        if (interactive) rowClasses.push('task-clickable');
        if (task.done_by) rowClasses.push('task-row-done');
        const dataAttr = interactive ? `data-task-id="${task.id}"` : '';
        const dueText = task.due ? getLabel(task.due) : 'Ohne Datum';
        const titleText = escapeHtml(decodeHtmlEntities(task.title));
        const groupName = task.group ? decodeHtmlEntities(task.group) : 'offen';
        const doneText = task.done_by ? `Erledigt: ${decodeHtmlEntities(task.done_by)}` : 'Unerledigt';
        const statusClass = task.done_by ? 'task-status task-status-done' : 'task-status task-status-open';
        return `
            <div class="${rowClasses.join(' ')}" ${dataAttr}>
                <div class="task-line">
                    <span class="task-date">${escapeHtml(dueText)}</span>
                    <span class="task-name">${titleText}</span>
                    <span class="task-group">(${escapeHtml(groupName)})</span>
                    <span class="${statusClass}">${escapeHtml(doneText)}</span>
                </div>
            </div>
        `;
    }).join('');

    return {
        html: `${title}${rows}`,
        bind: interactive ? bindTaskRowHandlers : null
    };
}

function renderAll() {
    if (typeof window.hausordnungRenderCombined === 'function') {
        window.hausordnungRenderCombined();
    }
}

function fetchTaskUsers() {
    if (taskUsersCache) {
        return Promise.resolve(taskUsersCache);
    }
    return fetch('abfall_users.php')
        .then(response => response.json())
        .then(data => {
            if (!data || !Array.isArray(data.users)) {
                throw new Error('Invalid users response');
            }
            taskUsersCache = data.users;
            return taskUsersCache;
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
                <button class="task-modal-undone">Nicht erledigt</button>
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
    modal.querySelector('.task-modal-undone').addEventListener('click', () => {
        const taskId = parseInt(modal.getAttribute('data-task-id') || '0', 10);
        if (taskId > 0) {
            markTaskUndone(taskId);
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
    const groupText = taskItem.group ? `Zuständig: ${decodeHtmlEntities(taskItem.group)}` : 'Zuständig: offen';
    const doneText = taskItem.done_by ? `Erledigt: ${decodeHtmlEntities(taskItem.done_by)}` : 'Unerledigt';
    sub.textContent = `${dueText} - ${groupText} - ${doneText}`;
    modal.setAttribute('data-task-id', String(taskItem.id));

    list.innerHTML = '<div class="task-loading">Benutzer werden geladen...</div>';
    modal.classList.add('is-open');

    const undoButton = modal.querySelector('.task-modal-undone');
    undoButton.style.display = taskItem.done_by ? 'inline-flex' : 'none';

    fetchTaskUsers()
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
            const namesById = new Map((taskUsersCache || []).map(u => [u.id, u.name]));
            tasksCache = tasksCache.map(t => {
                if (t.id !== taskId) return t;
                const existing = (t.done_by ? String(t.done_by).split(',').map(s => s.trim()) : []);
                const added = userIds.map(id => namesById.get(id)).filter(Boolean);
                const merged = Array.from(new Set([...existing, ...added]));
                return { ...t, done_by: merged.join(', '), done_at: new Date().toISOString() };
            });
            renderAll();
            closeTaskModal();
        })
        .catch(() => {
            closeTaskModal();
        });
}

function markTaskUndone(taskId) {
    fetch('aufgaben_done.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ task_id: taskId, undone: true })
    })
        .then(response => response.json())
        .then(data => {
            if (!data || !data.ok) {
                throw new Error('Mark task undone failed');
            }
            tasksCache = tasksCache.map(t => {
                if (t.id === taskId) {
                    return { ...t, done_by: null, done_at: null };
                }
                return t;
            });
            renderAll();
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
            renderAll();
        })
        .catch(() => {
            tasksCache = [];
            renderAll();
        });
}

window.hausordnungTasks = {
    buildSection: () => buildTasksSection(tasksCache, true),
};
