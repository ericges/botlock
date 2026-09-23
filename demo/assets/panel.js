'use strict';

/*
 * Demo control panel. Everything works without this script (plain form
 * posts to /_demo.php); it adds live status, history, dirty tracking
 * and in-place actions.
 */
const demo = (() => {
    const element = document.getElementById('demo-data');
    const data = element ? JSON.parse(element.textContent) : {schema: {}, values: {}, presets: {}, preset: null};

    return {
        schema: data.schema,
        presets: data.presets,
        preset: data.preset,
        /** Saved settings; updated after every in-place save or preset. */
        values: data.values,
        refreshStatus: async () => {},
    };
})();

/** Saved value, or the numeric library default when the setting is unset. */
const effectiveInt = (key) => {
    const value = demo.values[key] ?? demo.schema[key]?.default;
    const number = parseInt(value, 10);

    return Number.isNaN(number) ? null : number;
};

/* Status: meters, header chip, history */

(() => {
    const status = document.querySelector('[data-status]');

    if (!status) {
        return;
    }

    const LEVEL_TEXT = [
        'no challenge',
        'challenge unlisted clients',
        'challenge everyone',
        'highest severity, challenge everyone',
    ];
    const POLL_INTERVAL_MS = 5000;
    const HISTORY_SIZE = 60;
    const SVG_NS = 'http://www.w3.org/2000/svg';

    const chip = document.querySelector('[data-chip]');
    const chipText = document.querySelector('[data-chip-text]');
    const fields = status.querySelectorAll('[data-field]');
    const headers = status.querySelector('[data-status-headers]');
    const updated = status.querySelector('[data-status-updated]');
    const poll = status.querySelector('[data-status-poll]');
    const history = status.querySelector('[data-history]');
    const historyCount = status.querySelector('[data-history-count]');
    const samples = [];
    let timer = null;

    const format = (value) => {
        if (value === null || value === undefined) {
            return '—';
        }

        return typeof value === 'object' ? JSON.stringify(value) : String(value);
    };

    const isLevel = (level) => Number.isInteger(level) && level >= 0 && level <= 3;

    const renderMeter = (row, level) => {
        const known = isLevel(level);

        row.dataset.level = known ? String(level) : '';
        row.querySelector('[data-meter-value]').textContent = known ? String(level) : '—';
        row.querySelectorAll('.meter i').forEach((segment, index) => {
            segment.classList.toggle('on', known && index < level);
        });

        const text = row.querySelector('[data-meter-text]');
        if (text) {
            text.textContent = row.classList.contains('is-main') ? (known ? LEVEL_TEXT[level] : 'not evaluated') : '';
        }
    };

    const renderRate = (data) => {
        const window = effectiveInt('INDIVIDUAL_RATE_WINDOW_SEC');
        const thresholds = [1, 2, 3].map((n) => effectiveInt(`LEVEL_${n}_THRESHOLD_INDIVIDUAL`));

        status.querySelector('[data-rate]').textContent = format(data?.individual_rate);
        status.querySelector('[data-rate-window]').textContent = format(window);
        status.querySelector('[data-rate-thresholds]').textContent =
            `(L1 ${thresholds[0]} · L2 ${thresholds[1]} · L3 ${thresholds[2]})`;
    };

    const renderChip = (data) => {
        const level = data?.threat_level;

        chip.dataset.level = isLevel(level) ? String(level) : '';
        chipText.textContent = data
            ? `L${isLevel(level) ? level : '?'} · ${isLevel(level) ? LEVEL_TEXT[level] : 'not evaluated'} · grant ${data.passed ? '✓' : '✗'}`
            : 'status unavailable';
    };

    const renderHistory = () => {
        const scale = Math.max(effectiveInt('LEVEL_3_THRESHOLD_INDIVIDUAL') ?? 1, ...samples.map((s) => s.rate ?? 0), 1);
        const slot = 240 / HISTORY_SIZE;
        const offset = HISTORY_SIZE - samples.length;

        history.replaceChildren(...samples.map((sample, index) => {
            // Without a rate (override, individual limit off) the bar shows the level instead.
            const ratio = sample.rate === null ? (sample.level ?? 0) / 3 : sample.rate / scale;
            const height = Math.max(2, Math.min(1, ratio) * 40);
            const bar = document.createElementNS(SVG_NS, 'rect');
            const title = document.createElementNS(SVG_NS, 'title');

            bar.setAttribute('x', String((offset + index) * slot));
            bar.setAttribute('y', String(40 - height));
            bar.setAttribute('width', String(slot * 0.75));
            bar.setAttribute('height', String(height));
            bar.setAttribute('class', `level-${isLevel(sample.level) ? sample.level : 'none'}`);
            title.textContent = `${sample.time}: level ${format(sample.level)}, rate ${format(sample.rate)}`;
            bar.append(title);

            return bar;
        }));

        historyCount.textContent = String(samples.length);
    };

    const showHeaders = (response) => {
        const lines = ['Botlock-Error', 'Botlock-Warning']
            .map((name) => [name, response.headers.get(name)])
            .filter(([, value]) => value)
            .map(([name, value]) => `${name}: ${value}`);

        headers.textContent = lines.join('\n');
        headers.hidden = lines.length === 0;
    };

    const render = (data) => {
        status.querySelectorAll('[data-meter]').forEach((row) => renderMeter(row, data?.[row.dataset.meter]));
        fields.forEach((field) => {
            field.textContent = data ? format(data[field.dataset.field]) : '—';
        });
        renderRate(data);
        renderChip(data);
    };

    const refresh = async () => {
        try {
            const response = await fetch(status.dataset.status, {cache: 'no-store', credentials: 'same-origin'});
            showHeaders(response);

            const type = response.headers.get('Content-Type') || '';
            const data = type.includes('json') ? await response.json() : null;

            render(data);

            if (data) {
                samples.push({time: new Date().toLocaleTimeString(), level: data.threat_level, rate: data.individual_rate});
                samples.splice(0, samples.length - HISTORY_SIZE);
                renderHistory();
            }

            updated.textContent = data
                ? `Updated ${new Date().toLocaleTimeString()}`
                : `HTTP ${response.status}: no status available (is BOTLOCK booting?)`;
        } catch (error) {
            updated.textContent = `Status request failed: ${error.message}`;
        }
    };

    poll.addEventListener('change', () => {
        clearInterval(timer);
        timer = poll.checked ? setInterval(refresh, POLL_INTERVAL_MS) : null;
    });
    status.querySelector('[data-status-refresh]').addEventListener('click', refresh);

    demo.refreshStatus = refresh;

    refresh();
})();

/* Request burst */

(() => {
    const burst = document.querySelector('[data-burst]');

    if (!burst) {
        return;
    }

    const count = burst.querySelector('[data-burst-count]');
    const start = burst.querySelector('[data-burst-start]');
    const result = burst.querySelector('[data-burst-result]');

    start.addEventListener('click', async () => {
        const total = Math.min(200, Math.max(1, parseInt(count.value, 10) || 1));
        const codes = {};

        start.disabled = true;

        for (let i = 1; i <= total; i++) {
            try {
                const response = await fetch(burst.dataset.burst, {cache: 'no-store', credentials: 'same-origin'});
                codes[response.status] = (codes[response.status] || 0) + 1;
            } catch (error) {
                codes.failed = (codes.failed || 0) + 1;
            }

            result.textContent = `${i}/${total}`;
        }

        result.textContent = 'Done: ' + Object.entries(codes).map(([code, n]) => `${n}× ${code}`).join(', ');
        start.disabled = false;

        demo.refreshStatus();
    });
})();

/* Copy buttons of the curl recipes */

document.querySelectorAll('[data-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
        const command = button.closest('.recipe').querySelector('code').textContent;

        try {
            await navigator.clipboard.writeText(command);
            button.textContent = 'Copied';
        } catch {
            button.textContent = 'Copy failed';
        }

        setTimeout(() => { button.textContent = 'Copy'; }, 1500);
    });
});
