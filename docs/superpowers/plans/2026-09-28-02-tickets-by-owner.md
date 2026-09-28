# 02 · Tickets addressed by owner and id (#7) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A client presenting another client's ticket id can no longer destroy that ticket.

**Architecture:** The store locates a ticket by `(subject, id)` instead of `id` alone: the file name becomes `sha256(subject \0 id)`. A request from another fingerprint computes a path that does not exist, so it finds and consumes nothing. No peek or restore step, so no race.

**Tech Stack:** PHP 8.2+, PHPUnit 11, DDEV.

**Spec:** `docs/superpowers/specs/2026-09-28-review-findings-design.md`, section B2. Commit 2 of 10; requires plan 01 (`Action\TicketService`).

## Global Constraints

- PHP 8.2 compatible; `declare(strict_types=1)` in new files; four-space indentation; typed properties and returns; trailing commas in multiline argument lists.
- No new dependencies; all state file-based in `BOTLOCK_STATE_DIR`.
- One focused commit per finding, short lowercase imperative summary, **no `Co-Authored-By` trailer**.
- Before committing: `ddev composer test`, `ddev composer validate --no-check-publish`, full PHP syntax check (`ddev exec sh -c "find src tests templates translations demo -name '*.php' -print0 | xargs -0 -n1 php -l"`).

## Review Focus

- A request without a fingerprint (`context->fingerprint === null`) must not find a ticket issued to the empty string: `redeem()` passes `(string) $fingerprint`, and tickets are only ever issued with a non-empty fingerprint, so `consume('', $id)` misses. Pinned in Task 1 Step 1 (`testRequestWithoutFingerprintFindsNothing`).
- The foreign-client check must leave the owner able to finish: pinned by the extended `testTicketOfAnotherClientIsRejected`.
- File names must not reveal the id or the subject: pinned by the updated file-name test.
- Tickets saved before this commit (old file names) are simply unreachable and swept later; the branch is unreleased, so no migration.

---

### Task 1: Key the stores by subject and id

**Files:**
- Modify: `src/Challenge/ChallengeTicketStore.php`, `src/Challenge/FileChallengeTicketStore.php`, `src/Action/TicketService.php` (`redeem()`), `tests/Support/InMemoryChallengeTicketStore.php`
- Test: `tests/Challenge/FileChallengeTicketStoreTest.php`, `tests/Action/TicketFlowTest.php`

**Interfaces:**
- Consumes: `TicketService::redeem(mixed $id, Request $request)` from plan 01.
- Produces:
  - `ChallengeTicketStore::consume(string $subject, string $id): ?ChallengeTicket`
  - `InMemoryChallengeTicketStore::find(string $id): ?ChallengeTicket` (test helper, does not consume); `$tickets` is now keyed by `subject . "\0" . id`.

- [ ] **Step 1: Write the failing store tests**

In `tests/Challenge/FileChallengeTicketStoreTest.php` change every `consume($x)` call to `consume('fp', $x)` (the helper issues tickets to subject `'fp'`), update the file-name expectation, and add two tests:

```php
    public function testTicketIsFiledUnderItsIssueMinuteWithoutRevealingTheId(): void
    {
        $ticket = self::ticket(issuedAt: 1_800_000_000.5);
        $this->store->save($ticket);

        [$file] = \glob($this->dir . '/tickets/*/*');

        self::assertSame(\sprintf('%s/tickets/inst_%08x/%s.json', $this->dir, 30_000_000, \hash('sha256', "fp\0" . $ticket->id)), $file);
        self::assertStringNotContainsString($ticket->id, $file);
        self::assertSame(0600, \fileperms($file) & 0777);
        self::assertSame(0700, \fileperms(\dirname($file)) & 0777);
    }

    public function testAnotherSubjectFindsNothingAndLeavesTheTicket(): void
    {
        $ticket = self::ticket();
        $this->store->save($ticket);

        self::assertNull($this->store->consume('other-fp', $ticket->id));
        self::assertCount(1, \glob($this->dir . '/tickets/*/*'), 'the owner\'s ticket is untouched');
        self::assertEquals($ticket, $this->store->consume('fp', $ticket->id));
    }

    public function testRequestWithoutFingerprintFindsNothing(): void
    {
        $ticket = self::ticket();
        $this->store->save($ticket);

        self::assertNull($this->store->consume('', $ticket->id));
        self::assertNotNull($this->store->consume('fp', $ticket->id));
    }
```

- [ ] **Step 2: Run them to see them fail**

Run: `ddev exec vendor/bin/phpunit tests/Challenge/FileChallengeTicketStoreTest.php`
Expected: FAIL — `consume()` receives two arguments, finds nothing for `'fp'` (the id lands in `$subject`), and the file name differs.

- [ ] **Step 3: Change the interface**

`src/Challenge/ChallengeTicketStore.php`, replace the `consume()` declaration:

```php
    /**
     * Removes and returns the ticket issued to $subject under $id. Of
     * several concurrent callers at most one receives it, which makes every
     * ticket single-use. Another subject finds nothing and removes nothing.
     */
    public function consume(string $subject, string $id): ?ChallengeTicket;
```

- [ ] **Step 4: Change the file store**

In `src/Challenge/FileChallengeTicketStore.php`:

Class docblock, after the first paragraph, add:

```php
 * The file name hashes the subject together with the id, so a client that
 * presents another client's ticket id looks for a file that does not exist
 * and cannot consume, and thereby destroy, that ticket.
```

`save()`: replace `$path = $this->file($ticket->id);` with

```php
        $path = $this->file($ticket->subject, $ticket->id);
```

`consume()`:

```php
    public function consume(string $subject, string $id): ?ChallengeTicket
    {
        if (!ChallengeTicket::isValidId($id)) {
            return null;
        }

        $path = $this->file($subject, $id);
        $claimed = $path . '.' . \bin2hex(\random_bytes(4)) . '.claimed';

        if (!@\rename($path, $claimed)) {
            return null;
        }

        $json = @\file_get_contents($claimed);
        @\unlink($claimed);

        if ($json === false) {
            return null;
        }

        $ticket = ChallengeTicket::fromArray(\json_decode($json, true));

        return $ticket?->id === $id && $ticket->subject === $subject ? $ticket : null;
    }
```

`file()`:

```php
    private function file(string $subject, string $id): string
    {
        return $this->bucket($id) . \DIRECTORY_SEPARATOR . \hash('sha256', $subject . "\0" . $id) . '.json';
    }
```

- [ ] **Step 5: Run the store tests**

Run: `ddev exec vendor/bin/phpunit tests/Challenge/FileChallengeTicketStoreTest.php`
Expected: PASS.

- [ ] **Step 6: Change the in-memory double**

`tests/Support/InMemoryChallengeTicketStore.php`:

```php
/**
 * Test double: keeps tickets in an array keyed like the file store, by
 * subject and id, and can refuse writes.
 */
final class InMemoryChallengeTicketStore implements ChallengeTicketStore
{
    /** @var array<string, ChallengeTicket> keyed by subject . "\0" . id */
    public array $tickets = [];

    /** @var list<array{float, int}> issuedBefore and maxEntries of each sweep */
    public array $gcCalls = [];

    public function __construct(public bool $failing = false) {}

    public function save(ChallengeTicket $ticket): bool
    {
        if ($this->failing) {
            return false;
        }

        $this->tickets[$ticket->subject . "\0" . $ticket->id] = $ticket;

        return true;
    }

    public function consume(string $subject, string $id): ?ChallengeTicket
    {
        $key = $subject . "\0" . $id;
        $ticket = $this->tickets[$key] ?? null;
        unset($this->tickets[$key]);

        return $ticket;
    }

    /**
     * The stored ticket with this id, whoever it was issued to; for
     * assertions only, it does not consume.
     */
    public function find(string $id): ?ChallengeTicket
    {
        foreach ($this->tickets as $ticket) {
            if ($ticket->id === $id) {
                return $ticket;
            }
        }

        return null;
    }
```

(`collectGarbage()` stays as it is.)

- [ ] **Step 7: Update the flow test and pin the owner case**

In `tests/Action/TicketFlowTest.php` replace every `$this->store->tickets[$challenge['cid']]` with `$this->store->find($challenge['cid'])` (six occurrences), then extend the foreign-client test:

```php
    public function testTicketOfAnotherClientIsRejected(): void
    {
        $challenge = $this->challenge(level: 1);
        $solution = self::solve($challenge['pow']);

        $this->now += 1.5;
        $this->assertRejected(400, fn() => $this->verify($challenge['cid'], $solution, level: 1, fingerprint: 'fingerprint-b'));
        self::assertNotNull($this->store->find($challenge['cid']), 'the foreign attempt leaves the ticket');
        self::assertSame(200, $this->verify($challenge['cid'], $solution, level: 1), 'the owner can still redeem it');
    }
```

- [ ] **Step 8: Run the flow test to see it fail**

Run: `ddev exec vendor/bin/phpunit tests/Action/TicketFlowTest.php`
Expected: FAIL — `TicketService::redeem()` still calls `consume($id)` with one argument (`ArgumentCountError`).

- [ ] **Step 9: Pass the subject in `redeem()`**

`src/Action/TicketService.php`, in `redeem()`:

```php
        $subject = (string) $request->context->fingerprint;

        if (!ChallengeTicket::isValidId($id)
            || !($ticket = $this->tickets->consume($subject, $id))
            || !\hash_equals($ticket->subject, $subject)
            || $ticket->isExpired($this->now()))
        {
            throw new JsonResponseException('Invalid challenge', 400);
        }
```

Update its docblock first line to: `Consumes the client's ticket and checks that it has not expired and still covers the current threat level. A ticket issued to another fingerprint is not found and stays untouched.`

- [ ] **Step 10: Run the whole suite**

Run: `ddev composer test`
Expected: PASS (431 tests: the two new store tests on top of 429).

- [ ] **Step 11: Syntax check, validate, commit**

```bash
ddev composer validate --no-check-publish
ddev exec sh -c "find src tests templates translations demo -name '*.php' -print0 | xargs -0 -n1 php -l" | grep -v '^No syntax errors'
git add src/Challenge/ChallengeTicketStore.php src/Challenge/FileChallengeTicketStore.php src/Action/TicketService.php tests/Support/InMemoryChallengeTicketStore.php tests/Challenge/FileChallengeTicketStoreTest.php tests/Action/TicketFlowTest.php
git commit -m "find challenge tickets by owner and id"
```

- [ ] **Step 12: DDEV check**

Solve a level-1 challenge at `https://botlock.ddev.site/protected/` in the browser; then check `ls .demo/state/tickets/*/` is empty after the grant (the ticket was found under its new name and consumed).
