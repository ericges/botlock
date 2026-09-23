'use strict';

/*
 * Demo control panel. Everything works without this script (plain form
 * posts to /_demo.php); it adds live status, history, dirty tracking
 * and in-place actions.
 */
const demo = (() => {
    const element = document.getElementById('demo-data');
    const data = element ? JSON.parse(element.textContent) : {schema: {}, values: {}, presets: {}, preset: null, identities: {}};

    return {
        schema: data.schema,
        presets: data.presets,
        preset: data.preset,
        identities: data.identities,
        /** Selected identity; fields null means this browser, requests go direct. */
        identity: {key: 'browser', label: 'This browser', fields: null},
        /** Saved settings; updated after every in-place save or preset. */
        values: data.values,
        refreshStatus: async () => {},
        showPreset: () => {},
        /** Reloads the protected-page frame if auto-reload is on (or when forced). */
        reloadFrame: () => {},
        refreshLog: async () => {},
    };
})();

/** Replaces the saved settings and tells interested sections about it. */
demo.setValues = (values) => {
    demo.values = values;
    document.dispatchEvent(new CustomEvent('demo:values', {detail: values}));
};

/** Short-lived notification; errors stay until dismissed. */
const toast = (message, kind = 'success') => {
    const container = document.querySelector('[data-toasts]');
    const item = document.createElement('div');
    const text = document.createElement('span');
    const close = document.createElement('button');

    item.className = `toast ${kind}`;
    item.setAttribute('role', kind === 'error' ? 'alert' : 'status');
    text.textContent = message;
    close.type = 'button';
    close.textContent = '×';
    close.setAttribute('aria-label', 'Dismiss');
    close.addEventListener('click', () => item.remove());
    item.append(text, close);
    container.append(item);

    if (kind !== 'error') {
        setTimeout(() => item.remove(), 3000);
    }
};

/**
 * POSTs to /_demo.php asking for JSON. Resolves with the response body
 * when ok; shows an error toast and resolves with null otherwise.
 */
const postAction = async (body) => {
    try {
        const response = await fetch('/_demo.php', {
            method: 'POST',
            body,
            headers: {Accept: 'application/json'},
            credentials: 'same-origin',
        });
        const result = await response.json();

        if (!response.ok || !result.ok) {
            throw new Error(result.error || `HTTP ${response.status}`);
        }

        return result;
    } catch (error) {
        toast(`Action failed: ${error.message}`, 'error');
        return null;
    }
};

/** base64url of the identity JSON, as Identity::decode() expects it. */
const encodeIdentity = (fields) => {
    const bytes = new TextEncoder().encode(JSON.stringify(fields));
    const binary = Array.from(bytes, (byte) => String.fromCharCode(byte)).join('');

    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
};

/** URL of the protected area as the selected identity reaches it, plus a suffix like "?_botlock=status". */
demo.target = (suffix = '') => (demo.identity.fields
    ? `/_forge.php/${encodeIdentity(demo.identity.fields)}/protected/${suffix}`
    : `/protected/${suffix}`);

/** fetch() options for a dashboard request to the protected area; the relay logs the source. */
demo.fetchOptions = (source, options = {}) => ({
    cache: 'no-store',
    credentials: 'same-origin',
    ...options,
    headers: {...(options.headers || {}), ...(demo.identity.fields ? {'X-Demo-Source': source} : {})},
});

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
        'click to start the challenge',
        'slider captcha',
        'blocked with 429',
    ];
    const POLL_INTERVAL_MS = 5000;
    const HISTORY_SIZE = 60;
    const SVG_NS = 'http://www.w3.org/2000/svg';

    const chip = document.querySelector('[data-chip]');
    const chipIdentity = chip.querySelector('[data-chip-identity]');
    const chipLevel = chip.querySelector('[data-chip-level]');
    const chipLevelText = chip.querySelector('[data-chip-level-text]');
    const chipGrant = chip.querySelector('[data-chip-grant]');
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

    const isLevel = (level) => Number.isInteger(level) && level >= 0 && level <= 4;

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
        const thresholds = [1, 2, 3, 4].map((n) => effectiveInt(`LEVEL_${n}_THRESHOLD_INDIVIDUAL`));

        status.querySelector('[data-rate]').textContent = format(data?.individual_rate);
        status.querySelector('[data-rate-window]').textContent = format(window);
        status.querySelector('[data-rate-thresholds]').textContent =
            `(L1 ${thresholds[0]} · L2 ${thresholds[1]} · L3 ${thresholds[2]} · L4 ${thresholds[3]})`;
    };

    const renderChip = (data) => {
        const level = data?.threat_level;

        const known = isLevel(level);

        chip.dataset.level = known ? String(level) : '';
        chip.dataset.grant = data?.passed ? 'yes' : 'no';
        chipIdentity.textContent = demo.identity.label;
        chipLevel.textContent = data ? (known ? String(level) : '?') : '—';
        chipLevelText.textContent = data ? (known ? LEVEL_TEXT[level] : 'not evaluated') : 'status unavailable';
        chipGrant.textContent = data ? (data.passed ? '✓ passed' : '✗ none') : '—';
    };

    const renderHistory = () => {
        const scale = Math.max(effectiveInt('LEVEL_4_THRESHOLD_INDIVIDUAL') ?? 1, ...samples.map((s) => s.rate ?? 0), 1);
        const slot = 240 / HISTORY_SIZE;
        const offset = HISTORY_SIZE - samples.length;

        history.replaceChildren(...samples.map((sample, index) => {
            // Without a rate (override, individual limit off) the bar shows the level instead.
            const ratio = sample.rate === null ? (sample.level ?? 0) / 4 : sample.rate / scale;
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
            const response = await fetch(demo.target('?_botlock=status'), demo.fetchOptions('status'));
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

/* Settings: change markers, per-field reset, unsaved changes, in-place save */

(() => {
    const form = document.querySelector('[data-settings-form]');

    if (!form) {
        return;
    }

    const savebar = form.querySelector('[data-savebar]');
    const savebarText = form.querySelector('[data-savebar-text]');
    const savebarIdle = savebarText.innerHTML;
    const discard = form.querySelector('[data-discard]');
    const rows = new Map([...form.querySelectorAll('.field[data-key]')].map((row) => [row.dataset.key, row]));

    const controls = (key) => ({
        input: form.elements[`settings[${key}]`],
        inherit: form.elements[`default[${key}]`] ?? null,
    });

    /** Current form value in the shape Settings::normalize() stores. */
    const read = (key) => {
        const {input, inherit} = controls(key);

        if (demo.schema[key].type === 'list') {
            return inherit.checked
                ? null
                : input.value.split(/\r?\n/).map((line) => line.trim()).filter((line) => line !== '');
        }

        const value = input.value.trim();

        return value === '' ? null : value;
    };

    const write = (key, value) => {
        const {input, inherit} = controls(key);

        if (demo.schema[key].type === 'list') {
            inherit.checked = value === null;
            input.value = (value ?? []).join('\n');
            return;
        }

        input.value = value ?? '';
    };

    const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

    const dirtyKeys = () => [...rows.keys()].filter((key) => !same(read(key), demo.values[key] ?? null));

    const update = () => {
        const dirty = new Set(dirtyKeys());

        rows.forEach((row, key) => {
            row.classList.toggle('is-set', read(key) !== null);
            row.classList.toggle('is-dirty', dirty.has(key));
        });

        form.querySelectorAll('[data-group]').forEach((group) => {
            const keys = [...group.querySelectorAll('.field[data-key]')].map((row) => row.dataset.key);
            const set = keys.filter((key) => read(key) !== null).length;
            const unsaved = keys.filter((key) => dirty.has(key)).length;

            group.querySelector('[data-group-count]').textContent =
                [String(keys.length), set ? `${set} set` : '', unsaved ? `${unsaved} unsaved` : ''].filter(Boolean).join(' · ');
        });

        savebar.classList.toggle('is-dirty', dirty.size > 0);
        discard.hidden = dirty.size === 0;

        if (dirty.size > 0) {
            savebarText.textContent = `${dirty.size} unsaved change${dirty.size === 1 ? '' : 's'}`;
        } else {
            savebarText.innerHTML = savebarIdle;
        }
    };

    const load = (values) => {
        rows.forEach((row, key) => write(key, values[key] ?? null));
        update();
    };

    form.addEventListener('input', (event) => {
        // Typing into a list means it should no longer inherit the default.
        const key = event.target.closest('.field[data-key]')?.dataset.key;
        if (key && event.target.tagName === 'TEXTAREA') {
            controls(key).inherit.checked = false;
        }

        update();
    });
    form.addEventListener('change', update);

    form.addEventListener('click', (event) => {
        const reset = event.target.closest('[data-field-reset]');

        if (reset) {
            write(reset.closest('.field[data-key]').dataset.key, null);
            update();
        }
    });

    discard.addEventListener('click', () => load(demo.values));

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const body = new FormData(form);
        body.set('action', 'save');

        const result = await postAction(body);

        if (result) {
            demo.setValues(result.values);
            demo.showPreset(result.preset);
            toast(result.message);
            demo.refreshStatus();
            demo.reloadFrame();
        }
    });

    document.addEventListener('demo:values', (event) => load(event.detail));

    window.addEventListener('beforeunload', (event) => {
        if (dirtyKeys().length > 0) {
            event.preventDefault();
        }
    });

    update();
})();

/* Presets and tools: post in place */

(() => {
    const presetButtons = document.querySelectorAll('button[name="preset"]');
    const presetLabel = document.querySelector('[data-preset-label]');

    const showPreset = (id) => {
        demo.preset = id;
        presetButtons.forEach((button) => button.setAttribute('aria-pressed', String(button.value === id)));
        presetLabel.textContent = id ? demo.presets[id].description : 'Custom settings: no preset matches.';
    };

    document.querySelectorAll('[data-action-form]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            // Read the submitter before disabling it; disabled buttons are not submitted.
            const body = new FormData(form, event.submitter);
            const buttons = form.id ? document.querySelectorAll(`[form="${form.id}"]`) : form.querySelectorAll('button');
            buttons.forEach((button) => { button.disabled = true; });

            const result = await postAction(body);

            buttons.forEach((button) => { button.disabled = false; });

            if (!result) {
                return;
            }

            demo.setValues(result.values);
            showPreset(result.preset);
            toast(result.message);
            demo.refreshLog();

            if (result.notice !== 'log-cleared') {
                demo.refreshStatus();
                demo.reloadFrame();
            }
        });
    });

    demo.showPreset = showPreset;
})();

/* Drop ?demo=<notice> after showing its banner, so a reload does not repeat it. */

if (new URLSearchParams(location.search).has('demo')) {
    history.replaceState(null, '', location.pathname);
}

/* Protected-page frame: state badge, reload, auto-reload, reset challenge */

(() => {
    const block = document.querySelector('[data-frame-block]');

    if (!block) {
        return;
    }

    const frame = block.querySelector('[data-frame]');
    const state = block.querySelector('[data-frame-state]');
    const auto = document.querySelector('[data-frame-auto]');

    /** What the frame shows: the protected page, the challenge, the level-4 block, or something else. */
    const classify = () => {
        try {
            const doc = frame.contentDocument;

            if (doc?.body?.hasAttribute('data-demo-protected')) {
                return 'protected';
            }

            if (doc?.getElementById('bot-check-widget')) {
                return 'challenge';
            }

            return doc?.querySelector('h1')?.textContent === 'Error 429' ? 'blocked' : 'error';
        } catch {
            return 'error';
        }
    };

    frame.addEventListener('load', () => {
        const current = classify();

        state.dataset.state = current;
        state.textContent = {error: 'other response', blocked: 'blocked (429)'}[current] ?? current;
        demo.refreshStatus();
        demo.refreshLog();
    });

    const url = block.querySelector('[data-frame-url]');
    const open = block.querySelector('[data-frame-open]');

    const navigate = (src) => {
        state.dataset.state = '';
        state.textContent = 'loading';
        url.textContent = src;
        open.href = src;
        frame.src = src;
    };

    const reload = () => navigate(demo.target());

    demo.navigateFrame = reload;

    block.querySelector('[data-frame-reload]').addEventListener('click', reload);

    demo.reloadFrame = (force = false) => {
        if (force || auto.checked) {
            reload();
        }
    };

    // Reset challenge in place: without "location" the action answers JSON.
    document.querySelector('[data-reset-form]')?.addEventListener('submit', async (event) => {
        event.preventDefault();

        try {
            const response = await fetch(demo.target('?_botlock=reset'), demo.fetchOptions('reset', {method: 'POST'}));

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            toast('Challenge reset: the BOTLOCK session was cleared.');
        } catch (error) {
            toast(`Reset failed: ${error.message}`, 'error');
        }

        demo.refreshStatus();
        demo.reloadFrame();
    });
})();

/* Frame tabs and the relay's request log */

(() => {
    const block = document.querySelector('[data-frame-block]');
    const list = document.querySelector('[data-log]');

    if (!block || !list) {
        return;
    }

    const POLL_INTERVAL_MS = 2000;
    const tabs = block.querySelectorAll('[data-tab]');
    const panels = block.querySelectorAll('[data-panel]');
    const count = block.querySelector('[data-log-count]');
    const includeAll = block.querySelector('[data-log-all]');
    const note = block.querySelector('[data-log-note]');
    const empty = block.querySelector('[data-log-empty]');
    let timer = null;
    let rendered = '';

    const element = (tag, className, text) => {
        const node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    };

    const statusClass = (status) => {
        if (status === 401) {
            return 'challenge';
        }

        if (status === 429) {
            return 'blocked';
        }

        return status >= 200 && status < 400 ? 'ok' : 'fail';
    };

    const entryNode = (entry, open) => {
        const details = element('details', 'log-entry');
        const summary = element('summary');
        const detail = element('div', 'log-detail');

        details.dataset.id = entry.id ?? '';
        details.open = open;
        summary.append(
            element('span', '', entry.time),
            element('span', 'source', entry.source),
            element('span', '', entry.method),
            element('span', 'target', entry.target),
            element('span', `status ${statusClass(entry.status)}`, String(entry.status)),
            element('span', 'meta', `${entry.ms} ms`),
        );
        summary.querySelector('.target').title = entry.target;

        detail.append(
            element('h3', '', 'Sent to BOTLOCK'),
            element('pre', '', (entry.request ?? []).join('\n')),
            element('h3', '', 'Response headers (before cookie and Location rewriting)'),
            element('pre', '', (entry.response ?? []).join('\n') || '—'),
            element('h3', '', `Body (${entry.bytes} bytes${entry.bytes > (entry.body ?? '').length ? ', truncated' : ''})`),
            element('pre', '', entry.body || '—'),
        );
        details.append(summary, detail);

        return details;
    };

    const refresh = async () => {
        note.hidden = demo.identity.fields !== null;

        try {
            const response = await fetch('/_demo.php?log=1', {cache: 'no-store', credentials: 'same-origin'});
            const entries = (await response.json()).entries ?? [];
            const shown = entries.filter((entry) => includeAll.checked || entry.source === 'frame').reverse();
            const signature = shown.map((entry) => entry.id).join(',');

            count.textContent = shown.length ? `(${shown.length})` : '';
            empty.hidden = shown.length > 0;

            if (signature === rendered) {
                return;
            }

            const open = new Set([...list.querySelectorAll('details[open]')].map((node) => node.dataset.id));
            list.replaceChildren(...shown.map((entry) => entryNode(entry, open.has(entry.id))));
            rendered = signature;
        } catch (error) {
            count.textContent = '(?)';
        }
    };

    const show = (name) => {
        tabs.forEach((tab) => tab.setAttribute('aria-selected', String(tab.dataset.tab === name)));
        panels.forEach((panel) => { panel.hidden = panel.dataset.panel !== name; });

        clearInterval(timer);
        timer = name === 'log' ? setInterval(refresh, POLL_INTERVAL_MS) : null;

        if (name === 'log') {
            refresh();
        }
    };

    tabs.forEach((tab) => tab.addEventListener('click', () => show(tab.dataset.tab)));
    includeAll.addEventListener('change', () => {
        rendered = '';
        refresh();
    });

    demo.refreshLog = refresh;
})();

/* Identity: which client the frame, status, burst and reset act as */

(() => {
    const section = document.querySelector('[data-identity]');

    if (!section) {
        return;
    }

    const STORAGE_KEY = 'botlock-demo-identity';
    const select = section.querySelector('[data-identity-preset]');
    const form = section.querySelector('[data-identity-form]');
    const note = section.querySelector('[data-identity-note]');
    const inputs = ['ua', 'ip', 'lang', 'headers'].map((name) => form.elements[name]);

    const readFields = () => ({
        ua: form.elements.ua.value.trim(),
        ip: form.elements.ip.value.trim(),
        lang: form.elements.lang.value.trim(),
        headers: form.elements.headers.value.split(/\r?\n/).map((line) => line.trim()).filter((line) => line !== ''),
    });

    const writeFields = (fields) => {
        form.elements.ua.value = fields?.ua ?? '';
        form.elements.ip.value = fields?.ip ?? '';
        form.elements.lang.value = fields?.lang ?? '';
        form.elements.headers.value = (fields?.headers ?? []).join('\n');
        inputs.forEach((input) => { input.disabled = fields === null; });
        note.textContent = fields === null ? 'direct, your own headers' : 'relayed via /_forge.php';
        note.title = fields === null
            ? 'Requests go straight to /protected/ with this browser\'s headers; they are not relayed or logged.'
            : 'Requests go through /_forge.php, which sends them to /protected/ with these headers.';
    };

    const apply = (key, fields) => {
        const label = key === 'custom' ? 'Custom' : demo.identities[key]?.label ?? key;

        demo.identity = {key, label, fields};
        select.value = key;

        try {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify({key, fields}));
        } catch {
            // Storage unavailable (private mode, blocked): the identity just is not remembered.
        }

        demo.navigateFrame?.();
        demo.refreshStatus();
    };

    select.addEventListener('change', () => {
        if (select.value === 'custom') {
            inputs.forEach((input) => { input.disabled = false; });
            return;
        }

        const fields = demo.identities[select.value]?.fields ?? null;
        writeFields(fields);
        apply(select.value, fields);
    });

    form.addEventListener('input', () => {
        select.value = 'custom';
    });

    form.addEventListener('submit', (event) => {
        event.preventDefault();

        if (select.value !== 'custom' && demo.identities[select.value]?.fields === null) {
            apply('browser', null);
            return;
        }

        apply(select.value, readFields());
    });

    // Restore this tab's identity, then point the frame at it.
    let saved = null;
    try {
        saved = JSON.parse(sessionStorage.getItem(STORAGE_KEY) || 'null');
    } catch {
        saved = null;
    }

    if (saved && (saved.key === 'custom' || saved.key in demo.identities)) {
        writeFields(saved.fields);
        apply(saved.key, saved.fields);
    } else {
        writeFields(null);
    }
})();

/* Request burst */

(() => {
    const burst = document.querySelector('[data-burst]');

    if (!burst) {
        return;
    }

    const count = burst.querySelector('[data-burst-count]');
    const start = burst.querySelector('[data-burst-start]');
    const result = document.querySelector('[data-burst-result]');
    const overrideHint = document.querySelector('[data-burst-override]');

    document.addEventListener('demo:values', (event) => {
        overrideHint.hidden = event.detail.THREAT_LEVEL_OVERRIDE === null;
    });

    start.addEventListener('click', async () => {
        const total = Math.min(200, Math.max(1, parseInt(count.value, 10) || 1));
        const codes = {};

        start.disabled = true;

        for (let i = 1; i <= total; i++) {
            try {
                const response = await fetch(demo.target(), demo.fetchOptions('burst'));
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
