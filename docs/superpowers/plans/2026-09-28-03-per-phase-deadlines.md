# 03 · Per-phase deadlines (#5) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Time spent on an interaction no longer eats into the proof-of-work window; every phase of a ticket gets a full `TTL`.

**Architecture:** `ChallengeTicket` stores an explicit deadline (`expiresAt`, array key `exp`) instead of deriving it from `issuedAt`. `renewed($now)` moves it to `now + TTL`, capped at `issuedAt + MAX_LIFETIME` (`3 × TTL`, one per phase: gate, interaction, proof). `InteractAction` renews when it accepts the interaction; plan 04 also renews when the puzzle is rendered. The proof of work signs the deadline as it stands when the proof is issued. Ticket sweeps cut off at `now - MAX_LIFETIME - 60`.

**Tech Stack:** PHP 8.2+, PHPUnit 11, DDEV.

**Spec:** `docs/superpowers/specs/2026-09-28-review-findings-design.md`, section B3. Commit 3 of 10; requires plans 01–02.

Deviation from the spec's wording: the spec says "`expiresAt()` returns it". The deadline becomes the public readonly property `$expiresAt` and the method is removed, so there are not two members of the same name; every caller reads the property.

## Global Constraints

- PHP 8.2 compatible; `declare(strict_types=1)` in new files; four-space indentation; typed properties and returns; trailing commas in multiline argument lists.
- No new dependencies; all state file-based in `BOTLOCK_STATE_DIR`.
- One focused commit per finding, short lowercase imperative summary, **no `Co-Authored-By` trailer**.
- Before committing: `ddev composer test`, `ddev composer validate --no-check-publish`, full PHP syntax check.

## Review Focus

- A ticket that is already expired must not be revived by renewal: `redeem()` checks expiry before any action calls `renewed()`. Pinned by the existing `testExpiredTicketIsRejected` (no renewal happens on that path).
- Repeated renewals must never go past `issuedAt + MAX_LIFETIME`: pinned in `ChallengeTicketTest`.
- Sweeps must not delete a renewed ticket that is still valid, even though it sits in an old issue-minute directory: pinned by `testSweepKeepsARenewedTicket`.
- Stored JSON without `exp` (a ticket written before this commit) is refused rather than given a guessed deadline.

---

### Task 1: Explicit deadline on the ticket

**Files:**
- Modify: `src/Challenge/ChallengeTicket.php`
- Test: `tests/Challenge/ChallengeTicketTest.php`, `tests/Challenge/FileChallengeTicketStoreTest.php` (ticket factory only)

**Interfaces:**
- Produces:
  - `ChallengeTicket::MAX_LIFETIME = 3 * TTL`
  - constructor parameter `int $expiresAt` (required, right after `issuedAt`), public readonly property `$expiresAt`
  - `renewed(float $now): self`
  - private `with(?int $expiresAt = null, ?float $difficulty = null, ?bool $interacted = null): self` — plan 04 adds `?int $sliderTarget` and `?string $key`
  - `expiresAt()` method removed

- [ ] **Step 1: Write the failing ticket tests**

Append to `tests/Challenge/ChallengeTicketTest.php` (add `use GES\Botlock\Challenge\Interaction;`):

```php
    public function testRenewedGivesAFreshPhaseUpToTheLifetime(): void
    {
        $ticket = self::ticket(issuedAt: 1_800_000_000.7);
        self::assertSame(1_800_000_300, $ticket->expiresAt);

        $renewed = $ticket->renewed(1_800_000_250.2);
        self::assertSame(1_800_000_550, $renewed->expiresAt);
        self::assertSame(1_800_000_300, $ticket->expiresAt, 'the original stays as it was');

        self::assertSame(1_800_000_900, $renewed->renewed(1_800_000_800.0)->expiresAt, 'capped at the lifetime');
    }

    public function testExpiryIsTheStoredDeadline(): void
    {
        $ticket = self::ticket(issuedAt: 1_800_000_000.0)->renewed(1_800_000_200.0);

        self::assertFalse($ticket->isExpired(1_800_000_500.0));
        self::assertTrue($ticket->isExpired(1_800_000_500.5));
    }

    public function testDeadlineSurvivesTheRoundTrip(): void
    {
        $ticket = self::ticket(issuedAt: 1_800_000_000.0)->renewed(1_800_000_100.0);
        self::assertEquals($ticket, ChallengeTicket::fromArray($ticket->toArray()));

        $data = $ticket->toArray();
        unset($data['exp']);
        self::assertNull(ChallengeTicket::fromArray($data), 'a ticket without a deadline is refused');
    }

    private static function ticket(float $issuedAt): ChallengeTicket
    {
        return new ChallengeTicket(
            id: ChallengeTicket::newId($issuedAt),
            subject: 'fp',
            level: 2,
            interaction: Interaction::Click,
            issuedAt: $issuedAt,
            expiresAt: (int) \floor($issuedAt) + ChallengeTicket::TTL,
        );
    }
```

In `tests/Challenge/FileChallengeTicketStoreTest.php`, `ticket()` factory, add after `issuedAt: $issuedAt,`:

```php
            expiresAt: (int) \floor($issuedAt) + ChallengeTicket::TTL,
```

- [ ] **Step 2: Run them to see them fail**

Run: `ddev exec vendor/bin/phpunit tests/Challenge`
Expected: errors `Unknown named parameter $expiresAt`.

- [ ] **Step 3: Implement the deadline**

In `src/Challenge/ChallengeTicket.php`:

Constants:

```php
    /** Lifetime of one phase in seconds: the gate, the interaction or the proof. */
    public const TTL = 300;

    /** Longest a ticket lives across all of its phases. */
    public const MAX_LIFETIME = 3 * self::TTL;
```

Constructor docblock gains `@param int $expiresAt Unix time the current phase ends at, see renewed()`; the constructor becomes:

```php
    public function __construct(
        public string      $id,
        public string      $subject,
        public int         $level,
        public Interaction $interaction,
        public float       $issuedAt,
        public int         $expiresAt,
        public float       $difficulty = 1.0,
        public ?int        $sliderTarget = null,
        public bool        $interacted = false,
        public ?string     $key = null,
    ) {}
```

Replace `expiresAt()`, `isExpired()` and `withInteracted()` with:

```php
    public function isExpired(float $now): bool
    {
        return $now > $this->expiresAt;
    }

    /**
     * The ticket with a fresh phase: its deadline moves to TTL from $now,
     * but never past MAX_LIFETIME after issuing.
     */
    public function renewed(float $now): self
    {
        return $this->with(expiresAt: \min((int) \floor($now) + self::TTL, (int) \floor($this->issuedAt) + self::MAX_LIFETIME));
    }

    /**
     * @param float|null $difficulty proof-of-work difficulty from now on; null keeps it
     */
    public function withInteracted(?float $difficulty = null): self
    {
        return $this->with(difficulty: $difficulty, interacted: true);
    }
```

Add at the end of the class:

```php
    private function with(?int $expiresAt = null, ?float $difficulty = null, ?bool $interacted = null): self
    {
        return new self(
            id: $this->id,
            subject: $this->subject,
            level: $this->level,
            interaction: $this->interaction,
            issuedAt: $this->issuedAt,
            expiresAt: $expiresAt ?? $this->expiresAt,
            difficulty: $difficulty ?? $this->difficulty,
            sliderTarget: $this->sliderTarget,
            interacted: $interacted ?? $this->interacted,
            key: $this->key,
        );
    }
```

`toArray()`: add `'exp' => $this->expiresAt,` after `'iat'`.

`fromArray()`: add `|| !\is_int($data['exp'] ?? null)` to the guard after the `iat` check, and construct with named arguments:

```php
        return new self(
            id: $data['id'],
            subject: $data['sub'],
            level: $data['lvl'],
            interaction: $interaction,
            issuedAt: (float) $data['iat'],
            expiresAt: $data['exp'],
            difficulty: (float) $data['dif'],
            sliderTarget: \is_int($data['tgt'] ?? null) ? $data['tgt'] : null,
            interacted: ($data['done'] ?? false) === true,
            key: \is_string($data['key'] ?? null) && \is_string($key = \base64_decode($data['key'], true)) ? $key : null,
        );
```

Class docblock: append `Each phase (the gate, the interaction, the proof) has TTL seconds; the ticket lives MAX_LIFETIME at most.`

- [ ] **Step 4: Run the challenge tests**

Run: `ddev exec vendor/bin/phpunit tests/Challenge`
Expected: PASS.

### Task 2: Renew on interaction, sign the renewed deadline, sweep by lifetime

**Files:**
- Modify: `src/Action/ChallengeAction.php`, `src/Action/InteractAction.php`, `src/Action/TicketService.php`, `src/Challenge/FileChallengeTicketStore.php` (docblock), `README.md:211`
- Test: `tests/Action/TicketFlowTest.php`

**Interfaces:**
- Consumes: `ChallengeTicket::$expiresAt`, `renewed()`, `MAX_LIFETIME` from Task 1; `TicketService` from plan 01.

- [ ] **Step 1: Write the failing flow tests**

In `tests/Action/TicketFlowTest.php` change the sweep expectation and add two tests:

```php
    public function testSweepBudgetOutpacesTheTicketsIssuedBetweenSweeps(): void
    {
        $this->challenge(level: 1, gcProbability: 1);

        self::assertSame([[$this->now - ChallengeTicket::MAX_LIFETIME - 60, 2]], $this->store->gcCalls);
    }

    public function testASlowInteractionStillGetsAFullProofWindow(): void
    {
        $challenge = $this->challenge(level: 2);

        $this->now += ChallengeTicket::TTL - 10;
        $ready = $this->interact($challenge, level: 2);
        self::assertSame((int) \floor($this->now) + ChallengeTicket::TTL, $ready['exp']);
        self::assertSame($ready['exp'], $ready['pow']['exp'], 'the proof signs the renewed deadline');

        $this->now += 200;
        self::assertGreaterThan($challenge['exp'], $this->now, 'past the first deadline');
        self::assertSame(200, $this->verify($challenge['cid'], self::solve($ready['pow']), level: 2));
    }

    public function testSweepKeepsARenewedTicket(): void
    {
        $challenge = $this->challenge(level: 2);
        $this->now += 250;
        $ready = $this->interact($challenge, level: 2);

        $this->now += 200;
        $this->challenge(level: 1, gcProbability: 1);

        self::assertSame(200, $this->verify($challenge['cid'], self::solve($ready['pow']), level: 2));
    }
```

- [ ] **Step 2: Run the flow test to see it fail**

Run: `ddev exec vendor/bin/phpunit tests/Action/TicketFlowTest.php`
Expected: errors — `ChallengeAction` constructs a ticket without `expiresAt`, and `TicketService` calls the removed `expiresAt()`.

- [ ] **Step 3: Issue with a deadline**

`src/Action/ChallengeAction.php`, in the `new ChallengeTicket(` call add after `issuedAt: $now,`:

```php
            expiresAt: (int) \floor($now) + ChallengeTicket::TTL,
```

- [ ] **Step 4: Read the property in the service and sweep by lifetime**

`src/Action/TicketService.php`: in `describe()` `'exp' => $ticket->expiresAt,`; in `proofOfWork()` `->create($ticket->subject, $binding, $ticket->expiresAt);` and its docblock `and it expires with the ticket's current phase.`; in `maybeCollectGarbage()`:

```php
            // A minute of slack past the longest lifetime; renewed tickets
            // stay in their issue minute. Every call issues one ticket, so a
            // budget of twice the sweep interval outpaces them.
            $this->tickets->collectGarbage($this->now() - ChallengeTicket::MAX_LIFETIME - 60, 2 * $this->gcProbability);
```

- [ ] **Step 5: Renew when the interaction is accepted**

`src/Action/InteractAction.php`, replace the `withInteracted` block:

```php
        // The proof gets a phase of its own, however long the interaction took.
        $ticket = $ticket->withInteracted(
            $verdict === SliderVerdict::Assisted ? $ticket->difficulty * $this->policy->assistedFactor() : null,
        )->renewed($now);
```

Class docblock: after "The ticket is consumed and stored again as interacted" insert `with a fresh deadline for the proof`.

- [ ] **Step 6: Update the store docblock and README**

`src/Challenge/FileChallengeTicketStore.php`, class docblock: replace `A ticket lives five minutes, so cleanup deletes whole minutes that ended before the cutoff` with `A ticket lives fifteen minutes at most (ChallengeTicket::MAX_LIFETIME), so cleanup deletes whole minutes that ended before the cutoff`.

`README.md`, paragraph starting "Each challenge is a single-use ticket": replace `kept in \`BOTLOCK_STATE_DIR\` for five minutes.` with `kept in \`BOTLOCK_STATE_DIR\`; each of its steps (the interaction, the proof of work) has five minutes, so a slow interaction does not shorten the time left for the proof.`

- [ ] **Step 7: Run the whole suite**

Run: `ddev composer test`
Expected: PASS (436 tests).

- [ ] **Step 8: Syntax check, validate, commit**

```bash
ddev composer validate --no-check-publish
ddev exec sh -c "find src tests templates translations demo -name '*.php' -print0 | xargs -0 -n1 php -l" | grep -v '^No syntax errors'
git add src/Challenge/ChallengeTicket.php src/Challenge/FileChallengeTicketStore.php src/Action/ChallengeAction.php src/Action/InteractAction.php src/Action/TicketService.php README.md tests/Challenge/ChallengeTicketTest.php tests/Challenge/FileChallengeTicketStoreTest.php tests/Action/TicketFlowTest.php
git commit -m "give each ticket phase its own deadline"
```

- [ ] **Step 9: DDEV check**

Back up `.demo/settings.json`, set `"THREAT_LEVEL_OVERRIDE": "2"`, open `https://botlock.ddev.site/protected/`, wait a minute before clicking the button, and confirm the grant; then look at the newest ticket JSON under `.demo/state/tickets/` right after the click (before the proof finishes, or with the network throttled) — its `exp` is about 300 s after the click, not after issuing. Restore `settings.json`.
