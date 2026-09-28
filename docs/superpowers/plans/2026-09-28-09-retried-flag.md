# 09 · "Missed" only after a slider miss (#8) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The "That did not fit" message above a slider appears only when the previous attempt was a slider that missed, never after a failed click or on a first puzzle.

**Architecture:** `handleFailure()` learns which interaction failed. It passes `retried = true` to the next `botlock()` only for a `RetryError` from the slider. The confirm and slider handlers pass their interaction; the catch in `botlock()` passes none.

**Tech Stack:** vanilla JS in `templates/challenge.php`; PHPUnit 11 string assertions on the rendered page (the existing convention for page scripts); DDEV.

**Spec:** `docs/superpowers/specs/2026-09-28-review-findings-design.md`, section C4. Commit 9 of 10; the page script it edits already has the changes from plans 04 and 05 (`BlockedError`, `BusyError` branches in `handleFailure()`).

## Global Constraints

- Four-space indentation in the script; no unrelated reformatting; no new translation strings.
- One focused commit per finding, short lowercase imperative summary, **no `Co-Authored-By` trailer**.
- Before committing: `ddev composer test`, `ddev composer validate --no-check-publish`, full PHP syntax check.

## Review Focus

- A click that fails with 403 while the level rises to 3 in between: the next slider shows no "missed" message. Checked by hand in Step 5.
- A slider miss: the next slider still shows the message (`sliderRetry`). Checked by hand in Step 5.
- A 409 restart from the slider (level rose): no "missed" message — it was not a miss. `RestartError` never sets `retried`.
- The page test pins the exact expression, so a later edit that drops the interaction check turns it red.

---

### Task 1: Pass the failing interaction to `handleFailure()`

**Files:**
- Modify: `templates/challenge.php` (script: `RetryError` comment, `handleFailure()`, `botlock()` catch, `showConfirm()`, `showSlider()`)
- Test: `tests/Middleware/ChallengeDocumentMiddlewareTest.php`

- [ ] **Step 1: Write the failing page test**

Append to `tests/Middleware/ChallengeDocumentMiddlewareTest.php`:

```php
    public function testOnlyASliderMissShowsTheMissedMessage(): void
    {
        $body = (string) $this->middleware()->process($this->request('en'), $this->failingNext())->getBody();

        self::assertStringContainsString('function handleFailure(error, attempt, interaction = null)', $body);
        self::assertStringContainsString("botlock(attempt + 1, error instanceof RetryError && interaction === 'slider')", $body);
        self::assertStringContainsString("handleFailure(error, attempt, 'click')", $body);
        self::assertStringContainsString("handleFailure(error, attempt, 'slider')", $body);
    }
```

- [ ] **Step 2: Run it to see it fail**

Run: `ddev exec vendor/bin/phpunit --filter testOnlyASliderMissShowsTheMissedMessage tests/Middleware/ChallengeDocumentMiddlewareTest.php`
Expected: FAIL on the first assertion.

- [ ] **Step 3: Implement**

In `templates/challenge.php`:

The `RetryError` comment:

```js
    // A 403 "retry": the interaction was not accepted (a missed slider, or a
    // report that did not open); the ticket is spent, a new challenge follows.
    class RetryError extends Error {}
```

`handleFailure()` — its signature and the retry branch (the `BlockedError` and `BusyError` branches above it stay):

```js
    // interaction: the one that failed ('click' or 'slider'), null when none had started.
    function handleFailure(error, attempt, interaction = null) {
```

```js
        if ((error instanceof RestartError || error instanceof RetryError) && attempt < MAX_ATTEMPTS) {
            showWorking();
            // "Missed" belongs above a new puzzle only after a slider that missed.
            botlock(attempt + 1, error instanceof RetryError && interaction === 'slider').catch((error) => {
                console.error(error);
                showError();
            });
            return;
        }
```

In `showConfirm()`: `.catch((error) => handleFailure(error, attempt, 'click'));`
In `showSlider()`: `.catch((error) => handleFailure(error, attempt, 'slider'));`
The catch at the end of `botlock()` stays `handleFailure(error, attempt);`.

- [ ] **Step 4: Run the page tests**

Run: `ddev exec vendor/bin/phpunit tests/Middleware/ChallengeDocumentMiddlewareTest.php`
Expected: PASS.

- [ ] **Step 5: DDEV check by hand (local Linux browser)**

1. Back up `.demo/settings.json`; save `{"THREAT_LEVEL_OVERRIDE": "3"}`; clear the state.
2. Slider miss: open `https://botlock.ddev.site/protected/`, move the piece far off the gap, confirm. The new puzzle shows "That did not fit. Please try again with the new picture." above it.
3. Click failure followed by a slider: save `{"THREAT_LEVEL_OVERRIDE": "2"}`, reload the protected page, and before clicking "Verify" run in the console `const k = await crypto.subtle.generateKey({name: 'AES-GCM', length: 256}, true, ['encrypt']); const orig = crypto.subtle.importKey.bind(crypto.subtle); crypto.subtle.importKey = async () => k;` so the report is sealed with a wrong key (the server answers 403). In another tab save `{"THREAT_LEVEL_OVERRIDE": "3"}`. Click "Verify": the page fetches a slider challenge and shows the puzzle **without** the "did not fit" message. Reload afterwards: the console hook would also spoil the slider report.
4. Restore `settings.json`; clear the state.

- [ ] **Step 6: Full checks and commit**

```bash
ddev composer test
ddev composer validate --no-check-publish
ddev exec sh -c "find src tests templates translations demo -name '*.php' -print0 | xargs -0 -n1 php -l" | grep -v '^No syntax errors'
git add templates/challenge.php tests/Middleware/ChallengeDocumentMiddlewareTest.php
git commit -m "show the slider miss only after a slider"
```
