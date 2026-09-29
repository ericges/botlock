<?php declare(strict_types=1);

namespace GES\Botlock\Challenge;

use GES\Botlock\Config\RateLimitConfig;
use GES\Botlock\Filesystem\Sweep;
use GES\Botlock\Threat\ThreatStateStore;

/**
 * Counts slider puzzle renders, the expensive part of a level-3 challenge
 * and the only way to get another guess. Each client IP (the fingerprint
 * when there is none) gets BOTLOCK_SLIDER_IP_LIMIT renders per
 * BOTLOCK_SLIDER_IP_WINDOW_SEC, solved or not; an IPv6 address is counted
 * by its /64 network, so rotating inside it does not reset the budget.
 * Clients are stored under a hash of the instance id and the IP (or
 * network), never the IP itself.
 *
 * All clients together get BOTLOCK_SLIDER_GLOBAL_LIMIT renders per
 * minute, counted in the store's global state, so a flood from many IPs,
 * each within its budget, still cannot render more than that.
 *
 * The store is a ThreatStateStore of its own (Kernel roots it at
 * <stateDir>/puzzles and opens it on the first puzzle), so its window and
 * sweeps do not mix with the rate limiter's. When it cannot be opened, read
 * or written the budget counts as used up (per client or globally,
 * whichever failed): a storage fault must not hand out uncounted guesses.
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
     * Takes one render from the client's budget and one from the current
     * minute's, or says why not. A client over its budget takes no global
     * slot, and a client refused a global slot keeps its render.
     *
     * Counts the client twice: once before recording, to refuse an
     * already-exhausted client cheaply, and once after recording, because a
     * concurrent request from the same client can record its own render
     * between the two and would otherwise also read the stale first count
     * and also be Granted. The second count is the one that must hold: it
     * sees every render recorded up to and including this one, so the k-th
     * request to record in a window is the first that can be refused by it,
     * and at most $limit requests are ever Granted. A refusal there keeps
     * the render already recorded and the global slot already taken, so it
     * may refuse at the edge of the limit but never grants past it.
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

        if ($limit > 0) {
            if (!$this->store->recordIndividual($client, $now, $windowStart)) {
                return PuzzleBudgetResult::ClientExhausted;
            }

            $recount = $this->store->countIndividual($client, $windowStart);

            if ($recount === null || $recount > $limit) {
                return PuzzleBudgetResult::ClientExhausted;
            }
        }

        return PuzzleBudgetResult::Granted;
    }

    /**
     * Seconds until a refused client may ask again; 0 for a granted render.
     * Takes the same client as reserve().
     */
    public function retryAfter(PuzzleBudgetResult $result, ?string $clientIp, string $fingerprint): int
    {
        return match ($result) {
            PuzzleBudgetResult::Granted => 0,
            PuzzleBudgetResult::ClientExhausted => $this->clientRetryAfter($this->clientKey($clientIp, $fingerprint)),
            PuzzleBudgetResult::GlobalExhausted => 60 - $this->now() % 60,
        };
    }

    /**
     * Seconds until enough of the client's renders have left the window for
     * one more: the window counts a render until a full window has passed
     * since it. The whole window when the renders cannot be read, or when
     * fewer than the limit are there (a failed write refused the client).
     */
    private function clientRetryAfter(string $client): int
    {
        $now = $this->now();
        $window = $this->window();
        $limit = $this->config->sliderIpLimit;
        $renders = $this->store->individualTimestamps($client, $now - $window);

        if ($renders === null || $limit <= 0 || \count($renders) < $limit) {
            return $window;
        }

        \sort($renders);

        return \max(1, $renders[\count($renders) - $limit] + $window + 1 - $now);
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

    private function clientKey(?string $clientIp, string $fingerprint): string
    {
        return \hash('sha256', $clientIp !== null && $clientIp !== ''
            ? $this->instanceId . "\0" . self::clientToken($clientIp)
            : $this->instanceId . "\0fp\0" . $fingerprint);
    }

    /**
     * What a client IP is grouped under: an IPv4 address (including one
     * mapped from IPv6, folded to its plain IPv4 form) as itself, an IPv6
     * address by its /64 network, and anything that is not a valid IP as
     * itself, unchanged.
     */
    private static function clientToken(string $clientIp): string
    {
        $packed = @\inet_pton($clientIp);

        if ($packed === false || \strlen($packed) === 4) {
            return $clientIp;
        }

        // ::ffff:a.b.c.d: ten zero bytes, then 0xffff, then the IPv4 address.
        if (\substr($packed, 0, 12) === \str_repeat("\0", 10) . "\xff\xff") {
            return \inet_ntop(\substr($packed, 12, 4));
        }

        return 'v6/64:' . \bin2hex(\substr($packed, 0, 8));
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
        if (Sweep::isDue($this->config->gcProbability)) {
            $this->store->collectGarbage($now - $this->window());
        }
    }
}
