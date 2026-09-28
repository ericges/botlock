<?php declare(strict_types=1);
/**
 * Browser challenge page. Rendered by Template\LocalizedPage for
 * Middleware\ChallengeDocumentMiddleware, once per language, then cached.
 * The shared styles come from partials/style.php.
 *
 * @var string                $lang      Language code, always one of I18n\TranslationLoader::LANGUAGES
 * @var array<string,string>  $trans     Strings for that language (keys: I18n\TranslationLoader::KEYS)
 * @var string                $transJson Pre-encoded, script-safe JSON of $trans (emitted raw)
 * @var \Closure(string):string $e       HTML escape helper
 */
if (!isset($lang, $trans, $transJson, $e)) {
    \http_response_code(404);
    return;
}
?>
<!DOCTYPE html>
<html lang="<?= $e($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $e($trans['pageTitle']) ?></title>
    <style>
        <?php include __DIR__ . '/partials/style.php'; ?>

        #info-paragraph {
            text-wrap: balance;
        }

        #bot-check-widget {
            min-height: 3rem;
            margin-bottom: 2rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }

        .spinner {
            border: 4px solid var(--spinner-bg);
            width: 2rem;
            height: 2rem;
            border-radius: 50%;
            border-left-color: var(--spinner-color);
            animation: spin 1s ease infinite;
            margin: 1rem auto;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .noscript-warning {
            color: var(--noscript-color);
            background: var(--noscript-bg);
            border: 2px solid var(--noscript-color);
            padding: 1rem;
            margin-top: 1rem;
            border-radius: .25rem;
            text-align: left;
        }

        .noscript-warning > * {
            display: block;
            margin-bottom: 5px;
        }

        .status-message {
            font-size: 1.2em;
            color: var(--text-color);
            margin-top: 1rem;
        }

        .status-fail h1,
        .status-fail .status-message {
            color: var(--error-color);
        }

        .status-success h1,
        .status-success .status-message {
            color: var(--success-color);
        }

        .verify-button {
            background-color: var(--title-color);
            color: var(--card-bg);
            border: none;
            padding: 0.8rem 2rem;
            font-size: 1em;
            border-radius: .25rem;
            cursor: pointer;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .verify-button:hover,
        .verify-button:focus-visible {
            opacity: .85;
        }

        .verify-button:disabled {
            opacity: .4;
            cursor: not-allowed;
        }

        .puzzle-error {
            margin: -1rem 0 1rem;
            color: var(--error-color);
            text-wrap: balance;
        }

        .puzzle {
            position: relative;
            width: 100%;
            max-width: 280px;
            margin-bottom: 1rem;
            border-radius: .25rem;
            overflow: hidden;
        }

        .puzzle-bg {
            display: block;
            width: 100%;
            height: 100%;
        }

        .puzzle-piece {
            position: absolute;
            left: 0;
            height: auto;
            filter: drop-shadow(0 0 1px #fff) drop-shadow(0 0 1px #fff) drop-shadow(0 1px 3px rgba(0, 0, 0, .75));
        }

        .puzzle-piece.is-hinting {
            animation: puzzle-hint 1.1s ease-in-out .4s 2;
        }

        @keyframes puzzle-hint {
            0%, 100% {
                transform: translateX(0);
            }

            50% {
                transform: translateX(14px);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .puzzle-piece.is-hinting {
                animation: none;
            }
        }

        .puzzle-slider {
            -webkit-appearance: none;
            appearance: none;
            width: 100%;
            max-width: 280px;
            height: 28px;
            margin: 0 0 1.5rem;
            background: transparent;
        }

        .puzzle-slider::-webkit-slider-runnable-track {
            height: 6px;
            border-radius: 3px;
            background: var(--spinner-bg);
        }

        .puzzle-slider::-moz-range-track {
            height: 6px;
            border-radius: 3px;
            background: var(--spinner-bg);
        }

        /* The page script assumes this thumb size (THUMB_SIZE). */
        .puzzle-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            cursor: grab;
            width: 28px;
            height: 28px;
            margin-top: -11px;
            border-radius: 50%;
            background: var(--title-color) var(--slider-arrow) center / 18px no-repeat;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .3);
        }

        .puzzle-slider::-moz-range-thumb {
            cursor: grab;
            width: 28px;
            height: 28px;
            border: 0;
            border-radius: 50%;
            background: var(--title-color) var(--slider-arrow) center / 18px no-repeat;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .3);
        }

        .puzzle-slider:focus {
            outline: none;
        }

        .puzzle-slider:focus-visible {
            outline: 2px solid var(--title-color);
            outline-offset: 4px;
            border-radius: 14px;
        }

    </style>
</head>
<body>

<div id="security">
    <h1 id="main-heading"><?= $e($trans['mainHeading']) ?></h1>
    <p id="info-paragraph"><?= $e($trans['infoParagraph']) ?></p>

    <div id="bot-check-widget">
        <div class="spinner"></div>
    </div>

    <noscript>
        <div class="noscript-warning">
            <strong><?= $e($trans['noscriptHeading']) ?></strong>
            <span><?= $e($trans['noscriptText']) ?></span>
        </div>
    </noscript>

    <p id="footer-note"><?= $e($trans['footerNote']) ?></p>
</div>

<svg class="icon-wrapper" style="display: none;">
    <symbol id="icon-bot">
        <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 16 16" stroke-width="1">
            <path d="M6 12.5a0.5 0.5 0 0 1 0.5 -0.5h3a0.5 0.5 0 0 1 0 1h-3a0.5 0.5 0 0 1 -0.5 -0.5M3 8.062C3 6.76 4.235 5.765 5.53 5.886a26.6 26.6 0 0 0 4.94 0C11.765 5.765 13 6.76 13 8.062v1.157a0.93 0.93 0 0 1 -0.765 0.935c-0.845 0.147 -2.34 0.346 -4.235 0.346s-3.39 -0.2 -4.235 -0.346A0.93 0.93 0 0 1 3 9.219zm4.542 -0.827a0.25 0.25 0 0 0 -0.217 0.068l-0.92 0.9a25 25 0 0 1 -1.871 -0.183 0.25 0.25 0 0 0 -0.068 0.495c0.55 0.076 1.232 0.149 2.02 0.193a0.25 0.25 0 0 0 0.189 -0.071l0.754 -0.736 0.847 1.71a0.25 0.25 0 0 0 0.404 0.062l0.932 -0.97a25 25 0 0 0 1.922 -0.188 0.25 0.25 0 0 0 -0.068 -0.495c-0.538 0.074 -1.207 0.145 -1.98 0.189a0.25 0.25 0 0 0 -0.166 0.076l-0.754 0.785 -0.842 -1.7a0.25 0.25 0 0 0 -0.182 -0.135"></path>
            <path d="M8.5 1.866a1 1 0 1 0 -1 0V3h-2A4.5 4.5 0 0 0 1 7.5V8a1 1 0 0 0 -1 1v2a1 1 0 0 0 1 1v1a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-1a1 1 0 0 0 1 -1V9a1 1 0 0 0 -1 -1v-0.5A4.5 4.5 0 0 0 10.5 3h-2zM14 7.5V13a1 1 0 0 1 -1 1H3a1 1 0 0 1 -1 -1V7.5A3.5 3.5 0 0 1 5.5 4h5A3.5 3.5 0 0 1 14 7.5"></path>
        </svg>
    </symbol>
    <symbol id="icon-shield">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="-0.5 -0.5 16 16" fill="none" stroke="currentColor" stroke-width=".85" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12.5 8.125c0 3.125 -2.1875 4.6875 -4.7875 5.59375a0.625 0.625 0 0 1 -0.41875 -0.00625C4.6875 12.8125 2.5 11.25 2.5 8.125V3.75a0.625 0.625 0 0 1 0.625 -0.625c1.25 0 2.8125 -0.75 3.9000000000000004 -1.7000000000000002a0.73125 0.73125 0 0 1 0.95 0C9.06875 2.38125 10.625 3.125 11.875 3.125a0.625 0.625 0 0 1 0.625 0.625z"></path>
            <path d="m5.625 7.5 1.25 1.25 2.5 -2.5" style="stroke: var(--success-color)"></path>
        </svg>
    </symbol>
</svg>

<script>window.trans = <?= $transJson ?>;</script>

<script>
    const security = document.getElementById('security');
    const headingElement = document.getElementById('main-heading');
    const infoElement = document.getElementById('info-paragraph');
    const widgetElement = document.getElementById('bot-check-widget');
    const footerElement = document.getElementById('footer-note');

    // RFC 4122 v4 UUID; randomUUID() needs a secure context, getRandomValues() does not.
    function createNonce() {
        const c = window.crypto;
        if (!c) {
            return null;
        }
        if (typeof c.randomUUID === 'function') {
            return c.randomUUID();
        }
        if (typeof c.getRandomValues !== 'function') {
            return null;
        }
        const b = c.getRandomValues(new Uint8Array(16));
        b[6] = (b[6] & 0x0f) | 0x40;
        b[8] = (b[8] & 0x3f) | 0x80;
        const hex = Array.from(b, x => x.toString(16).padStart(2, '0')).join('');
        return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
    }

    async function sha(text, algo = 'SHA-256') {
        const encoder = new TextEncoder();
        const data = encoder.encode(text);
        const hashBuffer = await crypto.subtle.digest(algo, data);
        const hashArray = Array.from(new Uint8Array(hashBuffer));
        return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
    }

    function toBase64(bytes) {
        let binary = '';
        for (let i = 0; i < bytes.length; i += 0x8000) {
            binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
        }
        return btoa(binary);
    }

    // Seals the interaction report with the challenge's key (AES-GCM, the
    // challenge id as additional data), the only form the server accepts.
    async function seal(challenge, report) {
        const raw = Uint8Array.from(atob(challenge.key), (c) => c.charCodeAt(0));
        const key = await crypto.subtle.importKey('raw', raw, 'AES-GCM', false, ['encrypt']);
        const iv = crypto.getRandomValues(new Uint8Array(12));
        const data = new TextEncoder().encode(JSON.stringify(report));
        const additionalData = new TextEncoder().encode(challenge.cid);
        const sealed = await crypto.subtle.encrypt({ name: 'AES-GCM', iv, additionalData }, key, data);

        return { cid: challenge.cid, iv: toBase64(iv), ct: toBase64(new Uint8Array(sealed)) };
    }

    function showError() {
        security.classList.add('status-fail');
        headingElement.textContent = trans.errorHeading;
        infoElement.textContent = trans.errorMessage;
        widgetElement.innerHTML = `
            <svg class="status-icon" aria-hidden="true" focusable="false" data-prefix="fas" role="img" xmlns="http://www.w3.org/2000/svg">
                <use xlink:href="#icon-bot"></use>
            </svg>
        `;  // <span class="status-message">${currentTranslations.errorWidget}</span>
        footerElement.textContent = trans.errorFooter;
    }

    function showSuccess() {
        security.classList.add('status-success');
        headingElement.textContent = trans.successHeading;
        infoElement.textContent = trans.successMessage;
        widgetElement.innerHTML = `
            <svg class="status-icon" aria-hidden="true" focusable="false" data-prefix="fas" role="img" xmlns="http://www.w3.org/2000/svg">
                <use xlink:href="#icon-shield"></use>
            </svg>
        `;  // <span claavss="status-message">${currentTranslations.successWidget}</span>
        footerElement.textContent = trans.successFooter;
    }

    const MAX_ATTEMPTS = 5;

    // The threat level rose above the one the challenge was issued for: start over.
    class RestartError extends Error {}

    // The slider missed the gap; the ticket is spent, a new puzzle follows.
    class RetryError extends Error {}

    const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

    async function call(action, method, nonce, body) {
        const headers = {
            "Accept": "application/json",
            "Botlock-Nonce": nonce,
        };
        const options = { method, headers };

        if (body !== undefined) {
            headers["Content-Type"] = "application/json";
            options.body = JSON.stringify(body);
        }

        const response = await fetch(`?_botlock=${action}`, options);

        if (response.status === 409) {
            throw new RestartError();
        }

        if (response.status === 403) {
            throw new RetryError();
        }

        return response;
    }

    async function getChallenge(nonce) {
        const response = await call('challenge', 'GET', nonce);
        return response.ok ? await response.json() : null;
    }

    // Reports the completed interaction; the answer carries the proof of work.
    async function completeInteraction(challenge, nonce, report = {}) {
        const response = await call('challenge', 'POST', nonce, await seal(challenge, report));
        return response.ok ? await response.json() : null;
    }

    async function solveChallenge({
        'alg': algorithm,
        'exp': expire,
        'max': max = 0,
        'slt': salt,
        'tgt': target,
        'sig': signature,
    }) {
        const hashAlgo = {
            'sha256': 'SHA-256',
            'sha384': 'SHA-384',
            'sha512': 'SHA-512',
        }[algorithm] || null;

        if (!hashAlgo) {
            return null;
        }

        let num = null;

        for (let i = 1; i <= max; i++)
        {
            const hash = await sha(`${i}:${salt}:${expire}`, hashAlgo);

            if (hash === target) {
                num = i;
                break;
            }
        }

        if (num === null) {
            console.error("No match found.");
            return null;
        }

        return { num, sig: signature, slt: salt, exp: expire, alg: algorithm };
    }

    async function sendResult(result, nonce) {
        const response = await call('verify', 'POST', nonce, result);
        return response.ok;
    }

    function showWorking() {
        widgetElement.innerHTML = '<div class="spinner"></div>';
        headingElement.textContent = trans.mainHeading;
        infoElement.textContent = trans.infoParagraph;
    }

    function handleFailure(error, attempt) {
        if ((error instanceof RestartError || error instanceof RetryError) && attempt < MAX_ATTEMPTS) {
            showWorking();
            botlock(attempt + 1, error instanceof RetryError).catch((error) => {
                console.error(error);
                showError();
            });
            return;
        }

        console.error(error);
        showError();
    }

    async function botlock(attempt = 0, retried = false) {
        if (typeof window.crypto?.subtle?.digest !== 'function') {
            showError();
            return;
        }

        const nonce = createNonce();
        if (!nonce) {
            showError();
            return;
        }

        try {
            const challenge = await getChallenge(nonce);
            if (!challenge) {
                showError();
                return;
            }

            // The server rejects solutions sooner than min_ms after issuing.
            challenge.receivedAt = performance.now();

            switch (challenge.int) {
                case 'none':
                    await runChallenge(challenge, challenge.pow, nonce);
                    return;
                case 'click':
                    showConfirm(challenge, nonce, attempt);
                    return;
                case 'slider':
                    showSlider(challenge, nonce, attempt, retried);
                    return;
                default:
                    showError();
            }
        } catch (error) {
            handleFailure(error, attempt);
        }
    }

    function showConfirm(challenge, nonce, attempt) {
        headingElement.textContent = trans.confirmHeading;
        infoElement.textContent = trans.confirmParagraph;

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'verify-button';
        button.textContent = trans.verifyButton;
        button.addEventListener('click', () => {
            showWorking();
            completeInteraction(challenge, nonce)
                .then((ready) => ready ? runChallenge(challenge, ready.pow, nonce) : showError())
                .catch((error) => handleFailure(error, attempt));
        }, { once: true });

        widgetElement.replaceChildren(button);
        button.focus();
    }

    // Records how the slider was moved, for the server to judge: each drag
    // as {k: 'p', pt, t0, pts: [[dt, value, dy], …], co}, each key press
    // that moved it as {k: 'k', t, v, r}, and each press on the handle that
    // barely dragged as {k: 'c', t, d, v}. Times are milliseconds since the
    // puzzle was shown; dy is the pointer's vertical drift in puzzle pixels.
    function recordTrack(slider, frame, width, signal) {
        const MAX_SAMPLES = 2000;
        const shownAt = performance.now();
        const track = [];
        let samples = 0;
        let stroke = null;
        let key = null;

        const time = (event) => Math.round((event.timeStamp - shownAt) * 10) / 10;
        const add = (entry, size = 1) => {
            if (samples + size <= MAX_SAMPLES) {
                samples += size;
                track.push(entry);
            }
        };
        const trusted = (listener) => (event) => event.isTrusted && listener(event);

        slider.addEventListener('pointerdown', trusted((event) => {
            if (!event.isPrimary || event.defaultPrevented) {
                return;
            }
            stroke = {
                id: event.pointerId,
                pt: event.pointerType,
                t0: time(event),
                y0: event.clientY,
                dy: 0,
                pts: [],
                co: 0,
                scale: width / frame.getBoundingClientRect().width,
            };
        }), { signal });

        document.addEventListener('pointermove', trusted((event) => {
            if (stroke?.id === event.pointerId) {
                stroke.co += event.getCoalescedEvents?.().length || 1;
                stroke.dy = Math.round((event.clientY - stroke.y0) * stroke.scale * 10) / 10;
            }
        }), { signal });

        const end = trusted((event) => {
            if (stroke?.id !== event.pointerId) {
                return;
            }
            const { pt, t0, pts, co } = stroke;
            stroke = null;

            if (pts.length > 2) {
                add({ k: 'p', pt, t0, pts, co }, pts.length);
            } else if (pts.length) {
                add({ k: 'c', t: t0, d: Math.round((time(event) - t0) * 10) / 10, v: pts[pts.length - 1][1] });
            }
        });
        document.addEventListener('pointerup', end, { signal });
        document.addEventListener('pointercancel', end, { signal });

        // The value changes after keydown; the next input event records it.
        slider.addEventListener('keydown', trusted((event) => {
            key = { t: time(event), r: event.repeat };
        }), { signal });

        slider.addEventListener('input', trusted((event) => {
            const v = Number(slider.value);

            if (stroke) {
                samples + stroke.pts.length < MAX_SAMPLES && stroke.pts.push([Math.round((time(event) - stroke.t0) * 10) / 10, v, stroke.dy]);
            } else {
                add({ k: 'k', t: key?.t ?? time(event), v, r: key?.r ?? false });
                key = null;
            }
        }), { signal });

        return track;
    }

    const THUMB_SIZE = 28;

    // Only the handle moves the piece: a press elsewhere on the track would
    // make the range input jump there, which the server takes for a script.
    // Fingers get a wider margin than a mouse or pen.
    function lockToThumb(slider, signal) {
        const onThumb = (clientX, pointerType) => {
            const rect = slider.getBoundingClientRect();
            const center = rect.left + THUMB_SIZE / 2 + slider.value / slider.max * (rect.width - THUMB_SIZE);
            const margin = pointerType === 'touch' ? 12 : 4;
            return Math.abs(clientX - center) <= THUMB_SIZE / 2 + margin;
        };
        const block = (clientX, pointerType) => (event) => {
            if (!onThumb(clientX(event), pointerType(event))) {
                event.preventDefault();
            }
        };

        slider.addEventListener('pointerdown', block((e) => e.clientX, (e) => e.pointerType), { signal });
        slider.addEventListener('mousedown', block((e) => e.clientX, () => 'mouse'), { signal });
        slider.addEventListener('touchstart', block((e) => e.touches[0].clientX, () => 'touch'), { signal, passive: false });
    }

    // The range input works by dragging its handle or by the arrow keys.
    function showSlider(challenge, nonce, attempt, retried) {
        const { puzzle } = challenge;

        headingElement.textContent = trans.sliderHeading;
        infoElement.textContent = trans.sliderParagraph;

        const frame = document.createElement('div');
        frame.className = 'puzzle';
        frame.style.aspectRatio = `${puzzle.width} / ${puzzle.height}`;

        const background = new Image();
        background.className = 'puzzle-bg';
        background.alt = '';
        background.src = puzzle.bg;

        const piece = new Image();
        piece.className = 'puzzle-piece';
        piece.alt = '';
        piece.src = puzzle.piece;
        piece.style.width = `${puzzle.size / puzzle.width * 100}%`;
        piece.style.top = `${puzzle.y / puzzle.height * 100}%`;

        // A short nudge shows what to move, until the visitor starts.
        piece.classList.add('is-hinting');
        const stopHint = () => piece.classList.remove('is-hinting');
        piece.addEventListener('animationend', stopHint, { once: true });

        frame.append(background, piece);

        const slider = document.createElement('input');
        slider.type = 'range';
        slider.className = 'puzzle-slider';
        slider.min = '0';
        slider.max = String(puzzle.width - puzzle.size);
        slider.step = '1';
        slider.value = '0';
        slider.setAttribute('aria-label', trans.sliderLabel);

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'verify-button';
        button.textContent = trans.sliderSubmit;
        const recording = new AbortController();
        lockToThumb(slider, recording.signal);
        const track = recordTrack(slider, frame, puzzle.width, recording.signal);

        button.addEventListener('click', () => {
            recording.abort();
            showWorking();
            completeInteraction(challenge, nonce, { pos: Number(slider.value), track })
                .then((ready) => ready ? runChallenge(challenge, ready.pow, nonce) : showError())
                .catch((error) => handleFailure(error, attempt));
        }, { once: true });

        // Nothing to confirm while the piece is still at its start.
        const move = () => {
            piece.style.left = `${slider.value / puzzle.width * 100}%`;
            button.disabled = Number(slider.value) === 0;
        };
        slider.addEventListener('input', move);
        for (const type of ['input', 'keydown', 'pointerdown']) {
            slider.addEventListener(type, stopHint, { once: true });
        }
        move();

        widgetElement.replaceChildren(frame, slider, button);

        // After a miss the instructions stay; the error sits above the new puzzle.
        if (retried) {
            const error = document.createElement('p');
            error.className = 'puzzle-error';
            error.setAttribute('role', 'alert');
            error.textContent = trans.sliderRetry;
            widgetElement.prepend(error);
        }
        slider.focus();
    }

    async function runChallenge(challenge, pow, nonce) {
        const res = await solveChallenge(pow);
        if (res === null) {
            showError();
            return;
        }

        const wait = challenge.min_ms - (performance.now() - challenge.receivedAt);
        if (wait > 0) {
            await sleep(wait);
        }

        if (!await sendResult({ ...res, cid: challenge.cid }, nonce)) {
            showError();
            return;
        }

        showSuccess();

        setTimeout(() => {
            window.location.reload();
        }, 600);
    }

    document.addEventListener('DOMContentLoaded', () => botlock().catch((error) => {
        console.error(error);
        showError();
    }));
</script>

</body>
</html>
