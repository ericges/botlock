<?php declare(strict_types=1);

namespace GES\Botlock\Challenge;

use GES\Botlock\Config\RateLimitConfig;
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
        $probability = $this->config->gcProbability;

        if ($probability > 0 && \random_int(1, $probability) === 1) {
            $this->store->collectGarbage($now - $this->window());
        }
    }
}
