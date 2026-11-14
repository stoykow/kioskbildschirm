// kiosk.js
// Initialisierung

const kioskPrivateCleanups = [];

window.kioskIsPrivate = function () {
    return !!(window.kioskAccess && window.kioskAccess.private === true);
};

window.kioskRegisterPrivateCleanup = function (cleanup) {
    kioskPrivateCleanups.push(cleanup);
};

window.kioskHandleUnauthorized = function () {
    window.kioskAccess = { private: false, csrf: null };
    // Private Inhalte sofort entfernen, auch wenn ein späteres Neurendern scheitert.
    document.querySelectorAll('.task-title, .task-row, .sonstige-status, .waste-status, #weather-door-row, #task-modal, #waste-modal, #sonstige-modal')
        .forEach(element => element.remove());
    kioskPrivateCleanups.forEach(cleanup => {
        try { cleanup(); }
        catch (error) { console.error('Private Ansicht konnte nicht vollständig zurückgesetzt werden:', error); }
    });
    const logout = document.getElementById('kiosk-logout');
    if (logout) logout.remove();
    const login = document.getElementById('kiosk-login');
    if (login) login.hidden = false;
};

// Nur die eigenen Kiosk-Aufrufe verwenden diesen Helfer.
window.kioskFetch = function (url, options = {}) {
    const method = String(options.method || 'GET').toUpperCase();
    const headers = { ...(options.headers || {}) };
    if (!['GET', 'HEAD'].includes(method)) {
        if (!window.kioskIsPrivate() || !window.kioskAccess.csrf) {
            return Promise.reject(new Error('Private access required'));
        }
        headers['X-CSRF-Token'] = window.kioskAccess.csrf;
    }
    return fetch(url, { cache: 'no-store', credentials: 'same-origin', ...options, headers })
        .then(response => {
            if (response.status === 401) {
                window.kioskHandleUnauthorized();
                throw new Error('Private session expired');
            }
            return response;
        });
};

if (typeof window.addEventListener === 'function') {
    window.addEventListener('pageshow', event => {
        if (event.persisted) {
            window.kioskHandleUnauthorized();
            location.reload();
        }
    });
}

function initKiosk() {
    fetchWeather();
    fetchWaste();
    fetchSonstige();
    if (window.kioskIsPrivate()) fetchTasks();
    fetchTrains();
    fetchTrams();
    fetchBus();
    setInterval(updateStatusTime, 1000); // Datum/Uhrzeit im Status jede Sekunde
    setInterval(fetchWeather, 600000); // Aktualisiere das Wetter alle 10 Minuten
    setInterval(fetchTrains, 300000); // Aktualisiere die Zugdaten alle 5 Minuten
    setInterval(fetchTrams, 300000); // Aktualisiere die Tramdaten alle 5 Minuten
    setInterval(fetchBus, 300000); // Aktualisiere die Busdaten alle 5 Minuten
    setInterval(fetchWaste, 300000); // Aktualisiere die Abfallkalender alle 5 Minuten
    setInterval(fetchSonstige, 300000); // Aktualisiere die sonstigen Termine alle 5 Minuten
    setInterval(() => {
        if (window.kioskIsPrivate()) fetchTasks();
    }, 300000); // Aktualisiere private Aufgaben alle 5 Minuten
    setInterval(() => location.reload(), 1200000); // kompletter Reload alle 20 Minuten
}

window.onload = initKiosk;
