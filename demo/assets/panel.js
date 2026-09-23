'use strict';

(() => {
    const status = document.querySelector('[data-status]');

    if (!status) {
        return;
    }

    const endpoint = status.dataset.status;
    const fields = status.querySelectorAll('[data-field]');
    const headers = status.querySelector('[data-status-headers]');
    const updated = status.querySelector('[data-status-updated]');
    const poll = status.querySelector('[data-status-poll]');
    const POLL_INTERVAL_MS = 5000;
    let timer = null;

    const format = (value) => {
        if (value === null || value === undefined) {
            return '—';
        }

        return typeof value === 'object' ? JSON.stringify(value) : String(value);
    };

    const showHeaders = (response) => {
        const lines = ['Botlock-Error', 'Botlock-Warning']
            .map((name) => [name, response.headers.get(name)])
            .filter(([, value]) => value)
            .map(([name, value]) => `${name}: ${value}`);

        headers.textContent = lines.join('\n');
        headers.hidden = lines.length === 0;
    };

    const refresh = async () => {
        try {
            const response = await fetch(endpoint, {cache: 'no-store', credentials: 'same-origin'});
            showHeaders(response);

            const type = response.headers.get('Content-Type') || '';
            const data = type.includes('json') ? await response.json() : null;

            fields.forEach((field) => {
                field.textContent = data ? format(data[field.dataset.field]) : '—';
            });

            updated.textContent = data
                ? `Updated ${new Date().toLocaleTimeString()}`
                : `HTTP ${response.status}: no status available (is BOTLOCK booting?)`;
        } catch (error) {
            updated.textContent = `Status request failed: ${error.message}`;
        }
    };

    const setPolling = (enabled) => {
        clearInterval(timer);
        timer = enabled ? setInterval(refresh, POLL_INTERVAL_MS) : null;
    };

    status.querySelector('[data-status-refresh]').addEventListener('click', refresh);
    poll.addEventListener('change', () => setPolling(poll.checked));

    window.botlockDemo = {refreshStatus: refresh};

    refresh();
})();

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

        window.botlockDemo?.refreshStatus();
    });
})();

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
