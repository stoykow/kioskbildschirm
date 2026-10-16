// Der Reinigungsplan wechselt nach Datum zwischen den Bewohnergruppen.
(function () {
    'use strict';

    function addText(parent, tagName, className, text) {
        const element = document.createElement(tagName);
        element.className = className;
        element.textContent = text;
        parent.appendChild(element);
        return element;
    }

    function formatDate(value) {
        const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || ''));
        if (!match) throw new Error('Invalid cleaning date');
        const date = new Date(`${match[0]}T12:00:00Z`);
        if (Number.isNaN(date.getTime()) || date.toISOString().slice(0, 10) !== match[0]) {
            throw new Error('Invalid cleaning date');
        }
        return `${match[3]}.${match[2]}.${match[1]}`;
    }

    function rhythmText(plan) {
        const interval = Number(plan.interval);
        const units = { tage: ['jeden Tag', 'Tage'], wochen: ['jede Woche', 'Wochen'], monate: ['jeden Monat', 'Monate'] };
        if (!Number.isInteger(interval) || interval < 1 || !Object.prototype.hasOwnProperty.call(units, plan.unit)) {
            throw new Error('Invalid cleaning interval');
        }
        return interval === 1
            ? `Wechsel ${units[plan.unit][0]}`
            : `Wechsel alle ${interval} ${units[plan.unit][1]}`;
    }

    function buildPlan(plan) {
        const card = document.createElement('article');
        card.className = 'reinigungsplan-card';
        addText(card, 'h3', 'reinigungsplan-name', String(plan.title || 'Hausordnung'));
        addText(card, 'p', 'reinigungsplan-rhythm', rhythmText(plan));
        if (plan.details) {
            addText(card, 'p', 'reinigungsplan-details', String(plan.details));
        }
        if (!Array.isArray(plan.periods)) throw new Error('Invalid cleaning periods');
        if (plan.ended === true) {
            addText(card, 'p', 'reinigungsplan-status', `Plan beendet am ${formatDate(plan.end_date)}`);
            addText(card, 'p', 'reinigungsplan-next', 'Noch kein weiterer Reinigungsplan festgelegt.');
            return card;
        }
        if (plan.end_date) {
            addText(card, 'p', 'reinigungsplan-status', `Geplant bis einschließlich ${formatDate(plan.end_date)}`);
        }
        if (plan.periods.length === 0) {
            addText(card, 'p', 'reinigungsplan-status', 'Für diesen Reinigungsplan ist keine Bewohnergruppe verfügbar.');
            return card;
        }

        const currentIndex = plan.periods.findIndex(period => period.current === true);
        const periodIndex = currentIndex >= 0 ? currentIndex : 0;
        const period = plan.periods[periodIndex];
        const start = formatDate(period.start);
        const end = formatDate(period.end);
        const status = currentIndex >= 0 ? 'Aktuell zuständig' : `Start am ${start}`;
        addText(card, 'p', 'reinigungsplan-status', status);
        addText(card, 'p', 'reinigungsplan-group', String(period.group_name || 'Bewohnergruppe'));
        addText(card, 'p', 'reinigungsplan-period', `Von ${start} bis ${end}`);

        const next = plan.periods[periodIndex + 1];
        if (next) {
            addText(card, 'p', 'reinigungsplan-next',
                `Nächster Wechsel am ${formatDate(next.start)}: ${String(next.group_name || 'Bewohnergruppe')}`);
        } else if (plan.end_date) {
            addText(card, 'p', 'reinigungsplan-next',
                `Nach dem ${formatDate(plan.end_date)} ist noch kein weiterer Reinigungsplan festgelegt.`);
        }
        return card;
    }

    window.fetchReinigungsplan = async function () {
        const container = document.getElementById('reinigungsplan');
        if (!container) return;
        try {
            const response = await fetch('reinigungsplan_api.php', { cache: 'no-store' });
            if (!response.ok) throw new Error('Cleaning plan unavailable');
            const data = await response.json();
            if (!data || !Array.isArray(data.plans)) throw new Error('Invalid cleaning plans');
            const fragment = document.createDocumentFragment();
            if (data.plans.length > 0) {
                addText(fragment, 'h2', 'reinigungsplan-title', 'Reinigungsplan');
                data.plans.forEach(plan => fragment.appendChild(buildPlan(plan)));
            }
            container.replaceChildren(fragment);
            container.hidden = data.plans.length === 0;
        } catch (error) {
            container.replaceChildren();
            addText(container, 'h2', 'reinigungsplan-title', 'Reinigungsplan');
            addText(container, 'p', 'reinigungsplan-error', 'Der Reinigungsplan kann gerade nicht geladen werden.');
            container.hidden = false;
        }
    };
}());
