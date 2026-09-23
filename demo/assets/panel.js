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
