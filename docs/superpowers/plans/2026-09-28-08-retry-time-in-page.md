# 08 · Retry time rendered into the 429 page (#6) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The level-4 page shows its wait time without sending a `HEAD` request for its own URL, which counted toward the rate and could reach the protected application once the block lapsed.

**Architecture:** `RateLimitConfig::retryAfterSec()` becomes the single source of the wait. `LocalizedPage` accepts page variables, passes them to the template and folds them into its cache version, so a changed window renders anew. `Kernel` gives the blocked page `retryAfter`; `blocked.php` renders it as `data-retry-after` and formats it in the browser.

**Tech Stack:** PHP 8.2+, PHPUnit 11, DDEV; vanilla JS in `templates/blocked.php`.

**Spec:** `docs/superpowers/specs/2026-09-28-review-findings-design.md`, section C3. Commit 8 of 10; independent of plans 01–07 except that `Kernel` has changed around the edited lines.

## Global Constraints

- PHP 8.2 compatible; four-space indentation; typed properties and returns; trailing commas in multiline argument lists.
- No new translation strings (`blockedRetry` with `{time}`, `blockedWait` stay).
- One focused commit per finding, short lowercase imperative summary, **no `Co-Authored-By` trailer**.
- Before committing: `ddev composer test`, `ddev composer validate --no-check-publish`, full PHP syntax check.

## Review Focus

- A changed `BOTLOCK_INDIVIDUAL_RATE_WINDOW_SEC` must not keep serving a cached page with the old number: pinned by `testVarsReachTheTemplateAndItsCacheVersion`.
- A window of `0` must still show a positive time and send `Retry-After: 1`: pinned by `testRetryAfterIsTheWindowAndAtLeastOneSecond`.
- Header and page must agree: pinned by `testBlockedPageCarriesTheRetryTimeOfTheHeader`.
- Page variables must not override `lang`, `trans` or `transJson` (the fixed keys win in the merge).
- Without JavaScript the generic `blockedWait` note stays.

---

### Task 1: Page variables in `LocalizedPage`

**Files:**
- Modify: `src/Template/LocalizedPage.php`
- Test: `tests/Template/LocalizedPageTest.php`

**Interfaces:**
- Produces: `LocalizedPage::__construct(…, array $includes = [], array $vars = [])` — `$vars` last, `array<string, scalar>`.

- [ ] **Step 1: Write the failing test**

In `tests/Template/LocalizedPageTest.php` give the helper a parameter and add a test:

```php
    public function testVarsReachTheTemplateAndItsCacheVersion(): void
    {
        \file_put_contents($this->template, '<p data-wait="<?= $e((string) $wait) ?>"></p>');
        \clearstatcache();
        $request = Requests::make(headers: ['Accept-Language' => 'de']);

        self::assertSame('<p data-wait="60"></p>', $this->page(['wait' => 60])->respond($request, 200)->getBody());
        self::assertSame('<p data-wait="90"></p>', $this->page(['wait' => 90])->respond($request, 200)->getBody(), 'another value renders anew');
    }
```

```php
    private function page(array $vars = []): LocalizedPage
    {
        $translations = new TranslationLoader(__DIR__ . '/../../translations');

        return new LocalizedPage(
            new LanguageNegotiator($translations->supported()),
            $translations,
            new TemplateRenderer(),
            new RenderedPageCache($this->dir . '/cache', 'inst'),
            'test',
            $this->template,
            [$this->partial],
            $vars,
        );
    }
```

- [ ] **Step 2: Run it to see it fail**

Run: `ddev exec vendor/bin/phpunit tests/Template/LocalizedPageTest.php`
Expected: FAIL — `data-wait=""` (the extra argument is ignored, `$wait` is undefined).

- [ ] **Step 3: Implement**

`src/Template/LocalizedPage.php`:

Class docblock, append to the first paragraph: `Page variables are passed to the template and are part of the cache version.`

Constructor:

```php
    /**
     * @param string                $page         Cache name of the page, lowercase letters only
     * @param string                $templatePath Page template
     * @param list<string>          $includes     Partials the template includes
     * @param array<string, scalar> $vars         Further template variables; lang, trans and transJson are reserved
     */
    public function __construct(
        private LanguageNegotiator $negotiator,
        private TranslationLoader  $translations,
        private TemplateRenderer   $renderer,
        private RenderedPageCache  $cache,
        private string             $page,
        private string             $templatePath,
        private array              $includes = [],
        private array              $vars = [],
    ) {}
```

In `respond()`, after the `$version = RenderedPageCache::versionOf(...)` line:

```php
        // What the page variables say is rendered into the page as well.
        $version = \substr(\sha1($version . "\0" . \json_encode($this->vars, self::JSON_FLAGS)), 0, 12);
```

and the render call:

```php
        $html = $this->renderer->render($this->templatePath, [
            ...$this->vars,
            'lang' => $lang,
            'trans' => $trans,
            'transJson' => \json_encode($trans, self::JSON_FLAGS),
        ]);
```

- [ ] **Step 4: Run it**

Run: `ddev exec vendor/bin/phpunit tests/Template/LocalizedPageTest.php`
Expected: PASS.

### Task 2: One retry time for header and page

**Files:**
- Modify: `src/Config/RateLimitConfig.php`, `src/Middleware/ThreatBlockMiddleware.php`, `src/Kernel.php`, `templates/blocked.php`, `README.md` (layout row)
- Test: `tests/Config/RateLimitConfigTest.php`, `tests/Middleware/ThreatBlockMiddlewareTest.php`

**Interfaces:**
- Consumes: `LocalizedPage` `$vars` (Task 1).
- Produces: `RateLimitConfig::retryAfterSec(): int`; `blocked.php` template variable `int $retryAfter`.

- [ ] **Step 1: Write the failing tests**

`tests/Config/RateLimitConfigTest.php`:

```php
    public function testRetryAfterIsTheWindowAndAtLeastOneSecond(): void
    {
        self::assertSame(90, (new RateLimitConfig(individualRateWindowSec: 90))->retryAfterSec());
        self::assertSame(1, (new RateLimitConfig(individualRateWindowSec: 0))->retryAfterSec());
    }
```

`tests/Middleware/ThreatBlockMiddlewareTest.php`: replace `testBlockedPageAsksForRetryAfterWithHead` with

```php
    public function testBlockedPageCarriesTheRetryTimeOfTheHeader(): void
    {
        $request = Requests::make(headers: ['Accept' => 'text/html']);
        $request->context->threatLevel = 4;

        $response = $this->middleware(window: 90)->process($request, self::failingNext());
        $body = (string) $response->getBody();

        self::assertSame('90', $response->getHeader('Retry-After'));
        self::assertStringContainsString('data-retry-after="90"', $body);
        self::assertStringNotContainsString("method: 'HEAD'", $body, 'the page does not ask again');
        self::assertStringContainsString('new Intl.RelativeTimeFormat(', $body);
        self::assertStringContainsString("note.dataset.retryTemplate.replace('{time}', time)", $body);
    }
```

and the helper:

```php
    private function middleware(int $window = 60): ThreatBlockMiddleware
    {
        $translations = new TranslationLoader(self::ROOT . '/translations');
        $config = new RateLimitConfig(individualRateWindowSec: $window);

        return new ThreatBlockMiddleware(
            $config,
            new LocalizedPage(
                new LanguageNegotiator($translations->supported()),
                $translations,
                new TemplateRenderer(),
                new RenderedPageCache($this->cacheDir, 'inst'),
                'blocked',
                self::ROOT . '/templates/blocked.php',
                [self::ROOT . '/templates/partials/style.css'],
                ['retryAfter' => $config->retryAfterSec()],
            ),
        );
    }
```

- [ ] **Step 2: Run them to see them fail**

Run: `ddev exec vendor/bin/phpunit tests/Config/RateLimitConfigTest.php tests/Middleware/ThreatBlockMiddlewareTest.php`
Expected: errors — undefined method `retryAfterSec()`.

- [ ] **Step 3: Add `retryAfterSec()` and use it in the block**

`src/Config/RateLimitConfig.php`, after `isLevel4Enabled()`:

```php
    /**
     * Seconds a level-4 client is told to wait: the individual window,
     * which it has to fall below again, and at least one.
     */
    public function retryAfterSec(): int
    {
        return \max(1, $this->individualRateWindowSec);
    }
```

`src/Middleware/ThreatBlockMiddleware.php`: `$retryAfter = (string) $this->config->retryAfterSec();`

- [ ] **Step 4: Render the time into the page**

`templates/blocked.php`:

Header docblock, add `@var int $retryAfter Seconds until the client may retry, the same value as the Retry-After header`; the guard becomes `if (!isset($lang, $trans, $e, $retryAfter)) {`.

The note:

```php
    <p id="retry-note" data-retry-template="<?= $e($trans['blockedRetry']) ?>" data-retry-after="<?= (int) $retryAfter ?>"><?= $e($trans['blockedWait']) ?></p>
```

Replace the script:

```html
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
```

- [ ] **Step 5: Give the blocked page its variable in `Kernel`**

`src/Kernel.php`, the page factory and the block:

```php
        $page = fn (string $name, array $vars = []): LocalizedPage => new LocalizedPage(
            $negotiator,
            $translations,
            $renderer,
            $this->pageCache,
            $name,
            "{$templates}/{$name}.php",
            ["{$templates}/partials/style.css"],
            $vars,
        );
```

```php
            ->add(new ThreatBlockMiddleware($this->rate, $page('blocked', ['retryAfter' => $this->rate->retryAfterSec()])))
```

- [ ] **Step 6: README layout row**

`templates/blocked.php` row: replace `; its script reads \`Retry-After\` with a \`HEAD\` request and shows the wait time.` with `; it carries the \`Retry-After\` value, which its script formats as the wait time.`

- [ ] **Step 7: Full checks and commit**

```bash
ddev composer test
ddev composer validate --no-check-publish
ddev exec sh -c "find src tests templates translations demo -name '*.php' -print0 | xargs -0 -n1 php -l" | grep -v '^No syntax errors'
git add src/Template/LocalizedPage.php src/Config/RateLimitConfig.php src/Middleware/ThreatBlockMiddleware.php src/Kernel.php templates/blocked.php README.md tests/Template/LocalizedPageTest.php tests/Config/RateLimitConfigTest.php tests/Middleware/ThreatBlockMiddlewareTest.php
git commit -m "render the retry time into the 429 page"
```

- [ ] **Step 8: DDEV check**

Back up `.demo/settings.json`; save `{"THREAT_LEVEL_OVERRIDE": "4", "INDIVIDUAL_RATE_WINDOW_SEC": "120"}`. Open `https://botlock.ddev.site/protected/` in the local browser: the note reads "You can try again in 2 minutes." and the network tab shows no second request to the page. Change the window to `90`, reload: "in 90 seconds" (the cache rendered anew). Restore `settings.json`.
