# 04 · Puzzle gate and per-IP render budget (#1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Blind guessing of the level-3 slider is limited to `BOTLOCK_SLIDER_IP_LIMIT` renders per client IP per window, each paid with a gate proof of work, and a bare `GET challenge` no longer renders a picture.

**Architecture:** A level-3 `GET challenge` issues the ticket in a *gate* phase and answers with a base-difficulty proof of work bound to `id|gate|level`. The new `POST ?_botlock=puzzle` (`Action\PuzzleAction`) redeems the ticket, verifies the gate, reserves a render from `Challenge\PuzzleBudget` (a per-client counter in its own `FileThreatStateStore` under `<stateDir>/puzzles`), and only then renders the puzzle, storing target, key and render time in the renewed ticket. The slider track is timed from the render. The challenge page solves the gate before it shows the slider and shows the blocked message on `429`.

**Tech Stack:** PHP 8.2+, PHPUnit 11, DDEV; vanilla JS in `templates/challenge.php`.

**Spec:** `docs/superpowers/specs/2026-09-28-review-findings-design.md`, section A (without the global cap, which is plan 05) and B4. Commit 4 of 10; requires plans 01–03.

## Global Constraints

- PHP 8.2 compatible; `declare(strict_types=1)` in new files; four-space indentation; typed properties and returns; trailing commas in multiline argument lists.
- No new dependencies; all state file-based in `BOTLOCK_STATE_DIR`. No raw client IP is written to disk.
- Every new `BOTLOCK_*` setting goes into the README settings table and `demo/_lib/Settings.php`.
- No new translation strings; the page reuses `blockedHeading`, `blockedMessage`, `blockedRetry`, `blockedWait`, `blockedFooter`.
- One focused commit per finding, short lowercase imperative summary, **no `Co-Authored-By` trailer**.
- Before committing: `ddev composer test`, `ddev composer validate --no-check-publish`, full PHP syntax check.

## Review Focus

- A script that solves the gate and answers the slider instantly: the track must be timed from the render (`puzzleAt`), not from issuing, or the gate time would satisfy `SliderTrack`'s 800 ms minimum. Pinned by `testTimeAtTheGateDoesNotCountAsLookingAtThePicture` (Task 3).
- An untrusted crawler at level 3 must pay the gate at base difficulty, not × `BOTLOCK_CRAWLER_FACTOR` (it pays that on the final proof). Pinned by `testCrawlersPayTheGateAtBaseDifficulty` (Task 3).
- A second `POST puzzle` for a ticket that already has its puzzle must not re-render (a free re-roll of the target): pinned by `testOnlyAGateTicketOpensAPuzzle` (Task 3).
- A storage fault in the budget store must refuse the render rather than hand out uncounted guesses. Pinned by `testAnUnavailableStoreRefuses` (Task 2).
- Two concurrent renders by one IP at `limit - 1` may both pass (count and record are two calls, like the rate limiter). Accepted: the overshoot is bounded by concurrency, not by time.

---

### Task 1: Gate phase on the ticket

**Files:**
- Modify: `src/Challenge/ChallengeTicket.php`
- Test: `tests/Challenge/ChallengeTicketTest.php`

**Interfaces:**
- Consumes: `ChallengeTicket::with()` and `renewed()` from plan 03.
- Produces:
  - constructor parameter `?float $puzzleAt = null` (last), array key `pat`
  - `awaitsPuzzle(): bool` — `Slider` and no target yet
  - `withPuzzle(int $target, string $key, float $now): self`
  - `gateBinding(): string` — `id|gate|level`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Challenge/ChallengeTicketTest.php` (add `use GES\Botlock\Challenge\InteractionCipher;`):

```php
    public function testASliderTicketAwaitsItsPuzzleUntilItHasOne(): void
    {
        $ticket = self::slider(issuedAt: 1_800_000_000.0);
        self::assertTrue($ticket->awaitsPuzzle());
        self::assertFalse($ticket->isReadyForProof());

        $key = InteractionCipher::newKey();
        $opened = $ticket->withPuzzle(123, $key, 1_800_000_004.25);
        self::assertFalse($opened->awaitsPuzzle());
        self::assertSame(123, $opened->sliderTarget);
        self::assertSame($key, $opened->key);
        self::assertSame(1_800_000_004.25, $opened->puzzleAt);
        self::assertEquals($opened, ChallengeTicket::fromArray($opened->toArray()), 'the render time is stored');

        self::assertFalse(self::ticket(issuedAt: 1_800_000_000.0)->awaitsPuzzle(), 'a click ticket has no puzzle');
    }

    public function testTheGateBindingDiffersFromTheProofBinding(): void
    {
        $ticket = self::slider(issuedAt: 1_800_000_000.0);

        self::assertSame($ticket->id . '|gate|3', $ticket->gateBinding());
        self::assertNotSame($ticket->binding(), $ticket->gateBinding());
    }

    private static function slider(float $issuedAt): ChallengeTicket
    {
        return new ChallengeTicket(
            id: ChallengeTicket::newId($issuedAt),
            subject: 'fp',
            level: 3,
            interaction: Interaction::Slider,
            issuedAt: $issuedAt,
            expiresAt: (int) \floor($issuedAt) + ChallengeTicket::TTL,
        );
    }
```

- [ ] **Step 2: Run them to see them fail**

Run: `ddev exec vendor/bin/phpunit tests/Challenge/ChallengeTicketTest.php`
Expected: errors `Call to undefined method … awaitsPuzzle()`.

- [ ] **Step 3: Implement**

In `src/Challenge/ChallengeTicket.php`:

Constructor docblock: add `@param float|null $puzzleAt Unix time the slider puzzle was rendered; null before and without one`; add the parameter last:

```php
        public ?string     $key = null,
        public ?float      $puzzleAt = null,
    ) {}
```

Add after `isReadyForProof()`:

```php
    /**
     * A slider ticket at the gate: its puzzle has not been rendered yet.
     */
    public function awaitsPuzzle(): bool
    {
        return $this->interaction === Interaction::Slider && $this->sliderTarget === null;
    }

    /**
     * @param float $now render time; the slider is timed from it, not from issuing
     */
    public function withPuzzle(int $target, string $key, float $now): self
    {
        return $this->with(sliderTarget: $target, key: $key, puzzleAt: $now);
    }
```

After `binding()`:

```php
    /**
     * Ticket fields the gate proof of work in front of the slider puzzle is
     * bound to; never valid for the final proof, and vice versa.
     */
    public function gateBinding(): string
    {
        return $this->id . '|gate|' . $this->level;
    }
```

Extend the private `with()`:

```php
    private function with(
        ?int $expiresAt = null,
        ?float $difficulty = null,
        ?bool $interacted = null,
        ?int $sliderTarget = null,
        ?string $key = null,
        ?float $puzzleAt = null,
    ): self {
        return new self(
            id: $this->id,
            subject: $this->subject,
            level: $this->level,
            interaction: $this->interaction,
            issuedAt: $this->issuedAt,
            expiresAt: $expiresAt ?? $this->expiresAt,
            difficulty: $difficulty ?? $this->difficulty,
            sliderTarget: $sliderTarget ?? $this->sliderTarget,
            interacted: $interacted ?? $this->interacted,
            key: $key ?? $this->key,
            puzzleAt: $puzzleAt ?? $this->puzzleAt,
        );
    }
```

`toArray()`: add `'pat' => $this->puzzleAt,` last. `fromArray()`: add the named argument `puzzleAt: \is_numeric($data['pat'] ?? null) ? (float) $data['pat'] : null,` last.

Class docblock: replace "The client only ever sees the id and, for an interaction, the key to seal its report with" with "The client only ever sees the id and, for an interaction, the key to seal its report with (for the slider only once it paid the gate and got its puzzle)".

- [ ] **Step 4: Run them**

Run: `ddev exec vendor/bin/phpunit tests/Challenge/ChallengeTicketTest.php`
Expected: PASS.

### Task 2: `PuzzleBudget` with the per-client limit, and its settings

**Files:**
- Create: `src/Challenge/PuzzleBudgetResult.php`, `src/Challenge/PuzzleBudget.php`
- Modify: `src/Config/RateLimitConfig.php`
- Test: `tests/Challenge/PuzzleBudgetTest.php` (new), `tests/Config/RateLimitConfigTest.php`

**Interfaces:**
- Consumes: `Threat\ThreatStateStore` (`countIndividual`, `recordIndividual`, `collectGarbage`), `tests/Support/InMemoryThreatStateStore`.
- Produces (plan 05 extends both):
  - `enum PuzzleBudgetResult { case Granted; case ClientExhausted; }`
  - `PuzzleBudget::__construct(RateLimitConfig $config, ThreatStateStore $store, string $instanceId, ?\Closure $clock = null)` — clock returns `int` Unix seconds
  - `reserve(?string $clientIp, string $fingerprint): PuzzleBudgetResult`
  - `retryAfter(PuzzleBudgetResult $result): int`
  - `RateLimitConfig::$sliderIpLimit` (default 10), `RateLimitConfig::$sliderIpWindowSec` (default 600)

- [ ] **Step 1: Write the failing config test**

In `tests/Config/RateLimitConfigTest.php` add `'SLIDER_IP_LIMIT', 'SLIDER_IP_WINDOW_SEC',` to `NAMES` and:

```php
    public function testSliderBudgetDefaultsAndClamping(): void
    {
        $config = RateLimitConfig::fromEnv();
        self::assertSame([10, 600], [$config->sliderIpLimit, $config->sliderIpWindowSec]);

        \putenv('BOTLOCK_THRESHOLD_FACTOR=3');
        \putenv('BOTLOCK_SLIDER_IP_LIMIT=-5');
        \putenv('BOTLOCK_SLIDER_IP_WINDOW_SEC=0');
        $config = RateLimitConfig::fromEnv();
        self::assertSame([0, 1], [$config->sliderIpLimit, $config->sliderIpWindowSec], 'clamped, and not scaled by the factor');

        \putenv('BOTLOCK_SLIDER_IP_LIMIT=25');
        self::assertSame(25, RateLimitConfig::fromEnv()->sliderIpLimit);
    }
```

- [ ] **Step 2: Write the failing budget test**

`tests/Challenge/PuzzleBudgetTest.php`:

```php
<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Challenge;

use GES\Botlock\Challenge\PuzzleBudget;
use GES\Botlock\Challenge\PuzzleBudgetResult;
use GES\Botlock\Config\RateLimitConfig;
use GES\Botlock\Tests\Support\InMemoryThreatStateStore;
use PHPUnit\Framework\TestCase;

final class PuzzleBudgetTest extends TestCase
{
    private const IP = '203.0.113.10';

    private InMemoryThreatStateStore $store;
    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        $this->store = new InMemoryThreatStateStore();
    }

    public function testAClientGetsItsLimitPerWindow(): void
    {
        $budget = $this->budget(limit: 3);

        for ($i = 0; $i < 3; $i++) {
            self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve(self::IP, 'fp'));
        }
        self::assertSame(PuzzleBudgetResult::ClientExhausted, $budget->reserve(self::IP, 'fp'));

        $this->now += 300;
        self::assertSame(PuzzleBudgetResult::ClientExhausted, $budget->reserve(self::IP, 'fp'), 'still inside the window');

        $this->now += 301;
        self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve(self::IP, 'fp'), 'refusals did not extend the wait');
    }

    public function testTheIpCountsNotTheFingerprint(): void
    {
        $budget = $this->budget(limit: 1);

        self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve(self::IP, 'fp-a'));
        self::assertSame(PuzzleBudgetResult::ClientExhausted, $budget->reserve(self::IP, 'fp-b'), 'new headers, same IP');
        self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve('203.0.113.11', 'fp-a'), 'another IP');
    }

    public function testWithoutAnIpTheFingerprintCounts(): void
    {
        $budget = $this->budget(limit: 1);

        self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve(null, 'fp-a'));
        self::assertSame(PuzzleBudgetResult::ClientExhausted, $budget->reserve('', 'fp-a'), 'an empty IP is no IP');
        self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve(null, 'fp-b'));
    }

    public function testZeroLimitDisablesTheClientBudget(): void
    {
        $budget = $this->budget(limit: 0);

        for ($i = 0; $i < 50; $i++) {
            self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve(self::IP, 'fp'));
        }
        self::assertSame([], $this->store->individual, 'nothing is counted');
    }

    public function testAnUnavailableStoreRefuses(): void
    {
        $this->store = new InMemoryThreatStateStore(failIndividualRead: true);
        self::assertSame(PuzzleBudgetResult::ClientExhausted, $this->budget()->reserve(self::IP, 'fp'), 'unreadable');

        $this->store = new InMemoryThreatStateStore(failIndividualWrite: true);
        self::assertSame(PuzzleBudgetResult::ClientExhausted, $this->budget()->reserve(self::IP, 'fp'), 'unwritable');
    }

    public function testNoRawAddressIsStored(): void
    {
        $this->budget()->reserve(self::IP, 'fp');
        $this->budget(instanceId: 'other')->reserve(self::IP, 'fp');

        $keys = \array_keys($this->store->individual);
        self::assertCount(2, $keys, 'each instance counts on its own');
        foreach ($keys as $key) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
        }
    }

    public function testRefusedClientsRetryAfterTheWindow(): void
    {
        self::assertSame(600, $this->budget()->retryAfter(PuzzleBudgetResult::ClientExhausted));
        self::assertSame(0, $this->budget()->retryAfter(PuzzleBudgetResult::Granted));
        self::assertSame(1, $this->budget(window: 0)->retryAfter(PuzzleBudgetResult::ClientExhausted), 'at least a second');
    }

    public function testSweepsItsOwnWindow(): void
    {
        $this->budget(gcProbability: 1)->reserve(self::IP, 'fp');

        self::assertSame([['windowStart' => $this->now - 600, 'maxEntries' => 500]], $this->store->gcCalls);
    }

    private function budget(int $limit = 10, int $window = 600, int $gcProbability = 0, string $instanceId = 'inst'): PuzzleBudget
    {
        return new PuzzleBudget(
            new RateLimitConfig(gcProbability: $gcProbability, sliderIpLimit: $limit, sliderIpWindowSec: $window),
            $this->store,
            $instanceId,
            fn(): int => $this->now,
        );
    }
}
```

- [ ] **Step 3: Run both to see them fail**

Run: `ddev exec vendor/bin/phpunit tests/Challenge/PuzzleBudgetTest.php tests/Config/RateLimitConfigTest.php`
Expected: errors — class `PuzzleBudget` not found, unknown named parameter `$sliderIpLimit`, undefined property `sliderIpLimit`.

- [ ] **Step 4: Add the settings**

`src/Config/RateLimitConfig.php`, constructor, after `gcProbability`:

```php
        /** One request in this many sweeps stale per-client state; 0 disables. */
        public int  $gcProbability = 1000,
        /** Slider puzzles rendered per client IP within sliderIpWindowSec; 0 disables the per-client budget. */
        public int  $sliderIpLimit = 10,
        /** Window of the per-client puzzle budget in seconds; also its Retry-After. */
        public int  $sliderIpWindowSec = 600,
    ) {}
```

`fromEnv()`, after `gcProbability:` (unscaled, like the window):

```php
            sliderIpLimit: \max(0, Env::int('SLIDER_IP_LIMIT', 10)),
            sliderIpWindowSec: \max(1, Env::int('SLIDER_IP_WINDOW_SEC', 600)),
```

Class docblock: append ` The slider puzzle budget is not scaled.`

- [ ] **Step 5: Create the result enum**

`src/Challenge/PuzzleBudgetResult.php`:

```php
<?php declare(strict_types=1);

namespace GES\Botlock\Challenge;

/**
 * Whether PuzzleBudget lets one more slider puzzle be rendered.
 */
enum PuzzleBudgetResult
{
    case Granted;

    /** The client used up its puzzles for the window. */
    case ClientExhausted;
}
```

- [ ] **Step 6: Create the budget**

`src/Challenge/PuzzleBudget.php`:

```php
<?php declare(strict_types=1);

namespace GES\Botlock\Challenge;

use GES\Botlock\Config\RateLimitConfig;
use GES\Botlock\Threat\ThreatStateStore;

/**
 * Counts slider puzzle renders, the expensive part of a level-3 challenge
 * and the only way to get another guess. Each client IP (the fingerprint
 * when there is none) gets BOTLOCK_SLIDER_IP_LIMIT renders per
 * BOTLOCK_SLIDER_IP_WINDOW_SEC, solved or not. Clients are stored under a
 * hash of the instance id and the IP, never the IP itself.
 *
 * The store is a ThreatStateStore of its own (Kernel roots it at
 * <stateDir>/puzzles), so its window and sweeps do not mix with the rate
 * limiter's. When it cannot be read or written the budget counts as used
 * up: a storage fault must not hand out uncounted guesses.
 */
final readonly class PuzzleBudget
{
    /**
     * @param \Closure|null $clock returns the current Unix timestamp; defaults to time()
     */
    public function __construct(
        private RateLimitConfig $config,
        private ThreatStateStore $store,
        private string $instanceId,
        private ?\Closure $clock = null,
    ) {}

    /**
     * Takes one render from the client's budget, or says why not.
     */
    public function reserve(?string $clientIp, string $fingerprint): PuzzleBudgetResult
    {
        $now = $this->now();
        $this->maybeCollectGarbage($now);

        if ($this->config->sliderIpLimit <= 0) {
            return PuzzleBudgetResult::Granted;
        }

        $client = $this->clientKey($clientIp, $fingerprint);
        $windowStart = $now - $this->window();
        $count = $this->store->countIndividual($client, $windowStart);

        if ($count === null
            || $count >= $this->config->sliderIpLimit
            || !$this->store->recordIndividual($client, $now, $windowStart))
        {
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
        };
    }

    private function clientKey(?string $clientIp, string $fingerprint): string
    {
        return \hash('sha256', $clientIp !== null && $clientIp !== ''
            ? $this->instanceId . "\0" . $clientIp
            : $this->instanceId . "\0fp\0" . $fingerprint);
    }

    private function window(): int
    {
        return \max(1, $this->config->sliderIpWindowSec);
    }

    private function now(): int
    {
        return $this->clock ? ($this->clock)() : \time();
    }

    private function maybeCollectGarbage(int $now): void
    {
        $probability = $this->config->gcProbability;

        if ($probability > 0 && \random_int(1, $probability) === 1) {
            $this->store->collectGarbage($now - $this->window());
        }
    }
}
```

- [ ] **Step 7: Run both**

Run: `ddev exec vendor/bin/phpunit tests/Challenge/PuzzleBudgetTest.php tests/Config/RateLimitConfigTest.php`
Expected: PASS.

### Task 3: `POST ?_botlock=puzzle`, the gate in `GET challenge`, slider timing

**Files:**
- Create: `src/Action/PuzzleAction.php`
- Modify: `src/Action/ChallengeAction.php`, `src/Action/InteractAction.php`, `src/Kernel.php`
- Test: `tests/Action/TicketFlowTest.php`

**Interfaces:**
- Consumes: `TicketService` (plan 01), `ChallengeTicket::awaitsPuzzle()`, `withPuzzle()`, `gateBinding()`, `renewed()`, `puzzleAt` (Task 1), `PuzzleBudget`, `PuzzleBudgetResult` (Task 2).
- Produces:
  - `PuzzleAction::__construct(ProofOfWorkConfig $config, TicketService $tickets, PuzzleBudget $budget)`
  - JSON of `GET challenge` for a slider: `cid`, `lvl`, `int`, `exp`, `min_ms`, `gate` (proof of work) — no `puzzle`, no `key`, no `pow`
  - JSON of `POST puzzle` on success: `cid`, `lvl`, `int`, `exp`, `min_ms`, `key`, `puzzle`; on a bad gate `401 {"ok": false}`; on a refusal `429 {"ok": false, "error": "Too Many Requests", "code": 429}` with `Retry-After`
  - `Kernel` constructor parameter `?PuzzleBudget $puzzleBudget` before `$bootError`

- [ ] **Step 1: Rewrite the level-3 flow tests**

In `tests/Action/TicketFlowTest.php`:

Imports: add

```php
use GES\Botlock\Action\PuzzleAction;
use GES\Botlock\Challenge\ProofOfWork;
use GES\Botlock\Challenge\PuzzleBudget;
use GES\Botlock\Config\RateLimitConfig;
use GES\Botlock\Http\Response;
use GES\Botlock\Tests\Support\InMemoryThreatStateStore;
```

Properties and `setUp()`:

```php
    private RateLimitConfig $rate;
    private InMemoryThreatStateStore $budgetStore;
```

```php
        $this->rate = new RateLimitConfig(gcProbability: 0);
        $this->budgetStore = new InMemoryThreatStateStore();
```

In `request()` add `$request->context->clientIp = '203.0.113.10';` next to the fingerprint.

Helpers (add next to `interact()`):

```php
    /**
     * Posts a gate solution; the answer carries the puzzle and the key.
     */
    private function puzzle(string $cid, array $solution, int $level = 3): Response
    {
        $action = new PuzzleAction(
            $this->config,
            $this->service(),
            new PuzzleBudget($this->rate, $this->budgetStore, 'inst', fn(): int => (int) $this->now),
        );

        return $action->handle($this->request('POST', $level, body: $solution + ['cid' => $cid]));
    }

    /**
     * Pays the gate and merges the puzzle answer into the challenge, like the page does.
     */
    private function openPuzzle(array $challenge): array
    {
        return self::json($this->puzzle($challenge['cid'], self::solve($challenge['gate']))) + $challenge;
    }

    private function sliderChallenge(): array
    {
        return $this->openPuzzle($this->challenge(level: 3));
    }
```

In `testSliderSolvedByKeysGetsAHarderProof`, `testMissedSliderSpendsTheTicket`, `testSliderWithoutPositionOrTrackIsRejected`, `testScriptedSlideIsRejectedLikeAMiss`, `testSlideAnsweredTooSoonIsRejected` and `testUnsealedReportIsRejected` replace `$this->challenge(level: 3)` with `$this->sliderChallenge()`.

Replace `testLevelThreeSliderMustHitTheGap` and `testOnlyInteractiveChallengesCarryAKey`:

```php
    public function testLevelThreeSliderMustHitTheGap(): void
    {
        $challenge = $this->sliderChallenge();
        $target = $this->store->find($challenge['cid'])->sliderTarget;

        $this->now += 3;
        $ready = $this->interact($challenge, level: 3, report: self::slide($target + 3));
        self::assertSame(200, $ready['pow']['max'], 'a drag keeps the difficulty');

        self::assertSame(200, $this->verify($challenge['cid'], self::solve($ready['pow']), level: 3));
        self::assertSame(3, $this->session->get('grant'));
    }

    public function testOnlyInteractiveChallengesCarryAKey(): void
    {
        self::assertArrayNotHasKey('key', $this->challenge(level: 1));
        self::assertArrayNotHasKey('key', $this->challenge(level: 3), 'the slider key comes with its puzzle');
        self::assertSame(32, \strlen(\base64_decode($this->sliderChallenge()['key'], true)));

        $ready = $this->interact($this->challenge(level: 2), level: 2);
        self::assertArrayNotHasKey('key', $ready, 'the proof answer does not echo the key');
    }
```

Add the gate tests:

```php
    public function testLevelThreeStartsAtTheGate(): void
    {
        $challenge = $this->challenge(level: 3);

        self::assertSame('slider', $challenge['int']);
        self::assertSame(200, $challenge['gate']['max'], 'the gate is at base difficulty');
        self::assertSame($challenge['exp'], $challenge['gate']['exp']);
        self::assertArrayNotHasKey('puzzle', $challenge);
        self::assertArrayNotHasKey('pow', $challenge);
        self::assertNull($this->store->find($challenge['cid'])->sliderTarget, 'nothing is rendered yet');
    }

    public function testCrawlersPayTheGateAtBaseDifficulty(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)';

        $challenge = $this->challenge(level: 3);

        self::assertSame('slider', $challenge['int']);
        self::assertSame(200, $challenge['gate']['max'], 'the crawler factor applies to the final proof only');
        self::assertSame(15.0, $this->store->find($challenge['cid'])->difficulty);
    }

    public function testTheGateOpensThePuzzle(): void
    {
        $challenge = $this->challenge(level: 3);
        $this->now += 2;
        $opened = $this->openPuzzle($challenge);
        $ticket = $this->store->find($opened['cid']);

        self::assertArrayHasKey('puzzle', $opened);
        self::assertSame(32, \strlen(\base64_decode($opened['key'], true)));
        self::assertIsInt($ticket->sliderTarget);
        self::assertSame($this->now, $ticket->puzzleAt);
        self::assertSame((int) \floor($this->now) + ChallengeTicket::TTL, $opened['exp'], 'the slider gets a phase of its own');
        self::assertStringNotContainsString('"' . $ticket->sliderTarget . '"', \json_encode($opened), 'the target is not in the answer');
    }

    public function testAProofForAnotherBindingDoesNotOpenThePuzzle(): void
    {
        $challenge = $this->challenge(level: 3);
        $foreign = (new ProofOfWork($this->config))->create(self::FP, $challenge['cid'] . '|slider|3', $challenge['exp']);

        $response = $this->puzzle($challenge['cid'], self::solve($foreign));

        self::assertSame(401, $response->getStatus());
        self::assertNull($this->store->find($challenge['cid']), 'the ticket is spent');
        self::assertSame([], $this->budgetStore->individual, 'nothing was rendered or counted');
    }

    public function testTheGateSolutionDoesNotVerify(): void
    {
        $challenge = $this->challenge(level: 3);

        $this->now += 1.5;
        $this->assertRejected(400, fn() => $this->verify($challenge['cid'], self::solve($challenge['gate']), level: 3));
    }

    public function testOnlyAGateTicketOpensAPuzzle(): void
    {
        $click = $this->challenge(level: 2);
        $pow = (new ProofOfWork($this->config))->create(self::FP, $click['cid'] . '|gate|2', $click['exp']);
        $this->assertRejected(400, fn() => $this->puzzle($click['cid'], self::solve($pow), level: 2));

        $opened = $this->sliderChallenge();
        $pow = (new ProofOfWork($this->config))->create(self::FP, $opened['cid'] . '|gate|3', $opened['exp']);
        $this->assertRejected(400, fn() => $this->puzzle($opened['cid'], self::solve($pow)), 'no second puzzle for one ticket');
    }

    public function testSlidingBeforeThePuzzleIsRejected(): void
    {
        $this->assertRejected(400, fn() => $this->interact($this->challenge(level: 3), level: 3));
    }

    public function testTimeAtTheGateDoesNotCountAsLookingAtThePicture(): void
    {
        $challenge = $this->challenge(level: 3);
        $this->now += 5;
        $opened = $this->openPuzzle($challenge);
        $target = $this->store->find($opened['cid'])->sliderTarget;

        $this->now += 0.5;
        $this->assertRejected(403, fn() => $this->interact($opened, level: 3, report: self::slide($target)));
    }

    public function testTheClientBudgetRefusesFurtherPuzzles(): void
    {
        $this->rate = new RateLimitConfig(gcProbability: 0, sliderIpLimit: 2, sliderIpWindowSec: 600);
        $this->sliderChallenge();
        $this->sliderChallenge();

        $challenge = $this->challenge(level: 3);
        $response = $this->puzzle($challenge['cid'], self::solve($challenge['gate']));

        self::assertSame(429, $response->getStatus());
        self::assertSame('600', $response->getHeader('Retry-After'));
        self::assertSame(['ok' => false, 'error' => 'Too Many Requests', 'code' => 429], \json_decode((string) $response->getBody(), true));
        self::assertNull($this->store->find($challenge['cid']), 'the ticket is spent');
    }
```

- [ ] **Step 2: Run the flow test to see it fail**

Run: `ddev exec vendor/bin/phpunit tests/Action/TicketFlowTest.php`
Expected: errors — class `PuzzleAction` not found; `gate` missing from the level-3 answer.

- [ ] **Step 3: Issue slider tickets at the gate**

`src/Action/ChallengeAction.php`:

Remove the `SliderPuzzle` import. Class docblock, replace the second paragraph:

```php
 * The answer names the required interaction. Only when none is required
 * does it carry the proof of work right away; otherwise the client gets it
 * from InteractAction once the interaction is done. A click ticket carries
 * the key the client seals its report with. A slider ticket starts at the
 * gate: the answer carries a proof of work at base difficulty, which
 * PuzzleAction takes in exchange for the puzzle and its key, so a bare
 * request never costs the server a picture.
```

In `handle()` delete the `$puzzle = …` line, change the key argument and the answer:

```php
            // The slider's key comes with its puzzle, see PuzzleAction.
            key: $interaction === Interaction::Click ? InteractionCipher::newKey() : null,
        );

        $this->tickets->save($ticket);
        $this->tickets->maybeCollectGarbage();

        $data = $this->tickets->describe($ticket);

        if ($ticket->isReadyForProof()) {
            $data['pow'] = $this->tickets->proofOfWork($ticket, $ticket->binding(), $ticket->difficulty);
        }

        if ($ticket->awaitsPuzzle()) {
            // Base difficulty for everyone: the gate pays for the picture, the final proof for the grant.
            $data['gate'] = $this->tickets->proofOfWork($ticket, $ticket->gateBinding(), 1.0);
        }

        if ($ticket->key !== null) {
            $data['key'] = \base64_encode($ticket->key);
        }

        return new JsonResponse(200, $data);
```

(The `sliderTarget:` argument is removed from the constructor call.)

- [ ] **Step 4: Create `PuzzleAction`**

`src/Action/PuzzleAction.php`:

```php
<?php declare(strict_types=1);

namespace GES\Botlock\Action;

use GES\Botlock\Challenge\InteractionCipher;
use GES\Botlock\Challenge\ProofOfWork;
use GES\Botlock\Challenge\PuzzleBudget;
use GES\Botlock\Challenge\PuzzleBudgetResult;
use GES\Botlock\Challenge\SliderPuzzle;
use GES\Botlock\Config\ProofOfWorkConfig;
use GES\Botlock\Exception\JsonResponseException;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Http\Response\JsonResponse;

/**
 * POST ?_botlock=puzzle — pays for a level-3 slider puzzle with the gate
 * proof of work of its ticket (body {"cid": …, "num": …, "sig": …, "slt": …,
 * "exp": …, "alg": …}) and answers with the puzzle images and the key the
 * client seals its slider report with.
 *
 * The ticket is consumed first and only stored again with its puzzle, so a
 * wrong gate solution (401) or a used-up budget (429 with Retry-After)
 * spends it. Each render counts against PuzzleBudget whatever the slider
 * later does, so every guess costs a gate proof of work and a render of
 * the client's budget. The ticket gets a fresh deadline for the slider, and
 * the slider is timed from the render.
 */
final readonly class PuzzleAction implements ActionHandlerInterface
{
    public function __construct(
        private ProofOfWorkConfig $config,
        private TicketService $tickets,
        private PuzzleBudget $budget,
    ) {}

    /**
     * @throws JsonResponseException
     */
    public function handle(Request $request): Response
    {
        SessionNonce::assertMatches($request);

        if (!$data = $request->getJsonBody()) {
            throw new JsonResponseException('Invalid data', 400);
        }

        $ticket = $this->tickets->redeem($data['cid'] ?? null, $request);

        if (!$ticket->awaitsPuzzle()) {
            throw new JsonResponseException('Invalid challenge', 400);
        }

        unset($data['cid'], $data['nonce']);

        if (!(new ProofOfWork($this->config))->verify($data, $ticket->subject, $ticket->gateBinding())) {
            return new JsonResponse(401, ['ok' => false]);
        }

        $context = $request->context;
        $result = $this->budget->reserve($context->clientIp, (string) $context->fingerprint);

        if ($result !== PuzzleBudgetResult::Granted) {
            return self::refusal($result, $this->budget->retryAfter($result));
        }

        $now = $this->tickets->now();
        $puzzle = SliderPuzzle::create();
        $ticket = $ticket->withPuzzle($puzzle->target, InteractionCipher::newKey(), $now)->renewed($now);

        $this->tickets->save($ticket);

        return new JsonResponse(200, $this->tickets->describe($ticket) + [
            'key' => \base64_encode($ticket->key),
            'puzzle' => $puzzle->toArray(),
        ]);
    }

    private static function refusal(PuzzleBudgetResult $result, int $retryAfter): JsonResponse
    {
        [$status, $error] = match ($result) {
            PuzzleBudgetResult::ClientExhausted => [429, 'Too Many Requests'],
        };

        return new JsonResponse(
            $status,
            ['ok' => false, 'error' => $error, 'code' => $status],
            ['Retry-After' => (string) $retryAfter],
        );
    }
}
```

- [ ] **Step 5: Reject sliding at the gate and time the slider from the render**

`src/Action/InteractAction.php`:

```php
        if (!$ticket->interaction->isInteractive() || $ticket->interacted || $ticket->awaitsPuzzle()) {
            throw new JsonResponseException('Invalid challenge', 400);
        }
```

and in the verdict `match`:

```php
            // From the render: time at the gate was spent before there was a picture.
            $ticket->interaction === Interaction::Slider => self::judgeSlider($ticket, $report, ($now - ($ticket->puzzleAt ?? $ticket->issuedAt)) * 1000),
```

Class docblock, first sentence: `POST ?_botlock=challenge — reports the completed interaction of a ticket (for the slider once PuzzleAction rendered its puzzle; body …`.

- [ ] **Step 6: Wire the budget and the action in `Kernel`**

`src/Kernel.php`:

Imports: `use GES\Botlock\Action\PuzzleAction;` and `use GES\Botlock\Challenge\PuzzleBudget;`.

`boot()`, after `$tickets = …`:

```php
            // A store of its own: its window and sweeps must not mix with the rate limiter's.
            $puzzleBudget = new PuzzleBudget(
                $rate,
                new FileThreatStateStore($kernelConfig->stateDir . \DIRECTORY_SEPARATOR . 'puzzles', $kernelConfig->instanceId),
                $kernelConfig->instanceId,
            );

            return new static($botlockRoot, $botDetect, $pow, $detection, $rate, $rateLimiter, $whitelist, $pageCache, $tickets, $puzzleBudget);
```

`passThrough()`: `return new static($botlockRoot, null, null, null, null, null, null, null, null, null, $reason);`

Constructor: add `private ?PuzzleBudget $puzzleBudget,` after `$tickets`.

`handleRequest()` guard: add `|| !$this->puzzleBudget` after `!$this->tickets`.

Action map, after `'POST challenge'`:

```php
                'POST puzzle' => new PuzzleAction($this->pow, $ticketService, $this->puzzleBudget),
```

- [ ] **Step 7: Run the whole suite**

Run: `ddev composer test`
Expected: PASS.

### Task 4: Challenge page pays the gate and shows the budget refusal

**Files:**
- Modify: `templates/challenge.php` (script block)
- Test: `tests/Middleware/ChallengeDocumentMiddlewareTest.php`

**Interfaces:**
- Consumes: the JSON contract from Task 3.
- Produces (plans 05 and 09 edit these): `class BlockedError`, `function retryAfter(response)`, `async function openPuzzle(challenge, nonce)`, `function showBlocked(seconds)`, `function relativeTime(seconds)`; `handleFailure(error, attempt)` keeps its signature here.

- [ ] **Step 1: Write the failing page test**

Append to `tests/Middleware/ChallengeDocumentMiddlewareTest.php`:

```php
    public function testSliderPuzzleIsPaidForWithTheGate(): void
    {
        $body = (string) $this->middleware()->process($this->request('en'), $this->failingNext())->getBody();

        self::assertStringContainsString('await solveChallenge(challenge.gate)', $body);
        self::assertStringContainsString("call('puzzle', 'POST', nonce", $body);
        self::assertStringContainsString('response.status === 429', $body);
        self::assertStringContainsString('throw new BlockedError(retryAfter(response))', $body);
        self::assertStringContainsString('trans.blockedRetry.replace(', $body);
    }
```

- [ ] **Step 2: Run it to see it fail**

Run: `ddev exec vendor/bin/phpunit --filter testSliderPuzzleIsPaidForWithTheGate tests/Middleware/ChallengeDocumentMiddlewareTest.php`
Expected: FAIL on the first assertion.

- [ ] **Step 3: Add the error class and the header helper**

In `templates/challenge.php`, after `class RetryError extends Error {}`:

```js
    // The client used up its slider puzzles for now; Retry-After says for how long.
    class BlockedError extends Error {
        constructor(seconds) {
            super('blocked');
            this.seconds = seconds;
        }
    }

    // Retry-After in whole seconds, or null when it is missing or not a number of seconds.
    function retryAfter(response) {
        const seconds = Number(response.headers.get('Retry-After'));
        return Number.isInteger(seconds) && seconds >= 1 ? seconds : null;
    }
```

In `call()`, after the 403 branch:

```js
        if (response.status === 429) {
            throw new BlockedError(retryAfter(response));
        }
```

- [ ] **Step 4: Pay the gate before the slider**

After `completeInteraction()`:

```js
    // Pays for the slider picture with the gate proof of work; the answer
    // carries the puzzle, the key and the slider's own deadline.
    async function openPuzzle(challenge, nonce) {
        const solution = await solveChallenge(challenge.gate);
        if (solution === null) {
            return null;
        }

        const response = await call('puzzle', 'POST', nonce, { ...solution, cid: challenge.cid });
        return response.ok ? await response.json() : null;
    }
```

In `botlock()`, replace the `case 'slider':` branch:

```js
                case 'slider': {
                    const puzzle = await openPuzzle(challenge, nonce);
                    if (!puzzle) {
                        showError();
                        return;
                    }
                    showSlider({ ...challenge, ...puzzle }, nonce, attempt, retried);
                    return;
                }
```

- [ ] **Step 5: Show the refusal**

After `showError()`:

```js
    function relativeTime(seconds) {
        const lang = document.documentElement.lang;
        // Plain "sr" formats in Cyrillic; the Serbian strings are Latin.
        const format = new Intl.RelativeTimeFormat(lang === 'sr' ? 'sr-Latn' : lang);
        return seconds % 60 === 0 ? format.format(seconds / 60, 'minute') : format.format(seconds, 'second');
    }

    // Too many requests or slider puzzles: the same words as the 429 page, no retry.
    function showBlocked(seconds) {
        security.classList.add('status-fail');
        headingElement.textContent = trans.blockedHeading;
        infoElement.textContent = trans.blockedMessage;

        const note = document.createElement('p');
        note.textContent = seconds === null ? trans.blockedWait : trans.blockedRetry.replace('{time}', relativeTime(seconds));
        widgetElement.replaceChildren(note);

        footerElement.textContent = trans.blockedFooter;
    }
```

At the top of `handleFailure()`:

```js
        if (error instanceof BlockedError) {
            showBlocked(error.seconds);
            return;
        }
```

- [ ] **Step 6: Run the page tests**

Run: `ddev exec vendor/bin/phpunit tests/Middleware/ChallengeDocumentMiddlewareTest.php`
Expected: PASS.

### Task 5: Docs, demo schema, commit, DDEV checks

**Files:**
- Modify: `README.md`, `CLAUDE.md`, `demo/_lib/Settings.php`, `demo/_lib/Notices.php`

- [ ] **Step 1: README settings table**

In "Threat and rate-limit settings", after the `BOTLOCK_GC_PROBABILITY` row:

```markdown
| `BOTLOCK_SLIDER_IP_LIMIT` | `10` | Slider puzzles rendered per client IP within `BOTLOCK_SLIDER_IP_WINDOW_SEC`. Further puzzle requests from that IP are answered with `429` and the page shows how long to wait. Every render counts, solved or not, so blind guessing gets this many tries per window. Clients without a known IP are counted by fingerprint. Not scaled by `BOTLOCK_THRESHOLD_FACTOR`. `0` disables the budget. |
| `BOTLOCK_SLIDER_IP_WINDOW_SEC` | `600` | Window of the per-IP slider puzzle budget in seconds, also sent as its `Retry-After`. The effective minimum is `1`. |
```

- [ ] **Step 2: README flow and layout**

In the paragraph starting "Each challenge is a single-use ticket", after the sentence ending "`POST ?_botlock=challenge` hands it out once the interaction is reported.", insert:

```markdown
At level `3` the answer carries a proof of work at base difficulty instead of a
picture: `POST ?_botlock=puzzle` takes its solution, counts the render against
`BOTLOCK_SLIDER_IP_LIMIT` and only then draws the puzzle, so a bare request never
costs the server a picture, and the slider is timed from the moment it was drawn.
```

and replace "A missed slider spends it as well, so every guess costs a new challenge." with "A missed slider spends it as well, so every guess costs a new challenge, a gate proof of work and a puzzle from the client's budget."

Layout table: the `src/Action/` row becomes `` Handlers for the `?_botlock=<action>` endpoints: `challenge` (`GET` issues a ticket, `POST` reports the interaction), `puzzle` (pays the level-3 gate for the slider picture), `verify`, `reset` and `status`. They share `TicketService` for saving, redeeming and describing tickets. ``; in the `src/Challenge/` row insert `the per-IP budget of slider puzzle renders, ` before `the slider puzzle`.

- [ ] **Step 3: CLAUDE.md**

In the `src/Challenge/` parenthetical, before `the level-3 \`SliderPuzzle\``, insert `` the `PuzzleBudget` that counts slider puzzle renders per client IP (its store is a separate `FileThreatStateStore` under `<stateDir>/puzzles`), ``. In the "Testing Guidelines" section, after "For middleware changes, verify pass-through behavior plus relevant `_botlock` actions such as `challenge` (`GET` and `POST`)," insert "`puzzle`,".

- [ ] **Step 4: Demo schema and notice**

`demo/_lib/Settings.php`, after the `GC_PROBABILITY` line:

```php
            'SLIDER_IP_LIMIT' => ['group' => 'Rate limits', 'type' => self::TYPE_INT, 'label' => 'Slider puzzles per IP', 'default' => '10', 'help' => 'Per window; 429 beyond. 0 disables.'],
            'SLIDER_IP_WINDOW_SEC' => ['group' => 'Rate limits', 'type' => self::TYPE_INT, 'label' => 'Slider budget window (s)', 'default' => '600'],
```

`demo/_lib/Notices.php`: `'cleared' => 'Rate-limit state, slider puzzle budgets and cached challenge pages deleted.',`

- [ ] **Step 5: Full checks and commit**

```bash
ddev composer test
ddev composer validate --no-check-publish
ddev exec sh -c "find src tests templates translations demo -name '*.php' -print0 | xargs -0 -n1 php -l" | grep -v '^No syntax errors'
git add src/Challenge/ChallengeTicket.php src/Challenge/PuzzleBudget.php src/Challenge/PuzzleBudgetResult.php src/Config/RateLimitConfig.php src/Action/PuzzleAction.php src/Action/ChallengeAction.php src/Action/InteractAction.php src/Kernel.php templates/challenge.php tests/Challenge/ChallengeTicketTest.php tests/Challenge/PuzzleBudgetTest.php tests/Config/RateLimitConfigTest.php tests/Action/TicketFlowTest.php tests/Middleware/ChallengeDocumentMiddlewareTest.php README.md CLAUDE.md demo/_lib/Settings.php demo/_lib/Notices.php
git commit -m "pay for the slider puzzle with a gate and a per-ip budget"
```

- [ ] **Step 6: DDEV checks**

1. Back up `.demo/settings.json`; save `{"THREAT_LEVEL_OVERRIDE": "3", "SLIDER_IP_LIMIT": "2"}` as the settings; clear the state (`curl -s -X POST -d action=clear-state https://botlock.ddev.site/_demo.php`).
2. `curl -s -H 'Botlock-Nonce: 123e4567-e89b-42d3-a456-426614174000' 'https://botlock.ddev.site/protected/?_botlock=challenge'` — JSON has `gate`, no `puzzle`, no `key`.
3. In the browser (the local Linux one), open `https://botlock.ddev.site/protected/`: spinner, then the slider appears. Ask the user to solve it by hand (automation is rejected by design); it grants.
4. Reload with a fresh session twice more (clear the cookie): the third attempt shows "Too Many Requests" with "You can try again in 10 minutes." and no slider.
5. `ls .demo/state/puzzles/ua/*/` shows 64-hex `.lst` files, no IP in any name.
6. Restore `.demo/settings.json` and clear the state again.
