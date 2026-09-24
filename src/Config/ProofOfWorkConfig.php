<?php declare(strict_types=1);

namespace GES\Botlock\Config;

/**
 * Everything the challenge and session signing needs.
 */
final readonly class ProofOfWorkConfig
{
    public const ALLOWED_ALGORITHMS = ['sha256', 'sha384', 'sha512'];

    public function __construct(
        /** Secret used to sign challenges and session JWTs. */
        public string $secret,
        /** Hash algorithm the browser brute-forces; one of ALLOWED_ALGORITHMS. */
        public string $algorithm = 'sha256',
        /** Challenge and session lifetime in seconds. */
        public int    $expire = 3600,
        /** Base upper bound of the number searched by a challenge. */
        public int    $maxNumber = 50000,
        /** Difficulty multiplier for crawlers that are not good bots; clamped to >= 1. */
        public int    $crawlerFactor = 15,
        /** Minimum milliseconds between issuing a challenge and accepting its solution. */
        public int    $minSolveMs = 1000,
        /** Difficulty multiplier for slider solves by keys or track presses instead of a drag; clamped to >= 1. */
        public int    $assistedFactor = 4,
    ) {
        if (!\in_array($this->algorithm, self::ALLOWED_ALGORITHMS, true)) {
            throw new \InvalidArgumentException('Invalid PoW algorithm provided');
        }
    }

    /**
     * @throws \InvalidArgumentException on an unsupported BOTLOCK_POW_ALGORITHM
     */
    public static function fromEnv(string $secret): self
    {
        return new self(
            secret: $secret,
            algorithm: \strtolower((string) Env::get('POW_ALGORITHM', 'sha256')),
            expire: (int) (Env::get('EXPIRE') ?: 3600),
            maxNumber: (int) (Env::get('MAX_NUMBER') ?: 50000),
            crawlerFactor: (int) (Env::get('CRAWLER_FACTOR') ?: 15),
            minSolveMs: \max(0, Env::int('MIN_SOLVE_MS', 1000)),
            assistedFactor: (int) (Env::get('SLIDER_ASSISTED_FACTOR') ?: 4),
        );
    }

    public function getCrawlerFactor(): int
    {
        return \max(1, $this->crawlerFactor);
    }

    public function getAssistedFactor(): int
    {
        return \max(1, $this->assistedFactor);
    }
}
