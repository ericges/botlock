<?php declare(strict_types=1);
/**
 * Browser challenge page. Rendered by Template\TemplateRenderer from
 * Middleware\ChallengeDocumentMiddleware, once per language, then cached.
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
        :root {
            --body-bg: #f0f0f0;
            --card-bg: #fff;
            --title-color: #4e4848;
            --text-color: #686868;
            --icon-color: #d9dbdd;
            --spinner-color: var(--title-color);
            --spinner-bg: rgba(0, 0, 0, 0.1);
            --noscript-bg: rgba(216, 0, 12, .06);
            --noscript-color: #d8000c;
            --error-color: #d8000c;
            --success-color: #28a745;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --body-bg: #141517;
                --card-bg: #202124;
                --title-color: #f8faff;
                --text-color: #b0b2ba;
                --icon-color: #757580;
                --spinner-color: var(--title-color);
                --spinner-bg: rgba(255, 255, 255, 0.1);
                --noscript-color: #ff7076;
                --error-color: #ff7076;
                --success-color: #5cb85c;
            }
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background-color: var(--body-bg);
            margin: 0;
            color: var(--title-color);
            hanging-punctuation: none;
            text-wrap: pretty;
        }

        #security {
            background-color: var(--card-bg);
            padding: 2.5rem;
            border-radius: .125rem;
            box-shadow: 0 1rem 1.5rem -.5rem rgba(0, 0, 0, 0.1);
            text-align: center;
            max-width: 60ch;
            width: 90%;
        }

        h1 {
            color: var(--title-color);
            margin-bottom: 1rem;
            font-size: 1.8em;
        }

        p {
            color: var(--text-color);
            margin-bottom: 2rem;
            line-height: 1.7;
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

        .status-icon {
            width: 6rem;
            height: 6rem;
            color: var(--icon-color);
        }

        .icon-wrapper {
            display: none;
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

    <p id="footer-note" style="font-size: 0.8em; color: #8a919c;"><?= $e($trans['footerNote']) ?></p>
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

    const nonce = crypto?.randomUUID();

    async function sha(text, algo = 'SHA-256') {
        const encoder = new TextEncoder();
        const data = encoder.encode(text);
        const hashBuffer = await crypto.subtle.digest(algo, data);
        const hashArray = Array.from(new Uint8Array(hashBuffer));
        return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
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

    async function getChallenge() {
        try {
            if (!nonce || nonce.length < 16) {
                console.error("Failed to generate nonce.");
                return null;
            }

            const response = await fetch('?_botlock=challenge', {
                method: "GET",
                headers: {
                    "Content-Type": "application/json",
                    "Accept": "application/json",
                    "Botlock-Nonce": nonce,
                }
            });
            if (!response.ok) {
                return null;
            }
            return await response.json()
        } catch (error) {
            console.error(error.message);
            return null;
        }
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

    async function sendResult(result) {
        const response = await fetch("?_botlock=verify", {
            method: "POST",
            body: JSON.stringify(result),
            headers: {
                "Content-Type": "application/json",
                "Botlock-Nonce": nonce,
            }
        })

        return response.ok;
    }

    async function botlock() {
        if (!window.crypto || !window.crypto.subtle) {
            showError();
            return;
        }

        const challenge = await getChallenge();
        if (!challenge) {
            showError();
            return;
        }

        const { auto_start: autoStart = false } = challenge;
        if (!autoStart) {
            showConfirm(challenge);
            return;
        }

        await runChallenge(challenge);
    }

    function showConfirm(challenge) {
        headingElement.textContent = trans.confirmHeading;
        infoElement.textContent = trans.confirmParagraph;

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'verify-button';
        button.textContent = trans.verifyButton;
        button.addEventListener('click', () => {
            widgetElement.innerHTML = '<div class="spinner"></div>';
            headingElement.textContent = trans.mainHeading;
            infoElement.textContent = trans.infoParagraph;
            runChallenge(challenge);
        }, { once: true });

        widgetElement.replaceChildren(button);
        button.focus();
    }

    async function runChallenge(challenge) {
        const res = await solveChallenge(challenge);
        if (res === null) {
            showError();
            return;
        }

        const success = await sendResult(res);
        if (!success) {
            showError();
            return;
        }

        showSuccess();

        setTimeout(() => {
            window.location.reload();
        }, 600);
    }

    document.addEventListener('DOMContentLoaded', botlock);
</script>

</body>
</html>
