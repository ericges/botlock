# 01 · Shared ticket service (#9) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the three copied `now()` helpers and the static calls between the challenge actions with one `Action\TicketService`.

**Architecture:** A `final readonly` service in `src/Action/` owns the ticket store, the proof-of-work config, the garbage-collection probability and the clock. `ChallengeAction`, `InteractAction` and `VerifyAction` receive it instead of the store and a clock. Pure refactor: no behavior, status code or JSON field changes.

**Tech Stack:** PHP 8.2+, PHPUnit 11, DDEV (no host PHP — every command runs through `ddev`).

**Spec:** `docs/superpowers/specs/2026-09-28-review-findings-design.md`, section B1. This is commit 1 of 10; plans 02–10 build on its names.

## Global Constraints

- PHP 8.2 compatible; `declare(strict_types=1)` in new files; four-space indentation; typed properties and returns; trailing commas in multiline argument lists.
- No new dependencies; all state file-based in `BOTLOCK_STATE_DIR`.
- One focused commit per finding, short lowercase imperative summary, **no `Co-Authored-By` trailer**; never commit `vendor/`, `botlock.phar`, IDE files or runtime state.
- Before committing: `ddev composer test`, `ddev composer validate --no-check-publish`, and `ddev exec sh -c "find src tests templates translations demo -name '*.php' -print0 | xargs -0 -n1 php -l"` all pass.
- Status codes and messages stay exactly as today: 400 `Invalid challenge`, 409 `restart`, 503 `Challenge unavailable`.

## Review Focus

- A `redeem()` whose clock is read once per call: `InteractAction` computes its elapsed time from `now()` separately — with the test clock both calls return the same value; in production the microseconds between them are irrelevant. Nothing else may call `microtime()` directly.
- `Kernel::passThrough()` passes positional nulls: this plan does not change the `Kernel` constructor, only what `handleRequest()` builds.
- The garbage-collection call must keep its exact arguments (`now - TTL - 60`, `2 * gcProbability`); `testSweepBudgetOutpacesTheTicketsIssuedBetweenSweeps` pins them.

---

### Task 1: Introduce `TicketService` and move the actions onto it

**Files:**
- Create: `src/Action/TicketService.php`
- Modify: `src/Action/ChallengeAction.php`, `src/Action/InteractAction.php`, `src/Action/VerifyAction.php`, `src/Kernel.php:120-133`
- Test: `tests/Action/TicketFlowTest.php`

**Interfaces:**
- Produces (used by plans 02–05):
  - `TicketService::__construct(ChallengeTicketStore $tickets, ProofOfWorkConfig $config, int $gcProbability = 1000, ?\Closure $clock = null)`
  - `now(): float`
  - `save(ChallengeTicket $ticket): void` — throws `JsonResponseException('Challenge unavailable', 503)`
  - `redeem(mixed $id, Request $request): ChallengeTicket` — throws 400 `Invalid challenge` / 409 `restart`
  - `describe(ChallengeTicket $ticket): array` — keys `cid`, `lvl`, `int`, `exp`, `min_ms`
  - `proofOfWork(ChallengeTicket $ticket, string $binding, float $difficulty): array`
  - `maybeCollectGarbage(): void`
  - `ChallengeAction::__construct(BotTestManager $detective, InteractionPolicy $policy, TicketService $tickets)`
  - `InteractAction::__construct(TicketService $tickets, InteractionPolicy $policy)`
  - `VerifyAction::__construct(ProofOfWorkConfig $config, TicketService $tickets)`

- [ ] **Step 1: Point the flow test at the new constructors**

In `tests/Action/TicketFlowTest.php` add `use GES\Botlock\Action\TicketService;` and replace the three action factories and the direct construction in `testUnsealedReportIsRejected`:

```php
    public function testUnsealedReportIsRejected(): void
    {
        $challenge = $this->challenge(level: 3);
        $target = $this->store->tickets[$challenge['cid']]->sliderTarget;
        $action = new InteractAction($this->service(), new InteractionPolicy($this->config));
        $request = $this->request('POST', level: 3, body: ['cid' => $challenge['cid'], 'pos' => $target]);

        $this->assertRejected(403, fn() => $action->handle($request));
        self::assertSame([], $this->store->tickets, 'the ticket is spent');
    }
```

```php
    private function interact(array $challenge, int $level, array $report = []): array
    {
        $action = new InteractAction($this->service(), new InteractionPolicy($this->config));
        $body = isset($challenge['key'])
            ? Reports::seal(\base64_decode($challenge['key']), $challenge['cid'], $report)
            : ['cid' => $challenge['cid']];

        return self::json($action->handle($this->request('POST', $level, body: $body)));
    }

    private function verify(string $cid, array $solution, int $level, string $fingerprint = self::FP): int
    {
        $action = new VerifyAction($this->config, $this->service());

        return $action->handle($this->request('POST', $level, body: $solution + ['cid' => $cid], fingerprint: $fingerprint))->getStatus();
    }

    private function challengeAction(int $gcProbability = 0): ChallengeAction
    {
        return new ChallengeAction(
            new BotTestManager(new DetectionConfig()),
            new InteractionPolicy($this->config),
            $this->service($gcProbability),
        );
    }

    private function service(int $gcProbability = 0): TicketService
    {
        return new TicketService($this->store, $this->config, $gcProbability, fn(): float => $this->now);
    }
```

- [ ] **Step 2: Run the flow test to see it fail**

Run: `ddev exec vendor/bin/phpunit tests/Action/TicketFlowTest.php`
Expected: errors `Class "GES\Botlock\Action\TicketService" not found`.

- [ ] **Step 3: Create the service**

`src/Action/TicketService.php`:

```php
<?php declare(strict_types=1);

namespace GES\Botlock\Action;

use GES\Botlock\Challenge\ChallengeTicket;
use GES\Botlock\Challenge\ChallengeTicketStore;
use GES\Botlock\Challenge\ProofOfWork;
use GES\Botlock\Config\ProofOfWorkConfig;
use GES\Botlock\Exception\JsonResponseException;
use GES\Botlock\Http\Request;

/**
 * What the challenge actions share about tickets: the clock, saving and
 * redeeming them, the fields the client may see and the proof of work
 * bound to them.
 */
final readonly class TicketService
{
    /**
     * @param int           $gcProbability one issued ticket in this many sweeps expired tickets; 0 disables
     * @param \Closure|null $clock         returns the current Unix time with microseconds; defaults to microtime(true)
     */
    public function __construct(
        private ChallengeTicketStore $tickets,
        private ProofOfWorkConfig $config,
        private int $gcProbability = 1000,
        private ?\Closure $clock = null,
    ) {}

    public function now(): float
    {
        return $this->clock ? ($this->clock)() : \microtime(true);
    }

    /**
     * @throws JsonResponseException 503 when the ticket could not be written
     */
    public function save(ChallengeTicket $ticket): void
    {
        if (!$this->tickets->save($ticket)) {
            throw new JsonResponseException('Challenge unavailable', 503);
        }
    }

    /**
     * Consumes the ticket and checks that it belongs to this client, has not
     * expired and still covers the current threat level.
     *
     * @throws JsonResponseException
     */
    public function redeem(mixed $id, Request $request): ChallengeTicket
    {
        if (!ChallengeTicket::isValidId($id)
            || !($ticket = $this->tickets->consume($id))
            || !\hash_equals($ticket->subject, (string) $request->context->fingerprint)
            || $ticket->isExpired($this->now()))
        {
            throw new JsonResponseException('Invalid challenge', 400);
        }

        if (\max(1, $request->context->threatLevel ?? 1) > $ticket->level) {
            throw new JsonResponseException('restart', 409);
        }

        return $ticket;
    }

    /**
     * Public ticket fields: never the slider target, and not the key, which
     * only the answer that hands out the interaction carries.
     */
    public function describe(ChallengeTicket $ticket): array
    {
        return [
            'cid' => $ticket->id,
            'lvl' => $ticket->level,
            'int' => $ticket->interaction->value,
            'exp' => $ticket->expiresAt(),
            'min_ms' => $this->config->minSolveMs,
        ];
    }

    /**
     * Proof of work bound to the ticket: its signature covers the subject
     * and $binding, and it expires with the ticket.
     */
    public function proofOfWork(ChallengeTicket $ticket, string $binding, float $difficulty): array
    {
        return (new ProofOfWork($this->config))
            ->setDifficulty($difficulty)
            ->create($ticket->subject, $binding, $ticket->expiresAt());
    }

    /**
     * Sweeps expired tickets on roughly one call in gcProbability.
     */
    public function maybeCollectGarbage(): void
    {
        if ($this->gcProbability > 0 && \random_int(1, $this->gcProbability) === 1) {
            // A minute of slack past the lifetime. Every call issues one
            // ticket, so a budget of twice the sweep interval outpaces them.
            $this->tickets->collectGarbage($this->now() - ChallengeTicket::TTL - 60, 2 * $this->gcProbability);
        }
    }
}
```

- [ ] **Step 4: Move `ChallengeAction` onto the service**

Replace the constructor, `handle()` body and helpers of `src/Action/ChallengeAction.php` (keep the class docblock; `describe()`, `proofOfWork()`, `now()` and `maybeCollectGarbage()` are deleted). Remove the now unused `ChallengeTicketStore`, `ProofOfWork` and `ProofOfWorkConfig` imports:

```php
final readonly class ChallengeAction implements ActionHandlerInterface
{
    public function __construct(
        private BotTestManager $detective,
        private InteractionPolicy $policy,
        private TicketService $tickets,
    ) {}

    /**
     * @throws JsonResponseException
     */
    public function handle(Request $request): Response
    {
        SessionNonce::remember($request);

        $isCrawler = $this->detective->isCrawler();
        $isTrustedGoodBot = $isCrawler && $this->detective->isTrustedGoodBot($request->context);

        // Level 4 is refused before any action runs; a challenge is always for 1–3.
        $level = \min(3, \max(1, $request->context->threatLevel ?? 1));
        $interaction = $this->policy->interaction($level, $isCrawler, $isTrustedGoodBot);
        $puzzle = $interaction === Interaction::Slider ? SliderPuzzle::create() : null;
        $now = $this->tickets->now();

        $ticket = new ChallengeTicket(
            id: ChallengeTicket::newId($now),
            subject: $request->context->fingerprint,
            level: $level,
            interaction: $interaction,
            issuedAt: $now,
            difficulty: $this->policy->difficulty($isCrawler, $isTrustedGoodBot),
            sliderTarget: $puzzle?->target,
            key: $interaction->isInteractive() ? InteractionCipher::newKey() : null,
        );

        $this->tickets->save($ticket);
        $this->tickets->maybeCollectGarbage();

        $data = $this->tickets->describe($ticket);

        if ($ticket->isReadyForProof()) {
            $data['pow'] = $this->tickets->proofOfWork($ticket, $ticket->binding(), $ticket->difficulty);
        }

        if ($ticket->key !== null) {
            $data['key'] = \base64_encode($ticket->key);
        }

        if ($puzzle) {
            $data['puzzle'] = $puzzle->toArray();
        }

        return new JsonResponse(200, $data);
    }
}
```

- [ ] **Step 5: Move `InteractAction` onto the service**

In `src/Action/InteractAction.php` drop the `ChallengeTicketStore` and `ProofOfWorkConfig` imports, and replace constructor, `handle()`, `redeem()` and `now()` (keep `judgeSlider()` unchanged):

```php
    public function __construct(
        private TicketService $tickets,
        private InteractionPolicy $policy,
    ) {}

    /**
     * @throws JsonResponseException
     */
    public function handle(Request $request): Response
    {
        SessionNonce::assertMatches($request);

        $data = $request->getJsonBody() ?? [];
        $ticket = $this->tickets->redeem($data['cid'] ?? null, $request);
        $now = $this->tickets->now();

        if (!$ticket->interaction->isInteractive() || $ticket->interacted) {
            throw new JsonResponseException('Invalid challenge', 400);
        }

        $report = $ticket->key === null ? null : InteractionCipher::open($ticket->key, $ticket->id, $data['iv'] ?? null, $data['ct'] ?? null);

        $verdict = match (true) {
            $report === null => SliderVerdict::Rejected,
            $ticket->interaction === Interaction::Slider => self::judgeSlider($ticket, $report, ($now - $ticket->issuedAt) * 1000),
            default => null,
        };

        if ($verdict === SliderVerdict::Rejected) {
            throw new JsonResponseException('retry', 403);
        }

        $ticket = $ticket->withInteracted(
            $verdict === SliderVerdict::Assisted ? $ticket->difficulty * $this->policy->assistedFactor() : null,
        );

        $this->tickets->save($ticket);

        return new JsonResponse(200, $this->tickets->describe($ticket) + [
            'pow' => $this->tickets->proofOfWork($ticket, $ticket->binding(), $ticket->difficulty),
        ]);
    }
```

- [ ] **Step 6: Move `VerifyAction` onto the service**

In `src/Action/VerifyAction.php` replace the `ChallengeTicketStore` import with nothing (the service is in the same namespace), then:

```php
    public function __construct(
        private ProofOfWorkConfig $config,
        private TicketService $tickets,
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
        $now = $this->tickets->now();

        if (!$ticket->isReadyForProof()) {
            throw new JsonResponseException('Interaction required', 400);
        }

        if (($now - $ticket->issuedAt) * 1000 < $this->config->minSolveMs) {
            throw new JsonResponseException('Too fast', 400);
        }

        unset($data['cid'], $data['nonce']);

        $statusCode = 401;
        $session = $request->context->session;

        if ($ok = (new ProofOfWork($this->config))->verify($data, $ticket->subject, $ticket->binding()))
        {
            $session->set('grant', $ticket->level);
            SessionNonce::forget($request);
            $session->commit();
            $statusCode = 200;
        }

        return new JsonResponse($statusCode, ['ok' => $ok]);
    }
```

Delete the private `now()` method.

- [ ] **Step 7: Wire the service in `Kernel`**

In `src/Kernel.php` add `use GES\Botlock\Action\TicketService;` and replace the action map lines:

```php
        $middleware = new MiddlewareDispatcher();
        $policy = new InteractionPolicy($this->pow);
        $ticketService = new TicketService($this->tickets, $this->pow, $this->rate->gcProbability);
```

```php
            ->add(new ActionMiddleware([
                'GET challenge' => new ChallengeAction($this->detective, $policy, $ticketService),
                'POST challenge' => new InteractAction($ticketService, $policy),
                'POST verify' => new VerifyAction($this->pow, $ticketService),
                'POST reset' => new ResetAction(),
                'GET status' => new StatusAction(),
            ]))
```

- [ ] **Step 8: Run the whole suite**

Run: `ddev composer test`
Expected: `OK (429 tests, …)` — same count as before, nothing else changed.

- [ ] **Step 9: Check that no static coupling is left**

Run: `grep -rn "ChallengeAction::\|InteractAction::\|function now" src/Action`
Expected: only `src/Action/TicketService.php: public function now(): float`.

- [ ] **Step 10: Name the service in the layout docs**

`README.md`, layout table row `src/Action/`: append ` They share \`TicketService\` for saving, redeeming and describing tickets.` to the cell text (before the closing `|`).

`CLAUDE.md`, first paragraph: replace `` `?_botlock=` action handlers in `src/Action/`, `` with `` `?_botlock=` action handlers in `src/Action/` (sharing `Action\TicketService` for ticket handling), ``.

- [ ] **Step 11: Syntax check, validate, commit**

```bash
ddev composer validate --no-check-publish
ddev exec sh -c "find src tests templates translations demo -name '*.php' -print0 | xargs -0 -n1 php -l" | grep -v '^No syntax errors'
git add src/Action/TicketService.php src/Action/ChallengeAction.php src/Action/InteractAction.php src/Action/VerifyAction.php src/Kernel.php tests/Action/TicketFlowTest.php README.md CLAUDE.md
git commit -m "share ticket handling between the challenge actions"
```

- [ ] **Step 12: DDEV smoke test**

With the demo on its default sandbox settings, open `https://botlock.ddev.site/protected/` and reload until the challenge page appears at level 1; it must solve itself and grant. `curl -s 'https://botlock.ddev.site/protected/?_botlock=status'` must answer 200 JSON.
