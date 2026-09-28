# 05 · Global render cap (#3) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A flood of level-3 puzzle requests from many IPs, each within its own budget, cannot render more than `BOTLOCK_SLIDER_GLOBAL_LIMIT` puzzles per minute (default 300, about 0.45 CPU core at the measured 90 ms each).

**Architecture:** `PuzzleBudget::reserve()` takes a slot from a per-minute counter kept in the budget store's global state (`updateGlobal`, flock-serialized) between the per-client check and the per-client record. A full minute or a failed update answers `GlobalExhausted`; `PuzzleAction` maps it to `503 Busy` with `Retry-After` set to the seconds left in the minute. The page keeps the spinner, waits, and starts over.

**Tech Stack:** PHP 8.2+, PHPUnit 11, DDEV; vanilla JS in `templates/challenge.php`.

**Spec:** `docs/superpowers/specs/2026-09-28-review-findings-design.md`, section A ("Global", settings table, page on 503). Commit 5 of 10; requires plan 04.

## Global Constraints

- PHP 8.2 compatible; `declare(strict_types=1)` in new files; four-space indentation; typed properties and returns; trailing commas in multiline argument lists.
- No new dependencies; all state file-based in `BOTLOCK_STATE_DIR`.
- Every new `BOTLOCK_*` setting goes into the README settings table and `demo/_lib/Settings.php`.
- No new translation strings.
- One focused commit per finding, short lowercase imperative summary, **no `Co-Authored-By` trailer**.
- Before committing: `ddev composer test`, `ddev composer validate --no-check-publish`, full PHP syntax check.

## Review Focus

- A client over its own budget must not take a global slot (it would let one IP starve everyone): pinned by `testAClientOverItsBudgetTakesNoGlobalSlot`.
- A client refused by the global cap must not lose a render of its own budget: pinned by `testARefusedGlobalSlotCostsTheClientNothing`.
- The minute boundary: the 60th second of a full minute is still refused, the next second is a fresh minute; `Retry-After` is never 0. Pinned by `testTheGlobalLimitCapsAllClientsPerMinute` and `testBusyClientsRetryAtTheNextMinute`.
- A lock timeout on the global file refuses (fail closed) instead of rendering uncounted: pinned by `testAFailedGlobalUpdateRefuses`.
- The page must not retry forever on 503: it stops after `MAX_ATTEMPTS` and shows the error.

---

### Task 1: Global slot in `PuzzleBudget`

**Files:**
- Modify: `src/Challenge/PuzzleBudget.php`, `src/Challenge/PuzzleBudgetResult.php`, `src/Config/RateLimitConfig.php`, `tests/Support/InMemoryThreatStateStore.php`
- Test: `tests/Challenge/PuzzleBudgetTest.php`, `tests/Config/RateLimitConfigTest.php`

**Interfaces:**
- Consumes: `PuzzleBudget`, `PuzzleBudgetResult` from plan 04.
- Produces:
  - `PuzzleBudgetResult::GlobalExhausted`
  - `RateLimitConfig::$sliderGlobalLimit` (default 300, `0` disables)
  - `InMemoryThreatStateStore` constructor flag `bool $failGlobalWrite = false` (last)

- [ ] **Step 1: Write the failing tests**

`tests/Config/RateLimitConfigTest.php`: add `'SLIDER_GLOBAL_LIMIT',` to `NAMES` and

```php
    public function testSliderGlobalLimitDefaultAndClamping(): void
    {
        self::assertSame(300, RateLimitConfig::fromEnv()->sliderGlobalLimit);

        \putenv('BOTLOCK_THRESHOLD_FACTOR=2');
        \putenv('BOTLOCK_SLIDER_GLOBAL_LIMIT=-1');
        self::assertSame(0, RateLimitConfig::fromEnv()->sliderGlobalLimit, 'clamped, and not scaled by the factor');

        \putenv('BOTLOCK_SLIDER_GLOBAL_LIMIT=1200');
        self::assertSame(1200, RateLimitConfig::fromEnv()->sliderGlobalLimit);
    }
```

`tests/Challenge/PuzzleBudgetTest.php`: change the helper and add the tests.

```php
    private function budget(int $limit = 10, int $window = 600, int $gcProbability = 0, string $instanceId = 'inst', int $globalLimit = 300): PuzzleBudget
    {
        return new PuzzleBudget(
            new RateLimitConfig(gcProbability: $gcProbability, sliderIpLimit: $limit, sliderIpWindowSec: $window, sliderGlobalLimit: $globalLimit),
            $this->store,
            $instanceId,
            fn(): int => $this->now,
        );
    }
```

```php
    // 1_800_000_000 is the first second of a minute.

    public function testTheGlobalLimitCapsAllClientsPerMinute(): void
    {
        $budget = $this->budget(globalLimit: 2);

        self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve('203.0.113.1', 'a'));
        self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve('203.0.113.2', 'b'));
        self::assertSame(PuzzleBudgetResult::GlobalExhausted, $budget->reserve('203.0.113.3', 'c'));

        $this->now += 59;
        self::assertSame(PuzzleBudgetResult::GlobalExhausted, $budget->reserve('203.0.113.3', 'c'), 'the last second of the minute');

        $this->now += 1;
        self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve('203.0.113.3', 'c'), 'a new minute');
    }

    public function testAClientOverItsBudgetTakesNoGlobalSlot(): void
    {
        $budget = $this->budget(limit: 1, globalLimit: 2);

        self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve('203.0.113.1', 'a'));
        self::assertSame(PuzzleBudgetResult::ClientExhausted, $budget->reserve('203.0.113.1', 'a'));
        self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve('203.0.113.2', 'b'), 'the refused client took no slot');
    }

    public function testARefusedGlobalSlotCostsTheClientNothing(): void
    {
        $budget = $this->budget(limit: 1, globalLimit: 1);

        self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve('203.0.113.1', 'a'));
        self::assertSame(PuzzleBudgetResult::GlobalExhausted, $budget->reserve('203.0.113.2', 'b'));

        $this->now += 60;
        self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve('203.0.113.2', 'b'), 'its own budget is untouched');
    }

    public function testZeroGlobalLimitDisablesTheCap(): void
    {
        $budget = $this->budget(globalLimit: 0);

        for ($i = 1; $i <= 50; $i++) {
            self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve("203.0.113.$i", 'fp'));
        }
        self::assertSame([], $this->store->global, 'nothing is counted');
    }

    public function testAFailedGlobalUpdateRefuses(): void
    {
        $this->store = new InMemoryThreatStateStore(failGlobalWrite: true);

        self::assertSame(PuzzleBudgetResult::GlobalExhausted, $this->budget()->reserve(self::IP, 'fp'));
        self::assertSame([], $this->store->individual, 'the client keeps its render');
    }

    public function testBusyClientsRetryAtTheNextMinute(): void
    {
        self::assertSame(60, $this->budget()->retryAfter(PuzzleBudgetResult::GlobalExhausted));

        $this->now += 45;
        self::assertSame(15, $this->budget()->retryAfter(PuzzleBudgetResult::GlobalExhausted));
    }
```

- [ ] **Step 2: Run them to see them fail**

Run: `ddev exec vendor/bin/phpunit tests/Challenge/PuzzleBudgetTest.php tests/Config/RateLimitConfigTest.php`
Expected: errors — unknown named parameter `$sliderGlobalLimit`, undefined case `GlobalExhausted`, unknown named parameter `$failGlobalWrite`.

- [ ] **Step 3: Setting, enum case, test double flag**

`src/Config/RateLimitConfig.php` constructor, after `sliderIpWindowSec`:

```php
        /** Slider puzzles rendered per minute across all clients; 0 disables the cap. */
        public int  $sliderGlobalLimit = 300,
```

`fromEnv()`, after `sliderIpWindowSec:`:

```php
            sliderGlobalLimit: \max(0, Env::int('SLIDER_GLOBAL_LIMIT', 300)),
```

`src/Challenge/PuzzleBudgetResult.php`, after `ClientExhausted`:

```php

    /** All clients together used up the puzzles of the current minute. */
    case GlobalExhausted;
```

`tests/Support/InMemoryThreatStateStore.php`: add `public bool $failGlobalWrite = false,` as the last constructor parameter and change `updateGlobal()`:

```php
        if ($this->failing || $this->failGlobalWrite) {
            return null;
        }
```

- [ ] **Step 4: Take the global slot in `reserve()`**

`src/Challenge/PuzzleBudget.php`: replace `reserve()` and `retryAfter()`, add `takeGlobalSlot()`:

```php
    /**
     * Takes one render from the client's budget and one from the current
     * minute's, or says why not. A client over its budget takes no global
     * slot, and a client refused a global slot keeps its render.
     */
    public function reserve(?string $clientIp, string $fingerprint): PuzzleBudgetResult
    {
        $now = $this->now();
        $this->maybeCollectGarbage($now);

        $limit = $this->config->sliderIpLimit;
        $client = $this->clientKey($clientIp, $fingerprint);
        $windowStart = $now - $this->window();

        if ($limit > 0) {
            $count = $this->store->countIndividual($client, $windowStart);

            if ($count === null || $count >= $limit) {
                return PuzzleBudgetResult::ClientExhausted;
            }
        }

        if (!$this->takeGlobalSlot($now)) {
            return PuzzleBudgetResult::GlobalExhausted;
        }

        if ($limit > 0 && !$this->store->recordIndividual($client, $now, $windowStart)) {
            return PuzzleBudgetResult::ClientExhausted;
        }

        return PuzzleBudgetResult::Granted;
    }

    /**
     * Seconds until a refused client may ask again; 0 for a granted render.
     */
    public function retryAfter(PuzzleBudgetResult $result): int
    {
        return match ($result) {
            PuzzleBudgetResult::Granted => 0,
            PuzzleBudgetResult::ClientExhausted => $this->window(),
            PuzzleBudgetResult::GlobalExhausted => 60 - $this->now() % 60,
        };
    }

    /**
     * Counts one render in the current minute unless the minute is full.
     * False when it is full or the count could not be updated.
     */
    private function takeGlobalSlot(int $now): bool
    {
        $limit = $this->config->sliderGlobalLimit;

        if ($limit <= 0) {
            return true;
        }

        $minute = \intdiv($now, 60);
        $taken = false;

        $state = $this->store->updateGlobal(static function (array $state) use ($minute, $limit, &$taken): array {
            $count = ($state['minute'] ?? null) === $minute ? (int) ($state['count'] ?? 0) : 0;

            if ($count < $limit) {
                $taken = true;
                $count++;
            }

            return ['minute' => $minute, 'count' => $count];
        });

        return $state !== null && $taken;
    }
```

Class docblock: after the first paragraph add:

```php
 * All clients together get BOTLOCK_SLIDER_GLOBAL_LIMIT renders per
 * minute, counted in the store's global state, so a flood from many IPs,
 * each within its budget, still cannot render more than that.
```

and change "When it cannot be read or written the budget counts as used up" to "When it cannot be read or written the budget counts as used up (per client or globally, whichever failed)".

- [ ] **Step 5: Run the budget and config tests**

Run: `ddev exec vendor/bin/phpunit tests/Challenge/PuzzleBudgetTest.php tests/Config/RateLimitConfigTest.php`
Expected: PASS.

### Task 2: `503 Busy` from `PuzzleAction`, waiting page

**Files:**
- Modify: `src/Action/PuzzleAction.php`, `templates/challenge.php`
- Test: `tests/Action/TicketFlowTest.php`, `tests/Middleware/ChallengeDocumentMiddlewareTest.php`

**Interfaces:**
- Consumes: `PuzzleBudgetResult::GlobalExhausted` (Task 1); `retryAfter(response)`, `call()`, `handleFailure()`, `sleep()` in the page (plan 04).
- Produces: `503 {"ok": false, "error": "Busy", "code": 503}` with `Retry-After`; page `class BusyError`.

- [ ] **Step 1: Write the failing tests**

`tests/Action/TicketFlowTest.php`:

```php
    public function testTheGlobalCapAnswers503UntilTheNextMinute(): void
    {
        $this->rate = new RateLimitConfig(gcProbability: 0, sliderGlobalLimit: 1);
        $this->sliderChallenge();

        $challenge = $this->challenge(level: 3);
        $response = $this->puzzle($challenge['cid'], self::solve($challenge['gate']));

        self::assertSame(503, $response->getStatus());
        self::assertSame((string) (60 - (int) $this->now % 60), $response->getHeader('Retry-After'));
        self::assertSame(['ok' => false, 'error' => 'Busy', 'code' => 503], \json_decode((string) $response->getBody(), true));
        self::assertNull($this->store->find($challenge['cid']), 'the ticket is spent');
    }
```

`tests/Middleware/ChallengeDocumentMiddlewareTest.php`:

```php
    public function testBusyPuzzleRendersAreWaitedOut(): void
    {
        $body = (string) $this->middleware()->process($this->request('en'), $this->failingNext())->getBody();

        self::assertStringContainsString('response.status === 503', $body);
        self::assertStringContainsString('throw new BusyError(retryAfter(response))', $body);
        self::assertStringContainsString('error instanceof BusyError && attempt < MAX_ATTEMPTS', $body);
    }
```

- [ ] **Step 2: Run them to see them fail**

Run: `ddev exec vendor/bin/phpunit --filter 'testTheGlobalCapAnswers503UntilTheNextMinute|testBusyPuzzleRendersAreWaitedOut' tests`
Expected: FAIL — `UnhandledMatchError` for `GlobalExhausted` in `PuzzleAction::refusal()`; the page assertions fail.

- [ ] **Step 3: Map the result**

`src/Action/PuzzleAction.php`, `refusal()`:

```php
        [$status, $error] = match ($result) {
            PuzzleBudgetResult::ClientExhausted => [429, 'Too Many Requests'],
            PuzzleBudgetResult::GlobalExhausted => [503, 'Busy'],
        };
```

Class docblock: change "or a used-up budget (429 with Retry-After)" to "a used-up client budget (429) or a full minute for all clients (503, both with Retry-After)".

- [ ] **Step 4: Wait out a busy answer in the page**

`templates/challenge.php`, after the `BlockedError` class:

```js
    // Too many slider puzzles right now for everyone; Retry-After says when the next minute starts.
    class BusyError extends Error {
        constructor(seconds) {
            super('busy');
            this.seconds = seconds;
        }
    }
```

In `call()`, after the 429 branch:

```js
        if (response.status === 503) {
            throw new BusyError(retryAfter(response));
        }
```

In `handleFailure()`, after the `BlockedError` branch:

```js
        if (error instanceof BusyError && attempt < MAX_ATTEMPTS) {
            showWorking();
            sleep(Math.min(60, error.seconds ?? 5) * 1000)
                .then(() => botlock(attempt + 1))
                .catch((error) => {
                    console.error(error);
                    showError();
                });
            return;
        }
```

- [ ] **Step 5: Run the whole suite**

Run: `ddev composer test`
Expected: PASS.

### Task 3: Docs, demo schema, commit, DDEV check

**Files:**
- Modify: `README.md`, `CLAUDE.md`, `demo/_lib/Settings.php`

- [ ] **Step 1: README**

Settings table, after the `BOTLOCK_SLIDER_IP_WINDOW_SEC` row:

```markdown
| `BOTLOCK_SLIDER_GLOBAL_LIMIT` | `300` | Slider puzzles rendered per minute across all clients. Beyond it, puzzle requests are answered with `503` and `Retry-After` until the next minute, and the page waits and starts over. Each render costs roughly 90 ms of CPU, so the default keeps a flood from many IPs to about half a core. Not scaled by `BOTLOCK_THRESHOLD_FACTOR`. `0` disables the cap. |
```

In the flow paragraph, after the sentence ending "…and the slider is timed from the moment it was drawn." add: "`BOTLOCK_SLIDER_GLOBAL_LIMIT` caps the renders of all clients together per minute."

Layout table, `src/Challenge/` row: `the per-IP budget of slider puzzle renders, ` becomes `the per-IP and per-minute budgets of slider puzzle renders, `.

- [ ] **Step 2: CLAUDE.md**

`` the `PuzzleBudget` that counts slider puzzle renders per client IP `` becomes `` the `PuzzleBudget` that counts slider puzzle renders per client IP and per minute overall ``.

- [ ] **Step 3: Demo schema**

`demo/_lib/Settings.php`, after the `SLIDER_IP_WINDOW_SEC` line:

```php
            'SLIDER_GLOBAL_LIMIT' => ['group' => 'Rate limits', 'type' => self::TYPE_INT, 'label' => 'Slider puzzles per minute', 'default' => '300', 'help' => 'All clients; 503 beyond. 0 disables.'],
```

- [ ] **Step 4: Full checks and commit**

```bash
ddev composer test
ddev composer validate --no-check-publish
ddev exec sh -c "find src tests templates translations demo -name '*.php' -print0 | xargs -0 -n1 php -l" | grep -v '^No syntax errors'
git add src/Challenge/PuzzleBudget.php src/Challenge/PuzzleBudgetResult.php src/Config/RateLimitConfig.php src/Action/PuzzleAction.php templates/challenge.php tests/Support/InMemoryThreatStateStore.php tests/Challenge/PuzzleBudgetTest.php tests/Config/RateLimitConfigTest.php tests/Action/TicketFlowTest.php tests/Middleware/ChallengeDocumentMiddlewareTest.php README.md CLAUDE.md demo/_lib/Settings.php
git commit -m "cap slider puzzle renders per minute"
```

- [ ] **Step 5: DDEV check**

Back up `.demo/settings.json`; save `{"THREAT_LEVEL_OVERRIDE": "3", "SLIDER_GLOBAL_LIMIT": "1"}`; clear the state. Open `https://botlock.ddev.site/protected/` in the local browser: the slider appears. In a second, cookie-less window (or via a forged identity in the dashboard frame) open it again within the same minute: the spinner stays, the network tab shows `POST ?_botlock=puzzle` → `503` with `Retry-After`, and after the minute turns the slider appears. Restore `settings.json` and clear the state.
