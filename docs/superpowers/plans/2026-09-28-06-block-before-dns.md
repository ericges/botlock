# 06 · Block before crawler verification (#2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A level-4 request with a crawler User-Agent gets its `429` without the reverse and forward DNS lookups of crawler verification.

**Architecture:** Swap two lines in the `Kernel` pipeline so `ThreatBlockMiddleware` runs before `VerifyCrawlerMiddleware`. The block reads only `threatLevel`; verification can raise it to 2 at most, so no decision changes. `GET ?_botlock=status` is exempt from the block and still reaches the verifier, so its `crawler_verification` field is unchanged.

**Tech Stack:** PHP 8.2+, DDEV.

**Spec:** `docs/superpowers/specs/2026-09-28-review-findings-design.md`, section C1. Commit 6 of 10; independent of plans 01–05 but applied after them.

## Global Constraints

- PHP 8.2 compatible; four-space indentation; no unrelated reformatting.
- One focused commit per finding, short lowercase imperative summary, **no `Co-Authored-By` trailer**.
- Before committing: `ddev composer test`, `ddev composer validate --no-check-publish`, full PHP syntax check.

## Review Focus

- `GET ?_botlock=status` at level 4 for a crawler must still report `crawler_verification` (it is exempt from the block and continues to the verifier): checked in Step 5.
- A trusted good bot at level 4 is refused as before (`ThreatBlockMiddlewareTest::testTrustedGoodBotsAreRefusedToo` stays green — the block never looked at verification).
- The pipeline is assembled inside `Kernel::handleRequest()`, which exits, so the order has no unit test; the DDEV timing check in Steps 1 and 5 is the evidence, and the `Kernel` comment guards against a later swap back.

---

### Task 1: Move the block in front of the verifier

**Files:**
- Modify: `src/Kernel.php` (the `->add(...)` chain in `handleRequest()`), `src/Middleware/ThreatBlockMiddleware.php` (docblock)

- [ ] **Step 1: Measure the current level-4 time (baseline)**

Back up `.demo/settings.json`; save `{"THREAT_LEVEL_OVERRIDE": "4"}` and clear the state (`curl -s -X POST -d action=clear-state https://botlock.ddev.site/_demo.php`). Build a Googlebot identity from a real Google crawler address and time five requests through the relay:

```bash
id=$(printf '%s' '{"ua":"Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)","ip":"66.249.66.1","lang":"","headers":[]}' | base64 -w0 | tr '+/' '-_' | tr -d '=')
for i in 1 2 3 4 5; do curl -s -o /dev/null -w '%{http_code} %{time_total}\n' "https://botlock.ddev.site/_forge.php/$id/protected/"; done
```

Expected: `429` each time. Note the times; they include the DNS round-trips.

- [ ] **Step 2: Swap the two middlewares**

In `src/Kernel.php`:

```php
        $middleware
            ->add(new ErrorMiddleware)
            ->add(new WhoIsMiddleware($this->detection))
            ->add(new IgnoreListMiddleware($this->whitelist))
            ->add(new ThreatEvaluationMiddleware($this->rate, $this->rateLimiter))
            // Before crawler verification: a level-4 client is refused without
            // DNS lookups, and verification can never lift a level to 4.
            ->add(new ThreatBlockMiddleware($this->rate, $page('blocked')))
            ->add(new VerifyCrawlerMiddleware($this->detective, $this->detection))
            ->add(new ThreatPassMiddleware($this->detective))
```

(The `$page('blocked')` call keeps whatever arguments it has at this point.)

- [ ] **Step 3: Say it in the block's docblock**

`src/Middleware/ThreatBlockMiddleware.php`, class docblock, after "Retry-After is always set.":

```php
 * It runs before VerifyCrawlerMiddleware, so a blocked crawler costs no
 * DNS lookups; verification only ever raises a level to 2.
```

- [ ] **Step 4: Run the checks**

```bash
ddev composer test
ddev composer validate --no-check-publish
ddev exec sh -c "find src tests templates translations demo -name '*.php' -print0 | xargs -0 -n1 php -l" | grep -v '^No syntax errors'
```

Expected: all pass, test count unchanged.

- [ ] **Step 5: DDEV timing check**

1. Rerun the loop from Step 1 (same `$id`, override still 4): `429` each time, and the times drop by at least one DNS round-trip compared to the baseline. Opcache revalidates on the next request; if the first run still looks slow, run it again.
2. `curl -s "https://botlock.ddev.site/_forge.php/$id/protected/?_botlock=status"` still shows `"crawler_verification"` with a value (`verified` or `failed`), not `null`: status is exempt from the block and reaches the verifier.
3. Restore `settings.json`, clear the state.

- [ ] **Step 6: Commit**

```bash
git add src/Kernel.php src/Middleware/ThreatBlockMiddleware.php
git commit -m "refuse level 4 before verifying crawlers"
```
