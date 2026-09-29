// Seconds as a wait in the page's language: "in 10 minutes", "in 90 seconds".
function relativeTime(seconds) {
    const lang = document.documentElement.lang;
    // Plain "sr" formats in Cyrillic; the Serbian strings are Latin.
    const format = new Intl.RelativeTimeFormat(lang === 'sr' ? 'sr-Latn' : lang);
    return seconds % 60 === 0 ? format.format(seconds / 60, 'minute') : format.format(seconds, 'second');
}
