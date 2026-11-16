// Aufruf: node tests/abfall_ui_test.cjs
// Die öffentliche Anzeige wird mit unveränderten Skripten im Browserkontext geprüft.
'use strict';

process.env.TZ = 'Europe/Berlin';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const sourceDirectory = path.join(__dirname, '..');
const NativeDate = Date;
const liveEvents = [
    { id: 1, date: '2026-11-20', summary: 'Restmüll', done_at: '2026-11-13T07:32:00+01:00', done_by: 'David', rein_done_at: null },
    { id: 2, date: '2026-11-24', summary: 'Blaue Tonne', done_at: '2026-11-13T07:31:00+01:00', done_by: 'Daniel', rein_done_at: null },
    { id: 3, date: '2026-11-27', summary: 'Gelbe Tonne', done_at: '2026-11-13T07:31:00+01:00', done_by: 'Daniel', rein_done_at: null },
];
const openTask = {
    id: 91, title: 'Fenster prüfen', due: '2026-11-14',
    show_from: '2026-11-13T00:00:00+01:00', source_type: 'manuell',
    done_at: null, done_by: null,
};

function createBrowser(options = {}) {
    const state = {
        now: NativeDate.parse(options.now || '2026-11-14T12:00:00+01:00'),
        events: structuredClone(options.events || liveEvents),
        fallbackEvents: [], tasks: [], apiFailure: false, fallbackFailure: false,
        invalidFallback: false, requests: [],
        requestOptions: [], tasksUnauthorized: false,
        sonstige: [{ id: 8, date: '2026-11-15', title: 'Heizungswartung', requires_home: true, zuhause_by: 'PRIVAT-ZUHAUSE', zuhause_ids: [77] }],
    };
    class BrowserDate extends NativeDate {
        constructor(...args) { super(...(args.length ? args : [state.now])); }
        static now() { return state.now; }
    }
    const target = {
        html: '', text: '',
        set innerHTML(value) { this.html = value; this.text = ''; },
        get innerHTML() { return this.html; },
        set textContent(value) { this.text = value; this.html = ''; },
        get textContent() { return this.text; },
    };
    const weatherTarget = { innerHTML: '', textContent: '' };
    const document = {
        getElementById: id => id === 'termine' ? target : (id === 'weather' ? weatherTarget : null),
        querySelectorAll: () => [],
        createElement: () => ({
            value: '',
            set innerHTML(value) {
                this.value = String(value).replace(/&lt;/g, '<').replace(/&gt;/g, '>')
                    .replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&');
            },
        }),
    };
    const sandbox = {
        Date: BrowserDate, document, console: { error() {} },
        URL,
        location: new URL(options.url || 'https://kiosk.example/index.php'),
        history: { replaceState(_state, _title, url) { sandbox.location = new URL(url); } },
        kioskAccess: options.access ? { private: true, csrf: null, access: options.access } : (options.private === false ? undefined : { private: true, csrf: 'fixture-csrf' }),
        fetch: async (url, requestOptions = {}) => {
            state.requests.push(url);
            state.requestOptions.push(requestOptions);
            if (url === 'aufgaben_api.php') {
                return { ok: !state.tasksUnauthorized, status: state.tasksUnauthorized ? 401 : 200, json: async () => ({ tasks: structuredClone(state.tasks) }) };
            }
            if (url === 'abfall_api.php') {
                return { ok: !state.apiFailure, json: async () => ({ events: structuredClone(state.events) }) };
            }
            if (url === 'termine_sonstige_api.php') {
                return { ok: true, status: 200, json: async () => ({ events: structuredClone(state.sonstige) }) };
            }
            if (url.startsWith('geraet_status.php?')) {
                return { ok: true, status: 200, json: async () => [{ payload: {
                    'sensor.geiger_temperatur': { state: '21.3', attributes: { unit_of_measurement: '°C' } },
                    'sensor.geiger_luftfeuchte': { state: '53', attributes: { unit_of_measurement: '%' } },
                    'sensor.geiger_luftdruck': { state: '1013', attributes: { unit_of_measurement: 'hPa' } },
                    'sensor.multigeiger_geiger_dosisleistung': { state: '0.12', attributes: { unit_of_measurement: 'µSv/h' } },
                    'lock.haustur': { state: 'unlocked' },
                } }] };
            }
            assert.equal(url, 'enso.php');
            return {
                ok: !state.fallbackFailure,
                json: async () => state.invalidFallback ? {} : structuredClone(state.fallbackEvents),
            };
        },
    };
    sandbox.window = sandbox;
    vm.createContext(sandbox);
    for (const filename of ['kiosk.js', 'wetter.js', 'abfall.js', 'sonstige.js', 'aufgaben.js']) {
        vm.runInContext(fs.readFileSync(path.join(sourceDirectory, filename), 'utf8'), sandbox, { filename });
    }
    const settle = async () => { await new Promise(setImmediate); await new Promise(setImmediate); };
    return {
        state, sandbox, target, weatherTarget,
        html: () => target.innerHTML,
        async fetchWaste() { sandbox.fetchWaste(); await settle(); },
        async fetchTasks(tasks) { state.tasks = tasks; sandbox.fetchTasks(); await settle(); },
        async fetchSonstige() { sandbox.fetchSonstige(); await settle(); },
        async fetchWeather() { await sandbox.fetchWeather(); await settle(); },
        setTime(value) { state.now = NativeDate.parse(value); sandbox.hausordnungRenderCombined(); },
    };
}

const cases = [];
const test = (name, run) => cases.push({ name, run });
const eventIds = html => [...html.matchAll(/data-event-id="([0-9]+)"/g)].map(match => Number(match[1]));
const statusCount = html => [...html.matchAll(/Bereits rausgestellt/g)].length;

test('Die drei am 13.11. markierten Leerungen bleiben am 14.11. sichtbar', async () => {
    const browser = createBrowser();
    const original = structuredClone(browser.state.events);
    await browser.fetchWaste();
    assert.deepEqual(eventIds(browser.html()), [1, 2, 3]);
    assert.equal(statusCount(browser.html()), 3);
    assert.match(browser.html(), /Restmüll/);
    assert.match(browser.html(), /Blaue Tonne/);
    assert.match(browser.html(), /Gelbe Tonne/);
    assert.doesNotMatch(browser.html(), /reinstellen/);
    assert.deepEqual(browser.state.events, original);
});

test('Noch nicht rausgestellte künftige Leerungen erscheinen ohne Erledigt-Hinweis', async () => {
    const browser = createBrowser({ events: [{ ...liveEvents[0], done_at: null, done_by: null }] });
    await browser.fetchWaste();
    assert.deepEqual(eventIds(browser.html()), [1]);
    assert.equal(statusCount(browser.html()), 0);
});

test('Am Abholtag vor sechs Uhr bleibt die Rausgestellt-Vorschau erhalten', async () => {
    const browser = createBrowser({ now: '2026-11-20T05:59:59+01:00', events: [liveEvents[0]] });
    await browser.fetchWaste();
    assert.deepEqual(eventIds(browser.html()), [1]);
    assert.match(browser.html(), /Heute/);
    assert.equal(statusCount(browser.html()), 1);
    assert.doesNotMatch(browser.html(), /reinstellen/);
});

test('Um sechs Uhr wechselt die Leerung in die bisherige Rein-Phase', async () => {
    const browser = createBrowser({ now: '2026-11-20T06:00:00+01:00', events: [liveEvents[0]] });
    await browser.fetchWaste();
    assert.deepEqual(eventIds(browser.html()), [1]);
    assert.match(browser.html(), /Restmüll reinstellen/);
    assert.equal(statusCount(browser.html()), 0);
});

test('Nach dem Reinstellen bleibt die Leerung genau zehn Minuten sichtbar', async () => {
    const browser = createBrowser({
        now: '2026-11-20T06:09:59+01:00',
        events: [{ ...liveEvents[0], rein_done_at: '2026-11-20T06:00:00+01:00', rein_done_by: 'David' }],
    });
    await browser.fetchWaste();
    assert.deepEqual(eventIds(browser.html()), [1]);
    browser.setTime('2026-11-20T06:10:00+01:00');
    assert.deepEqual(eventIds(browser.html()), [1]);
    browser.setTime('2026-11-20T06:10:01+01:00');
    assert.deepEqual(eventIds(browser.html()), []);
});

test('Eine künftige erledigte Leerung zieht ihre Rein-Aufgabe nicht vor', async () => {
    const browser = createBrowser({ events: [liveEvents[0]] });
    await browser.fetchWaste();
    await browser.fetchTasks([{ id: 92, title: 'Restmüll reinstellen', due: '2026-11-20',
        source_type: 'restmuell_rein', show_from: '2026-11-20T06:00:00+01:00', done_at: null, done_by: null }]);
    assert.deepEqual(eventIds(browser.html()), [1]);
    assert.doesNotMatch(browser.html(), /data-task-id="92"/);
    browser.setTime('2026-11-20T05:59:59+01:00');
    assert.doesNotMatch(browser.html(), /data-task-id="92"/);
    browser.setTime('2026-11-20T06:00:00+01:00');
    assert.match(browser.html(), /data-task-id="92"/);
});

test('Auch das Rausstellen bleibt als Aufgabe bis zum Vortag verborgen', async () => {
    const browser = createBrowser();
    await browser.fetchTasks([{ id: 93, title: 'Restmüll rausstellen', due: '2026-11-20',
        source_type: 'restmuell', show_from: '2026-11-19T00:00:00+01:00', done_at: null, done_by: null }]);
    assert.doesNotMatch(browser.html(), /data-task-id="93"/);
    browser.setTime('2026-11-19T00:00:00+01:00');
    assert.match(browser.html(), /data-task-id="93"/);
});

test('Vergangene Kalendertermine werden auch über den ICS-Fallback nicht wiederbelebt', async () => {
    const browser = createBrowser();
    browser.state.apiFailure = true;
    browser.state.fallbackEvents = [
        { date: '2026-11-09', summary: 'Blaue Tonne' },
        { date: '2026-11-12', summary: 'Gelbe Tonne' },
        { date: '2026-11-20', summary: 'Restmüll' },
    ];
    await browser.fetchWaste();
    assert.match(browser.html(), /Restmüll/);
    assert.doesNotMatch(browser.html(), /Blaue Tonne|Gelbe Tonne/);
    assert.doesNotMatch(browser.html(), /waste-clickable/);
});

test('Schadstoffmobil bleibt ohne Rausgestellt-Hinweis und ohne Interaktion', async () => {
    const browser = createBrowser({ events: [{ id: 4, date: '2026-11-15', summary: 'Schadstoffmobil Sechsstädteplatz', done_at: '2026-11-13T07:31:00+01:00' }] });
    await browser.fetchWaste();
    assert.match(browser.html(), /Schadstoffmobil Sechsstädteplatz/);
    assert.match(browser.html(), /waste-row-muted/);
    assert.doesNotMatch(browser.html(), /waste-clickable|data-event-id|Bereits rausgestellt/);
});

test('Schadstoffmobil erhält am Abholtag keine Reinstellen-Bezeichnung', async () => {
    const browser = createBrowser({ now: '2026-11-15T09:00:00+01:00', events: [{ id: 4, date: '2026-11-15', summary: 'Schadstoffmobil Sechsstädteplatz' }] });
    await browser.fetchWaste();
    assert.match(browser.html(), /Schadstoffmobil Sechsstädteplatz/);
    assert.doesNotMatch(browser.html(), /reinstellen/);
});

test('Doppelter Abruffehler erhält die letzten Termine und die Aufgaben', async () => {
    const browser = createBrowser();
    await browser.fetchWaste();
    await browser.fetchTasks([openTask]);
    browser.state.apiFailure = true;
    browser.state.fallbackFailure = true;
    await browser.fetchWaste();
    assert.deepEqual(eventIds(browser.html()), [1, 2, 3]);
    assert.equal(statusCount(browser.html()), 3);
    assert.match(browser.html(), /data-task-id="91"/);
    assert.match(browser.html(), /Die Abfalltermine konnten gerade nicht aktualisiert werden/);
    assert.equal(browser.target.textContent, '');
});

test('Fehler beim ersten Abruf verdeckt ebenfalls keine vorhandenen Aufgaben', async () => {
    const browser = createBrowser();
    await browser.fetchTasks([openTask]);
    browser.state.apiFailure = true;
    browser.state.fallbackFailure = true;
    await browser.fetchWaste();
    assert.match(browser.html(), /class="waste-title">Termine/);
    assert.match(browser.html(), /Die Abfalltermine konnten gerade nicht aktualisiert werden/);
    assert.match(browser.html(), /data-task-id="91"/);
});

test('Erfolgreicher Folgeabruf entfernt den Hinweis und aktualisiert die Termine', async () => {
    const browser = createBrowser();
    browser.state.apiFailure = true;
    browser.state.fallbackFailure = true;
    await browser.fetchWaste();
    browser.state.apiFailure = false;
    browser.state.events = [liveEvents[1]];
    await browser.fetchWaste();
    assert.deepEqual(eventIds(browser.html()), [2]);
    assert.doesNotMatch(browser.html(), /konnten gerade nicht aktualisiert werden/);
});

test('Eine ungültige Fallback-Antwort ersetzt keinen gültigen Cache', async () => {
    const browser = createBrowser();
    await browser.fetchWaste();
    browser.state.apiFailure = true;
    browser.state.invalidFallback = true;
    await browser.fetchWaste();
    assert.deepEqual(eventIds(browser.html()), [1, 2, 3]);
    assert.match(browser.html(), /konnten gerade nicht aktualisiert werden/);
});

test('Eine erfolgreiche Fallback-Antwort kann sich nach dem Fehler ebenfalls erholen', async () => {
    const browser = createBrowser();
    browser.state.apiFailure = true;
    browser.state.fallbackFailure = true;
    await browser.fetchWaste();
    browser.state.fallbackFailure = false;
    browser.state.fallbackEvents = [{ date: '2026-11-24', summary: 'Blaue Tonne' }];
    await browser.fetchWaste();
    assert.match(browser.html(), /Blaue Tonne/);
    assert.doesNotMatch(browser.html(), /konnten gerade nicht aktualisiert werden/);
});

test('Termintexte, Zeitangaben und Datenattribute können kein HTML einschleusen', async () => {
    const browser = createBrowser({ events: [{
        id: '5" onclick="alert(1)', date: '2026-11-20',
        summary: '<img src=x onerror=alert(1)>', start: '<svg onload=alert(2)>', end: '"<script>bad()</script>',
        done_at: '2026-11-13T07:31:00+01:00', done_by: '<script>bad()</script>',
    }] });
    await browser.fetchWaste();
    assert.doesNotMatch(browser.html(), /<img|<svg|<script>|data-event-id="5" onclick=/);
    assert.match(browser.html(), /&lt;img/);
    assert.match(browser.html(), /data-event-id="5&quot; onclick=&quot;/);
    assert.equal(statusCount(browser.html()), 1);
});

test('Ohne privaten Bootstrap bleiben Aufgaben und Türabruf vollständig aus', async () => {
    const browser = createBrowser({ private: false });
    await browser.fetchWaste();
    await browser.fetchTasks([openTask]);
    await browser.fetchWeather();
    assert.equal(browser.sandbox.kioskIsPrivate(), false);
    assert.doesNotMatch(browser.html(), /data-task-id|data-event-id|waste-clickable|Bereits rausgestellt/);
    assert.match(browser.html(), /Restmüll/);
    assert.equal(browser.state.requests.includes('aufgaben_api.php'), false);
    assert.equal(browser.state.requests.some(url => url.includes('nukihub5')), false);
    assert.doesNotMatch(browser.weatherTarget.innerHTML, /Haustür|aufgeschlossen/);
    assert.match(browser.weatherTarget.innerHTML, /21.3/);
});

test('Öffentliche Termin-Callbacks bereinigen auch versehentlich private Antwortfelder', async () => {
    const browser = createBrowser({ private: false });
    await browser.fetchWaste();
    await browser.fetchSonstige();
    assert.doesNotMatch(JSON.stringify(browser.sandbox.hausordnungSonstigeEvents), /PRIVAT-ZUHAUSE|zuhause_|requires_home/);
    assert.doesNotMatch(browser.html(), /David|Daniel|PRIVAT-ZUHAUSE|Bereits rausgestellt/);
});

test('401 entfernt private Aufgaben, Türstatus und zwischengespeicherte Anwesenheit sofort', async () => {
    const browser = createBrowser();
    await browser.fetchWaste();
    await browser.fetchSonstige();
    await browser.fetchTasks([openTask]);
    await browser.fetchWeather();
    assert.match(browser.html(), /data-task-id="91"/);
    assert.match(browser.weatherTarget.innerHTML, /Haustür/);
    browser.state.tasksUnauthorized = true;
    await browser.fetchTasks([openTask]);
    assert.equal(browser.sandbox.kioskIsPrivate(), false);
    assert.doesNotMatch(browser.html(), /data-task-id|waste-clickable|Bereits rausgestellt|PRIVAT-ZUHAUSE/);
    assert.doesNotMatch(browser.weatherTarget.innerHTML, /Haustür|aufgeschlossen/);
    assert.doesNotMatch(JSON.stringify(browser.sandbox.hausordnungSonstigeEvents), /zuhause_|requires_home/);
    assert.match(browser.html(), /Restmüll/);
});

test('Nur private Mutationen senden CSRF und öffentliche Mutationen erreichen fetch nicht', async () => {
    const privateBrowser = createBrowser();
    await privateBrowser.sandbox.kioskFetch('abfall_api.php', { method: 'POST' });
    assert.equal(privateBrowser.state.requestOptions[0].headers['X-CSRF-Token'], 'fixture-csrf');
    await privateBrowser.sandbox.kioskFetch('abfall_api.php');
    assert.equal(privateBrowser.state.requestOptions[1].headers['X-CSRF-Token'], undefined);
    const publicBrowser = createBrowser({ private: false });
    await assert.rejects(publicBrowser.sandbox.kioskFetch('abfall_api.php', { method: 'POST' }));
    assert.equal(publicBrowser.state.requests.length, 0);
});

test('Tablet-Zugang entfernt Haupttoken und autorisiert APIs ohne Cookies', async () => {
    const ticket = 'frame1.1797422400.' + 'a'.repeat(32) + '.' + 'b'.repeat(64);
    const browser = createBrowser({ access: ticket, url: 'https://kiosk.example/?TOKEN=geheim&FRAME=1' });
    assert.equal(browser.sandbox.location.search, '');
    assert.equal(browser.sandbox.location.hash, '#kiosk_access=' + ticket);
    await browser.sandbox.kioskFetch('abfall_api.php', { method: 'POST' });
    const request = browser.state.requestOptions[0];
    assert.equal(request.headers.Authorization, 'Bearer ' + ticket);
    assert.equal(request.headers['X-CSRF-Token'], undefined);
    assert.equal(request.credentials, 'omit');
    assert.equal(request.redirect, 'error');
    await assert.rejects(browser.sandbox.kioskFetch('https://other.example/'));
    assert.equal(browser.state.requests.length, 1);
});

test('Tablet-Zugang übersteht Reloads und verschwindet bei abgelaufener Berechtigung', async () => {
    const ticket = 'frame1.1797422400.' + 'a'.repeat(32) + '.' + 'b'.repeat(64);
    const browser = createBrowser({ private: false, url: 'https://kiosk.example/#kiosk_access=' + ticket });
    assert.equal(browser.sandbox.kioskIsPrivate(), true);
    await browser.fetchTasks([openTask]);
    assert.match(browser.html(), /data-task-id="91"/);
    browser.state.tasksUnauthorized = true;
    await browser.fetchTasks([openTask]);
    assert.equal(browser.sandbox.kioskIsPrivate(), false);
    assert.equal(browser.sandbox.location.hash, '');
    assert.equal(browser.sandbox.kioskAccess.access, undefined);
    assert.doesNotMatch(browser.html(), /data-task-id/);
});

(async () => {
    let failures = 0;
    for (const { name, run } of cases) {
        try { await run(); console.log('[OK] ' + name); }
        catch (error) { failures++; console.error('[FEHLER] ' + name); console.error(error); }
    }
    if (failures) { console.error(`${failures} von ${cases.length} Prüfungen fehlgeschlagen.`); process.exitCode = 1; }
    else console.log(`Alle ${cases.length} Prüfungen erfolgreich.`);
})().catch(error => { console.error(error); process.exitCode = 1; });
