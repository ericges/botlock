<?php declare(strict_types=1);

namespace GES\Botlock\Challenge;

use GES\Botlock\Config\ProofOfWorkConfig;
use function GES\Botlock\randStr;

class ProofOfWork
{
    private float $difficulty = 1;

    public function __construct(private readonly ProofOfWorkConfig $config) {}

    public function setDifficulty(float $difficulty): static
    {
        $this->difficulty = \max(0.0, $difficulty);

        return $this;
    }

    public function getDifficulty(): float
    {
        return $this->difficulty;
    }

    /**
     * @param string $subject Client identifier (fingerprint) the challenge is bound to;
     *                        a solution is only valid for the subject it was issued to.
     */
    public function create(string $subject): array
    {
        $factor = $this->getDifficulty();
        $max = \max(100, (int) \floor($this->config->maxNumber * $factor));
        $min = \intdiv($max, 10);  // 10% of max, >= 10

        $number = \random_int($min, $max);
        $salt = randStr(15);
        $expire = $this->config->expire;
        $expire = $expire > 0 ? time() + $this->config->expire : 0;

        $powAlgorithm = $this->config->algorithm;

        if (!$target = $this->hashTarget($powAlgorithm, $number, $salt, $expire)) {
            throw new \RuntimeException('Failed to create challenge');
        }

        return [
            'alg' => $powAlgorithm,
            'exp' => $expire,
            'max' => $max,
            'slt' => $salt,
            'tgt' => $target,
            'sig' => $this->sign($target, $subject),
        ];
    }

    /**
     * @param array  $data    Solution as submitted by the client: num, sig, slt, exp, alg
     * @param string $subject Client identifier the challenge was created for
     */
    public function verify($data, string $subject): bool
    {
        if (!\is_array($data)
            || \count($data) !== 5
            || !\is_string($signature = $data['sig'] ?? null)
            || !\is_string($salt = $data['slt'] ?? null) || $salt === ''
            || !\is_numeric($expire = $data['exp'] ?? null)
            || !\is_string($algorithm = $data['alg'] ?? null))
        {
            return false;
        }

        $number = $data['num'] ?? null;
        if (!\is_int($number) && !(\is_string($number) && \ctype_digit($number))) {
            return false;
        }

        $number = (int) $number;
        if ($number < 1) {
            return false;
        }

        if ($algorithm !== $this->config->algorithm) {
            return false;
        }

        $expire = (int) $expire;
        if (!$expire || ($expire > 0 && $expire < \time())) {
            return false;
        }

        if (!$hash = $this->hashTarget($algorithm, $number, $salt, $expire)) {
            return false;
        }

        return \hash_equals($this->sign($hash, $subject), $signature);
    }

    private function sign(string $target, string $subject): string
    {
        return \hash_hmac('sha384', $subject . "\0" . $target, $this->config->secret);
    }

    private function hashTarget(string $algorithm, int $number, string $salt, int $expire): ?string
    {
        if (!\in_array($algorithm, ProofOfWorkConfig::ALLOWED_ALGORITHMS)) {
            return null;
        }

        return \hash($algorithm, $number . ':' . $salt . ':' . $expire);
    }
}
