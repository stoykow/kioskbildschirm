// kiosk.js
// Initialisierung

const kioskPrivateCleanups = [];

// Das Fragment bleibt bei Reloads erhalten und wird nicht an den Server gesendet.
// Nur ein signiertes Ticket darf APIs freischalten, niemals die lokale Anzeige allein.
function kioskSetFrameFragment(ticket) {
    const url = new URL(window.location.href);
    url.searchParams.delete('TOKEN');
    url.searchParams.delete('FRAME');
    url.hash = ticket ? 'kiosk_access=' + ticket : '';
    window.history.replaceState(null, '', url.href);
}

if (window.location && window.history) {
    const fragment = /^#kiosk_access=(frame1\.[0-9]{10}\.[a-f0-9]{32}\.[a-f0-9]{64})$/.exec(window.location.hash);
    const ticket = (window.kioskAccess && window.kioskAccess.access) || (fragment && fragment[1]);
    if (ticket) {
        window.kioskAccess = { private: true, csrf: null, access: ticket };
        kioskSetFrameFragment(ticket);
        const login = document.getElementById('kiosk-login');
        if (login) login.hidden = true;
        const cookieLogout = document.getElementById('kiosk-logout');
        if (cookieLogout) cookieLogout.hidden = true;
        const logout = document.getElementById('kiosk-frame-logout');
        if (logout) {
            logout.hidden = false;
            logout.onclick = () => {
                window.kioskHandleUnauthorized();
                // Die Zugangsseite zeigt keine privaten Daten, auch bei alten Cookies.
                window.location.replace('zugang.php');
            };
        }
    }
}

window.kioskIsPrivate = function () {
    return !!(window.kioskAccess && window.kioskAccess.private === true);
};

const kioskLegalFooter = document.getElementById('kiosk-legal');
if (kioskLegalFooter) kioskLegalFooter.hidden = window.kioskIsPrivate();

window.kioskRegisterPrivateCleanup = function (cleanup) {
    kioskPrivateCleanups.push(cleanup);
};

window.kioskHandleUnauthorized = function () {
    if (window.kioskAccess && window.kioskAccess.access && window.location && window.history) {
        kioskSetFrameFragment(null);
    }
    window.kioskAccess = { private: false, csrf: null };
    if (kioskLegalFooter) kioskLegalFooter.hidden = false;
    const frameLogout = document.getElementById('kiosk-frame-logout');
    if (frameLogout) frameLogout.hidden = true;
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
    const access = window.kioskAccess && window.kioskAccess.access;
    if (access) {
        // Zugangsdaten ausschließlich an die eigene Anwendung senden.
        if (new URL(url, window.location.href).origin !== window.location.origin) {
            return Promise.reject(new Error('Cross-origin private request blocked'));
        }
        headers.Authorization = 'Bearer ' + access;
    }
    if (!['GET', 'HEAD'].includes(method)) {
        if (!window.kioskIsPrivate() || (!window.kioskAccess.csrf && !access)) {
            return Promise.reject(new Error('Private access required'));
        }
        if (!access) headers['X-CSRF-Token'] = window.kioskAccess.csrf;
    }
    return fetch(url, { cache: 'no-store', credentials: 'same-origin', ...options, headers, ...(access ? { credentials: 'omit', redirect: 'error' } : {}) })
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
            const ticket = window.kioskAccess && window.kioskAccess.access;
            window.kioskHandleUnauthorized();
            if (ticket) kioskSetFrameFragment(ticket);
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
