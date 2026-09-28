<?php declare(strict_types=1);
/**
 * Page for threat level 4 (429 Too Many Requests). Rendered by
 * Template\LocalizedPage for Middleware\ThreatBlockMiddleware, once per
 * language, then cached. The shared styles come from partials/style.css.
 *
 * @var string                $lang       Language code, always one of I18n\TranslationLoader::LANGUAGES
 * @var array<string,string>  $trans      Strings for that language (keys: I18n\TranslationLoader::KEYS)
 * @var \Closure(string):string $e        HTML escape helper
 * @var int                   $retryAfter Seconds until the client may retry, the same value as the Retry-After header
 */
if (!isset($lang, $trans, $e, $retryAfter)) {
    \http_response_code(404);
    return;
}
?>
<!DOCTYPE html>
<html lang="<?= $e($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $e($trans['blockedHeading']) ?></title>
    <style><?php \readfile(__DIR__ . '/partials/style.css'); ?></style>
    <style>
        #main-heading,
        #info-paragraph,
        #retry-note {
            text-wrap: balance;
        }

        .status-icon {
            display: block;
            margin: 0 auto 2rem;
        }
    </style>
</head>
<body data-botlock-blocked>

<div id="security">
    <h1 id="main-heading"><?= $e($trans['blockedHeading']) ?></h1>
    <p id="info-paragraph"><?= $e($trans['blockedMessage']) ?></p>

    <svg class="status-icon" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round">
        <path d="M5 22h14M5 2h14"></path>
        <path d="M17 22v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22"></path>
        <path d="M7 2v4.172a2 2 0 0 0 .586 1.414L12 12l4.414-4.414A2 2 0 0 0 17 6.172V2"></path>
    </svg>

    <p id="retry-note" data-retry-template="<?= $e($trans['blockedRetry']) ?>" data-retry-after="<?= (int) $retryAfter ?>"><?= $e($trans['blockedWait']) ?></p>

    <p id="footer-note"><?= $e($trans['blockedFooter']) ?></p>
</div>

<script>
    // Retry-After as rendered with the page: a page cannot read its own
    // response headers, and asking again would count as another request.
    // Without JavaScript the generic wait note stays.
    (() => {
        const note = document.getElementById('retry-note');
        const seconds = Number(note.dataset.retryAfter);

        if (!Number.isInteger(seconds) || seconds < 1) {
            return;
        }

        const lang = document.documentElement.lang;
        // Plain "sr" formats in Cyrillic; the Serbian strings are Latin.
        const format = new Intl.RelativeTimeFormat(lang === 'sr' ? 'sr-Latn' : lang);
        const time = seconds % 60 === 0 ? format.format(seconds / 60, 'minute') : format.format(seconds, 'second');

        note.textContent = note.dataset.retryTemplate.replace('{time}', time);
    })();
</script>

</body>
</html>
