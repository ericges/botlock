# Review findings on `threat-level-challenges` — design

Date: 2026-09-28
Branch: `threat-level-challenges` (PR into `main`)

## Goal

Resolve all ten findings of the high-effort code review of this branch before it is merged. Each finding is fixed in its own focused commit with tests; the suite, Composer validation and the PHP syntax check stay green after every commit.

Constraints: no new dependencies, all state file-based in `BOTLOCK_STATE_DIR`, PHP 8.2 compatible, repository conventions from `CLAUDE.md`. Every new `BOTLOCK_*` setting is documented in the README settings tables and added to the demo schema in `demo/_lib/Settings.php`.

Measured baseline (DDEV container, PHP 8.3): `SliderPuzzle::create()` costs about 90 ms CPU (65 ms with JIT) and its JSON is about 160 KB; PHP hashes about 4 M SHA-256 per second. Every `?_botlock=` request counts toward the client's individual rate.

## Findings

| # | Finding | Fix (section) |
|---|---------|---------------|
| 1 | Slider passable by blind guessing (±5 px over ~159 targets ≈ 7 %), misses are free | Puzzle gate and per-IP render budget (A) |
| 2 | Level-4 block runs after crawler DNS verification | Middleware order (C1) |
| 3 | Every level-3 `GET challenge` renders a puzzle (~90 ms CPU) | Puzzle gate and global render cap (A) |
| 4 | `finalDrag()` judges only the last stroke; a corrective drag demotes a real drag to Assisted | Main drag (C2) |
| 5 | Proof-of-work expiry equals the ticket's 300 s lifetime, interaction time included | Per-phase deadlines (B3) |
| 6 | 429 page re-requests its URL with `HEAD` to read `Retry-After` | Retry time rendered into the page (C3) |
| 7 | `redeem()` consumes a ticket before checking its owner | Tickets addressed by owner and id (B2) |
| 8 | Any 403 shows "slider missed", also after a click failure | Retried flag only after a slider miss (C4) |
| 9 | Duplicated `now()` helpers and static coupling between actions | Shared ticket service (B1) |
| 10 | `SliderPuzzle::accepts()` has a dead string branch | `accepts(int …)` (C5) |

## A. Level-3 flow: puzzle gate and render budgets (#1, #3)

### Protocol

1. `GET ?_botlock=challenge` at level 3 issues the ticket in a *gate* phase: interaction `slider`, no slider target, no key. The answer carries `gate`, a proof of work at base difficulty (factor 1 for every client, crawlers included) bound to `id|gate|level`. No puzzle is rendered.
2. New `POST ?_botlock=puzzle` (`Action\PuzzleAction`), body `{"cid": …, "num": …, "sig": …, "slt": …, "exp": …, "alg": …}`. In order:
   1. `SessionNonce::assertMatches()`;
   2. redeem the ticket (section B); it must be in the gate phase (`awaitsPuzzle()`), otherwise 400 `Invalid challenge`;
   3. verify the gate solution against `ticket->gateBinding()`; failure answers 401 `{"ok": false}` like `verify`;
   4. reserve a render slot from `PuzzleBudget`; refusal answers 429 or 503 (below);
   5. render the puzzle, store target and a new `InteractionCipher` key in the ticket, renew its deadline (B3), save it;
   6. answer `describe(ticket) + {"key": …, "puzzle": …}` — the same fields `GET challenge` carries for a slider today.
   The ticket is spent on every failure path (it was consumed in step 2 and only saved in step 5).
3. `POST ?_botlock=challenge` (sealed slider report) and `POST ?_botlock=verify` are unchanged.

Level 1–2 challenges and trusted good bots never enter the gate phase; their flow is unchanged.

### `Challenge\PuzzleBudget`

Counts puzzle renders. Constructed with the `RateLimitConfig`, a `ThreatStateStore` and an optional clock. `Kernel::boot()` gives it its own `FileThreatStateStore` rooted at `<stateDir>/puzzles`, so its individual files, global file and garbage-collection window never mix with the rate limiter's.

`reserve(?string $clientIp, string $fingerprint): PuzzleBudgetResult`

- **Per client**: key `sha256(instanceId . "\0" . ip)`, or `sha256(instanceId . "\0fp\0" . fingerprint)` when no client IP was determined. The raw IP is never written. Uses `countIndividual()` over `sliderIpWindowSec`; at or above `sliderIpLimit` the result is `ClientExhausted`; otherwise `recordIndividual()`.
- **Global**: `updateGlobal()` with a reducer holding `{"minute": <unix minute>, "count": n}`; a new minute resets the count. At or above `sliderGlobalLimit` the result is `GlobalExhausted` and nothing is incremented; `0` disables the cap. A failed global update (lock or I/O) is treated as exhausted: fail closed like the rate limiter.
- The per-client check runs first; the global slot is taken only for a request that passed it, and the per-client render is recorded only once the global slot was granted.
- Garbage collection: one `reserve()` in `gcProbability` calls `collectGarbage(now - sliderIpWindowSec)` on its store.
- `PuzzleBudgetResult` is an enum `Granted | ClientExhausted | GlobalExhausted`. `PuzzleBudget::retryAfter(result)` gives the seconds: the IP window for `ClientExhausted`, the seconds left in the current minute (at least 1) for `GlobalExhausted`.

`PuzzleAction` maps the result to a `JsonResponse` with the same body shape as `JsonResponseException::getData()` plus `Retry-After`:

| Result | Status | `error` |
|---|---|---|
| `ClientExhausted` | 429 | `Too Many Requests` |
| `GlobalExhausted` | 503 | `Busy` |

### Settings (in `RateLimitConfig`, not scaled by `BOTLOCK_THRESHOLD_FACTOR`)

| Variable | Default | Meaning |
|---|---|---|
| `BOTLOCK_SLIDER_IP_LIMIT` | `10` | Slider puzzles rendered per client IP within the window; further puzzle requests get 429. `0` disables the per-client budget. |
| `BOTLOCK_SLIDER_IP_WINDOW_SEC` | `600` | Window of the per-client budget, in seconds; also its `Retry-After`. Clamped to at least 1. |
| `BOTLOCK_SLIDER_GLOBAL_LIMIT` | `300` | Slider puzzles rendered per minute across all clients; further puzzle requests get 503 until the next minute. `0` disables the cap. |

Demo: added to the "Rate limits" group of `Settings::schema()`; the "clear rate-limit state" tool already deletes everything in the state directory but the secret, so it resets the budgets too — its notice text says so.

### Challenge page

- `botlock()`: for `int === 'slider'` the page shows the spinner, solves `challenge.gate` with the existing `solveChallenge()`, posts it to `?_botlock=puzzle` and calls `showSlider()` with the answer merged into the challenge (`key`, `puzzle`, `exp`).
- `call()` gains two outcomes: 429 throws a `BlockedError` carrying `Retry-After`; 503 throws a `BusyError` carrying it.
- `BlockedError`: the card shows `blockedHeading`, `blockedMessage` and `blockedRetry` with the time formatted like `blocked.php` does (`Intl.RelativeTimeFormat`, `sr` → `sr-Latn`), falling back to `blockedWait`. No retry.
- `BusyError`: the spinner stays, the page waits `Retry-After` seconds (capped at 60) and starts over with `botlock(attempt + 1)`, within `MAX_ATTEMPTS`.
- No new translation strings.

### Effect

A blind guesser gets at most 10 tries per IP per 10 minutes (≈ 0.7 expected grants), each costing a gate proof of work. A distributed flood renders at most 300 puzzles per minute (≈ 0.45 CPU core). Visitors at level 3 solve one extra base-difficulty proof of work before the picture.

## B. Ticket lifecycle (#5, #7, #9)

### B1. `Action\TicketService` (#9)

A `final readonly` class next to `SessionNonce`, in `src/Action/` because it throws `JsonResponseException` and reads the `Request`. Constructor: `ChallengeTicketStore $tickets`, `ProofOfWorkConfig $config`, `int $gcProbability = 1000`, `?\Closure $clock = null`.

| Method | Replaces |
|---|---|
| `now(): float` | the three private `now()` helpers |
| `save(ChallengeTicket): void` — 503 `Challenge unavailable` on a failed write | the inline save checks |
| `redeem(mixed $id, Request): ChallengeTicket` — 400 `Invalid challenge`, 409 `restart` | `InteractAction::redeem()` |
| `describe(ChallengeTicket): array` | `ChallengeAction::describe()` |
| `proofOfWork(ChallengeTicket, string $binding, float $difficulty): array` | `ChallengeAction::proofOfWork()` |
| `maybeCollectGarbage(): void` | `ChallengeAction::maybeCollectGarbage()` |

`ChallengeAction`, `PuzzleAction`, `InteractAction` and `VerifyAction` take the service instead of store and clock; `InteractionPolicy` stays separate. `Kernel` builds one service per request pipeline. `TicketFlowTest` builds the service with `InMemoryChallengeTicketStore` and its test clock.

### B2. Tickets addressed by owner and id (#7)

`ChallengeTicketStore::consume(string $subject, string $id): ?ChallengeTicket`. `FileChallengeTicketStore` names the file `sha256(subject . "\0" . id) . '.json'` in the issue-minute directory; `save()` derives it from `ticket->subject`. `InMemoryChallengeTicketStore` keys by `subject . "\0" . id`. A request from another fingerprint computes a path that does not exist: it gets 400 and the owner's ticket is untouched. `redeem()` keeps comparing `ticket->subject` with the fingerprint as defense in depth. Tickets written by the old naming are unreachable and removed by the normal sweep (the branch is unreleased).

### B3. Per-phase deadlines (#5)

- `ChallengeTicket` gains `public int $expiresAt` (array key `exp`), a required constructor parameter; `ChallengeAction` passes `floor(issuedAt) + TTL`. `expiresAt()` returns it; `isExpired()` compares against it.
- `MAX_LIFETIME = 3 * TTL` — one TTL per phase: gate, interaction, proof.
- `renewed(float $now): self` sets `expiresAt = min(floor(now) + TTL, floor(issuedAt) + MAX_LIFETIME)`. Called when the puzzle is rendered (A step 5) and when the interaction is accepted (`InteractAction`, together with `withInteracted()`).
- Every proof of work signs the ticket's `expiresAt` at the moment it is issued, so the gate proof and the final proof each get a full TTL; time spent on the slider no longer shortens the final proof.
- `fromArray()` rejects a ticket without an integer `exp`.
- Ticket garbage collection cuts off at `now - MAX_LIFETIME - 60`, so renewed tickets in an older issue-minute directory survive.

### B4. Phases and bindings

- `awaitsPuzzle()`: interaction `Slider` and `sliderTarget === null`.
- `withPuzzle(int $target, string $key): self` sets both.
- `gateBinding()`: `id|gate|level`; `binding()` stays `id|interaction|level`. A gate solution can therefore never pass `verify`, and a final solution never `puzzle`.
- `InteractAction` additionally rejects a ticket that still `awaitsPuzzle()` (400).

## C. Small fixes

### C1. Block before crawler verification (#2)

`Kernel` registers `ThreatBlockMiddleware` before `VerifyCrawlerMiddleware`. The block only reads `threatLevel`, and verification can raise it to 2 at most, so no decision changes; `GET status` still reaches the verifier and reports the same. A comment in `Kernel` records why the order matters. Verified through DDEV (pipeline assembly exits and is not unit-tested): a Googlebot User-Agent at `BOTLOCK_THREAT_LEVEL_OVERRIDE=4` gets the 429 without the DNS round-trip, compared by response time against the same request at level 3.

### C2. Judge the main drag (#4)

`SliderTrack::finalDrag()` becomes `mainDrag()`: the `p` entry with the largest `|v - from|` (the first on a tie). It must cover `DRAG_SHARE * pos` and pass `isHumanDrag()` for `Drag`; otherwise the track is judged as today (`Rejected` if it covers the share but fails `isHumanDrag()`, else the Assisted path). Entries after it are corrections, already checked by `stepsArePlausible()`.

### C3. Retry time rendered into the 429 page (#6)

- `RateLimitConfig::retryAfterSec(): int` — `max(1, individualRateWindowSec)`; `ThreatBlockMiddleware` uses it for the header.
- `LocalizedPage` gains `array $vars = []`: passed to the template and folded into the cache version (`versionOf(...)` combined with a hash of the JSON-encoded vars).
- `Kernel` builds the blocked page with `['retryAfter' => $rate->retryAfterSec()]`.
- `blocked.php` renders `data-retry-after` on `#retry-note` and formats the note from it; the `HEAD` fetch is removed. The README layout row for `templates/blocked.php` is updated.

### C4. "Missed" only after a slider miss (#8)

`handleFailure(error, attempt, interaction)` calls `botlock(attempt + 1, error instanceof RetryError && interaction === 'slider')`. `showConfirm()` passes `'click'`, `showSlider()` `'slider'`, the `botlock()` catch `null`.

### C5. `accepts(int $position, int $target)` (#10)

The string branch goes; `InteractAction::judgeSlider()` already checks `is_int`. The `SliderPuzzleTest` cases for digit strings are removed; `TicketFlowTest` keeps its "string pos is rejected" case.

## Testing

- `TicketFlowTest`: full gate → puzzle → slider → verify flow; gate solution rejected by `verify` and final solution by `puzzle`; `puzzle` on a non-gate ticket; a foreign fingerprint leaves the ticket redeemable by its owner; a slow slider (> TTL after issuing) still gets a full proof window; 429 and 503 from the budget with `Retry-After`.
- `PuzzleBudgetTest` (new): per-client limit and window rollover, IP-less fallback to the fingerprint, global limit and minute rollover, `0` disables each, failed global update fails closed, a refused global slot records no per-client render.
- `ChallengeTicketTest`: `exp` round trip, `renewed()` and its cap, `awaitsPuzzle()`/`withPuzzle()`, `gateBinding()`.
- `FileChallengeTicketStoreTest`: consume with the wrong subject returns null and leaves the file; garbage collection keeps a renewed ticket.
- `SliderTrackTest`: drag to 140 plus a 4-sample nudge to 147 → `Drag`; big drag plus a key correction → `Drag`; two half drags → Assisted path.
- `LocalizedPageTest`: different vars give different cache versions and reach the template. `ThreatBlockMiddlewareTest`: page and header carry the same retry time.
- `RateLimitConfigTest`: the three new settings, their defaults and clamping.
- DDEV: `GET`/`POST challenge`, `POST puzzle`, `verify`, `reset`, `status` via curl; level-3 slider solved by hand in a browser (automation is rejected by design); 429 after the eleventh puzzle from one IP; C1 and C4 as described above.
- Before each commit: `ddev composer test`, `ddev composer validate --no-check-publish`, the full PHP syntax check.

## Documentation

- README: the three settings in "Threat and rate-limit settings"; the `src/Action/` layout row lists `puzzle` and `TicketService`; `src/Challenge/` mentions `PuzzleBudget`; the `blocked.php` row no longer mentions `HEAD`.
- `CLAUDE.md`: the same layout additions, kept in sync with the README "Developers" section.
- `.gitattributes`: `/docs export-ignore`, so specs and plans stay out of Composer dist archives.

## Commit order

One commit per finding, each self-contained and green:

1. #9 shared ticket service
2. #7 tickets addressed by owner and id
3. #5 per-phase deadlines
4. #1 puzzle gate and per-IP render budget (the `puzzle` action, `PuzzleBudget` with the per-client limit, page flow, 429 handling)
5. #3 global render cap (the global limit, 503 handling)
6. #2 block before crawler verification
7. #4 judge the main drag
8. #6 retry time rendered into the 429 page
9. #8 retried flag only after a slider miss
10. #10 `accepts(int)`

## Out of scope

- Speeding up the page's proof-of-work solver (batching digests, a worker).
- Making the puzzle generator cheaper.
- Any change to level 1–2 challenges, grants or the rate limiter itself.
